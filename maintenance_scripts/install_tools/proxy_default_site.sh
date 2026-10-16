#!/usr/bin/env bash
#
# proxy_default_site.sh - the site a multi-tenant Docker host's proxy answers
# with for a name it does not serve (specs/multi_tenant_docker_hosts.md WP8).
#
# Version: 1.1 - the host's Apache runs in a systemd sandbox (apache2.service.d/joinery-sandbox.conf,
#               specs/multi_tenant_host_hardening.md D5): written here, so host housekeeping converges
#               it; Apache is started in it from nothing, as at a boot, and must answer both ports, or the sandbox comes back out.
#               Its write paths take in every folder Apache's configuration logs to (a vhost from
#               manage_domain.sh before 2.0 logs under its site's folder), and a sandbox Apache would
#               not serve in is remembered for a day, so a host where it cannot stick is not restarted
#               twice on every run (reviewer2 H1, H2).
# Version: 1.0
#
#   proxy_default_site.sh install   Root. Installs Apache when it is missing,
#                                   turns off Ubuntu's 000-default, writes and
#                                   enables the default site with a certificate
#                                   of its own, reloads Apache when anything
#                                   changed, and fails unless Apache then names
#                                   it as the default on :80 and :443. A
#                                   configuration Apache refuses is taken back
#                                   out, and Apache is not reloaded. Then the
#                                   sandbox: when it is new or changed, Apache
#                                   is started in it from nothing (stopped, then started, as at a boot) and must answer a
#                                   request on both ports; if it does not, the
#                                   sandbox is removed and Apache restarted
#                                   without it, and the install fails saying so.
#   proxy_default_site.sh check     Says whether the default site answers both
#                                   ports; exit 1 if not.
#   proxy_default_site.sh conf      Prints the default site's vhost.
#
# WHY. Apache picks a site by the name a request asks for, and a name no site
# claims goes to the FIRST site loaded for that port. On a host of many sites
# that is another customer's site and certificate: a domain pointed here by
# mistake, a name whose site has no certificate yet, or the host's bare address
# shows whichever site sorts first. The default site is loaded first
# (000-joinery-no-site.conf; install.sh refuses a Docker site whose name would
# sort ahead of it, and host_housekeeping.sh runs this install again on every
# run) and answers every such request with a plain "There is no site at this
# address" page, over TLS on a self-signed certificate that names no customer.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Tests point these into a scratch directory.
ROOT="${JOINERY_PDS_ROOT:-}"
APACHE_DIR="${ROOT}/etc/apache2"
CONF_NAME="000-joinery-no-site.conf"
CONF_PATH="${APACHE_DIR}/sites-available/${CONF_NAME}"
ENABLED_PATH="${APACHE_DIR}/sites-enabled/${CONF_NAME}"
UBUNTU_DEFAULT="${APACHE_DIR}/sites-enabled/000-default.conf"
# The name the site answers to, and its certificate's. .invalid is reserved:
# it can never be a customer's domain.
NAME="no-site.invalid"
CERT_ROOT="${JOINERY_PLACEHOLDER_ROOT:-/etc/ssl/joinery}"
CERT_DIR="${CERT_ROOT}/${NAME}"
EMPTY_ROOT="${ROOT}/var/www/joinery-no-site"
HTTP_PORT="${JOINERY_PDS_HTTP_PORT:-80}"
HTTPS_PORT="${JOINERY_PDS_HTTPS_PORT:-443}"
SANDBOX_PATH="${ROOT}/etc/systemd/system/apache2.service.d/joinery-sandbox.conf"
# The sandbox Apache would not serve in: its hash and when, one line.
REFUSED_PATH="${ROOT}/var/lib/joinery/apache-sandbox-refused"
REFUSED_FOR=86400

say() { printf '%s\n' "$*"; }
die() { printf 'proxy_default_site: %s\n' "$*" >&2; exit 1; }

