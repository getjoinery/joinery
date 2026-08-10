#!/usr/bin/env bash

# arm_ssl_retry.sh - watch for DNS, then issue the certificate on its own
# Version: 1.0.1 - --disarm is never refused by the routable-name guard: timers armed for an
#                  IP by earlier installers exist in the fleet, and refusing to clean one up
#                  leaves it polling a rate-limited CA forever
# Version: 1.0.0
#
# Description:
#   A site can come up before its domain points at the box — a fresh install
#   ahead of the DNS cutover, or a rebuild that will take the domain over later.
#   This arms a systemd timer that checks every five minutes and does nothing at
#   all until the domain resolves HERE, then issues once and disables itself. The
#   deployer points DNS whenever they get to it and the certificate arrives
#   without them doing anything, or knowing this existed.
#
#   The DNS lookup before each attempt is what makes an indefinite retry safe.
#   Let's Encrypt allows five FAILED VALIDATIONS per hostname per hour, so
#   hammering certbot at a domain that cannot resolve here would burn the budget
#   the eventually-correct attempt needs. A failed lookup costs nothing.
#
#   Units are templated on the domain, so a multi-site box gets one instance per
#   site rather than one timer that can only ever serve the first.
#
# Usage:
#   ./arm_ssl_retry.sh DOMAIN --setup-ssl PATH   Arm (or re-arm) for DOMAIN
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

[ -n "$SETUP_SSL" ] || { echo "--setup-ssl PATH is required when arming." >&2; exit 1; }

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
# all until the domain resolves to this server, then issues once and disables
# its own timer.
set -u

DOMAIN="${1:-}"
[ -n "$DOMAIN" ] || exit 0

CONF="/etc/joinery/ssl-retry/${DOMAIN}.conf"
[ -f "$CONF" ] || exit 0
# shellcheck source=/dev/null
. "$CONF"

SETUP_SSL="${SETUP_SSL:-}"
if [ ! -x "$SETUP_SSL" ] && [ ! -f "$SETUP_SSL" ]; then
    echo "setup_ssl.sh is no longer at $SETUP_SSL — nothing to run"
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
# validations, not failed lookups.
SERVER_IP=$(curl -s --max-time 5 ifconfig.me 2>/dev/null || curl -s --max-time 5 icanhazip.com 2>/dev/null)
DNS_IP=$(dig +short "$DOMAIN" 2>/dev/null | grep -E '^[0-9.]+$' | head -1)

if [ -z "$DNS_IP" ]; then
    echo "$DOMAIN does not resolve yet — waiting."
    exit 0
fi
if [ -z "$SERVER_IP" ]; then
    echo "Could not determine this server's public IP — waiting."
    exit 0
fi
if [ "$DNS_IP" != "$SERVER_IP" ]; then
    echo "$DOMAIN resolves to $DNS_IP, this server is $SERVER_IP — waiting."
    exit 0
fi

echo "$DOMAIN now points here. Requesting a certificate."
bash "$SETUP_SSL" "$DOMAIN" || echo "setup_ssl.sh returned non-zero; will try again."

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
