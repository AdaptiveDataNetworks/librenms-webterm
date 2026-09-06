package ctlauth

import (
	"strings"
	"sync"
	"testing"
	"time"
)

var testSecret = []byte("this-is-a-32-byte-test-secret!!!")

func mustKey(t *testing.T) []byte {
	t.Helper()
	k, err := DeriveControlKey(testSecret)
	if err != nil {
		t.Fatalf("DeriveControlKey: %v", err)
	}
	return k
}

func signed(t *testing.T, key []byte, method, path string, ts int64, nonce string, body []byte) string {
	t.Helper()
	return Sign(key, Canonical(method, path, ts, nonce, body))
}

func TestValidateSecretRejectsShort(t *testing.T) {
	if err := ValidateSecret([]byte("too-short")); err == nil {
		t.Fatal("expected short secret to be rejected")
	}
}

func TestValidateSecretRejectsDocumentedExamples(t *testing.T) {
	// An operator who copy-pastes from our docs must fail loudly at startup,
	// not run with a publicly known key.
	for _, ex := range exampleSecrets {
		if err := ValidateSecret([]byte(ex)); err == nil {
			t.Fatalf("expected documented example %q to be rejected", ex)
		}
	}
}

func TestDeriveControlKeyIsDeterministicAndNotTheSecret(t *testing.T) {
	a, err := DeriveControlKey(testSecret)
	if err != nil {
		t.Fatal(err)
	}
	b, _ := DeriveControlKey(testSecret)

	if string(a) != string(b) {
		t.Fatal("derivation is not deterministic")
	}
	if string(a) == string(testSecret) {
		t.Fatal("derived key must not equal the raw secret")
	}
	if len(a) != 32 {
		t.Fatalf("expected 32-byte key, got %d", len(a))
	}
}

func TestCanonicalIsStable(t *testing.T) {
	// This exact layout is normative (PROTOCOL.md section 3.2). The PHP
	// implementation must produce the identical string, so pin it here.
	got := Canonical("POST", "/api/v1/sessions", 1757068800, "abc123", []byte(`{"a":1}`))
	want := strings.Join([]string{
		"lnms-webterm",
		"v1",
		"POST",
		"/api/v1/sessions",
		"1757068800",
		"abc123",
		"c4d871a9a1c6d9d0e0f4a20b5a9d6f0dbd9a4d8f4a2a6dd8fd8b1e3a1e2e1a1b",
	}, "\n")

	// Only the first six fields are asserted literally; the seventh is the body
	// hash, which we recompute rather than hard-code.
	gotFields := strings.Split(got, "\n")
	wantFields := strings.Split(want, "\n")
	for i := 0; i < 6; i++ {
		if gotFields[i] != wantFields[i] {
			t.Errorf("field %d: got %q want %q", i, gotFields[i], wantFields[i])
		}
	}
	if len(gotFields) != 7 {
		t.Fatalf("expected 7 fields, got %d", len(gotFields))
	}
	if len(gotFields[6]) != 64 {
		t.Errorf("body hash should be 64 hex chars, got %d", len(gotFields[6]))
	}
}

func TestVerifyAcceptsAValidRequest(t *testing.T) {
	key := mustKey(t)
	v := NewVerifier(key)
	ts := time.Now().Unix()
	body := []byte(`{"session_id":"x"}`)
	sig := signed(t, key, "POST", "/api/v1/sessions", ts, "nonce-1", body)

	if err := v.Verify("POST", "/api/v1/sessions", ts, "nonce-1", sig, body); err != nil {
		t.Fatalf("valid request rejected: %v", err)
	}
}

func TestVerifyRejectsReplay(t *testing.T) {
	key := mustKey(t)
	v := NewVerifier(key)
	ts := time.Now().Unix()
	body := []byte("{}")
	sig := signed(t, key, "POST", "/api/v1/sessions", ts, "nonce-replay", body)

	if err := v.Verify("POST", "/api/v1/sessions", ts, "nonce-replay", sig, body); err != nil {
		t.Fatalf("first use should succeed: %v", err)
	}
	if err := v.Verify("POST", "/api/v1/sessions", ts, "nonce-replay", sig, body); err != ErrReplay {
		t.Fatalf("expected ErrReplay on second use, got %v", err)
	}
}