conf_text() {
    cat <<EOF
# Written by proxy_default_site.sh (specs/multi_tenant_docker_hosts.md WP8):
# the site this host answers with for a name no site on it claims. Loaded
# first, so Apache makes it the default on :80 and :443. Nothing here proxies
# anywhere, and its document root is empty and refused.
<VirtualHost *:${HTTP_PORT}>
    ServerName ${NAME}
    DocumentRoot ${EMPTY_ROOT}
    <Directory ${EMPTY_ROOT}>
        Options None
        AllowOverride None
        Require all denied
    </Directory>
    ErrorDocument 404 "<!doctype html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width'><title>No site here</title></head><body style='font-family:sans-serif;max-width:32em;margin:4em auto;padding:0 1em'><h1>There is no site at this address</h1></body></html>"
    RewriteEngine On
    RewriteRule ^ - [R=404,L]
</VirtualHost>

<IfFile ${CERT_DIR}/fullchain.pem>
<VirtualHost *:${HTTPS_PORT}>
    ServerName ${NAME}
    DocumentRoot ${EMPTY_ROOT}
    SSLEngine on
    SSLCertificateFile ${CERT_DIR}/fullchain.pem
    SSLCertificateKeyFile ${CERT_DIR}/privkey.pem
    ErrorDocument 404 "<!doctype html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width'><title>No site here</title></head><body style='font-family:sans-serif;max-width:32em;margin:4em auto;padding:0 1em'><h1>There is no site at this address</h1></body></html>"
    RewriteEngine On
    RewriteRule ^ - [R=404,L]
</VirtualHost>
</IfFile>
EOF
}

# The sandbox the host's Apache runs in (specs/multi_tenant_host_hardening.md
# D5). This Apache serves no PHP: it terminates TLS and proxies to the site
# containers. Its parent stays root to bind 80 and 443, read the certificates
# (root's own files), switch its children to www-data and signal them on a
# reload; at a start from nothing (a boot) Apache's start script hands its
# socket folder to www-data, which takes CAP_CHOWN: without it Apache did not
# start after a boot (se1, 2026-10-15). CAP_DAC_READ_SEARCH and netlink are not
# needed (reviewer2 H3). @privileged is not refused, since it holds setuid,
# setgroups and chown. /run is read-only here
# and empty after a boot, so systemd makes Apache's run and lock directories
# (the start script makes them only where they are missing); a write path that
# may not exist is marked '-', since a missing one would stop Apache starting.
# Apache's own logs may be anywhere its configuration names: every folder it
# logs to is a write path, or Apache cannot open the log and does not start.
sandbox_text() {
    local extra="" d
    while IFS= read -r d; do
        [[ -n "$d" ]] && extra="${extra} -${d}"
    done < <(log_dirs)
    printf '%s\n' "# Written by proxy_default_site.sh (specs/multi_tenant_host_hardening.md D5)." \
        "# Edits are replaced at the next host housekeeping run." \
        "[Service]" \
        "NoNewPrivileges=yes" \
        "ProtectSystem=strict" \
        "RuntimeDirectory=apache2 lock/apache2" \
        "RuntimeDirectoryPreserve=restart" \
        "ReadWritePaths=/var/log/apache2 -/var/cache/apache2 -/var/lib/apache2${extra}"
    cat <<'EOF'
ProtectHome=yes
PrivateTmp=yes
PrivateDevices=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectKernelLogs=yes
ProtectControlGroups=yes
RestrictNamespaces=yes
RestrictRealtime=yes
LockPersonality=yes
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
SystemCallArchitectures=native
SystemCallFilter=@system-service
SystemCallErrorNumber=EPERM
CapabilityBoundingSet=CAP_NET_BIND_SERVICE CAP_SETUID CAP_SETGID CAP_CHOWN CAP_KILL
EOF
}

