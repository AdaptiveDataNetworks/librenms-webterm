package server

import (
	"errors"
	"net"
	"strings"
	"testing"
)

func TestClassifyDialErrorSeparatesUnreachableFromRejected(t *testing.T) {
	// "Could not be reached" for a rejected password sends an operator into
	// firewall rules for a credential problem. These must not share a code.
	cases := []struct {
		name string
		err  error
		code int
		want string
	}{
		{"tcp refused", &net.OpError{Op: "dial", Err: errors.New("connection refused")}, 4503, "TCP connection"},
		{"auth rejected", errors.New("ssh: handshake failed: ssh: unable to authenticate, attempted methods [none password]"), 4502, "rejected the stored credential"},
		{"algorithm mismatch", errors.New("ssh: no common algorithm for key exchange"), 4502, "profile=legacy"},
		{"pty refused", errors.New("requesting pty: ssh: pty-req failed"), 4502, "refused to allocate a terminal"},
		{"unknown after connect", errors.New("something else entirely"), 4502, "could not establish"},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			code, msg := classifyDialError(tc.err)
			if code != tc.code {
				t.Fatalf("code = %d, want %d (msg %q)", code, tc.code, msg)
			}
			if !strings.Contains(strings.ToLower(msg), strings.ToLower(tc.want)) {
				t.Fatalf("msg = %q, want it to mention %q", msg, tc.want)
			}
		})
	}
}

func TestClassifyDialErrorNeverEchoesTheCredential(t *testing.T) {
	// The error text from a failed auth can carry context; the message shown to
	// the browser must not carry a secret along with it.
	err := errors.New("ssh: unable to authenticate with password hunter2, attempted methods [password]")

	_, msg := classifyDialError(err)

	if strings.Contains(msg, "hunter2") {
		t.Fatalf("close message leaked the credential: %q", msg)
	}
}
