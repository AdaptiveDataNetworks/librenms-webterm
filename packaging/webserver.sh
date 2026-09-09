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
        awk -v at="$_anchor_line" -v marker="$WEBTERM_MARKER" -v directive="$_directive" '
            { print }
            NR == at { print "    " marker; print "    " directive }' "$_backup" > "$VHOST"
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

    _code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 5 --http1.1 \
        -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
        -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: AAAAAAAAAAAAAAAAAAAAAA==' \
        -H 'Sec-WebSocket-Protocol: lnms-webterm.v1' \
        -H "Origin: $ORIGIN" "$ORIGIN/webterm/ws" 2>/dev/null) || _code="000"

    case "$_code" in
        101) say "  /webterm/ws reaches the gateway and the origin is accepted" ; return 0 ;;
        403) warn "/webterm/ws returned 403 -- the gateway is refusing this origin. Check WEBTERM_ALLOWED_ORIGINS=$ORIGIN" ;;
        404) warn "/webterm/ws returned 404 -- the proxy is missing, or proxy_pass has no /ws path component" ;;
        502|503) warn "/webterm/ws returned $_code -- the proxy is there but the gateway is not answering" ;;
        000) warn "could not reach $ORIGIN at all from this host (that may just be split DNS)" ;;
        *) warn "/webterm/ws returned $_code" ;;
    esac
    return 1
}
