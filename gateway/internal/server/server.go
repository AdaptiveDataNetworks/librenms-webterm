// Package server wires the control plane, the browser WebSocket and the
// terminal assets together.
package server

import (
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/coder/websocket"

	"github.com/adn/librenms-webterm/gateway/internal/config"
	"github.com/adn/librenms-webterm/gateway/internal/ctlauth"
	"github.com/adn/librenms-webterm/gateway/internal/proto"
	"github.com/adn/librenms-webterm/gateway/internal/session"
	"github.com/adn/librenms-webterm/gateway/internal/ui"
	"github.com/adn/librenms-webterm/gateway/internal/wsx"
)

type Server struct {
	cfg        config.Config
	log        *slog.Logger
	registry   *session.Registry
	verifier   *ctlauth.Verifier
	instanceID string

	drainOnce sync.Once
	draining  chan struct{}
}

func New(cfg config.Config, log *slog.Logger, instanceID string) (*Server, error) {
	key, err := ctlauth.DeriveControlKey(cfg.Secret)
	if err != nil {
		return nil, err
	}

	return &Server{
		cfg:        cfg,
		log:        log,
		registry:   session.NewRegistry(cfg.MaxSessions),
		verifier:   ctlauth.NewVerifier(key),
		instanceID: instanceID,
		draining:   make(chan struct{}),
	}, nil
}

func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()

	// Control plane: every one of these is HMAC-verified and never proxied to
	// the browser.
	mux.Handle("GET /api/v1/hello", s.control(s.handleHello))
	mux.Handle("POST /api/v1/sessions", s.control(s.handleCreateSession))
	mux.Handle("POST /api/v1/sessions/{id}/credential", s.control(s.handleCredential))
	mux.Handle("GET /api/v1/sessions", s.control(s.handleListSessions))
	mux.Handle("DELETE /api/v1/sessions/{id}", s.control(s.handleDeleteSession))
	mux.Handle("POST /api/v1/sessions/{id}/notice", s.control(s.handleNotice))

	mux.HandleFunc("GET /healthz", s.handleHealth)
	mux.HandleFunc("GET /readyz", s.handleReady)

	// Browser-reachable.
	mux.HandleFunc("GET /ws", s.handleWebSocket)
	mux.Handle("GET /ui/", ui.Handler("/ui"))

	return mux
}

// control wraps a handler with HMAC verification.
func (s *Server) control(next func(http.ResponseWriter, *http.Request, []byte)) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, err := io.ReadAll(io.LimitReader(r.Body, 1<<20))
		if err != nil {
			http.Error(w, "unreadable body", http.StatusBadRequest)

			return
		}

		ts := parseInt64(r.Header.Get(proto.HeaderTimestamp))
		nonce := r.Header.Get(proto.HeaderNonce)
		sig := r.Header.Get(proto.HeaderSignature)

		if err := s.verifier.Verify(r.Method, r.URL.Path, ts, nonce, sig, body); err != nil {
			// The reason is logged but not returned: an unauthenticated caller
			// learns only that it failed, not which check it failed.
			s.log.Warn("control plane request rejected",
				slog.String("path", r.URL.Path),
				slog.String("error", err.Error()))
			http.Error(w, "unauthorised", http.StatusUnauthorized)

			return
		}

		w.Header().Set("Cache-Control", "no-store")
		next(w, r, body)
	})
}

type helloResponse struct {
	Name         string   `json:"name"`
	Version      int      `json:"protocol"`
	MinVersion   int      `json:"min_protocol"`
	MaxVersion   int      `json:"max_protocol"`
	InstanceID   string   `json:"instance_id"`
	Capabilities []string `json:"capabilities"`
	Insecure     bool     `json:"insecure_control_plane"`
	MaxSessions  int      `json:"max_sessions"`
}

func (s *Server) handleHello(w http.ResponseWriter, r *http.Request, _ []byte) {
	writeJSON(w, http.StatusOK, helloResponse{
		Name:       proto.Name,
		Version:    proto.Version,
		MinVersion: proto.MinSupportedVersion,
		MaxVersion: proto.MaxSupportedVersion,
		InstanceID: s.instanceID,
		Capabilities: []string{
			"signed_certificate", "password", "private_key",
		},
		// Reported so that webterm:doctor can flag a gateway that was started
		// with the loopback guard disabled.
		Insecure:    s.cfg.AllowInsecureControlPlane,
		MaxSessions: s.cfg.MaxSessions,
	})
}

