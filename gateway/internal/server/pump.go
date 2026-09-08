package server

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"time"

	"github.com/coder/websocket"

	"github.com/AdaptiveDataNetworks/librenms-webterm/gateway/internal/proto"
	"github.com/AdaptiveDataNetworks/librenms-webterm/gateway/internal/session"
	"github.com/AdaptiveDataNetworks/librenms-webterm/gateway/internal/sshx"
	"github.com/AdaptiveDataNetworks/librenms-webterm/gateway/internal/wsx"
	"net"
	"strings"
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

// classifyDialError turns a dial failure into a close code and a message an
// operator can act on.
//
// The distinction that matters is whether we reached the device at all.
// "Could not be reached" sent for a rejected password puts an operator into
// firewall rules and routing tables for a credential problem, which is the
// most expensive wrong answer this code can give.
//
// Nothing here echoes the credential. x/crypto/ssh's auth error names the
// methods attempted, never the secret, and the messages below are fixed
// strings.
func classifyDialError(err error) (int, string) {
	switch {
	case errors.Is(err, sshx.ErrHostKeyMismatch):
		return 4403, "The device presented a different SSH host key than the one pinned. " +
			"This is either a legitimate key change or an interception; an administrator must review it."
	case errors.Is(err, sshx.ErrHostKeyUnpinned):
		return 4403, "No pinned SSH host key for this device, and its policy forbids trust-on-first-use."
	case errors.Is(err, sshx.ErrNotAnIPLiteral):
		return 4400, "The target address is not an IP literal."
	}

	// Never got a socket: genuinely unreachable.
	var netErr net.Error
	if errors.As(err, &netErr) && netErr.Timeout() {
		return 4503, "Timed out opening a TCP connection to the device on port 22."
	}

	var opErr *net.OpError
	if errors.As(err, &opErr) {
		return 4503, "Could not open a TCP connection to the device on port 22."
	}

	// Got a socket; the SSH layer refused. x/crypto/ssh returns plain errors
	// for these, so there is nothing typed to match on.
	text := err.Error()

	switch {
	case strings.Contains(text, "unable to authenticate"):
		return 4502, "The device rejected the stored credential. Check the target's principal " +
			"and the credential with: webterm:credentials:explain --device=<device>"
	case strings.Contains(text, "no common algorithm"):
		return 4502, "No SSH algorithm in common with the device. Older equipment may need: " +
			"webterm:target:enable --device=<device> --profile=legacy"
	case strings.Contains(text, "requesting pty"):
		return 4502, "The device accepted the login but refused to allocate a terminal."
	}

	return 4502, "Reached the device, but could not establish an SSH session."
}

func orDefault(v, fallback int) int {
	if v > 0 {
		return v
	}

	return fallback
}
