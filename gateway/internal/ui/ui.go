// Package ui serves the browser terminal.
//
// The assets are compiled into the binary, so installing the gateway does not
// mean copying JavaScript into LibreNMS's html/ tree. That matters more than it
// sounds: LibreNMS builds its front end through a single Vite entry point that
// plugins cannot extend, so the alternative is `vendor:publish` with a
// --force-on-every-upgrade footgun attached.
package ui

import (
	"embed"
	"io/fs"
	"net/http"
)

//go:embed assets
var assets embed.FS

// Handler serves the terminal page and its assets.
func Handler(prefix string) http.Handler {
	sub, err := fs.Sub(assets, "assets")
	if err != nil {
		panic(err)
	}

	fileServer := http.FileServer(http.FS(sub))

	return http.StripPrefix(prefix, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// The page is embedded in a frame by LibreNMS, so it must not be
		// framed by anything else. frame-ancestors is set by the caller via
		// the Content-Security-Policy header it already applies.
		w.Header().Set("X-Content-Type-Options", "nosniff")
		w.Header().Set("Cache-Control", "public, max-age=3600")
		w.Header().Set("Referrer-Policy", "no-referrer")

		if r.URL.Path == "" || r.URL.Path == "/" {
			r.URL.Path = "/terminal.html"
		}

		fileServer.ServeHTTP(w, r)
	}))
}
