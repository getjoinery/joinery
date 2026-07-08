#!/usr/bin/env bash
#
# provision_relay.sh - installer + configurator for a HARDENED INGEST RELAY.
#
# Sibling of install_email.sh (colocated mode). This builds the minimal, hardened,
# disposable VPS that fronts the public MX for a relay-fronted deployment
# (specs/inbound_email_hardened_ingest_relay_executor.md): Postfix + verify
# milters + the Go sealing binary + WireGuard, and NOTHING else — no PHP, no
# database, no web, no application. It accepts mail, verifies it, seals it to the
# recipient's public key at acceptance, and spools ciphertext; the main Joinery
# box dials out over WireGuard and pulls the sealed blobs.
#
# Version: 1.1 - /etc/opendkim creation + IPv4-only outbound (no v6 PTR on throwaway VPSes).
#
# What it configures (all fixed / deployment-independent):
#   - Installs postfix, opendkim, opendkim-tools, opendmarc, wireguard, rsync and
#     a Go toolchain to build the sealer. NO postfix-pgsql (the relay has no DB),
#     NO php.
#   - Builds relay-sealer (from the shipped relay-sealer/ Go source) to
#     /opt/joinery-relay/relay-sealer and wires it as the Postfix `joinery` pipe
#     transport (raw on stdin, ${recipient} ${sender} as argv).
#   - main.cf: inet_interfaces=all, mydestination=localhost, the RBL restriction
#     block (verbatim from install_email.sh), recipient validation at SMTP time
#     against the synced access map (preserving reject_unmatched), relay_domains +
#     transport_maps from the synced files, opendkim(8891)+opendmarc(8893) milters
#     stamping Authentication-Results.
#   - The synced routing map is a set of STATIC files pushed from the main server
#     over the tunnel (RelayMapSync): /etc/postfix/joinery-{relay-domains,recipients,
#     transport} and /opt/joinery-relay/routing.json. Empty placeholders are
#     created here so Postfix starts before the first sync.
#   - WireGuard: generates the relay keypair, writes a wg-quick config that LISTENS
#     (the relay never dials in; Joinery initiates the peering).
#   - Hardening: unattended-upgrades, key-only SSH, default-deny firewall
#     (25/tcp, WireGuard UDP, SSH).
#   - Prints the three values the main server needs: relay public IP, WireGuard
#     public key, spool endpoint.
#
# What it does NOT do (per-deployment, handled elsewhere):
#   - The routing map content: pushed from the main server (RelayMapSync), never
#     built here (the relay has no database).
#   - DNS records (MX, A, SPF, PTR).
#   - The WireGuard peer for the main box: added when the main box's public key is
#     known (by the provisioning job, or by hand from the printed values).
#   - Let's Encrypt / inbound STARTTLS cert (out of scope, matches install_email.sh).
#
# Usage:  sudo bash provision_relay.sh <mail-hostname>
#         e.g. sudo bash provision_relay.sh mx.example.com
set -euo pipefail

# --- preconditions -----------------------------------------------------------
if [[ "${EUID}" -ne 0 ]]; then
    echo "This script must run as root (installs packages, edits /etc/postfix)." >&2
    echo "Re-run with: sudo bash $0 <mail-hostname>" >&2
    exit 1
fi
if ! command -v apt-get >/dev/null 2>&1; then
    echo "This installer supports apt-based systems (Debian/Ubuntu) only." >&2
    exit 1
fi

MAIL_HOSTNAME="${1:-}"
if [[ -z "${MAIL_HOSTNAME}" || "${MAIL_HOSTNAME}" != *.* ]]; then
    echo "Usage: sudo bash $0 <mail-hostname>   (a FQDN, e.g. mx.example.com)" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SEALER_SRC="${SCRIPT_DIR}/relay-sealer"
