#!/usr/bin/env bash
#
# proxy_default_site.sh - the site a multi-tenant Docker host's proxy answers
# with for a name it does not serve (specs/multi_tenant_docker_hosts.md WP8).
#
# Version: 1.0
#
#   proxy_default_site.sh install   Root. Installs Apache when it is missing,
#                                   turns off Ubuntu's 000-default, writes and
#                                   enables the default site with a certificate
#                                   of its own, reloads Apache when anything
#                                   changed, and fails unless Apache then names
#                                   it as the default on :80 and :443. A
#                                   configuration Apache refuses is taken back
#                                   out, and Apache is not reloaded.
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
}

case "${1:-}" in
    install) do_install ;;
    check)   answers_both_ports ;;
    conf)    conf_text ;;
    *) die "usage: proxy_default_site.sh install|check|conf" ;;
esac
