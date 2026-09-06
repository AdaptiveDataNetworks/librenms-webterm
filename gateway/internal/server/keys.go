package server

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/pem"
	"fmt"
	"strings"

	"golang.org/x/crypto/ssh"
)

// generateEphemeralKey creates the keypair used for the signed-certificate
// flow.
//
// Generated here, per session, and discarded when the session ends. The private
// half never crosses a process boundary: the plugin receives only the public
// key, hands that to Vault to be signed, and sends back a certificate. So there
// is no long-lived private key anywhere to steal, and a compromise of the
// LibreNMS database yields nothing that can authenticate.
func generateEphemeralKey() (privatePEM string, publicAuthorized string, err error) {
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		return "", "", err
	}

	block, err := ssh.MarshalPrivateKey(priv, "")
	if err != nil {
		return "", "", fmt.Errorf("marshalling ephemeral key: %w", err)
	}

	sshPub, err := ssh.NewPublicKey(pub)
	if err != nil {
		return "", "", err
	}

	return string(pem.EncodeToMemory(block)),
		strings.TrimSpace(string(ssh.MarshalAuthorizedKey(sshPub))),
		nil
}
