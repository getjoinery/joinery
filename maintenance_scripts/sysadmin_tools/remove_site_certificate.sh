#!/usr/bin/env bash
#
# remove_site_certificate.sh - remove one site's HTTPS certificate from this
# machine once nothing on it uses the certificate, and say what it did as ONE
# JSON object on stdout.
#
# Version: 1.1 - "unused" is no longer read from sites-enabled alone: every
#                file Apache loads or could enable, every other file under
#                /etc, and what the machine serves on 443 are all asked. A certificate an
#                unguarded vhost names, once deleted, stops Apache reloading
#                for every site on the machine.
# Version: 1.0 - a removed site's certificate outlived it: remove_account.sh
#                took the vhost and left the Let's Encrypt lineage, so certbot
#                kept renewing a domain that now points at another server, and
#                the host kept reporting it. remove_account.sh calls this for
#                every certificate its vhost named; the agent's
#                remove_site_certificate operate word runs it for one name.
#
# THE CONTRACT, which tests/integration/remove_site_certificate_gate.sh pins:
#
#   - ONE argument: a certificate name, the domain certbot named the lineage
#     after, optionally with certbot's -NNNN suffix. Anything else is refused.
#   - Refused (exit 2, nothing touched) when anything still uses it:
#       1. any file under /etc outside /etc/letsencrypt and /etc/apache2
#          names /etc/letsencrypt/live/<name>/ or the placeholder directory
#          /etc/ssl/joinery/<domain> (a mail server, another web server);
#       2. any file Apache loads names either, wherever it lives
#          (apache2ctl -t -D DUMP_INCLUDES), or any site that could be
#          enabled (sites-available/*.conf), since a disabled site enabled
#          later would stop Apache reloading. Backups beside them (<site>.conf.
#          before-render.<time>) do not count: Apache never loads them. A
#          configuration Apache cannot load refuses, because what it uses
#          cannot then be read;
#       3. this machine serves the certificate on 443 for <domain> right now,
#          asked over 127.0.0.1 and ::1.
#     Nothing is ever moved aside to test: a run that died between moving and
#     restoring would strand a live certificate on a machine nobody can reach
#     as root.
#   - Removes, when present: the Let's Encrypt lineage through certbot delete
#     (its live, archive and renewal files, so renewal stops), and the
#     placeholder directory install.sh mints beside it.
#   - Every key is ALWAYS present; each part is removed, absent or failed,
#     judged by looking afterwards. Exit 0 whenever the object was printed.
#
# Runs on: a Docker host or a site's own machine, as root.

set -u
export LC_ALL=C

# The tree the paths hang from. Only the gate test moves it.
ETC="${REMOVE_SITE_CERTIFICATE_ETC:-/etc}"
CMD_TIMEOUT=120

NAME="${1:-}"
if [[ ! "$NAME" =~ ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+(-[0-9]{4})?$ ]]; then
    printf 'remove_site_certificate: %s is not a certificate name this node will remove\n' "${NAME:0:80}" >&2
    exit 2
fi
# certbot names a second lineage for the same domain <domain>-0001; the
# placeholder directory is always the bare domain.
DOMAIN="$NAME"
if [[ "$NAME" =~ ^(.+)-[0-9]{4}$ ]]; then
    DOMAIN="${BASH_REMATCH[1]}"
fi

LIVE="$ETC/letsencrypt/live/$NAME"
RENEWAL="$ETC/letsencrypt/renewal/$NAME.conf"
ARCHIVE="$ETC/letsencrypt/archive/$NAME"
PLACEHOLDER="$ETC/ssl/joinery/$DOMAIN"

refuse() {
    printf 'remove_site_certificate: %s is still in use: %s\n' "$NAME" "$1" >&2
    exit 2
}

names_it() { grep -qsIE -e "$LIVE_RE" -e "$PLACEHOLDER_RE" "$1"; }

# 1. Every file under /etc outside Apache's own tree that names either path:
# a mail server, another web server, anything. A dot in a name is literal.
# certbot's own records of the lineage are what is being removed.
re_escape() { printf '%s' "$1" | sed 's/[.]/\\./g'; }
LIVE_RE="/letsencrypt/live/$(re_escape "$NAME")/"
PLACEHOLDER_RE="/ssl/joinery/$(re_escape "$DOMAIN")([/\"'[:space:]]|\$)"
USED_BY=$(grep -rlsIE --exclude-dir=letsencrypt --exclude-dir=apache2 -e "$LIVE_RE" -e "$PLACEHOLDER_RE" "$ETC" 2>/dev/null | head -5 | tr '\n' ' ')
if [ -n "$USED_BY" ]; then
    refuse "named by ${USED_BY% }"
fi

# 2. Apache: every file it loads, wherever it lives, and every site that could
# be enabled (sites-available/*.conf). Not the backups beside them
# (<site>.conf.before-render.<time> and the like): Apache never loads them
# and a2ensite cannot enable them.
APACHE_FILES=$(printf '%s\n' "$ETC"/apache2/sites-available/*.conf "$ETC"/apache2/sites-enabled/* "$ETC"/apache2/conf-enabled/*)
if command -v apache2ctl >/dev/null 2>&1; then
    if ! INCLUDES=$(timeout 30 apache2ctl -t -D DUMP_INCLUDES 2>/dev/null); then
        refuse "Apache's configuration does not load, so what it uses cannot be read"
    fi
    APACHE_FILES="$APACHE_FILES"$'\n'"$(printf '%s\n' "$INCLUDES" | grep -oE '/[^[:space:]]+$')"
fi
while read -r f; do
    if [ -f "$f" ] && names_it "$f"; then
        refuse "named by $f"
    fi
done < <(printf '%s\n' "$APACHE_FILES" | sort -u)

# 3. What this machine serves for the domain on 443, against the certificate
# on disk (the lineage's, then the placeholder's).
fingerprint() { openssl x509 -noout -fingerprint -sha256 2>/dev/null | cut -d= -f2; }
if command -v openssl >/dev/null 2>&1; then
    for addr in 127.0.0.1 '[::1]'; do
        served=$(echo | timeout 10 openssl s_client -connect "$addr:443" -servername "$DOMAIN" 2>/dev/null | fingerprint)
        [ -n "$served" ] || continue
        for pem in "$LIVE/fullchain.pem" "$PLACEHOLDER/fullchain.pem"; do
            [ -f "$pem" ] || continue
            if [ "$(fingerprint < "$pem")" = "$served" ]; then
                refuse "this machine serves it for $DOMAIN on 443 (asked over $addr)"
            fi
        done
    done
fi

LE="absent"
if [ -e "$LIVE" ] || [ -e "$RENEWAL" ] || [ -e "$ARCHIVE" ]; then
    if command -v certbot >/dev/null 2>&1; then
        timeout "$CMD_TIMEOUT" certbot delete --cert-name "$NAME" --non-interactive >/dev/null 2>&1
    fi
    if [ -e "$LIVE" ] || [ -e "$RENEWAL" ] || [ -e "$ARCHIVE" ]; then
        LE="failed"
    else
        LE="removed"
    fi
fi

PH="absent"
if [ -e "$PLACEHOLDER" ]; then
    rm -rf "${PLACEHOLDER:?}"
    if [ -e "$PLACEHOLDER" ]; then
        PH="failed"
    else
        PH="removed"
    fi
fi

printf '{"name":"%s","letsencrypt":"%s","placeholder":"%s"}\n' "$NAME" "$LE" "$PH"
exit 0
