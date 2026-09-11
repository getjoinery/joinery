#!/usr/bin/env bash

# arm_ssl_retry.sh - watch for the name to reach this box, then issue the certificate on its own
# Version: 1.2.1 - The two ways the https hop can fail are two journal lines: 526 is the
#                  edge on Strict refusing the placeholder (set it to Full); 525 is this box
#                  answering no TLS at all, which after the placeholder is a defect
#                  (specs/tls_and_origin_trust.md R2).
# Version: 1.2.0 - The wait condition is "the name reaches this box", asked through
#                  install.sh's name_reaches_here (a nonce fetched through the name),
#                  not "the name resolves to this box's address". Behind Cloudflare
#                  the name never resolves here, so the old gate waited forever for
#                  a certificate HTTP-01 could have issued through the edge on the
#                  first tick (specs/tls_and_origin_trust.md WP10). The budget
#                  argument holds: a failed reach, like a failed lookup, costs
#                  nothing at Let's Encrypt, so five minutes stays safe.
# Version: 1.1.0 - --setup-ssl takes several candidate paths, colon-separated and most
#                  durable first; the timer runs the first that exists when it fires.
#                  An install arms it before the host agent's bundle exists, and the
#                  extracted release it named used to be deleted out from under it.
# Version: 1.0.1 - --disarm is never refused by the routable-name guard: timers armed for an
#                  IP by earlier installers exist in the fleet, and refusing to clean one up
#                  leaves it polling a rate-limited CA forever
# Version: 1.0.0
#
# Description:
#   A site can come up before its domain reaches the box — a fresh install
#   ahead of the DNS cutover, a rebuild that will take the domain over later, or
#   an edge already set to Full (Strict) that will not forward to an origin with
#   no certificate. This arms a systemd timer that checks every five minutes and
#   does nothing at all until a request for the domain REACHES here (directly or
#   through an edge), then issues once and disables itself. The deployer points
#   DNS, or sets the edge to Full, whenever they get to it and the certificate
#   arrives without them doing anything, or knowing this existed.
#
#   The reach probe before each attempt is what makes an indefinite retry safe.
#   Let's Encrypt allows five FAILED VALIDATIONS per hostname per hour, so
#   hammering certbot at a domain that cannot reach here would burn the budget
#   the eventually-correct attempt needs. A failed probe is one HTTP fetch of a
#   nonce and costs nothing at the CA.
#
#   Units are templated on the domain, so a multi-site box gets one instance per
#   site rather than one timer that can only ever serve the first.
#
# Usage:
#   ./arm_ssl_retry.sh DOMAIN --setup-ssl PATH[:PATH...]   Arm (or re-arm) for DOMAIN
#   ./arm_ssl_retry.sh DOMAIN --disarm           Stop watching for DOMAIN
#
# Exit status:
#   0  armed (or disarmed)
#   1  could not arm — no systemd, not root, or bad arguments. The caller is
#      expected to fall back to telling a human to run setup_ssl.sh.

set -uo pipefail

DOMAIN=""
SETUP_SSL=""
DISARM=false

while [[ $# -gt 0 ]]; do
    case $1 in
        --setup-ssl)   SETUP_SSL="$2"; shift 2 ;;
        --setup-ssl=*) SETUP_SSL="${1#*=}"; shift ;;
        --disarm)      DISARM=true; shift ;;
        --help|-h)     awk 'NR<3 {next} /^#/ {sub(/^# ?/,""); print; next} {exit}' "$0"; exit 0 ;;
        -*)            echo "Unknown option: $1" >&2; exit 1 ;;
        *)             if [ -z "$DOMAIN" ]; then DOMAIN="$1"; fi; shift ;;
    esac
done

[ -n "$DOMAIN" ] || { echo "A domain is required." >&2; exit 1; }