# Every folder outside /var/log/apache2 that Apache's configuration writes a
# log in (ErrorLog, CustomLog, TransferLog), one a line, sorted. A piped or
# syslog log is not a folder; a path in a shape the sandbox line cannot carry
# is left out, and Apache then says so when it does not start.
log_dirs() {
    local f path
    for f in "${APACHE_DIR}/apache2.conf" "${APACHE_DIR}"/sites-enabled/* "${APACHE_DIR}"/conf-enabled/*; do
        [[ -f "$f" ]] || continue
        while IFS= read -r path; do
            path="${path#\"}"; path="${path%\"}"
            path="${path//\$\{APACHE_LOG_DIR\}//var/log/apache2}"
            [[ "$path" == /* ]] || continue
            path="$(dirname "$path")"
            [[ "$path" =~ ^/[A-Za-z0-9._/-]+$ && "$path" != *..* ]] || continue
            [[ "$path" == /var/log/apache2 || "$path" == /var/log/apache2/* ]] && continue
            printf '%s\n' "$path"
        done < <(awk 'tolower($1) ~ /^(errorlog|customlog|transferlog)$/ { print $2 }' "$f")
    done | sort -u
}

# Whether Apache serves: a request for a name no site claims, on each port,
# gets the default site's 404. A configuration test says nothing about a
# sandbox; only a running Apache answering does.
serves_both_ports() {
    local code ok=0
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 -H "Host: ${NAME}" "http://127.0.0.1:${HTTP_PORT}/" 2>/dev/null || true)"
    [[ "$code" == 404 ]] || { say "Apache on :${HTTP_PORT}: answered ${code:-nothing}, not the default site's 404"; ok=1; }
    code="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 --resolve "${NAME}:${HTTPS_PORT}:127.0.0.1" "https://${NAME}:${HTTPS_PORT}/" 2>/dev/null || true)"
    [[ "$code" == 404 ]] || { say "Apache on :${HTTPS_PORT}: answered ${code:-nothing}, not the default site's 404"; ok=1; }
    return "$ok"
}

# Writes the sandbox when it is missing or differs, starts Apache in it anew
# (a reload does not change a unit's sandbox; a stop, unlike a restart, removes
# the run directories, so the start is a boot's), and takes it back out when
# Apache then does not serve: a sandbox Apache cannot run under takes every
# site on the host down, at the latest at the next boot.
converge_sandbox() {
    local tmp sha r_sha="" r_at=""
    [[ -n "$ROOT" || -d /run/systemd/system ]] || { say "sandbox: no systemd here; not written"; return 0; }
    tmp="$(mktemp)"
    sandbox_text > "$tmp"
    if [[ -f "$SANDBOX_PATH" ]] && cmp -s "$tmp" "$SANDBOX_PATH"; then
        rm -f "$tmp" "$REFUSED_PATH"
        say "sandbox: unchanged"
        return 0
    fi
    sha="$(sha256sum "$tmp" | cut -c1-64)"
    # The same sandbox Apache would not serve in, less than a day ago: not
    # tried again, so Apache is not restarted twice on every run. The run still
    # fails, so the host's converger shows as failed until it is put right.
    [[ -f "$REFUSED_PATH" ]] && read -r r_sha r_at < "$REFUSED_PATH" || true
    if [[ "$r_sha" == "$sha" && "$r_at" =~ ^[0-9]{1,12}$ ]] && (( $(date +%s) - r_at < REFUSED_FOR )); then
        rm -f "$tmp"
        die "Apache did not serve in its sandbox at $(date -u -d "@${r_at}" +%FT%TZ), and that sandbox is not tried again for a day or until it changes; journalctl -u apache2 from then says why"
    fi
    mkdir -p "$(dirname "$SANDBOX_PATH")"
    install -m 0644 "$tmp" "$SANDBOX_PATH"
    rm -f "$tmp"
    systemctl daemon-reload || true
    systemctl stop apache2 || true
    if systemctl start apache2 && systemctl is-active --quiet apache2 && serves_both_ports; then
        rm -f "$REFUSED_PATH"
        say "sandbox: written; Apache started in it from nothing, as at a boot, and serves"
        return 0
    fi
    rm -f "$SANDBOX_PATH"
    systemctl daemon-reload || true
    systemctl stop apache2 || true
    systemctl start apache2 || true
    mkdir -p "$(dirname "$REFUSED_PATH")"
    printf '%s %s\n' "$sha" "$(date +%s)" > "$REFUSED_PATH"
    die "Apache did not serve in the sandbox, so it was taken back out and Apache restarted without it; it is not tried again for a day or until it changes; see journalctl -u apache2"
}

# The server Apache picks for a request no site name matches, on one port, as
# `apache2ctl -S` lists it: a port with several sites prints "is a
# NameVirtualHost" and then its "default server"; a port with one prints that
# site on the same line.
default_server() {  # PORT < apache2ctl -S output
    awk -v port="$1" '
        $1 ~ ":" port "$" && $2 == "is" { want = 1; next }
        $1 ~ ":" port "$" { print $2; exit }
        want && $1 == "default" && $2 == "server" { print $3; exit }
    '
}

answers_both_ports() {  # prints one line per port; exit 1 unless both are ours
    local s bad=0 port d
    s="$(apache2ctl -S 2>/dev/null)" || true
    for port in "$HTTP_PORT" "$HTTPS_PORT"; do
        d="$(printf '%s\n' "$s" | default_server "$port")"
        if [[ "$d" == "$NAME" ]]; then
            say "default site on :${port}: answers"
        else
            say "default site on :${port}: NOT the default (${d:-no site on this port})"; bad=1
        fi
    done
    return "$bad"
}

do_install() {
    [[ -n "$ROOT" || "$EUID" -eq 0 ]] || die "install needs root"
    if ! command -v apache2ctl >/dev/null 2>&1; then
        apt-get install -y apache2 >/dev/null || die "could not install Apache"
    fi
    a2enmod ssl rewrite headers proxy proxy_http >/dev/null 2>&1 || die "could not enable Apache's ssl, rewrite, headers and proxy modules"

    # shellcheck source=_placeholder_cert.sh
    . "${SCRIPT_DIR}/_placeholder_cert.sh"
    mint_placeholder_cert "$NAME" >/dev/null || die "the default site has no certificate, so a name with no site of its own would still reach another site over TLS"
    mkdir -p "$EMPTY_ROOT" && chmod 755 "$EMPTY_ROOT"

    local tmp before="" changed=""
    tmp="$(mktemp)"
    conf_text > "$tmp"
    [[ -f "$CONF_PATH" ]] && before="$(cat "$CONF_PATH")"
    if [[ ! -f "$CONF_PATH" ]] || ! cmp -s "$tmp" "$CONF_PATH"; then
        mkdir -p "$(dirname "$CONF_PATH")"
        install -m 0644 "$tmp" "$CONF_PATH"
        changed=1
    fi
    rm -f "$tmp"
    mkdir -p "$(dirname "$ENABLED_PATH")"
    if [[ ! -L "$ENABLED_PATH" ]]; then
        ln -sfn "../sites-available/${CONF_NAME}" "$ENABLED_PATH"
        changed=1
    fi
    # Ubuntu's own default site serves /var/www/html, and sorts ahead of ours.
    local ubuntu_was_on=""
    if [[ -e "$UBUNTU_DEFAULT" || -L "$UBUNTU_DEFAULT" ]]; then
        ubuntu_was_on="$(readlink "$UBUNTU_DEFAULT" || true)"
        rm -f "$UBUNTU_DEFAULT"
        changed=1
    fi

    if [[ -n "$changed" ]]; then
        local parse
        if ! parse="$(apache2ctl configtest 2>&1)"; then
            # Back as it was: a configuration Apache refuses takes every site
            # on the host down at the next reload.
            if [[ -n "$before" ]]; then printf '%s\n' "$before" > "$CONF_PATH"; else rm -f "$CONF_PATH" "$ENABLED_PATH"; fi
            [[ -n "$ubuntu_was_on" ]] && ln -sfn "$ubuntu_was_on" "$UBUNTU_DEFAULT"
            die "Apache refused the configuration with the default site in it; nothing was changed: $(printf '%s' "$parse" | grep -v 'Syntax OK' | head -3 | tr '\n' ' ')"
        fi
        systemctl reload-or-restart apache2 || die "Apache did not reload"
        say "default site: written and loaded (${CONF_NAME})"
    else
        say "default site: unchanged (${CONF_NAME})"
    fi
    answers_both_ports || die "Apache does not answer an unknown name with the default site; see apache2ctl -S"
    converge_sandbox
}

case "${1:-}" in
    install) do_install ;;
    check)   answers_both_ports ;;
    conf)    conf_text ;;
    *) die "usage: proxy_default_site.sh install|check|conf" ;;
esac
