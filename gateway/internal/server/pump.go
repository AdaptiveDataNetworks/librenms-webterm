package server

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"time"

	"github.com/coder/websocket"

	"github.com/adn/librenms-webterm/gateway/internal/proto"
	"github.com/adn/librenms-webterm/gateway/internal/session"
	"github.com/adn/librenms-webterm/gateway/internal/sshx"
	"github.com/adn/librenms-webterm/gateway/internal/wsx"
)

// runSession connects to the device and moves bytes until something stops it.
func (s *Server) runSession(ctx context.Context, conn *websocket.Conn, p *session.Pending, auth wsx.Frame) {
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()

	writer := wsx.NewWriter(conn)

	dialCtx, dialCancel := context.WithTimeout(ctx, s.cfg.HandshakeTimeout)
	sshConn, err := sshx.Dial(dialCtx, p, s.cfg.HandshakeTimeout)
	dialCancel()

	if err != nil {
		// Host key problems get their own close code and a message that names
		// the actual situation: "connection failed" sends an operator hunting
		// through network configuration for a trust decision.
		code, msg := classifyDialError(err)
		s.log.Warn("ssh dial failed",
			slog.String("session_id", p.ID),
			slog.String("target", p.Target.IP),
			slog.String("error", err.Error()))

		_ = writer.Error(ctx, code, msg)
		_ = conn.Close(websocket.StatusCode(code), msg)

		return
	}
	defer func() { _ = sshConn.Close() }()

	closeOnce := make(chan struct{})
	var closeCode = 1000
	var closeReason = "session ended"

	live := &session.Live{
		ID:        p.ID,
		Target:    p.Target.IP,
		StartedAt: time.Now(),
		LastData:  time.Now(),
		Close: func(code int, reason string) {
			closeCode, closeReason = code, reason
			select {
			case <-closeOnce:
			default:
				close(closeOnce)
			}
		},
		Notice: func(level, message string) {
			_ = writer.Notice(ctx, level, message)
		},
	}

	s.registry.Activate(live)
	defer s.registry.Deactivate(p.ID)

	_ = writer.Send(ctx, wsx.Frame{Type: "ready", Session: p.ID, Version: proto.Version})

	if p.Target.HostKeyPolicy == "tofu_first_connect" && sshConn.HostKey.FirstSeen {
		_ = writer.Notice(ctx, "warning",
			"First connection to this device: its host key has been recorded but not verified against anything.")
	}

	go wsx.KeepAlive(ctx, conn)

	// Device -> browser.
	go func() {
		buf := make([]byte, 32*1024)
		for {
			n, err := sshConn.Stdout.Read(buf)
			if n > 0 {
				live.LastData = time.Now()
				if err := writer.Data(ctx, buf[:n]); err != nil {
					cancel()

					return
				}
			}
			if err != nil {
				if !errors.Is(err, io.EOF) {
					s.log.Debug("ssh read ended", slog.String("session_id", p.ID))
				}
				live.Close(1000, "remote host closed the connection")

				return
			}
		}
	}()

	idle := time.Duration(orDefault(p.Limits.IdleTimeout, int(s.cfg.IdleTimeout.Seconds()))) * time.Second
	maxDuration := time.Duration(orDefault(p.Limits.MaxDuration, int(s.cfg.MaxDuration.Seconds()))) * time.Second

	deadline := time.NewTimer(maxDuration)
	defer deadline.Stop()

	idleTicker := time.NewTicker(15 * time.Second)
	defer idleTicker.Stop()

	// Browser -> device.
	frames := make(chan wsx.Frame, 16)
	go func() {
		defer close(frames)
		for {
			_, data, err := conn.Read(ctx)
			if err != nil {
				return
			}

			var f wsx.Frame
			if err := json.Unmarshal(data, &f); err != nil {
				continue
			}

			select {
			case frames <- f:
			case <-ctx.Done():
				return
			}
		}
	}()

	if auth.Cols > 0 && auth.Rows > 0 {
		_ = sshConn.Session.WindowChange(auth.Rows, auth.Cols)
	}

	for {
		select {
		case <-ctx.Done():
			return

		case <-closeOnce:
			_ = writer.Error(ctx, closeCode, closeReason)
			_ = conn.Close(websocket.StatusCode(closeCode), closeReason)

			return

		case <-deadline.C:
			live.Close(4408, "maximum session duration reached")

		case <-idleTicker.C:
			if idle > 0 && time.Since(live.LastData) > idle {
				live.Close(4408, "disconnected after a period of inactivity")
			}

		case f, ok := <-frames:
			if !ok {
				return
			}

			switch f.Type {
			case "data":
				live.LastData = time.Now()
				if _, err := sshConn.Stdin.Write([]byte(f.Data)); err != nil {
					live.Close(4503, "lost connection to the device")
				}

			case "resize":
				if f.Cols > 0 && f.Rows > 0 {
					_ = sshConn.Session.WindowChange(f.Rows, f.Cols)
				}

			case "ping":
				_ = writer.Send(ctx, wsx.Frame{Type: "pong"})
			}
		}
	}
}

func classifyDialError(err error) (int, string) {
	switch {
	case errors.Is(err, sshx.ErrHostKeyMismatch):
		return 4403, "The device presented a different SSH host key than the one pinned. " +
			"This is either a legitimate key change or an interception; an administrator must review it."
	case errors.Is(err, sshx.ErrHostKeyUnpinned):
		return 4403, "No pinned SSH host key for this device, and its policy forbids trust-on-first-use."
	case errors.Is(err, sshx.ErrNotAnIPLiteral):
		return 4400, "The target address is not an IP literal."
	default:
		return 4503, "Could not establish an SSH session with the device."
	}
}

func orDefault(v, fallback int) int {
	if v > 0 {
		return v
	}

	return fallback
}
