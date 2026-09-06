package server

import (
	"bytes"
	"context"
	"encoding/json"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"os"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/adn/librenms-webterm/gateway/internal/config"
	"github.com/adn/librenms-webterm/gateway/internal/ctlauth"
	"github.com/adn/librenms-webterm/gateway/internal/proto"
)

const canary = "CANARY-PASSWORD-DO-NOT-LOG"

var testSecret = []byte("gateway-test-secret-32-bytes!!!!")

func testServer(t *testing.T, level slog.Level) (*Server, *bytes.Buffer) {
	t.Helper()

	logs := &bytes.Buffer{}
	log := slog.New(slog.NewJSONHandler(logs, &slog.HandlerOptions{Level: level}))

	cfg := config.Default()
	cfg.Secret = testSecret
	cfg.AllowedOrigins = []string{"https://librenms.example.com"}

	s, err := New(cfg, log, "gw-test")
	if err != nil {
		t.Fatal(err)
	}

	return s, logs
}

// signedRequest builds a correctly authenticated control-plane request.
func signedRequest(t *testing.T, method, path string, body []byte) *http.Request {
	t.Helper()

	key, err := ctlauth.DeriveControlKey(testSecret)
	if err != nil {
		t.Fatal(err)
	}

	ts := time.Now().Unix()
	nonce := strconv.FormatInt(time.Now().UnixNano(), 36)
	sig := ctlauth.Sign(key, ctlauth.Canonical(method, path, ts, nonce, body))

	req := httptest.NewRequest(method, path, bytes.NewReader(body))
	req.Header.Set(proto.HeaderTimestamp, strconv.FormatInt(ts, 10))
	req.Header.Set(proto.HeaderNonce, nonce)
	req.Header.Set(proto.HeaderSignature, sig)

	return req
}

// TestCredentialNeverReachesTheLogs is a blocking test.
//
// It runs at DEBUG, the noisiest level, because that is where an
// well-meant diagnostic line is most likely to be added later.
func TestCredentialNeverReachesTheLogs(t *testing.T) {
	s, logs := testServer(t, slog.LevelDebug)
	h := s.Handler()

	create, _ := json.Marshal(map[string]any{
		"protocol":   proto.Version,
		"session_id": "01TESTTESTTESTTESTTESTTEST",
		"target":     map[string]any{"ip": "10.0.0.1", "port": 22},
		"auth":       map[string]any{"method": "password", "username": "netops"},
	})

	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, signedRequest(t, "POST", "/api/v1/sessions", create))
	if rec.Code != http.StatusCreated {
		t.Fatalf("create failed: %d %s", rec.Code, rec.Body.String())
	}

	cred, _ := json.Marshal(map[string]any{
		"auth": map[string]any{"method": "password", "password": canary},
	})

	rec = httptest.NewRecorder()
	path := "/api/v1/sessions/01TESTTESTTESTTESTTESTTEST/credential"
	h.ServeHTTP(rec, signedRequest(t, "POST", path, cred))
	if rec.Code != http.StatusNoContent {
		t.Fatalf("credential failed: %d %s", rec.Code, rec.Body.String())
	}

	// List and hello must not echo it either.
	for _, p := range []string{"/api/v1/sessions", "/api/v1/hello"} {
		rec = httptest.NewRecorder()
		h.ServeHTTP(rec, signedRequest(t, "GET", p, nil))
		if strings.Contains(rec.Body.String(), canary) {
			t.Fatalf("credential leaked in the response from %s", p)
		}
	}

	if strings.Contains(logs.String(), canary) {
		t.Fatalf("credential leaked into the logs:\n%s", logs.String())
	}
}

func TestControlPlaneRejectsUnsignedRequests(t *testing.T) {
	s, _ := testServer(t, slog.LevelInfo)
	h := s.Handler()

	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, httptest.NewRequest("GET", "/api/v1/hello", nil))

	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("expected 401 for an unsigned request, got %d", rec.Code)
	}
}

func TestControlPlaneDoesNotRevealWhyItRejected(t *testing.T) {
	// An unauthenticated caller learns only that it failed. Distinguishing
	// "bad signature" from "stale timestamp" would help someone probing.
	s, _ := testServer(t, slog.LevelInfo)
	h := s.Handler()

	req := httptest.NewRequest("GET", "/api/v1/hello", nil)
	req.Header.Set(proto.HeaderTimestamp, "1")
	req.Header.Set(proto.HeaderNonce, "n")
	req.Header.Set(proto.HeaderSignature, "v1=00")

	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, req)

	if body := rec.Body.String(); strings.Contains(body, "timestamp") || strings.Contains(body, "signature") {
		t.Fatalf("rejection reason leaked to the caller: %s", body)
	}
}

