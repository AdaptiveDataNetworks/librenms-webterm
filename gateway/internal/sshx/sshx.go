// Package sshx dials devices.
//
// Everything security-relevant about the outbound connection lives here: which
// address may be reached, when the host key is checked, and what the far end is
// allowed to ask of us.
package sshx

import (
	"context"
	"errors"
	"fmt"
	"net"
	"strings"
	"time"

	"golang.org/x/crypto/ssh"

	"github.com/AdaptiveDataNetworks/librenms-webterm/gateway/internal/session"
)

var (
	ErrNotAnIPLiteral  = errors.New("sshx: target is not an IP literal")
	ErrHostKeyMismatch = errors.New("sshx: host key does not match any pinned key")
	ErrHostKeyUnpinned = errors.New("sshx: no pinned host key and policy forbids trust-on-first-use")
)

// HostKeyResult reports what happened during verification, so the caller can
// record a first-connect pin or raise a changed-key alert.
type HostKeyResult struct {
	Algorithm   string
	Fingerprint string
	PublicKey   string
	FirstSeen   bool
}

type Conn struct {
	Client  *ssh.Client
	Session *ssh.Session
	HostKey HostKeyResult

	Stdin  interface{ Write([]byte) (int, error) }
	Stdout interface{ Read([]byte) (int, error) }
}

// Dial connects, verifies the host key, authenticates, and opens one PTY.
//
// Order matters and is not negotiable: Go runs HostKeyCallback during key
// exchange, before any authentication method is offered, so a device presenting
// an unexpected key never sees our credential.
func Dial(ctx context.Context, p *session.Pending, timeout time.Duration) (*Conn, error) {
	ip := net.ParseIP(p.Target.IP)
	if ip == nil {
		// The plugin is supposed to have resolved this already. Refusing here
		// too means no code path can reach a name resolver by accident.
		return nil, fmt.Errorf("%w: %q", ErrNotAnIPLiteral, p.Target.IP)
	}

	auth, err := authMethods(p.Auth)
	if err != nil {
		return nil, err
	}

	result := &HostKeyResult{}
	cfg := &ssh.ClientConfig{
		User:            p.Auth.Username,
		Auth:            auth,
		HostKeyCallback: hostKeyCallback(p.Target, result),
		Timeout:         timeout,
	}
	applyProfile(cfg, p.Target.AlgorithmProfile)

	addr := net.JoinHostPort(ip.String(), fmt.Sprintf("%d", p.Target.Port))

	var d net.Dialer
	raw, err := d.DialContext(ctx, "tcp", addr)
	if err != nil {
		return nil, fmt.Errorf("dialling %s: %w", addr, err)
	}

	c, chans, reqs, err := ssh.NewClientConn(raw, addr, cfg)
	if err != nil {
		_ = raw.Close()
		return nil, err
	}

	// Every channel the SERVER tries to open is discarded: x11, auth-agent and
	// forwarded-tcpip all arrive this way. Rejecting them wholesale is what
	// stops a hostile or compromised device turning the session around and
	// using the gateway as a way into the monitoring network.
	client := ssh.NewClient(c, chans, reqs)

	sess, err := client.NewSession()
	if err != nil {
		_ = client.Close()
		return nil, err
	}

	modes := ssh.TerminalModes{ssh.ECHO: 1, ssh.TTY_OP_ISPEED: 38400, ssh.TTY_OP_OSPEED: 38400}
	if err := sess.RequestPty("xterm-256color", 24, 80, modes); err != nil {
		_ = sess.Close()
		_ = client.Close()
		return nil, fmt.Errorf("requesting pty: %w", err)
	}

	stdin, err := sess.StdinPipe()
	if err != nil {
		_ = client.Close()
		return nil, err
	}
	stdout, err := sess.StdoutPipe()
	if err != nil {
		_ = client.Close()
		return nil, err
	}
	sess.Stderr = nil

	if err := sess.Shell(); err != nil {
		_ = client.Close()
		return nil, fmt.Errorf("starting shell: %w", err)
	}

	return &Conn{Client: client, Session: sess, HostKey: *result, Stdin: stdin, Stdout: stdout}, nil
}

func (c *Conn) Close() error {
	if c.Session != nil {
		_ = c.Session.Close()
	}
	if c.Client != nil {
		return c.Client.Close()
	}

	return nil
}