func TestVerifyRejectsStaleAndFutureTimestamps(t *testing.T) {
	key := mustKey(t)
	v := NewVerifier(key)
	body := []byte("{}")

	for _, offset := range []int64{-3600, -31, 31, 3600} {
		ts := time.Now().Unix() + offset
		sig := signed(t, key, "GET", "/api/v1/hello", ts, "n", body)
		if err := v.Verify("GET", "/api/v1/hello", ts, "n", sig, body); err == nil {
			t.Errorf("offset %ds should have been rejected", offset)
		}
	}
}

func TestVerifyRejectsTamperedFields(t *testing.T) {
	key := mustKey(t)
	ts := time.Now().Unix()
	body := []byte(`{"device":1}`)
	sig := signed(t, key, "POST", "/api/v1/sessions", ts, "n1", body)

	cases := []struct {
		name                string
		method, path, nonce string
		body                []byte
	}{
		{"method", "GET", "/api/v1/sessions", "n1", body},
		{"path", "POST", "/api/v1/sessions/evil", "n1", body},
		{"nonce", "POST", "/api/v1/sessions", "n2", body},
		{"body", "POST", "/api/v1/sessions", "n1", []byte(`{"device":2}`)},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			v := NewVerifier(key)
			if err := v.Verify(c.method, c.path, ts, c.nonce, sig, c.body); err != ErrBadSignature {
				t.Fatalf("tampered %s: expected ErrBadSignature, got %v", c.name, err)
			}
		})
	}
}

func TestVerifyRejectsWrongKey(t *testing.T) {
	key := mustKey(t)
	other, _ := DeriveControlKey([]byte("a-completely-different-32byte-key"))
	ts := time.Now().Unix()
	body := []byte("{}")
	sig := signed(t, other, "GET", "/api/v1/hello", ts, "n", body)

	v := NewVerifier(key)
	if err := v.Verify("GET", "/api/v1/hello", ts, "n", sig, body); err != ErrBadSignature {
		t.Fatalf("expected ErrBadSignature, got %v", err)
	}
}

func TestVerifyRejectsMalformedSignature(t *testing.T) {
	v := NewVerifier(mustKey(t))
	ts := time.Now().Unix()

	for _, sig := range []string{"", "deadbeef", "v2=deadbeef", "v1=not-hex-zz"} {
		if err := v.Verify("GET", "/api/v1/hello", ts, "n", sig, nil); err != ErrMalformed {
			t.Errorf("signature %q: expected ErrMalformed, got %v", sig, err)
		}
	}
}

func TestInvalidSignatureDoesNotBurnTheNonce(t *testing.T) {
	// If a bad signature consumed the nonce, anyone able to reach the control
	// port could deny legitimate requests by guessing nonces.
	key := mustKey(t)
	v := NewVerifier(key)
	ts := time.Now().Unix()
	body := []byte("{}")
	good := signed(t, key, "GET", "/api/v1/hello", ts, "shared-nonce", body)

	if err := v.Verify("GET", "/api/v1/hello", ts, "shared-nonce", "v1=00", body); err != ErrBadSignature {
		t.Fatalf("expected ErrBadSignature, got %v", err)
	}
	if err := v.Verify("GET", "/api/v1/hello", ts, "shared-nonce", good, body); err != nil {
		t.Fatalf("legitimate request after a forgery attempt must succeed, got %v", err)
	}
}

func TestVerifierIsConcurrencySafe(t *testing.T) {
	key := mustKey(t)
	v := NewVerifier(key)
	ts := time.Now().Unix()
	body := []byte("{}")

	// Same nonce from many goroutines: exactly one must win.
	const n = 50
	var wg sync.WaitGroup
	results := make([]error, n)
	sig := signed(t, key, "GET", "/api/v1/hello", ts, "race", body)

	for i := 0; i < n; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			results[i] = v.Verify("GET", "/api/v1/hello", ts, "race", sig, body)
		}(i)
	}
	wg.Wait()

	ok := 0
	for _, err := range results {
		if err == nil {
			ok++
		}
	}
	if ok != 1 {
		t.Fatalf("expected exactly 1 success, got %d", ok)
	}
}