RELAY_HOME="/opt/joinery-relay"
SEALER_BIN="${RELAY_HOME}/relay-sealer"
SPOOL_DIR="/var/spool/joinery-relay"
RELAY_USER="joinery-relay"
WG_IF="wg0"
WG_PORT="51820"
WG_ADDR="10.99.0.1/24"          # the relay's tunnel address; Joinery is 10.99.0.2
MAP_DOMAINS="/etc/postfix/joinery-relay-domains"
MAP_RECIPIENTS="/etc/postfix/joinery-recipients"
MAP_TRANSPORT="/etc/postfix/joinery-transport"
MAP_SRS="/etc/postfix/joinery-srs"
ROUTING_JSON="${RELAY_HOME}/routing.json"

if [[ ! -d "${SEALER_SRC}" ]]; then
    echo "ERROR: sealer source not found at ${SEALER_SRC}" >&2
    echo "Run this script from the shipped plugins/mailbox/provisioning/ directory." >&2
    exit 1
fi

export DEBIAN_FRONTEND=noninteractive

# --- 1. install packages -----------------------------------------------------
# No postfix-pgsql (no app DB on the relay), no php. golang-go builds the sealer.
PACKAGES=(postfix opendkim opendkim-tools opendmarc wireguard wireguard-tools rsync ufw golang-go ca-certificates)
MISSING=()
for pkg in "${PACKAGES[@]}"; do
    if dpkg -s "${pkg}" >/dev/null 2>&1; then
        echo "Already installed: ${pkg}"
    else
        MISSING+=("${pkg}")
    fi
