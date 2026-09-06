// Package config loads gateway configuration and enforces the rules that must
// hold before the process is allowed to serve anything.
package config

import (
	"errors"
	"fmt"
	"net"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/adn/librenms-webterm/gateway/internal/ctlauth"
)

type Config struct {
	Listen        string
	MetricsListen string
	SecretFile    string
	Secret        []byte

	AllowedOrigins            []string
	AllowInsecureControlPlane bool

	MaxSessions      int
	IdleTimeout      time.Duration
	MaxDuration      time.Duration
	HandshakeTimeout time.Duration
	PendingTTL       time.Duration

	LogLevel string
}

func Default() Config {
	return Config{
		Listen:           "127.0.0.1:8377",
		MetricsListen:    "127.0.0.1:8378",
		SecretFile:       "/etc/librenms-webterm/gateway.secret",
		MaxSessions:      100,
		IdleTimeout:      15 * time.Minute,
		MaxDuration:      4 * time.Hour,
		HandshakeTimeout: 20 * time.Second,
		PendingTTL:       30 * time.Second,
		LogLevel:         "info",
	}
}

// Load reads configuration from the environment, then validates it.
//
// Environment rather than a config file so that the systemd unit and the
// container image configure identically, and so no secret is ever written into
// a file we also parse for other settings.
func Load() (Config, error) {
	c := Default()

	if v := os.Getenv("WEBTERM_LISTEN"); v != "" {
		c.Listen = v
	}
	if v := os.Getenv("WEBTERM_METRICS_LISTEN"); v != "" {
		c.MetricsListen = v
	}
	if v := os.Getenv("WEBTERM_SECRET_FILE"); v != "" {
		c.SecretFile = v
	}
	if v := os.Getenv("WEBTERM_ALLOWED_ORIGINS"); v != "" {
		for _, o := range strings.Split(v, ",") {
			if o = strings.TrimSpace(o); o != "" {
				c.AllowedOrigins = append(c.AllowedOrigins, o)
			}
		}
	}
	if v := os.Getenv("WEBTERM_LOG_LEVEL"); v != "" {
		c.LogLevel = v
	}
	if os.Getenv("WEBTERM_INSECURE_CONTROL_PLANE") == "true" {
		c.AllowInsecureControlPlane = true
	}
	if v := os.Getenv("WEBTERM_MAX_SESSIONS"); v != "" {
		n, err := strconv.Atoi(v)
		if err != nil || n < 1 {
			return c, fmt.Errorf("WEBTERM_MAX_SESSIONS must be a positive integer, got %q", v)
		}
		c.MaxSessions = n
	}

	if err := c.loadSecret(); err != nil {
		return c, err
	}

	return c, c.Validate()
}

// loadSecret reads the shared secret from a file path only.
//
// Never from an environment variable: environments are visible in /proc, leak
// into crash dumps and `systemctl show`, and get copied into support bundles. A
// file has an owner and a mode.
func (c *Config) loadSecret() error {
	raw, err := os.ReadFile(c.SecretFile)
	if err != nil {
		return fmt.Errorf("reading shared secret from %s: %w", c.SecretFile, err)
	}

	secret := []byte(strings.TrimSpace(string(raw)))
	if err := ctlauth.ValidateSecret(secret); err != nil {
		return fmt.Errorf("shared secret in %s: %w", c.SecretFile, err)
	}

	c.Secret = secret

	return nil
}

var ErrNonLoopback = errors.New(
	"refusing to bind a non-loopback address: the control plane has no transport security " +
		"and exposing it lets anyone who can reach the port create sessions. " +
		"Put it behind the LibreNMS reverse proxy, or set WEBTERM_INSECURE_CONTROL_PLANE=true if you " +
		"genuinely have another control",
)

func (c Config) Validate() error {
	if !c.AllowInsecureControlPlane {
		if err := requireLoopback(c.Listen); err != nil {
			return err
		}
	}

	if err := requireLoopback(c.MetricsListen); err != nil {
		return fmt.Errorf("metrics listener: %w", err)
	}

	return nil
}

func requireLoopback(addr string) error {
	host, _, err := net.SplitHostPort(addr)
	if err != nil {
		return fmt.Errorf("invalid listen address %q: %w", addr, err)
	}

	// An empty host means "all interfaces", which is the most common way to
	// expose this by accident.
	if host == "" {
		return fmt.Errorf("%w (address %q binds all interfaces)", ErrNonLoopback, addr)
	}

	ip := net.ParseIP(host)
	if ip == nil {
		// A hostname could resolve anywhere, including elsewhere later.
		return fmt.Errorf("%w (address %q is not an IP literal)", ErrNonLoopback, addr)
	}

	if !ip.IsLoopback() {
		return fmt.Errorf("%w (address %q)", ErrNonLoopback, addr)
	}

	return nil
}
