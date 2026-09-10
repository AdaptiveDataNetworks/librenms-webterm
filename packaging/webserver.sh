# Reverse-proxy configuration for install.sh.
#
# The shape here is deliberate. Rather than splicing location blocks into a
# vhost the operator wrote -- which is hard to undo, hard to re-run, and easy to
# leave in a state where the web server will not start -- this writes ONE file
# that we own and adds ONE include line inside the chosen server block. Undoing
# it is deleting a line, and re-running finds its own marker and stops.
#
# The include file cannot live in conf.d: on both nginx and Apache that
# directory is pulled in at server/http scope, and a `location` is only valid
# inside `server {}`. So it sits beside the main config instead.

WEBTERM_MARKER="# librenms-webterm (managed) -- remove this line to detach"

webterm_print_proxy_config() {
    if [ "${WEBSERVER:-nginx}" = apache ]; then
        cat <<'APACHE'
    ProxyPass        /webterm/ws  ws://127.0.0.1:8377/ws  upgrade=websocket timeout=3600
    ProxyPassReverse /webterm/ws  ws://127.0.0.1:8377/ws
    ProxyPass        /webterm/ui/ http://127.0.0.1:8377/ui/
    ProxyPassReverse /webterm/ui/ http://127.0.0.1:8377/ui/
APACHE
    else
        cat <<'NGINX'
    location ^~ /webterm/ws {
        # The trailing /ws is load-bearing. With no URI component nginx forwards
        # the original path and the gateway, which serves /ws, answers 404.
        proxy_pass http://127.0.0.1:8377/ws;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_buffering off;
        proxy_read_timeout 3600s;
    }

    location ^~ /webterm/ui/ {
        proxy_pass http://127.0.0.1:8377/ui/;
        proxy_set_header Host $host;
    }
NGINX
    fi
}

# Does the RESOLVED config already mention us? Covers blocks an operator added
# by hand, which must not be duplicated.
webterm_already_proxied() {
    case "${WEBSERVER:-}" in
        nginx)  nginx -T 2>/dev/null | grep -q '/webterm/' ;;
        apache) { apachectl -t -D DUMP_INCLUDES >/dev/null 2>&1; apachectl -S 2>/dev/null; } | grep -q '/webterm/' \
                    || grep -rqs '/webterm/' /etc/httpd /etc/apache2 2>/dev/null ;;
        *) return 1 ;;
    esac
}

webterm_candidate_vhosts() {
    case "${WEBSERVER:-}" in
        nginx)  nginx -T 2>/dev/null | sed -n 's#^# configuration file \(.*\):$#\1#p' | grep -v 'nginx.conf$' ;;
        apache) apachectl -S 2>/dev/null | sed -n 's#.*(\(/[^:]*\):[0-9]*).*#\1#p' | sort -u ;;
    esac
}

webterm_config_test() {
    case "${WEBSERVER:-}" in
        nginx)  nginx -t >/dev/null 2>&1 ;;
        apache) { have apachectl && apachectl configtest >/dev/null 2>&1; } || httpd -t >/dev/null 2>&1 ;;
        *) return 0 ;;
    esac
}

webterm_reload() {
    case "${WEBSERVER:-}" in
        nginx)  systemctl reload nginx >/dev/null 2>&1 ;;
        apache) systemctl reload httpd >/dev/null 2>&1 || systemctl reload apache2 >/dev/null 2>&1 ;;
    esac
}