done
if [[ ${#MISSING[@]} -gt 0 ]]; then
    echo "Installing: ${MISSING[*]}"
    apt-get update -qq
    apt-get install -y "${MISSING[@]}"
else
    echo "All relay packages already installed."
fi
systemctl enable postfix >/dev/null 2>&1 || true

# --- 2. relay user + spool + build the sealer --------------------------------
if ! id -u "${RELAY_USER}" >/dev/null 2>&1; then
    useradd --system --home-dir "${RELAY_HOME}" --shell /usr/sbin/nologin "${RELAY_USER}"
    echo "created system user ${RELAY_USER}"
fi
mkdir -p "${RELAY_HOME}" "${SPOOL_DIR}" "${SPOOL_DIR}/tmp"
chown -R "${RELAY_USER}:${RELAY_USER}" "${SPOOL_DIR}"
chmod 700 "${SPOOL_DIR}"
chown root:"${RELAY_USER}" "${RELAY_HOME}"
chmod 750 "${RELAY_HOME}"

echo "building relay-sealer (CGO off, static)..."
# Build as a normal user context but write into RELAY_HOME. A module-cache dir is
# needed for the one dependency (golang.org/x/crypto) to be fetched.
export GOFLAGS="-mod=mod"
export GOCACHE="${RELAY_HOME}/.gocache"
export GOPATH="${RELAY_HOME}/.gopath"
mkdir -p "${GOCACHE}" "${GOPATH}"
( cd "${SEALER_SRC}" && CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o "${SEALER_BIN}" . )
chown "${RELAY_USER}:${RELAY_USER}" "${SEALER_BIN}"
chmod 755 "${SEALER_BIN}"
echo "sealer built: ${SEALER_BIN}"

# --- 3. placeholder synced maps (Postfix must start before the first sync) ----
for f in "${MAP_DOMAINS}" "${MAP_RECIPIENTS}" "${MAP_TRANSPORT}"; do
    [[ -f "${f}" ]] || : > "${f}"
    postmap "${f}"
done
# The SRS accept map is a regexp map (no postmap); create it empty if absent.
[[ -f "${MAP_SRS}" ]] || : > "${MAP_SRS}"
if [[ ! -f "${ROUTING_JSON}" ]]; then
    printf '{"recipients":{},"domains":{},"forwarding_domains":[]}\n' > "${ROUTING_JSON}"
fi
chown root:"${RELAY_USER}" "${ROUTING_JSON}"
chmod 640 "${ROUTING_JSON}"

# --- 4. master.cf: the Go sealer pipe transport (assert, self-repairing) ------
# flags=DRh — deliberately NOT 'u' (fold localpart to lowercase): SRS bounce
# addresses carry a case-sensitive hash in the local part, and folding it makes
# every bounce fail validation on the main box. The sealer receives ${recipient}
# ${sender} as argv and the raw message on stdin, and runs as the unprivileged
# relay user. The Go binary reads its paths from JOINERY_RELAY_ROUTING /
# JOINERY_RELAY_SPOOL, which default to the paths configured above.
SEALER_ARGV="argv=${SEALER_BIN} \${recipient} \${sender}"
SEALER_DEF="joinery unix - n n - 5 pipe flags=DRh user=${RELAY_USER} ${SEALER_ARGV}"
existing_joinery="$(postconf -M joinery/unix 2>/dev/null | tr -s ' \t' ' ' | tr -d '\n' || true)"
if [[ -z "${existing_joinery}" ]]; then
    postconf -Me "joinery/unix=${SEALER_DEF}"
    echo "master.cf: added joinery sealer pipe transport"
elif [[ ( "${existing_joinery}" == *"${SEALER_ARGV} "* || "${existing_joinery}" == *"${SEALER_ARGV}" ) && "${existing_joinery}" == *"flags=DRh "* ]]; then
    echo "master.cf: joinery sealer transport already correct."
else
    postconf -Me "joinery/unix=${SEALER_DEF}"
    echo "master.cf: repaired stale joinery sealer transport"
fi

# --- 5. main.cf --------------------------------------------------------------
postconf -e "myhostname = ${MAIL_HOSTNAME}"
postconf -e "inet_interfaces = all"
postconf -e "mydestination = localhost, localhost.localdomain"

# Prefer IPv4 for outbound (forward + SRS bounce legs). A fresh VPS gets an IPv6
# address whose PTR is almost never set, and big receivers (Gmail) hard-reject
# IPv6 mail without a matching PTR + authentication (550 IPv6AuthError). The
# IPv4 PTR is what the provisioning DNS sets, so send from IPv4.
postconf -e "smtp_address_preference = ipv4"

# The main Joinery box submits outbound compose through this relay over the tunnel
# (smarthost — Phase 7), so the WireGuard subnet is trusted to relay anywhere.
# permit_mynetworks in smtpd_recipient_restrictions then accepts those sends.
postconf -e "mynetworks = 127.0.0.0/8, [::1]/128, 10.99.0.0/24"

# The relay is authoritative for the hosted domains (synced) and routes each to
# the sealer pipe. reject_unauth_destination then accepts recipients in these and
# rejects relay attempts for anything else.
postconf -e "relay_domains = hash:${MAP_DOMAINS}"
postconf -e "transport_maps = hash:${MAP_TRANSPORT}"

# RBL block — verbatim from install_email.sh — plus SMTP-time recipient
# validation against the synced access map (preserving reject_unmatched: listed
# aliases OK, unmatched under a reject domain REJECTed, no backscatter).
postconf -e "smtpd_recipient_restrictions = permit_mynetworks, reject_unauth_destination, reject_rbl_client zen.spamhaus.org, reject_rbl_client bl.spamcop.net, reject_rbl_client b.barracudacentral.org, reject_rhsbl_helo dbl.spamhaus.org, reject_rhsbl_sender dbl.spamhaus.org, check_recipient_access regexp:${MAP_SRS}, check_recipient_access hash:${MAP_RECIPIENTS}, permit"
echo "main.cf: relay_domains, transport, recipient validation, RBL set"

# --- 6. opendkim + opendmarc (verify-mode, verbatim from install_email.sh) ----
AUTHSERV_ID="${MAIL_HOSTNAME}"
mkdir -p /run/opendkim
chown opendkim:opendkim /run/opendkim 2>/dev/null || true
mkdir -p /etc/opendkim
[[ -f /etc/opendkim/key.table ]]     || : > /etc/opendkim/key.table
[[ -f /etc/opendkim/signing.table ]] || : > /etc/opendkim/signing.table
[[ -f /etc/opendkim/trusted.hosts ]] || printf '127.0.0.1\n::1\nlocalhost\n' > /etc/opendkim/trusted.hosts

OPENDKIM_MARKER='joinery-managed opendkim.conf'
if ! grep -qF "${OPENDKIM_MARKER}" /etc/opendkim.conf 2>/dev/null; then
    [[ -f /etc/opendkim.conf && ! -f /etc/opendkim.conf.pre-joinery ]] && cp /etc/opendkim.conf /etc/opendkim.conf.pre-joinery
    cat > /etc/opendkim.conf <<OPENDKIMCONF
# ${OPENDKIM_MARKER} — managed by mailbox/provisioning/provision_relay.sh.
# Mode v = VERIFY inbound only (the relay does not sign; DKIM signing stays in-app
# on the main box and rides through the outbound smarthost).
# RemoveARAll + RemoveARFrom strip any inbound Authentication-Results header that
# forges OUR authserv-id BEFORE opendkim stamps its own, so a sender cannot smuggle
# a fake "spf=pass dkim=pass" verdict the main box would trust.
Syslog                  yes
SyslogSuccess           yes
UMask                   007
Mode                    v
Canonicalization        relaxed/simple
Socket                  inet:8891@localhost
PidFile                 /run/opendkim/opendkim.pid
UserID                  opendkim
AuthservID              ${AUTHSERV_ID}
RemoveARAll             yes
RemoveARFrom            ${AUTHSERV_ID}
KeyTable                /etc/opendkim/key.table
SigningTable            refile:/etc/opendkim/signing.table
ExternalIgnoreList      /etc/opendkim/trusted.hosts
InternalHosts           /etc/opendkim/trusted.hosts
OPENDKIMCONF
    echo "opendkim: wrote /etc/opendkim.conf (verify, AuthservID ${AUTHSERV_ID})"
else
    echo "opendkim: already managed by us - leaving it."
fi
if [[ -f /etc/default/opendkim ]]; then
    if grep -qE '^[[:space:]]*SOCKET=' /etc/default/opendkim; then
        sed -i 's#^[[:space:]]*SOCKET=.*#SOCKET="inet:8891@localhost"#' /etc/default/opendkim
    else
        echo 'SOCKET="inet:8891@localhost"' >> /etc/default/opendkim
    fi
fi

mkdir -p /run/opendmarc
chown opendmarc:opendmarc /run/opendmarc 2>/dev/null || true
OPENDMARC_MARKER='joinery-managed opendmarc.conf'
if ! grep -qF "${OPENDMARC_MARKER}" /etc/opendmarc.conf 2>/dev/null; then
    [[ -f /etc/opendmarc.conf && ! -f /etc/opendmarc.conf.pre-joinery ]] && cp /etc/opendmarc.conf /etc/opendmarc.conf.pre-joinery
    cat > /etc/opendmarc.conf <<OPENDMARCCONF
# ${OPENDMARC_MARKER} — managed by mailbox/provisioning/provision_relay.sh.
# Stamps SPF + DMARC into Authentication-Results; never rejects (stamp-only).
AuthservID              ${AUTHSERV_ID}
Socket                  inet:8893@localhost
PidFile                 /run/opendmarc/opendmarc.pid
UserID                  opendmarc
UMask                   0002
Syslog                  true
SoftwareHeader          true
SPFSelfValidate         true
RejectFailures          false
OPENDMARCCONF
    echo "opendmarc: wrote /etc/opendmarc.conf (AuthservID ${AUTHSERV_ID})"
else
    echo "opendmarc: already managed by us - leaving it."
fi
if [[ -f /etc/default/opendmarc ]]; then
    if grep -qE '^[[:space:]]*SOCKET=' /etc/default/opendmarc; then
        sed -i 's#^[[:space:]]*SOCKET=.*#SOCKET="inet:8893@localhost"#' /etc/default/opendmarc
    else
        echo 'SOCKET="inet:8893@localhost"' >> /etc/default/opendmarc
    fi
fi

systemctl enable opendkim opendmarc >/dev/null 2>&1 || true
systemctl restart opendkim 2>/dev/null || service opendkim restart 2>/dev/null || echo "WARN: restart opendkim manually" >&2
systemctl restart opendmarc 2>/dev/null || service opendmarc restart 2>/dev/null || echo "WARN: restart opendmarc manually" >&2

# --- 6b. rspamd content spam scanner -----------------------------------------
# The relay stamps the X-Spam header inside the sealed raw so the main box's
# deferred ingest (and the transport pull path) can read a content-spam verdict —
# identical to what the colocated main-box MTA stamps. add_header only; the relay
# NEVER rejects on content (the reviewable-verdict model). rspamd runs as a milter
# AFTER opendkim(verify)+opendmarc so it can score on the auth results.
CS_PACKAGES=(rspamd redis-server)
CS_MISSING=()
for pkg in "${CS_PACKAGES[@]}"; do
    dpkg -s "${pkg}" >/dev/null 2>&1 && echo "Already installed: ${pkg}" || CS_MISSING+=("${pkg}")
done
if [[ ${#CS_MISSING[@]} -gt 0 ]]; then
    echo "Installing: ${CS_MISSING[*]}"
    apt-get update -qq
    apt-get install -y "${CS_MISSING[@]}"
fi
mkdir -p /etc/rspamd/local.d
# The header NAMES are the contract InboundEmailRouter::readSpamHeader() parses;
# keep them in step with that class's SPAM_*_HEADER constants.
cat > /etc/rspamd/local.d/milter_headers.conf <<'RSPAMDHDR'
# joinery-managed - content spam header contract (InboundEmailRouter::readSpamHeader).
extended_spam_headers = true;
use = ["spam-header", "x-spam-status", "authentication-results"];
RSPAMDHDR
cat > /etc/rspamd/local.d/actions.conf <<'RSPAMDACT'
# joinery-managed - header-stamping only; rejection disabled (out of scope).
reject = null;
greylist = null;
add_header = 6;
RSPAMDACT
cat > /etc/rspamd/local.d/classifier-bayes.conf <<'RSPAMDBAYES'
# joinery-managed - Bayes tokens persist in redis (disposable; the main box is truth).
backend = "redis";
servers = "127.0.0.1:6379";
autolearn = true;
RSPAMDBAYES
cat > /etc/rspamd/local.d/redis.conf <<'RSPAMDREDIS'
# joinery-managed - local redis for Bayes/statistics.
servers = "127.0.0.1:6379";
RSPAMDREDIS
cat > /etc/rspamd/local.d/worker-proxy.inc <<'RSPAMDPROXY'
# joinery-managed - Postfix milter (self-scan) on 11332.
milter = yes;
timeout = 120s;
upstream "local" {
  default = yes;
  self_scan = yes;
}
bind_socket = "*:11332";
RSPAMDPROXY

# Wire rspamd into the milter chain AFTER opendkim+opendmarc.
postconf -e "milter_default_action = accept"
postconf -e "smtpd_milters = inet:localhost:8891, inet:localhost:8893, inet:localhost:11332"
postconf -e "non_smtpd_milters ="
echo "main.cf: milters wired (opendkim:8891, opendmarc:8893, rspamd:11332)"

systemctl enable redis-server rspamd >/dev/null 2>&1 || true
systemctl restart redis-server 2>/dev/null || service redis-server restart 2>/dev/null || echo "WARN: restart redis manually" >&2
systemctl restart rspamd 2>/dev/null || service rspamd restart 2>/dev/null || echo "WARN: restart rspamd manually" >&2
echo "content-spam: rspamd milter on 11332 (add-header only). NOTE: needs outbound DNS for RBLs."

# --- 7. WireGuard (the relay LISTENS; Joinery dials out) ----------------------
mkdir -p /etc/wireguard
chmod 700 /etc/wireguard
if [[ ! -f /etc/wireguard/relay_private.key ]]; then
    umask 077
    wg genkey > /etc/wireguard/relay_private.key
    wg pubkey < /etc/wireguard/relay_private.key > /etc/wireguard/relay_public.key
    echo "wireguard: generated relay keypair"
fi
WG_PRIV="$(cat /etc/wireguard/relay_private.key)"
WG_PUB="$(cat /etc/wireguard/relay_public.key)"

# Write the interface config only if absent, so a re-run never wipes a [Peer]
# block the provisioning job (or an operator) has since added for the main box.
if [[ ! -f "/etc/wireguard/${WG_IF}.conf" ]]; then
    cat > "/etc/wireguard/${WG_IF}.conf" <<WGCONF
# joinery-managed - hardened ingest relay tunnel. The relay only LISTENS; the
# main Joinery box initiates the peering. Add the main box as a [Peer] with its
# public key and AllowedIPs = 10.99.0.2/32 (done by the provisioning job).
[Interface]
Address = ${WG_ADDR}
ListenPort = ${WG_PORT}
PrivateKey = ${WG_PRIV}
WGCONF
    chmod 600 "/etc/wireguard/${WG_IF}.conf"
    echo "wireguard: wrote /etc/wireguard/${WG_IF}.conf"
else
    echo "wireguard: /etc/wireguard/${WG_IF}.conf exists - leaving it (peer edits preserved)."
fi
systemctl enable "wg-quick@${WG_IF}" >/dev/null 2>&1 || true
systemctl restart "wg-quick@${WG_IF}" 2>/dev/null || echo "WARN: bring up wg-quick@${WG_IF} manually once a peer is added" >&2

# --- 8. hardening ------------------------------------------------------------
# Unattended security upgrades.
if ! dpkg -s unattended-upgrades >/dev/null 2>&1; then
    apt-get install -y unattended-upgrades
fi
dpkg-reconfigure -f noninteractive unattended-upgrades >/dev/null 2>&1 || true

# Key-only SSH.
SSHD_DROPIN=/etc/ssh/sshd_config.d/10-joinery-relay.conf
mkdir -p /etc/ssh/sshd_config.d
cat > "${SSHD_DROPIN}" <<SSHDCONF
# joinery-managed - key-only SSH on the relay.
PasswordAuthentication no
ChallengeResponseAuthentication no
PermitRootLogin prohibit-password
SSHDCONF
systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true

# Default-deny firewall: SMTP in, WireGuard in, SSH in.
ufw --force reset >/dev/null 2>&1 || true
ufw default deny incoming >/dev/null 2>&1 || true
ufw default allow outgoing >/dev/null 2>&1 || true
ufw allow 25/tcp        >/dev/null 2>&1 || true
ufw allow "${WG_PORT}/udp" >/dev/null 2>&1 || true
ufw allow 22/tcp        >/dev/null 2>&1 || true
ufw --force enable      >/dev/null 2>&1 || true
echo "firewall: default-deny; allow 25/tcp, ${WG_PORT}/udp, 22/tcp"

# --- 9. validate + restart Postfix -------------------------------------------
if postfix check; then
    systemctl restart postfix 2>/dev/null || { postfix stop 2>/dev/null || true; postfix start; }
    echo "Postfix configuration validated and restarted."
else
    echo "WARNING: 'postfix check' reported problems - NOT restarting. Review above." >&2
    exit 1
fi

# --- 10. print what the main server needs ------------------------------------
PUBLIC_IP="$(curl -fsS --max-time 5 https://api.ipify.org 2>/dev/null || hostname -I 2>/dev/null | awk '{print $1}' || echo 'unknown')"
echo
echo "================= HARDENED RELAY READY ================="
echo "RELAY_READY"
echo "  Mail hostname     : ${MAIL_HOSTNAME}"
echo "  Relay public IP   : ${PUBLIC_IP}"
echo "  WireGuard pubkey  : ${WG_PUB}"
echo "  WireGuard endpoint: ${PUBLIC_IP}:${WG_PORT}"
echo "  Relay tunnel IP   : ${WG_ADDR%/*}"
echo "  Spool endpoint    : ${RELAY_USER}@${WG_ADDR%/*}:${SPOOL_DIR}"
echo
echo "Next, on the main Joinery box:"
echo "  1. Point the MX + A records for every hosted domain at ${PUBLIC_IP}."
echo "  2. Add this relay under the Mailbox relay setup (paste the values above),"
echo "     which peers WireGuard (Joinery dials out), pushes the alias map, and"
echo "     starts pulling the spool."
echo "  3. Set the relay's PTR record to ${MAIL_HOSTNAME} at your VPS provider."
echo "========================================================"
