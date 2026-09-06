// Command librenms-webterm-gw is the WebTerm SSH gateway.
//
// It terminates the browser WebSocket, dials the device, and moves bytes. It
// holds no database credentials, no Vault token and no LibreNMS session: the
// plugin pushes it everything it needs over a loopback control plane, and it
// never calls back.
package main

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/AdaptiveDataNetworks/librenms-webterm/gateway/internal/config"
	"github.com/AdaptiveDataNetworks/librenms-webterm/gateway/internal/proto"
	"github.com/AdaptiveDataNetworks/librenms-webterm/gateway/internal/server"
)

// Set at build time by GoReleaser.
var (
	version = "dev"
	commit  = "none"
	date    = "unknown"
)

func main() {
	if len(os.Args) < 2 {
		usage()
		os.Exit(2)
	}

	switch os.Args[1] {
	case "serve":
		os.Exit(serve(os.Args[2:]))
	case "init":
		os.Exit(initSecret(os.Args[2:]))
	case "healthcheck":
		os.Exit(healthcheck(os.Args[2:]))
	case "version", "--version", "-v":
		fmt.Printf("librenms-webterm-gw %s (commit %s, built %s, protocol v%d)\n",
			version, commit, date, proto.Version)

		return
	default:
		usage()
		os.Exit(2)
	}
}

func usage() {
	fmt.Fprintf(os.Stderr, `librenms-webterm-gw %s

Usage:
  librenms-webterm-gw serve         Run the gateway
  librenms-webterm-gw init          Generate a shared secret file
  librenms-webterm-gw healthcheck   Probe a running gateway (for containers)
  librenms-webterm-gw version       Print version and protocol information

Configuration is read from the environment; see the documentation at
https://adaptivedatanetworks.github.io/librenms-webterm/gateway/configure/
`, version)
}

func serve(args []string) int {
	fs := flag.NewFlagSet("serve", flag.ExitOnError)
	_ = fs.Parse(args)

	cfg, err := config.Load()
	if err != nil {
		// Configuration errors are fatal by design: a gateway that starts with
		// a missing secret or a non-loopback bind is worse than one that does
		// not start, because the failure would be silent.
		fmt.Fprintf(os.Stderr, "configuration error: %v\n", err)

		return 1
	}

	log := newLogger(cfg.LogLevel)

	instanceID := "gw-" + randomHex(8)

	srv, err := server.New(cfg, log, instanceID)
	if err != nil {
		fmt.Fprintf(os.Stderr, "startup error: %v\n", err)

		return 1
	}

	httpSrv := &http.Server{
		Addr:              cfg.Listen,
		Handler:           srv.Handler(),
		ReadHeaderTimeout: 10 * time.Second,
		// No WriteTimeout: a terminal session is a long-lived stream, and a
		// write deadline would sever it mid-use.
		IdleTimeout: 120 * time.Second,
	}

	ln, err := net.Listen("tcp", cfg.Listen)
	if err != nil {
		fmt.Fprintf(os.Stderr, "cannot listen on %s: %v\n", cfg.Listen, err)

		return 1
	}

	log.Info("gateway starting",
		slog.String("version", version),
		slog.String("instance_id", instanceID),
		slog.String("listen", cfg.Listen),
		slog.Int("protocol", proto.Version),
		slog.Int("allowed_origins", len(cfg.AllowedOrigins)))

	if len(cfg.AllowedOrigins) == 0 {
		log.Warn("no allowed origins configured; every browser connection will be refused " +
			"(set WEBTERM_ALLOWED_ORIGINS to your LibreNMS URL)")
	}

	notifySystemd("READY=1")

	errCh := make(chan error, 1)
	go func() { errCh <- httpSrv.Serve(ln) }()

	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)

	select {
	case err := <-errCh:
		if err != nil && !errors.Is(err, http.ErrServerClosed) {
			log.Error("server stopped", slog.String("error", err.Error()))

			return 1
		}
	case sig := <-stop:
		log.Info("shutting down", slog.String("signal", sig.String()))
		notifySystemd("STOPPING=1")

		srv.Drain()

		ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
		defer cancel()
		_ = httpSrv.Shutdown(ctx)
	}

	return 0
}

// initSecret writes a fresh shared secret with restrictive permissions.
func initSecret(args []string) int {
	fs := flag.NewFlagSet("init", flag.ExitOnError)
	path := fs.String("path", "/etc/librenms-webterm/gateway.secret", "where to write the shared secret")
	force := fs.Bool("force", false, "overwrite an existing secret")
	_ = fs.Parse(args)

	if _, err := os.Stat(*path); err == nil && !*force {
		fmt.Fprintf(os.Stderr,
			"refusing to overwrite the existing secret at %s\n"+
				"Rotating it requires updating LibreNMS at the same time, or every session mint will fail.\n"+
				"Pass --force if that is what you intend.\n", *path)

		return 1
	}

	secret := make([]byte, proto.SecretBytes)
	if _, err := rand.Read(secret); err != nil {
		fmt.Fprintf(os.Stderr, "generating secret: %v\n", err)

		return 1
	}

	encoded := hex.EncodeToString(secret)

	if err := os.WriteFile(*path, []byte(encoded+"\n"), 0o600); err != nil {
		fmt.Fprintf(os.Stderr, "writing %s: %v\n", *path, err)

		return 1
	}

	fmt.Printf("Wrote a new shared secret to %s (mode 0600).\n\n", *path)
	fmt.Printf("Give LibreNMS the same file, then point the plugin at it:\n")
	fmt.Printf("  ./lnms webterm:config set gateway.secret_file %s\n", *path)

	return 0
}

func healthcheck(args []string) int {
	fs := flag.NewFlagSet("healthcheck", flag.ExitOnError)
	addr := fs.String("addr", "127.0.0.1:8377", "gateway control address")
	_ = fs.Parse(args)

	client := &http.Client{Timeout: 3 * time.Second}
	resp, err := client.Get("http://" + *addr + "/healthz")
	if err != nil {
		fmt.Fprintf(os.Stderr, "unhealthy: %v\n", err)

		return 1
	}
	defer func() { _ = resp.Body.Close() }()

	if resp.StatusCode != http.StatusOK {
		fmt.Fprintf(os.Stderr, "unhealthy: status %d\n", resp.StatusCode)

		return 1
	}

	fmt.Println("ok")

	return 0
}

func newLogger(level string) *slog.Logger {
	var l slog.Level
	if err := l.UnmarshalText([]byte(level)); err != nil {
		l = slog.LevelInfo
	}

	// JSON so the audit-adjacent fields survive log shipping intact.
	return slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: l}))
}

func randomHex(n int) string {
	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		return "unknown"
	}

	return hex.EncodeToString(b)
}

// notifySystemd sends a readiness notification when running under a
// Type=notify unit. Implemented directly rather than pulling in a dependency:
// it is a datagram to a socket named in the environment.
func notifySystemd(state string) {
	addr := os.Getenv("NOTIFY_SOCKET")
	if addr == "" {
		return
	}

	if addr[0] == '@' {
		addr = "\x00" + addr[1:]
	}

	conn, err := net.DialUnix("unixgram", nil, &net.UnixAddr{Name: addr, Net: "unixgram"})
	if err != nil {
		return
	}
	defer func() { _ = conn.Close() }()

	_, _ = conn.Write([]byte(state))
}