type createSessionRequest struct {
	Protocol  int            `json:"protocol"`
	SessionID string         `json:"session_id"`
	Target    session.Target `json:"target"`
	Auth      session.Auth   `json:"auth"`
	Limits    session.Limits `json:"limits"`
}

type createSessionResponse struct {
	SessionID  string `json:"session_id"`
	InstanceID string `json:"instance_id"`
	Ticket     string `json:"ticket"`
	ExpiresAt  string `json:"expires_at"`
	PublicKey  string `json:"public_key,omitempty"`
}

func (s *Server) handleCreateSession(w http.ResponseWriter, r *http.Request, body []byte) {
	var req createSessionRequest
	if err := json.Unmarshal(body, &req); err != nil {
		http.Error(w, "invalid json", http.StatusBadRequest)

		return
	}

	if req.Protocol < proto.MinSupportedVersion || req.Protocol > proto.MaxSupportedVersion {
		writeJSON(w, http.StatusConflict, map[string]any{
			"error":        "unsupported protocol version",
			"min_protocol": proto.MinSupportedVersion,
			"max_protocol": proto.MaxSupportedVersion,
		})

		return
	}

	if req.SessionID == "" || req.Target.IP == "" || req.Auth.Username == "" {
		http.Error(w, "session_id, target.ip and auth.username are required", http.StatusBadRequest)

		return
	}

	p := &session.Pending{
		ID:     req.SessionID,
		Target: req.Target,
		Auth:   req.Auth,
		Limits: req.Limits,
	}

	resp := createSessionResponse{SessionID: req.SessionID, InstanceID: s.instanceID}

	if req.Auth.Method == "signed_certificate" {
		privatePEM, publicKey, err := generateEphemeralKey()
		if err != nil {
			s.log.Error("generating ephemeral key", slog.String("error", err.Error()))
			http.Error(w, "key generation failed", http.StatusInternalServerError)

			return
		}
		p.SetPrivateKey([]byte(privatePEM))
		p.Auth.PrivateKey = privatePEM
		resp.PublicKey = publicKey
	}

	ticket, err := s.registry.Create(p, s.cfg.PendingTTL)
	if err != nil {
		if errors.Is(err, session.ErrAtCapacity) {
			http.Error(w, "gateway at capacity", http.StatusTooManyRequests)

			return
		}
		http.Error(w, "could not create session", http.StatusInternalServerError)

		return
	}

	resp.Ticket = ticket
	resp.ExpiresAt = p.ExpiresAt.UTC().Format(time.RFC3339)

	s.log.Info("session created",
		slog.String("session_id", req.SessionID),
		slog.String("method", req.Auth.Method),
		slog.String("target", req.Target.IP))

	writeJSON(w, http.StatusCreated, resp)
}

func (s *Server) handleCredential(w http.ResponseWriter, r *http.Request, body []byte) {
	var req struct {
		Auth session.Auth `json:"auth"`
	}
	if err := json.Unmarshal(body, &req); err != nil {
		http.Error(w, "invalid json", http.StatusBadRequest)

		return
	}

	id := r.PathValue("id")

	// The ephemeral private key was generated here and must survive the
	// credential write, which only carries the certificate.
	if req.Auth.Method == "signed_certificate" {
		if p, ok := s.registry.PendingPrivateKey(id); ok {
			req.Auth.PrivateKey = p
		}
	}

	if err := s.registry.SupplyCredential(id, req.Auth); err != nil {
		status := http.StatusNotFound
		if !errors.Is(err, session.ErrNotFound) {
			status = http.StatusConflict
		}
		http.Error(w, err.Error(), status)

		return
	}

	w.WriteHeader(http.StatusNoContent)
}