// hostKeyCallback verifies against the pins the plugin supplied.
//
// Note there is no "accept anything" path. Every SSH library in common use
// verifies nothing by default; making the insecure option unrepresentable here
// is the only way to be sure it cannot be reached by configuration.
func hostKeyCallback(target session.Target, out *HostKeyResult) ssh.HostKeyCallback {
	return func(hostname string, remote net.Addr, key ssh.PublicKey) error {
		out.Algorithm = key.Type()
		out.Fingerprint = ssh.FingerprintSHA256(key)
		out.PublicKey = strings.TrimSpace(string(ssh.MarshalAuthorizedKey(key)))

		presented := key.Marshal()

		for _, entry := range target.KnownHosts {
			pinned, err := parseAuthorizedKey(entry)
			if err != nil {
				continue
			}
			if string(pinned.Marshal()) == string(presented) {
				return nil
			}
		}

		if len(target.KnownHosts) > 0 {
			// A key exists but does not match: this is the changed-key case,
			// which must always be a hard failure.
			return fmt.Errorf("%w (device presented %s)", ErrHostKeyMismatch, out.Fingerprint)
		}

		if target.HostKeyPolicy == "tofu_first_connect" {
			out.FirstSeen = true
			return nil
		}

		return fmt.Errorf("%w (device presented %s)", ErrHostKeyUnpinned, out.Fingerprint)
	}
}

func parseAuthorizedKey(entry string) (ssh.PublicKey, error) {
	entry = strings.TrimSpace(entry)
	if entry == "" {
		return nil, errors.New("empty entry")
	}

	key, _, _, _, err := ssh.ParseAuthorizedKey([]byte(entry))

	return key, err
}

func authMethods(a session.Auth) ([]ssh.AuthMethod, error) {
	switch a.Method {
	case "password":
		if a.Password == "" {
			return nil, errors.New("sshx: password method with no password")
		}

		return []ssh.AuthMethod{ssh.Password(a.Password)}, nil

	case "private_key":
		signer, err := parsePrivateKey(a.PrivateKey, a.Passphrase)
		if err != nil {
			return nil, err
		}

		return []ssh.AuthMethod{ssh.PublicKeys(signer)}, nil

	case "signed_certificate":
		// The private half was generated in this process and never left it; the
		// certificate is what Vault signed over the matching public half.
		signer, err := parsePrivateKey(a.PrivateKey, "")
		if err != nil {
			return nil, err
		}

		pk, _, _, _, err := ssh.ParseAuthorizedKey([]byte(a.Certificate))
		if err != nil {
			return nil, fmt.Errorf("sshx: parsing certificate: %w", err)
		}

		cert, ok := pk.(*ssh.Certificate)
		if !ok {
			return nil, errors.New("sshx: supplied certificate is not an SSH certificate")
		}

		certSigner, err := ssh.NewCertSigner(cert, signer)
		if err != nil {
			return nil, fmt.Errorf("sshx: building certificate signer: %w", err)
		}

		return []ssh.AuthMethod{ssh.PublicKeys(certSigner)}, nil
	}

	return nil, fmt.Errorf("sshx: unsupported auth method %q", a.Method)
}

func parsePrivateKey(key, passphrase string) (ssh.Signer, error) {
	if key == "" {
		return nil, errors.New("sshx: no private key supplied")
	}

	if passphrase != "" {
		return ssh.ParsePrivateKeyWithPassphrase([]byte(key), []byte(passphrase))
	}

	return ssh.ParsePrivateKey([]byte(key))
}

// applyProfile selects the cipher and MAC set.
//
// The "legacy" profile widens what Go offers for older equipment, but it cannot
// conjure algorithms the library does not implement: aes192-cbc, aes256-cbc and
// hmac-md5 are absent from x/crypto/ssh entirely. Devices that offer only those
// are unreachable through this gateway, and reference/compatibility.md says so
// rather than implying a profile covers them.
func applyProfile(cfg *ssh.ClientConfig, profile string) {
	if profile != "legacy" {
		return
	}

	cfg.KeyExchanges = append(cfg.Config.KeyExchanges,
		"diffie-hellman-group14-sha1",
		"diffie-hellman-group1-sha1",
		"diffie-hellman-group-exchange-sha1",
	)
	cfg.Ciphers = append(cfg.Config.Ciphers,
		"aes128-cbc",
		"3des-cbc",
	)
	cfg.MACs = append(cfg.Config.MACs, "hmac-sha1", "hmac-sha1-96")
	cfg.HostKeyAlgorithms = append(cfg.HostKeyAlgorithms, ssh.KeyAlgoRSA, ssh.KeyAlgoDSA)
}
