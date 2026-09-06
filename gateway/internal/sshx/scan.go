package sshx

import (
	"context"
	"errors"
	"fmt"
	"net"
	"strings"
	"time"

	"golang.org/x/crypto/ssh"
)

// ScanResult is a host key as presented by a device.
type ScanResult struct {
	Algorithm   string `json:"algorithm"`
	PublicKey   string `json:"public_key"`
	Fingerprint string `json:"fingerprint"`
}

// ScanHostKey opens a connection far enough to see the host key, then stops.
//
// Deliberately never authenticates: this runs before an operator has decided to
// trust the device, so offering a credential would defeat the purpose. The
// handshake is abandoned as soon as the key has been captured.
//
// Scanning is initiated by the plugin, like everything else on the control
// plane -- the gateway never calls back into LibreNMS, so it has no way to
// report a key it discovered on its own.
func ScanHostKey(ctx context.Context, ip string, port int, timeout time.Duration) ([]ScanResult, error) {
	parsed := net.ParseIP(ip)
	if parsed == nil {
		return nil, fmt.Errorf("%w: %q", ErrNotAnIPLiteral, ip)
	}

	addr := net.JoinHostPort(parsed.String(), fmt.Sprintf("%d", port))

	var results []ScanResult
	captured := errors.New("host key captured")

	var d net.Dialer
	conn, err := d.DialContext(ctx, "tcp", addr)
	if err != nil {
		return nil, fmt.Errorf("dialling %s: %w", addr, err)
	}
	defer func() { _ = conn.Close() }()

	cfg := &ssh.ClientConfig{
		User:    "webterm-scan",
		Timeout: timeout,
		HostKeyCallback: func(_ string, _ net.Addr, key ssh.PublicKey) error {
			results = append(results, ScanResult{
				Algorithm:   key.Type(),
				PublicKey:   strings.TrimSpace(string(ssh.MarshalAuthorizedKey(key))),
				Fingerprint: ssh.FingerprintSHA256(key),
			})

			// Abort before any authentication is attempted.
			return captured
		},
		// No auth methods at all: we are not trying to log in.
		Auth: []ssh.AuthMethod{},
	}

	_, _, _, err = ssh.NewClientConn(conn, addr, cfg)

	if len(results) == 0 {
		return nil, fmt.Errorf("no host key offered by %s: %w", addr, err)
	}

	return results, nil
}
