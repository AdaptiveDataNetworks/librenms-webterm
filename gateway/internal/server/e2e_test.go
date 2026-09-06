package server

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/coder/websocket"
	"golang.org/x/crypto/ssh"

	"github.com/adn/librenms-webterm/gateway/internal/config"
	"github.com/adn/librenms-webterm/gateway/internal/proto"
)

/*
End-to-end through the whole gateway: a real SSH server, the real control
plane, a real WebSocket.

An in-process SSH server rather than a container, so this runs in CI on every
push with no daemon to install. It exercises the parts that unit tests cannot:
that host key verification really happens before authentication, that the byte
pump really moves data both ways, and that a changed host key really refuses.
*/

// testSSHServer is a minimal SSH server that echoes what it receives.
type testSSHServer struct {
	listener net.Listener
	hostKey  ssh.Signer
	password string
}

func newTestSSHServer(t *testing.T, password string) *testSSHServer {
	t.Helper()

	_, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	signer, err := ssh.NewSignerFromKey(priv)
	if err != nil {
		t.Fatal(err)
	}

	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}

	s := &testSSHServer{listener: ln, hostKey: signer, password: password}
	go s.serve()
	t.Cleanup(func() { _ = ln.Close() })

	return s
}

func (s *testSSHServer) addr() (string, int) {
	host, _, _ := net.SplitHostPort(s.listener.Addr().String())
	port := s.listener.Addr().(*net.TCPAddr).Port

	return host, port
}

func (s *testSSHServer) publicKeyLine() string {
	return strings.TrimSpace(string(ssh.MarshalAuthorizedKey(s.hostKey.PublicKey())))
}

func (s *testSSHServer) serve() {
	cfg := &ssh.ServerConfig{
		PasswordCallback: func(c ssh.ConnMetadata, pass []byte) (*ssh.Permissions, error) {
			if string(pass) == s.password {
				return nil, nil
			}

			return nil, errors.New("denied")
		},
	}
	cfg.AddHostKey(s.hostKey)

	for {
		conn, err := s.listener.Accept()
		if err != nil {
			return
		}
		go s.handle(conn, cfg)
	}
}

func (s *testSSHServer) handle(conn net.Conn, cfg *ssh.ServerConfig) {
	sshConn, chans, reqs, err := ssh.NewServerConn(conn, cfg)
	if err != nil {
		return
	}
	defer func() { _ = sshConn.Close() }()

	go ssh.DiscardRequests(reqs)

	for newChan := range chans {
		if newChan.ChannelType() != "session" {
			_ = newChan.Reject(ssh.UnknownChannelType, "only session channels")

			continue
		}

		ch, chReqs, err := newChan.Accept()
		if err != nil {
			return
		}

		go func() {
			for req := range chReqs {
				// Accept pty-req and shell; everything else is refused.
				switch req.Type {
				case "pty-req", "shell", "window-change":
					if req.WantReply {
						_ = req.Reply(true, nil)
					}
				default:
					if req.WantReply {
						_ = req.Reply(false, nil)
					}
				}
			}
		}()

		go func() {
			defer func() { _ = ch.Close() }()
			_, _ = ch.Write([]byte("welcome\r\n"))
			_, _ = io.Copy(ch, ch) // echo
		}()
	}
}

// harness wires a gateway in front of a test SSH server.
type harness struct {
	srv    *httptest.Server
	ssh    *testSSHServer
	server *Server
}

func newHarness(t *testing.T, knownHosts []string) *harness {
	t.Helper()

	sshServer := newTestSSHServer(t, "hunter2")

	logs := &strings.Builder{}
	cfg := config.Default()
	cfg.Secret = testSecret
	cfg.AllowedOrigins = []string{"https://librenms.example.com"}
	cfg.HandshakeTimeout = 10 * time.Second

	s, err := New(cfg, slog.New(slog.NewJSONHandler(logs, nil)), "gw-e2e")
	if err != nil {
		t.Fatal(err)
	}

	ts := httptest.NewServer(s.Handler())
	t.Cleanup(ts.Close)

	return &harness{srv: ts, ssh: sshServer, server: s}
}