webterm_configure_webserver() {
    [ "${WEBSERVER:-none}" = none ] && return 0

    step "Reverse proxy"

    if webterm_already_proxied; then
        say "  Your $WEBSERVER config already proxies /webterm/ -- leaving it alone."
        say "  If the terminal 404s, check that /webterm/ws proxies to .../ws (with the path)"
        say "  and that a /webterm/ui/ block exists too."
        return 0
    fi

    if ! confirm "  Add the WebTerm proxy to your $WEBSERVER config?" "${CONFIGURE_WEBSERVER:-ask}"; then
        say ""
        say "  Add these inside the LibreNMS server block yourself, then reload $WEBSERVER:"
        say ""
        webterm_print_proxy_config
        return 0
    fi

    if [ -z "${VHOST:-}" ]; then
        _candidates=$(webterm_candidate_vhosts | head -20)
        if [ -n "$_candidates" ]; then
            say ""
            say "  Files $WEBSERVER is currently loading:"
            printf '%s\n' "$_candidates" | sed 's/^/    /'
            say ""
        fi
        VHOST=$(ask "  Which file holds the LibreNMS server block?" "$(printf '%s\n' "$_candidates" | head -1)")
    fi
    [ -f "$VHOST" ] || { warn "no such file: $VHOST -- printing the config instead"; webterm_print_proxy_config; return 0; }

    if grep -q 'librenms-webterm (managed)' "$VHOST"; then
        say "  $VHOST already includes our proxy config."
        return 0
    fi

    # WEBTERM_SNIPPET_DIR exists so the insertion can be tested without writing
    # to a real /etc, and so a distro with a different prefix can be pointed
    # somewhere else without patching this script.
    case "$WEBSERVER" in
        nginx)  _snippet="${WEBTERM_SNIPPET_DIR:-/etc/nginx}/webterm-proxy.conf" ;;
        apache) _default=/etc/httpd
                [ -d /etc/httpd ] || _default=/etc/apache2
                _snippet="${WEBTERM_SNIPPET_DIR:-$_default}/webterm-proxy.conf" ;;
    esac

    webterm_print_proxy_config > "$_snippet"
    chmod 0644 "$_snippet"
    say "  wrote $_snippet"

    _backup="$VHOST.webterm-backup.$$"
    cp -p "$VHOST" "$_backup"

    # Which block? "The first one" is wrong for the most common production
    # layout, where a port-80 block does nothing but redirect to 443 and the
    # real vhost follows it. Putting the proxy in the redirect block yields an
    # install that looks fine and 404s. So score the blocks and take the one
    # that actually serves LibreNMS.
    if [ "$WEBSERVER" = nginx ]; then
        _anchor_line=$(awk '
            BEGIN { depth = 0; start = 0; best = 0; bestscore = -1 }
            {
                if (depth == 0 && $0 ~ /^[ \t]*server[ \t]*\{/) { start = NR; score = 0 }
                if (start > 0 && depth >= 1) {
                    if ($0 ~ /[ \t]root[ \t]/)      score += 3
                    if ($0 ~ /fastcgi_pass/)        score += 3
                    if ($0 ~ /listen[^;]*443/)      score += 2
                    if ($0 ~ /return[ \t]+30[12]/)  score -= 5
                }
                o = $0; nopen  = gsub(/\{/, "{", o)
                o = $0; nclose = gsub(/\}/, "}", o)
                depth += nopen - nclose
                if (start > 0 && depth == 0) {
                    if (score > bestscore) { bestscore = score; best = start }
                    start = 0
                }
            }
            END { print best }' "$_backup")
    else
        _anchor_line=$(awk '
            BEGIN { best = 0; bestscore = -1; inv = 0 }
            /<VirtualHost/            { start = NR; score = 0; inv = 1 }
            inv {
                if ($0 ~ /DocumentRoot/) score += 3
                if ($0 ~ /:443/)         score += 2
                if ($0 ~ /Redirect/)     score -= 5
            }
            /<\/VirtualHost>/ {
                if (inv && score > bestscore) { bestscore = score; best = start }
                inv = 0
            }
            END { print best }' "$_backup")
    fi

    if [ "${_anchor_line:-0}" -gt 0 ]; then
        _directive="include $_snippet;"
        [ "$WEBSERVER" = apache ] && _directive="Include $_snippet"
        # Printing the directive AFTER the anchor line is right for a normal
        # multi-line block and wrong for `server { ... }` written on one line --
        # there it lands outside the block, and nginx rejects the whole file with
        # "location directive is not allowed here". So when the anchor line also
        # closes its block, inject just after the opening brace instead.
        awk -v at="$_anchor_line" -v marker="$WEBTERM_MARKER" -v directive="$_directive" '
            NR == at {
                o = $0; nopen  = gsub(/\{/, "{", o)
                o = $0; nclose = gsub(/\}/, "}", o)
                if (nclose >= nopen && nopen > 0) {
                    sub(/\{/, "{ " marker "\n    " directive, $0)
                    print
                    next
                }
                print
                print "    " marker
                print "    " directive
                next
            }
            { print }' "$_backup" > "$VHOST"
    fi

    if ! grep -q 'librenms-webterm (managed)' "$VHOST"; then
        cp -p "$_backup" "$VHOST"; rm -f "$_backup"
        warn "could not find a server block in $VHOST -- restored it and changed nothing"
        webterm_print_proxy_config
        return 0
    fi

    if ! webterm_config_test; then
        cp -p "$_backup" "$VHOST"; rm -f "$_backup"
        warn "$WEBSERVER rejected the new config -- restored $VHOST and changed nothing"
        return 0
    fi

    if webterm_reload; then
        say "  added the proxy to $VHOST and reloaded $WEBSERVER"
        rm -f "$_backup"
    else
        cp -p "$_backup" "$VHOST"; rm -f "$_backup"
        webterm_config_test && webterm_reload || true
        warn "$WEBSERVER would not reload -- restored $VHOST"
    fi
}

# Prove the proxy path reaches the gateway.
#
# The obvious implementation of this check is BACKWARDS, which is worth spelling
# out. On success the gateway answers 101 and then holds the socket open for its
# 20-second handshake timeout, so curl blocks and exits 52. On every failure
# path -- 403, 404, 502 -- curl exits 0. Under `set -e` a naive probe therefore
# aborts the installer on a healthy install and passes on a broken one.
#
# So: bound it with --max-time, ignore curl's exit code entirely, and judge only
# the status line.
webterm_verify_proxy() {
    have curl || return 0
    # Nothing to verify if we were told not to touch a web server: there is no
    # proxy for the request to traverse, and warning about one is noise.
    [ "${WEBSERVER:-none}" = none ] && return 0

    # Retry, because `systemctl reload nginx` returns before nginx has finished
    # swapping configs. Probing immediately hits the OLD config, falls through to
    # the LibreNMS `location /`, loops into index.php and returns 500 -- a loud
    # warning about a perfectly good install. Measured: 500 immediately, 101 three
    # seconds later, on an unchanged config.
    _code=000
    _try=0
    while [ "$_try" -lt 10 ]; do
        # First three digits, not the raw output. On a successful upgrade curl
        # writes its -w output TWICE -- 101 for the switch, then 000 when the
        # held-open connection hits --max-time -- and the caller then reports
        # "/webterm/ws returned 101000", which looks like a gateway fault on an
        # install that is working perfectly. The first code is the answer.
        _code=$(_webterm_probe_once | tr -dc '0-9' | cut -c1-3)
        [ -n "$_code" ] || _code=000
        case "$_code" in
            101) break ;;
        esac
        _try=$((_try + 1))
        sleep 1
    done

    case "$_code" in
        101) say "  /webterm/ws reaches the gateway and the origin is accepted" ; return 0 ;;
        403) warn "/webterm/ws returned 403 -- the gateway is refusing this origin. Check WEBTERM_ALLOWED_ORIGINS=$ORIGIN" ;;
        404) warn "/webterm/ws returned 404 -- the proxy is missing, or proxy_pass has no /ws path component" ;;
        500) warn "/webterm/ws returned 500 -- the request is reaching LibreNMS instead of the proxy, so the location block is not in the server that serves $ORIGIN" ;;
        502|503) warn "/webterm/ws returned $_code -- the proxy is there but the gateway is not answering" ;;
        000) warn "could not reach $ORIGIN at all from this host (that may just be split DNS)" ;;
        *) warn "/webterm/ws returned $_code" ;;
    esac
    return 1
}

_webterm_probe_once() {
    curl -sS -o /dev/null -w '%{http_code}' --max-time 5 --http1.1 \
        -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
        -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: AAAAAAAAAAAAAAAAAAAAAA==' \
        -H 'Sec-WebSocket-Protocol: lnms-webterm.v1' \
        -H "Origin: $ORIGIN" "$ORIGIN/webterm/ws" 2>/dev/null
    # No `|| printf 000` here. curl still writes its -w output when the transfer
    # fails, so a fallback appends a SECOND code and the caller reports
    # "returned 000000" -- six zeros, which reads like a bug in the gateway
    # rather than an unreachable host. An empty result is handled by the caller.
}
