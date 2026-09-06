// Package ctlauth implements authentication for the control plane between the
// LibreNMS PHP plugin and the gateway.
//
// The threat this defends against is not an attacker on the wire -- the control
// plane is loopback-only. It is an attacker who has found any way to make a
// request to the gateway's control port (a confused-deputy SSRF in another
// service on the host, a misconfigured proxy, a co-tenant process). Without
// this, such a request could create a session and obtain a ticket.
//
// See protocol/PROTOCOL.md section 3, which is normative for the wire format.
package ctlauth

import (
	"crypto/hkdf"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"strings"
	"sync"
	"time"

	"github.com/adn/librenms-webterm/gateway/internal/proto"
)

var (
	ErrSecretTooShort = errors.New("ctlauth: shared secret must be at least 32 bytes")
	ErrSecretExample  = errors.New("ctlauth: shared secret matches a value published in documentation")
	ErrBadTimestamp   = errors.New("ctlauth: timestamp outside accepted window")
	ErrReplay         = errors.New("ctlauth: nonce already used")
	ErrBadSignature   = errors.New("ctlauth: signature verification failed")
	ErrMalformed      = errors.New("ctlauth: malformed signature header")
)

// exampleSecrets are values that appear in our own documentation. An operator
// who copy-pastes from the docs must get a hard failure at startup rather than
// a gateway that runs with a publicly known key.
var exampleSecrets = []string{
	"00000000000000000000000000000000",
	"changeme-changeme-changeme-chang",
	"0123456789abcdef0123456789abcdef",
}

// DeriveControlKey derives the control-plane subkey from the shared secret.
//
// Subkeys are per-purpose so that no endpoint can be used as a signing oracle
// for another. A future purpose gets a new info string, never the raw secret.
func DeriveControlKey(secret []byte) ([]byte, error) {
	if err := ValidateSecret(secret); err != nil {
		return nil, err
	}

	return hkdf.Key(sha256.New, secret, []byte(proto.HKDFSalt), proto.HKDFInfoControl, 32)
}

// ValidateSecret enforces the rules the gateway applies at startup. It refuses
// to run rather than degrade: a short or published secret is a configuration
// error the operator must see immediately, not at first connection.
func ValidateSecret(secret []byte) error {
	if len(secret) < proto.SecretBytes {
		return fmt.Errorf("%w (got %d)", ErrSecretTooShort, len(secret))
	}
	for _, ex := range exampleSecrets {
		if hmac.Equal(secret, []byte(ex)) {
			return ErrSecretExample
		}
	}

	return nil
}

// Canonical builds the string that is signed. Its exact byte layout is
// normative; both implementations must produce identical output.
func Canonical(method, path string, unixTS int64, nonce string, body []byte) string {
	sum := sha256.Sum256(body)

	return strings.Join([]string{
		proto.Name,
		"v1",
		method,
		path,
		fmt.Sprintf("%d", unixTS),
		nonce,
		hex.EncodeToString(sum[:]),
	}, "\n")
}

// Sign returns the value for the signature header.
func Sign(key []byte, canonical string) string {
	mac := hmac.New(sha256.New, key)
	mac.Write([]byte(canonical))

	return proto.SignaturePrefix + hex.EncodeToString(mac.Sum(nil))
}

// Verifier validates inbound control-plane requests. Safe for concurrent use.
type Verifier struct {
	key []byte
	now func() time.Time

	mu     sync.Mutex
	seen   map[string]time.Time
	lastGC time.Time
}

func NewVerifier(key []byte) *Verifier {
	return &Verifier{
		key:  key,
		now:  time.Now,
		seen: make(map[string]time.Time),
	}
}

// Verify checks, in order: clock skew, nonce replay, then signature.
//
// Order matters for cost, not for security: the cheap checks shed load first.
// The signature comparison is constant time regardless.
func (v *Verifier) Verify(method, path string, unixTS int64, nonce, signature string, body []byte) error {
	now := v.now()

	skew := now.Unix() - unixTS
	if skew < 0 {
		skew = -skew
	}
	if skew > proto.TimestampSkewSeconds {
		return fmt.Errorf("%w: %ds", ErrBadTimestamp, skew)
	}

	if nonce == "" {
		return ErrMalformed
	}

	if !strings.HasPrefix(signature, proto.SignaturePrefix) {
		return ErrMalformed
	}
	provided, err := hex.DecodeString(strings.TrimPrefix(signature, proto.SignaturePrefix))
	if err != nil {
		return ErrMalformed
	}

	expected := Sign(v.key, Canonical(method, path, unixTS, nonce, body))
	expectedRaw, _ := hex.DecodeString(strings.TrimPrefix(expected, proto.SignaturePrefix))

	// Check the signature BEFORE consuming the nonce. Otherwise an attacker who
	// can guess a nonce could burn it with an unsigned request and deny the
	// legitimate one that follows.
	if !hmac.Equal(provided, expectedRaw) {
		return ErrBadSignature
	}

	if !v.consumeNonce(nonce, now) {
		return ErrReplay
	}

	return nil
}

// consumeNonce records the nonce, returning false if it was already present.
func (v *Verifier) consumeNonce(nonce string, now time.Time) bool {
	v.mu.Lock()
	defer v.mu.Unlock()

	if now.Sub(v.lastGC) > time.Second {
		horizon := now.Add(-proto.NonceCacheSeconds * time.Second)
		for n, seenAt := range v.seen {
			if seenAt.Before(horizon) {
				delete(v.seen, n)
			}
		}
		v.lastGC = now
	}

	if _, exists := v.seen[nonce]; exists {
		return false
	}
	v.seen[nonce] = now

	return true
}