func (h *harness) createSession(t *testing.T, id string, knownHosts []string, policy, password string) string {
	t.Helper()

	ip, port := h.ssh.addr()

	body, _ := json.Marshal(map[string]any{
		"protocol":   proto.Version,
		"session_id": id,
		"target": map[string]any{
			"ip": ip, "port": port,
			"host_key_policy": policy,
			"known_hosts":     knownHosts,
		},
		"auth":   map[string]any{"method": "password", "username": "netops"},
		"limits": map[string]any{"idle_timeout": 900, "max_duration": 3600},
	})

	req := signedRequest(t, "POST", "/api/v1/sessions", body)
	req.URL.Scheme = "http"
	req.URL.Host = strings.TrimPrefix(h.srv.URL, "http://")
	req.RequestURI = ""

	resp, err := h.srv.Client().Do(req)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = resp.Body.Close() }()

	var created createSessionResponse
	if err := json.NewDecoder(resp.Body).Decode(&created); err != nil {
		t.Fatalf("decoding create response: %v", err)
	}

	credBody, _ := json.Marshal(map[string]any{
		"auth": map[string]any{"method": "password", "password": password},
	})
	credReq := signedRequest(t, "POST", "/api/v1/sessions/"+id+"/credential", credBody)
	credReq.URL.Scheme = "http"
	credReq.URL.Host = strings.TrimPrefix(h.srv.URL, "http://")
	credReq.RequestURI = ""

	credResp, err := h.srv.Client().Do(credReq)
	if err != nil {
		t.Fatal(err)
	}
	_ = credResp.Body.Close()

	return created.Ticket
}

func (h *harness) connect(t *testing.T, ticket string) (*websocket.Conn, context.CancelFunc) {
	t.Helper()

	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)

	wsURL := "ws" + strings.TrimPrefix(h.srv.URL, "http") + "/ws"
	conn, _, err := websocket.Dial(ctx, wsURL, &websocket.DialOptions{
		Subprotocols: []string{proto.Subprotocol},
		HTTPHeader:   map[string][]string{"Origin": {"https://librenms.example.com"}},
	})
	if err != nil {
		cancel()
		t.Fatalf("websocket dial: %v", err)
	}

	auth, _ := json.Marshal(map[string]any{"type": "auth", "ticket": ticket, "cols": 80, "rows": 24})
	if err := conn.Write(ctx, websocket.MessageText, auth); err != nil {
		cancel()
		t.Fatal(err)
	}

	return conn, cancel
}

func readFrame(t *testing.T, ctx context.Context, conn *websocket.Conn) map[string]any {
	t.Helper()

	_, data, err := conn.Read(ctx)
	if err != nil {
		t.Fatalf("reading frame: %v", err)
	}

	var f map[string]any
	if err := json.Unmarshal(data, &f); err != nil {
		t.Fatalf("decoding frame: %v", err)
	}

	return f
}

func TestEndToEndShellSession(t *testing.T) {
	h := newHarness(t, nil)
	ticket := h.createSession(t, "01E2ESESSION0000000000000A", []string{h.ssh.publicKeyLine()}, "pin", "hunter2")

	conn, cancel := h.connect(t, ticket)
	defer cancel()
	defer func() { _ = conn.CloseNow() }()

	ctx := context.Background()

	// ready, then the server's banner.
	if f := readFrame(t, ctx, conn); f["type"] != "ready" {
		t.Fatalf("expected a ready frame, got %v", f["type"])
	}

	var banner string
	for i := 0; i < 5 && !strings.Contains(banner, "welcome"); i++ {
		f := readFrame(t, ctx, conn)
		if f["type"] == "data" {
			banner += f["data"].(string)
		}
	}
	if !strings.Contains(banner, "welcome") {
		t.Fatalf("did not receive the SSH banner, got %q", banner)
	}

	// Round-trip: type something and see it echoed back through SSH.
	payload, _ := json.Marshal(map[string]any{"type": "data", "data": "hello world\n"})
	if err := conn.Write(ctx, websocket.MessageText, payload); err != nil {
		t.Fatal(err)
	}

	var echoed string
	for i := 0; i < 10 && !strings.Contains(echoed, "hello world"); i++ {
		f := readFrame(t, ctx, conn)
		if f["type"] == "data" {
			echoed += f["data"].(string)
		}
	}

	if !strings.Contains(echoed, "hello world") {
		t.Fatalf("data did not round-trip through the SSH session, got %q", echoed)
	}
}