func (s *Server) handleListSessions(w http.ResponseWriter, r *http.Request, _ []byte) {
	live := s.registry.List()
	out := make([]map[string]any, 0, len(live))

	for _, l := range live {
		out = append(out, map[string]any{
			"session_id": l.ID,
			"target":     l.Target,
			"started_at": l.StartedAt.UTC().Format(time.RFC3339),
			"last_data":  l.LastData.UTC().Format(time.RFC3339),
		})
	}

	pending, liveCount := s.registry.Counts()

	writeJSON(w, http.StatusOK, map[string]any{
		"instance_id": s.instanceID,
		"pending":     pending,
		"live":        liveCount,
		"sessions":    out,
	})
}

func (s *Server) handleDeleteSession(w http.ResponseWriter, r *http.Request, body []byte) {
	var req struct {
		Reason string `json:"reason"`
	}
	_ = json.Unmarshal(body, &req)

	id := r.PathValue("id")
	l, ok := s.registry.Get(id)
	if !ok {
		w.WriteHeader(http.StatusNoContent)

		return
	}

	reason := req.Reason
	if reason == "" {
		reason = "closed by LibreNMS"
	}
	l.Close(4403, reason)

	s.log.Info("session killed", slog.String("session_id", id), slog.String("reason", reason))
	w.WriteHeader(http.StatusNoContent)
}

func (s *Server) handleNotice(w http.ResponseWriter, r *http.Request, body []byte) {
	var req struct {
		Level   string `json:"level"`
		Message string `json:"message"`
	}
	if err := json.Unmarshal(body, &req); err != nil {
		http.Error(w, "invalid json", http.StatusBadRequest)

		return
	}

	if n, ok := s.registry.Notice(r.PathValue("id")); ok {
		n(req.Level, req.Message)
	}

	w.WriteHeader(http.StatusNoContent)
}

func (s *Server) handleHealth(w http.ResponseWriter, _ *http.Request) {
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "instance_id": s.instanceID})
}

func (s *Server) handleReady(w http.ResponseWriter, _ *http.Request) {
	select {
	case <-s.draining:
		writeJSON(w, http.StatusServiceUnavailable, map[string]any{"status": "draining"})
	default:
		pending, live := s.registry.Counts()
		writeJSON(w, http.StatusOK, map[string]any{
			"status": "ready", "pending": pending, "live": live,
		})
	}
}

func (s *Server) handleWebSocket(w http.ResponseWriter, r *http.Request) {
	conn, err := wsx.Accept(w, r, s.cfg.AllowedOrigins)
	if err != nil {
		// Accept has already written the response, including the reject header.
		s.log.Warn("websocket rejected",
			slog.String("origin", r.Header.Get("Origin")),
			slog.String("error", err.Error()))

		return
	}

	ctx := r.Context()
	defer func() { _ = conn.CloseNow() }()

	auth, err := wsx.ReadAuth(ctx, conn, s.cfg.HandshakeTimeout)
	if err != nil {
		_ = conn.Close(websocket.StatusCode(4400), "expected an auth frame")

		return
	}

	p, err := s.registry.Redeem(auth.Ticket)
	if err != nil {
		s.log.Warn("ticket refused", slog.String("error", err.Error()))
		_ = conn.Close(websocket.StatusCode(4401), "ticket invalid or expired")

		return
	}

	s.runSession(ctx, conn, p, auth)
}

func writeJSON(w http.ResponseWriter, status int, body any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(body)
}

func parseInt64(s string) int64 {
	var n int64
	for _, c := range strings.TrimSpace(s) {
		if c < '0' || c > '9' {
			return 0
		}
		n = n*10 + int64(c-'0')
	}

	return n
}

// Drain stops accepting new sessions and tells live ones to finish.
//
// Terminals are interactive: dropping them silently on a restart looks like a
// network fault. Each gets an in-band notice first, so the operator knows what
// happened and that reconnecting is expected to work.
func (s *Server) Drain() {
	s.drainOnce.Do(func() {
		close(s.draining)

		for _, l := range s.registry.List() {
			if l.Notice != nil {
				l.Notice("warning", "The terminal gateway is restarting. Reconnect in a moment.")
			}
		}

		time.Sleep(500 * time.Millisecond)

		for _, l := range s.registry.List() {
			if l.Close != nil {
				l.Close(1001, "gateway shutting down")
			}
		}
	})
}