# An IP address has no certificate to issue and localhost has nowhere to issue
# from. Refusing here keeps a pointless timer off the box.
#
# Only when ARMING, though. Timers armed for an IP already exist in the fleet —
# earlier installers had no such guard — and refusing to disarm one because it
# should never have been armed would leave it polling a rate-limited CA every
# five minutes forever, with no way to stop it short of editing systemd by hand.
# Cleanup must always be permitted.
if [ "$DISARM" = false ]; then
    if [[ "$DOMAIN" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || [ "$DOMAIN" = "localhost" ]; then
        echo "SSL_RETRY_SKIPPED $DOMAIN (not a routable domain name)"
        exit 1
    fi
fi

if ! command -v systemctl > /dev/null 2>&1 || ! systemctl list-units > /dev/null 2>&1; then
    echo "SSL_RETRY_UNAVAILABLE (no systemd on this machine)"
    exit 1
fi

if [ "$(id -u)" -ne 0 ]; then
    echo "SSL_RETRY_UNAVAILABLE (must run as root)" >&2
    exit 1
fi

if [ "$DISARM" = true ]; then
    rm -f "/etc/joinery/ssl-retry/${DOMAIN}.conf"
    systemctl disable "joinery-ssl-retry@${DOMAIN}.timer" > /dev/null 2>&1 || true
    systemctl stop --no-block "joinery-ssl-retry@${DOMAIN}.timer" > /dev/null 2>&1 || true
    echo "SSL_RETRY_DISARMED $DOMAIN"
    exit 0
fi

[ -n "$SETUP_SSL" ] || { echo "--setup-ssl PATH[:PATH...] is required when arming." >&2; exit 1; }

mkdir -p /etc/joinery/ssl-retry
cat > "/etc/joinery/ssl-retry/${DOMAIN}.conf" <<EOF
# Written when a certificate was deferred. Read by /usr/local/sbin/joinery-ssl-retry.
# Removing this file stops the retries.
DOMAIN=${DOMAIN}
SETUP_SSL=${SETUP_SSL}
EOF
chmod 600 "/etc/joinery/ssl-retry/${DOMAIN}.conf"

cat > /usr/local/sbin/joinery-ssl-retry <<'RETRY_EOF'
#!/usr/bin/env bash
# Issue the certificate an install or a restore could not, once DNS finally
# points here.
#
# Run from joinery-ssl-retry@<domain>.timer every few minutes; does nothing at
# all until a request for the domain reaches this server, then issues once and
# disables its own timer.
set -u

DOMAIN="${1:-}"
[ -n "$DOMAIN" ] || exit 0

CONF="/etc/joinery/ssl-retry/${DOMAIN}.conf"
[ -f "$CONF" ] || exit 0
# shellcheck source=/dev/null
. "$CONF"

# SETUP_SSL may name several candidates, colon-separated and most durable
# first: an install arms this timer before the host agent's bundle exists, so
# the bundle path is listed ahead of the extracted release that is there in
# the meantime. The first that exists when the timer fires is the one run; none
# existing is a wait, not a give-up, because the bundle lands on its own.
RUN_SSL=""
IFS=':' read -r -a CANDIDATES <<< "${SETUP_SSL:-}"
for c in "${CANDIDATES[@]}"; do
    if [ -f "$c" ]; then RUN_SSL="$c"; break; fi
done
if [ -z "$RUN_SSL" ]; then
    echo "setup_ssl.sh is not at any of: ${SETUP_SSL:-(none)} — waiting."
    exit 0
fi

give_up() {
    echo "$1"
    rm -f "$CONF"
    systemctl disable "joinery-ssl-retry@${DOMAIN}.timer" > /dev/null 2>&1 || true
    systemctl stop --no-block "joinery-ssl-retry@${DOMAIN}.timer" > /dev/null 2>&1 || true
    exit 0
}

# A certificate signed by somebody else is the finish line. "A file exists at
# the cert path" is not: an operator, or an origin-certificate flow in front of
# this box, can put a self-signed certificate there, and stopping on that would
# retire the timer without ever issuing a real one. Self-signed means issuer
# equals subject.
have_real_cert() {
    local pem="/etc/letsencrypt/live/${DOMAIN}/fullchain.pem"
    [ -f "$pem" ] || return 1
    local issuer subject
    issuer=$(openssl x509 -in "$pem" -noout -issuer 2>/dev/null | sed 's/^issuer=//')
    subject=$(openssl x509 -in "$pem" -noout -subject 2>/dev/null | sed 's/^subject=//')
    [ -n "$issuer" ] && [ "$issuer" != "$subject" ]
}

have_real_cert && give_up "A CA-issued certificate is already in place for $DOMAIN."

# The cheap check, before spending an attempt. Let's Encrypt counts failed
# validations, not failed probes.
#
# The question is whether a request for the name lands on this box, which is
# what HTTP-01 needs and which is true both when the name resolves here and
# when it resolves to an edge that forwards here. install.sh answers it with
# name_reaches_here (a nonce written where the site serves files and fetched
# through the name); it is sourced from beside the setup_ssl.sh that will run.
INSTALL_SH="$(cd "$(dirname "$RUN_SSL")/../install_tools" 2>/dev/null && pwd)/install.sh"
if [ ! -f "$INSTALL_SH" ]; then
    echo "install.sh is not beside $RUN_SSL — waiting."
    exit 0
fi
# shellcheck source=/dev/null
. "$INSTALL_SH"

reach=0
name_reaches_here "$DOMAIN" || reach=$?
case "$reach" in
    0) ;;
    2)  echo "$DOMAIN reaches an edge that is on Full (Strict) and refuses the placeholder certificate this box presents. Set the edge to Full until the first certificate lands — waiting."
        exit 0 ;;
    3)  echo "$DOMAIN reaches an edge, but this box did not complete a TLS handshake: the placeholder certificate is missing. On the host: bash $(dirname "$INSTALL_SH")/render_vhost.sh — waiting."
        exit 0 ;;
    *)  echo "$DOMAIN does not reach this box yet — waiting."
        exit 0 ;;
esac

echo "$DOMAIN now reaches here (${REACH_STATE:-?}). Requesting a certificate."
bash "$RUN_SSL" "$DOMAIN" || echo "setup_ssl.sh returned non-zero; will try again."

have_real_cert && give_up "Certificate issued for $DOMAIN. Retry timer disabled."

echo "Still no CA-issued certificate for $DOMAIN — will try again."
exit 0
RETRY_EOF
chmod 755 /usr/local/sbin/joinery-ssl-retry

cat > /etc/systemd/system/joinery-ssl-retry@.service <<'EOF'
[Unit]
Description=Issue a deferred Joinery SSL certificate for %i
After=network-online.target apache2.service
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/joinery-ssl-retry %i
EOF

cat > /etc/systemd/system/joinery-ssl-retry@.timer <<'EOF'
[Unit]
Description=Retry a deferred Joinery SSL certificate for %i

[Timer]
OnBootSec=3min
OnUnitActiveSec=5min
AccuracySec=30s
Unit=joinery-ssl-retry@%i.service

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload > /dev/null 2>&1 || true
if ! systemctl enable --now "joinery-ssl-retry@${DOMAIN}.timer" > /dev/null 2>&1; then
    echo "SSL_RETRY_UNAVAILABLE (systemd refused the timer)" >&2
    exit 1
fi

echo "SSL_RETRY_ARMED $DOMAIN"
exit 0