func TestChangedHostKeyIsRefusedBeforeAuthentication(t *testing.T) {
	// The device must never see the credential when its key does not match.
	h := newHarness(t, nil)

	_, otherPriv, _ := ed25519.GenerateKey(rand.Reader)
	otherSigner, _ := ssh.NewSignerFromKey(otherPriv)
	wrongKey := strings.TrimSpace(string(ssh.MarshalAuthorizedKey(otherSigner.PublicKey())))

	ticket := h.createSession(t, "01E2EWRONGKEY000000000000A", []string{wrongKey}, "pin", "hunter2")

	conn, cancel := h.connect(t, ticket)
	defer cancel()
	defer func() { _ = conn.CloseNow() }()

	f := readFrame(t, context.Background(), conn)

	if f["type"] != "error" {
		t.Fatalf("expected an error frame, got %v", f["type"])
	}
	if code, _ := f["code"].(float64); int(code) != 4403 {
		t.Fatalf("expected close code 4403, got %v", f["code"])
	}
	if msg, _ := f["message"].(string); !strings.Contains(msg, "different SSH host key") {
		t.Fatalf("the message should name the actual situation, got %q", msg)
	}
}

func TestUnpinnedHostKeyIsRefusedUnderPinPolicy(t *testing.T) {
	h := newHarness(t, nil)
	ticket := h.createSession(t, "01E2ENOPIN00000000000000A", nil, "pin", "hunter2")

	conn, cancel := h.connect(t, ticket)
	defer cancel()
	defer func() { _ = conn.CloseNow() }()

	f := readFrame(t, context.Background(), conn)
	if f["type"] != "error" {
		t.Fatalf("expected an error frame, got %v", f["type"])
	}
}

func TestFirstConnectTofuIsAllowedWhenPolicySaysSo(t *testing.T) {
	h := newHarness(t, nil)
	ticket := h.createSession(t, "01E2ETOFU000000000000000A", nil, "tofu_first_connect", "hunter2")

	conn, cancel := h.connect(t, ticket)
	defer cancel()
	defer func() { _ = conn.CloseNow() }()

	if f := readFrame(t, context.Background(), conn); f["type"] != "ready" {
		t.Fatalf("expected a ready frame, got %v", f["type"])
	}
}

func TestWrongPasswordFailsWithoutLeakingIt(t *testing.T) {
	h := newHarness(t, nil)
	ticket := h.createSession(t, "01E2EBADPASS000000000000A", []string{h.ssh.publicKeyLine()}, "pin", "WRONG-CANARY")

	conn, cancel := h.connect(t, ticket)
	defer cancel()
	defer func() { _ = conn.CloseNow() }()

	f := readFrame(t, context.Background(), conn)

	if f["type"] != "error" {
		t.Fatalf("expected an error frame, got %v", f["type"])
	}
	if msg, _ := f["message"].(string); strings.Contains(msg, "WRONG-CANARY") {
		t.Fatal("the credential leaked into the error shown to the browser")
	}
}

func TestTicketCannotBeRedeemedTwice(t *testing.T) {
	h := newHarness(t, nil)
	ticket := h.createSession(t, "01E2EREPLAY0000000000000A", []string{h.ssh.publicKeyLine()}, "pin", "hunter2")

	conn, cancel := h.connect(t, ticket)
	defer cancel()
	if f := readFrame(t, context.Background(), conn); f["type"] != "ready" {
		t.Fatalf("first connection should succeed, got %v", f["type"])
	}

	// Second attempt with the same ticket.
	ctx, cancel2 := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel2()

	wsURL := "ws" + strings.TrimPrefix(h.srv.URL, "http") + "/ws"
	conn2, _, err := websocket.Dial(ctx, wsURL, &websocket.DialOptions{
		Subprotocols: []string{proto.Subprotocol},
		HTTPHeader:   map[string][]string{"Origin": {"https://librenms.example.com"}},
	})
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = conn2.CloseNow() }()

	auth, _ := json.Marshal(map[string]any{"type": "auth", "ticket": ticket})
	_ = conn2.Write(ctx, websocket.MessageText, auth)

	if _, _, err := conn2.Read(ctx); err == nil {
		t.Fatal("a replayed ticket must not open a second session")
	}

	_ = conn.CloseNow()
}
