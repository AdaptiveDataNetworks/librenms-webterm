// Package wsx terminates the browser WebSocket and pumps bytes to and from the
// SSH session.
package wsx

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"

	"github.com/coder/websocket"

	"github.com/adn/librenms-webterm/gateway/internal/proto"
)

// Frame is the JSON envelope in both directions.
type Frame struct {
	Type    string `json:"type"`
	Ticket  string `json:"ticket,omitempty"`
	Data    string `json:"data,omitempty"`
	Cols    int    `json:"cols,omitempty"`
	Rows    int    `json:"rows,omitempty"`
	Level   string `json:"level,omitempty"`
	Message string `json:"message,omitempty"`
	Code    int    `json:"code,omitempty"`
	Session string `json:"session_id,omitempty"`
	Version int    `json:"server_version,omitempty"`
}

var ErrOriginRejected = errors.New("wsx: origin not allow-listed")

// CheckOrigin validates the Origin header against an allow-list.
//
// Deny by default, including when the list is empty: an unconfigured gateway
// accepts no browser at all. SameSite cookies do NOT prevent cross-site
// WebSocket hijacking -- the handshake is not subject to CORS and carries
// cookies regardless -- so this check, not the cookie, is the control.
func CheckOrigin(r *http.Request, allowed []string) error {
	origin := r.Header.Get("Origin")
	if origin == "" {
		// A browser always sends Origin on a WebSocket handshake. Its absence
		// means a non-browser client, which has no business here.
		return ErrOriginRejected
	}

	got, err := url.Parse(origin)
	if err != nil {
		return ErrOriginRejected
	}

	for _, a := range allowed {
		want, err := url.Parse(strings.TrimSpace(a))
		if err != nil {
			continue
		}
		if strings.EqualFold(got.Scheme, want.Scheme) && strings.EqualFold(got.Host, want.Host) {
			return nil
		}
	}

	return ErrOriginRejected
}

// Accept upgrades the connection after checking the origin.
//
// On rejection it writes 403 with an explicit header BEFORE upgrading, so the
// failure is visible in the browser's network tab. Operators otherwise spend
// hours on this: a silent close looks identical to a proxy timeout, which is
// why troubleshooting.md keys the symptom off X-WebTerm-Reject.
func Accept(w http.ResponseWriter, r *http.Request, allowed []string) (*websocket.Conn, error) {
	if err := CheckOrigin(r, allowed); err != nil {
		w.Header().Set(proto.HeaderRejectReason, "origin")
		http.Error(w, "origin not permitted", http.StatusForbidden)

		return nil, err
	}

	return websocket.Accept(w, r, &websocket.AcceptOptions{
		Subprotocols: []string{proto.Subprotocol},
		// Origin is enforced above with our own allow-list.
		InsecureSkipVerify: true,
	})
}

// ReadAuth reads the first frame, which must carry the ticket.
//
// The ticket travels here rather than in the URL because query strings are
// written verbatim to nginx access logs and leak through Referer. A short-lived
// single-use credential sitting in a log file that ships to a SIEM is a
// credential in a place nobody expects one.
func ReadAuth(ctx context.Context, c *websocket.Conn, timeout time.Duration) (Frame, error) {
	ctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	_, data, err := c.Read(ctx)
	if err != nil {
		return Frame{}, err
	}

	var f Frame
	if err := json.Unmarshal(data, &f); err != nil {
		return Frame{}, err
	}

	if f.Type != "auth" || f.Ticket == "" {
		return Frame{}, errors.New("wsx: first frame must be an auth frame carrying a ticket")
	}

	return f, nil
}

// Writer serialises frames to the socket. A websocket connection permits only
// one concurrent writer, and output arrives from both the SSH pump and the
// keepalive goroutine.
type Writer struct {
	mu   sync.Mutex
	conn *websocket.Conn
}

func NewWriter(c *websocket.Conn) *Writer {
	return &Writer{conn: c}
}

func (w *Writer) Send(ctx context.Context, f Frame) error {
	payload, err := json.Marshal(f)
	if err != nil {
		return err
	}

	w.mu.Lock()
	defer w.mu.Unlock()

	return w.conn.Write(ctx, websocket.MessageText, payload)
}

func (w *Writer) Data(ctx context.Context, b []byte) error {
	return w.Send(ctx, Frame{Type: "data", Data: string(b)})
}

func (w *Writer) Notice(ctx context.Context, level, message string) error {
	return w.Send(ctx, Frame{Type: "notice", Level: level, Message: message})
}

func (w *Writer) Error(ctx context.Context, code int, message string) error {
	return w.Send(ctx, Frame{Type: "error", Code: code, Message: message})
}

// KeepAlive pings on an interval well under nginx's proxy_read_timeout.
//
// nginx defaults that to 60 seconds. Without pings, every idle terminal dies
// after a minute and presents as an unexplained hang -- the single most
// commonly reported problem with browser terminals behind a proxy.
func KeepAlive(ctx context.Context, c *websocket.Conn) {
	ticker := time.NewTicker(proto.WSPingIntervalSeconds * time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			pingCtx, cancel := context.WithTimeout(ctx, proto.WSPongTimeoutSeconds*time.Second)
			err := c.Ping(pingCtx)
			cancel()

			if err != nil {
				return
			}
		}
	}
}