func TestWebSocketRejectsUnknownOrigin(t *testing.T) {
	s, _ := testServer(t, slog.LevelInfo)
	h := s.Handler()

	req := httptest.NewRequest("GET", "/ws", nil)
	req.Header.Set("Origin", "https://evil.example.com")
	req.Header.Set("Connection", "Upgrade")
	req.Header.Set("Upgrade", "websocket")

	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, req)

	if rec.Code != http.StatusForbidden {
		t.Fatalf("expected 403 for a foreign origin, got %d", rec.Code)
	}
	if rec.Header().Get(proto.HeaderRejectReason) != "origin" {
		t.Fatal("rejection must be diagnosable from the browser network tab")
	}
}

func TestHelloReportsProtocolRange(t *testing.T) {
	s, _ := testServer(t, slog.LevelInfo)
	h := s.Handler()

	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, signedRequest(t, "GET", "/api/v1/hello", nil))

	var resp helloResponse
	if err := json.Unmarshal(rec.Body.Bytes(), &resp); err != nil {
		t.Fatal(err)
	}

	if resp.MinVersion != proto.MinSupportedVersion || resp.MaxVersion != proto.MaxSupportedVersion {
		t.Fatal("hello must report the supported protocol range for skew detection")
	}
}

func TestCreateSessionRejectsUnsupportedProtocol(t *testing.T) {
	s, _ := testServer(t, slog.LevelInfo)
	h := s.Handler()

	body, _ := json.Marshal(map[string]any{
		"protocol":   99,
		"session_id": "01X",
		"target":     map[string]any{"ip": "10.0.0.1", "port": 22},
		"auth":       map[string]any{"method": "password", "username": "netops"},
	})

	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, signedRequest(t, "POST", "/api/v1/sessions", body))

	if rec.Code != http.StatusConflict {
		t.Fatalf("expected 409 for an unsupported protocol, got %d", rec.Code)
	}
}

func TestSignedCertificateFlowReturnsAPublicKeyOnly(t *testing.T) {
	s, _ := testServer(t, slog.LevelInfo)
	h := s.Handler()

	body, _ := json.Marshal(map[string]any{
		"protocol":   proto.Version,
		"session_id": "01CERT",
		"target":     map[string]any{"ip": "10.0.0.1", "port": 22},
		"auth":       map[string]any{"method": "signed_certificate", "username": "netops"},
	})

	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, signedRequest(t, "POST", "/api/v1/sessions", body))

	var resp createSessionResponse
	if err := json.Unmarshal(rec.Body.Bytes(), &resp); err != nil {
		t.Fatal(err)
	}

	if !strings.HasPrefix(resp.PublicKey, "ssh-ed25519 ") {
		t.Fatalf("expected an ed25519 public key, got %q", resp.PublicKey)
	}
	// The private half must never appear in a response.
	if strings.Contains(rec.Body.String(), "PRIVATE KEY") {
		t.Fatal("the ephemeral private key escaped in the response")
	}
}

func TestConfigRefusesNonLoopbackBind(t *testing.T) {
	// Config.Validate is what stops an operator exposing an unauthenticated-
	// looking control port to the network.
	cfg := config.Default()
	cfg.Listen = "0.0.0.0:8377"

	if err := cfg.Validate(); err == nil {
		t.Fatal("expected a non-loopback bind to be refused")
	}

	cfg.AllowInsecureControlPlane = true
	if err := cfg.Validate(); err != nil {
		t.Fatalf("explicit opt-in should be allowed: %v", err)
	}
}

func TestUIAssetsAreEmbedded(t *testing.T) {
	// Embedded so that installing the gateway never means copying JavaScript
	// into LibreNMS's html/ tree.
	s, _ := testServer(t, slog.LevelInfo)

	rec := httptest.NewRecorder()
	s.Handler().ServeHTTP(rec, httptest.NewRequest("GET", "/ui/xterm.js", nil))

	if rec.Code != http.StatusOK || rec.Body.Len() < 10000 {
		t.Fatalf("xterm.js not served from the binary: status %d, %d bytes", rec.Code, rec.Body.Len())
	}
}

func TestMain(m *testing.M) {
	os.Exit(m.Run())
}

var _ = context.Background
