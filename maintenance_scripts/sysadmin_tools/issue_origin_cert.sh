#!/usr/bin/env bash
#
# issue_origin_cert.sh - issue (or re-issue) the origin certificate for a name,
# apex + www, on the box that terminates TLS for it.
# Version: 1.1 - 525 and 526 are two messages (a box with no TLS listener at all
#                is a missing placeholder, not an edge on Strict); www resolves
#                through name_resolves, which never mistakes a CNAME target for
#                an address (review round 1, R2 and R4a).
# Version: 1.0 - specs/implemented/tls_and_origin_trust.md WP0 and the www re-issue. Run as
#                root on a bare-metal node, or on the Docker host for a
#                container: probes that the apex and www reach this box, prints
#                the exact certbot line, asks, runs it, reloads Apache, then
#                verifies at 127.0.0.1:443 with SNI that the certificate
#                presented covers each name. Never deletes anything.
#
# Usage:
#   sudo ./issue_origin_cert.sh <domain> [--yes]
#
# The certificate lands at /etc/letsencrypt/live/<domain>/ where the proxy and
# bare-metal vhost templates' <IfFile> :443 block already reads it; no vhost
# is edited (certonly). --expand is passed only when the lineage already
# exists, which is what certbot needs to add www to an apex-only certificate.
# The lineage's renewal conf ends with installer = None and a reload hook, so
# renewals reload Apache and never touch the vhost.

set -euo pipefail

DOMAIN=""
YES=0
for arg in "$@"; do
    case "$arg" in
        --yes|-y) YES=1 ;;
        --help|-h) awk 'NR<3 {next} /^#/ {sub(/^# ?/,""); print; next} {exit}' "$0"; exit 0 ;;
        -*) echo "Unknown option: $arg" >&2; exit 1 ;;
        *) [ -z "$DOMAIN" ] && DOMAIN="$arg" ;;
    esac
done
[ -n "$DOMAIN" ] || { echo "Usage: $0 <domain> [--yes]" >&2; exit 1; }
case "$DOMAIN" in
    www.*) echo "Give the apex name; www is added on its own." >&2; exit 1 ;;
esac

if [ "$EUID" -ne 0 ]; then
    echo "ERROR: this script writes /etc/letsencrypt/ and reloads Apache. Re-run with sudo." >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALL_SH="${SCRIPT_DIR}/../install_tools/install.sh"
[ -f "$INSTALL_SH" ] || { echo "ERROR: could not find install.sh at $INSTALL_SH" >&2; exit 1; }
# install.sh returns early when sourced: only the helpers (print_*,
# name_reaches_here, site_serving_name) come in.
# shellcheck source=/dev/null
. "$INSTALL_SH"

command -v certbot >/dev/null 2>&1 || { echo "ERROR: certbot is not installed on this box." >&2; exit 1; }

echo "== Reach =="
reach=0
name_reaches_here "$DOMAIN" || reach=$?
case "$reach" in
    0) ;;
    2)  echo "The edge in front of ${DOMAIN} is on Full (Strict) and refuses the placeholder certificate this box presents." >&2
        echo "Set it to Full, re-run this, then set Strict. Nothing issued." >&2
        exit 1 ;;
    3)  echo "${DOMAIN} reaches an edge, but this box did not complete a TLS handshake: the placeholder certificate is missing." >&2
        echo "Run: sudo bash ${SCRIPT_DIR}/../install_tools/render_vhost.sh   then re-run this. Nothing issued." >&2
        exit 1 ;;
    *)  echo "${DOMAIN} does not reach this box, so HTTP-01 cannot be answered from here. Nothing issued." >&2
        exit 1 ;;
esac

NAMES=(-d "$DOMAIN")
www_ip="$(name_resolves "www.${DOMAIN}")"
if [ -n "$www_ip" ]; then
    if name_reaches_here "www.${DOMAIN}"; then
        NAMES+=(-d "www.${DOMAIN}")
    else
        echo "www.${DOMAIN} resolves (${www_ip}) but does not reach this box; it is left out." >&2
    fi
else
    echo "www.${DOMAIN} does not resolve; issuing for ${DOMAIN} only."
fi

EXPAND=()
if [ -d "/etc/letsencrypt/live/${DOMAIN}" ]; then
    EXPAND=(--expand)
    echo "A lineage for ${DOMAIN} already exists; --expand lets it take on the names above."
fi

CMD=(certbot certonly --apache "${NAMES[@]}" "${EXPAND[@]}" --non-interactive --agree-tos
     --register-unsafely-without-email --deploy-hook 'systemctl reload apache2')

echo
echo "== Will run =="
printf '  %q' "${CMD[@]}"; echo
echo
if [ "$YES" -ne 1 ]; then
    read -r -p "Proceed? [y/N] " answer
    case "$answer" in
        y|Y|yes|YES) ;;
        *) echo "Nothing issued."; exit 0 ;;
    esac
fi

"${CMD[@]}"

# certonly records installer = None for the lineage. Make sure of it, and of
# the hook, the same way render_vhost.sh does on every converge: a renewal
# must reload Apache and must not edit the vhost.
RENEWAL="/etc/letsencrypt/renewal/${DOMAIN}.conf"
if [ -f "$RENEWAL" ]; then
    sed -i -E 's/^([[:space:]]*installer[[:space:]]*=[[:space:]]*)apache[[:space:]]*$/\1None/' "$RENEWAL"
    if grep -q '^\[renewalparams\]' "$RENEWAL" && ! grep -qE '^[[:space:]]*renew_hook[[:space:]]*=' "$RENEWAL"; then
        sed -i '/^\[renewalparams\]/a renew_hook = systemctl reload apache2' "$RENEWAL"
    fi
fi

if apache2ctl configtest >/dev/null 2>&1; then
    systemctl reload apache2 || true
    echo "Apache reloaded."
else
    echo "WARNING: apache2ctl configtest failed - review the vhost by hand." >&2
fi

echo
echo "== Verify at the origin (127.0.0.1:443, SNI per name) =="
fail=0
for n in "$DOMAIN" $( [ "${#NAMES[@]}" -gt 2 ] && echo "www.${DOMAIN}" ); do
    presented="$(echo | openssl s_client -connect 127.0.0.1:443 -servername "$n" 2>/dev/null \
        | openssl x509 -noout -ext subjectAltName 2>/dev/null | tr ',' '\n' | sed -n 's/.*DNS:[[:space:]]*//p' | tr '\n' ' ')"
    case " ${presented} " in
        *" ${n} "*) echo "  ${n}: covered (${presented% })" ;;
        *) echo "  ${n}: NOT covered - presented: ${presented:-nothing}"; fail=1 ;;
    esac
done
[ "$fail" -eq 0 ] || { echo "The origin does not yet present the new certificate for every name. Check the vhost's <IfFile> block and reload." >&2; exit 1; }
echo "Done. Renewal is on certbot's timer; the vhost was not edited."
