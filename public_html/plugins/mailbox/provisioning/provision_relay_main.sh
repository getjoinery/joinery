#!/usr/bin/env bash
# provision_relay_main.sh — the MAIN BOX half of the hardened ingest relay
# tunnel (specs/mailbox_relay_fix_pack.md § R2-5). provision_relay.sh builds the
# relay side (which LISTENS on WireGuard); this script gives the main box the
# identity and plumbing it needs to dial out. One-time root bootstrap, idempotent:
#
#   - generates the main box's WireGuard keypair (private key stays root-only in
#     /etc/wireguard; the relay peers the PUBLIC half)
#   - writes /etc/wireguard/jyrelay0.conf (tunnel address 10.99.0.2/24, no
#     ListenPort — the main box only dials out) and enables wg-quick@jyrelay0
#   - installs /usr/local/sbin/joinery-relay-peer, a narrow root helper that adds
#     one relay as a peer, plus a sudoers rule letting the web user invoke it —
#     that is how the provision job's result processor peers a freshly
#     provisioned relay with no manual step
#   - generates the RELAY PULL KEY at {site root}/config/relay_pull_key, a
#     dedicated SSH identity owned by the web user (spool pull, map push and the
#     health battery all run as the web user, and ssh requires the key file to
#     be owned by its caller with mode 600); the provision job installs the
#     public half on the relay and the relay row points at this path
#   - registers the public key in app settings (mailbox_relay_wg_public_key) via
#     plugins/mailbox/utils/relay_wg_register.php, which unlocks the relay
#     admin page's provision form
#
# Usage: sudo bash provision_relay_main.sh

set -euo pipefail

WG_IF="jyrelay0"
WG_ADDR="10.99.0.2/24"          # provision_relay.sh fixes the relay at 10.99.0.1
PRIV="/etc/wireguard/joinery_main_private.key"
PUB="/etc/wireguard/joinery_main_public.key"
PEER_HELPER="/usr/local/sbin/joinery-relay-peer"
SUDOERS_FILE="/etc/sudoers.d/joinery-relay"

if [[ ${EUID} -ne 0 ]]; then
    echo "ERROR: run as root (sudo bash $0)." >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PUBLIC_HTML="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
REGISTER_CLI="${PUBLIC_HTML}/plugins/mailbox/utils/relay_wg_register.php"

WEB_USER="www-data"
if ! id "${WEB_USER}" >/dev/null 2>&1; then
    WEB_USER="apache"
fi

if ! command -v wg >/dev/null 2>&1; then
    DEBIAN_FRONTEND=noninteractive apt-get install -y wireguard wireguard-tools
fi

# --- 1. keypair ---------------------------------------------------------------
mkdir -p /etc/wireguard
chmod 700 /etc/wireguard
if [[ ! -f "${PRIV}" ]]; then
    ( umask 077; wg genkey > "${PRIV}"; wg pubkey < "${PRIV}" > "${PUB}" )
    echo "wireguard: generated main-box keypair"
else
    # Re-derive the public half in case only the private key survived.
    ( umask 077; wg pubkey < "${PRIV}" > "${PUB}" )
fi

# --- 2. interface (dial-out only; peers are appended by the helper) -----------
if [[ ! -f "/etc/wireguard/${WG_IF}.conf" ]]; then
    cat > "/etc/wireguard/${WG_IF}.conf" <<WGCONF
[Interface]
# Main-box side of the Joinery relay tunnel. No ListenPort: this box dials out.
# Relay peers are appended by ${PEER_HELPER}.
PrivateKey = $(cat "${PRIV}")
Address = ${WG_ADDR}
WGCONF
    chmod 600 "/etc/wireguard/${WG_IF}.conf"
    echo "wireguard: wrote /etc/wireguard/${WG_IF}.conf"
else
    echo "wireguard: /etc/wireguard/${WG_IF}.conf exists - leaving it (peer edits preserved)."
fi
systemctl enable "wg-quick@${WG_IF}" >/dev/null 2>&1 || true
systemctl start "wg-quick@${WG_IF}" 2>/dev/null || true  # peerless start is fine

# --- 3. peer helper + sudoers -------------------------------------------------
# The helper is the ONLY root surface the web user gets: it validates both
# arguments against strict patterns and touches nothing but the jyrelay0 peer
# set, so job-supplied values cannot smuggle options or other commands.
cat > "${PEER_HELPER}" <<'HELPER'
#!/usr/bin/env bash
# joinery-relay-peer <wireguard-public-key> <endpoint-host:port>
# Adds (or refreshes) one Joinery relay as a WireGuard peer of jyrelay0 and
# applies the config live. Installed by provision_relay_main.sh; invoked via
# sudo by the provision job's result processor.
set -euo pipefail
WG_IF="jyrelay0"
CONF="/etc/wireguard/${WG_IF}.conf"
KEY="${1:-}"
ENDPOINT="${2:-}"
if [[ ! "${KEY}" =~ ^[A-Za-z0-9+/]{43}=$ ]]; then
    echo "joinery-relay-peer: bad public key" >&2; exit 2
fi
if [[ ! "${ENDPOINT}" =~ ^[A-Za-z0-9.-]+:[0-9]{1,5}$ ]]; then
    echo "joinery-relay-peer: bad endpoint (host:port)" >&2; exit 2
fi
if [[ ! -f "${CONF}" ]]; then
    echo "joinery-relay-peer: ${CONF} missing - run provision_relay_main.sh first" >&2; exit 3
fi
if ! grep -qF "${KEY}" "${CONF}"; then
    printf '\n[Peer]\nPublicKey = %s\nEndpoint = %s\nAllowedIPs = 10.99.0.1/32\nPersistentKeepalive = 25\n' \
        "${KEY}" "${ENDPOINT}" >> "${CONF}"
fi
systemctl enable "wg-quick@${WG_IF}" >/dev/null 2>&1 || true
if wg show "${WG_IF}" >/dev/null 2>&1; then
    wg syncconf "${WG_IF}" <(wg-quick strip "${WG_IF}")
else
    wg-quick up "${WG_IF}"
fi
echo "PEERED ${ENDPOINT}"
HELPER
chmod 755 "${PEER_HELPER}"

echo "${WEB_USER} ALL=(root) NOPASSWD: ${PEER_HELPER}" > "${SUDOERS_FILE}"
chmod 440 "${SUDOERS_FILE}"
if ! visudo -cf "${SUDOERS_FILE}" >/dev/null; then
    rm -f "${SUDOERS_FILE}"
    echo "ERROR: generated sudoers rule failed validation - removed." >&2
    exit 1
fi
echo "sudoers: ${WEB_USER} may run ${PEER_HELPER}"

# --- 4. relay pull key (the web user's own SSH identity for the tunnel) --------
# Must match RelaySsh::pullKeyPath(): {site root}/config/relay_pull_key.
PULL_KEY="$(cd "${PUBLIC_HTML}/.." && pwd)/config/relay_pull_key"
if [[ ! -f "${PULL_KEY}" ]]; then
    ( umask 077; ssh-keygen -t ed25519 -N '' -C 'joinery-relay-pull' -f "${PULL_KEY}" -q )
    echo "pull key: generated ${PULL_KEY}"
fi
chown "${WEB_USER}:${WEB_USER}" "${PULL_KEY}" "${PULL_KEY}.pub"
chmod 600 "${PULL_KEY}"
chmod 644 "${PULL_KEY}.pub"

# --- 5. register the public key in app settings --------------------------------
if [[ ! -f "${REGISTER_CLI}" ]]; then
    echo "ERROR: ${REGISTER_CLI} not found - is this script inside the app tree?" >&2
    exit 1
fi
sudo -u "${WEB_USER}" php "${REGISTER_CLI}" "$(cat "${PUB}")"

echo ""
echo "Main box relay tunnel identity is ready:"
echo "  WireGuard pubkey : $(cat "${PUB}")"
echo "  Tunnel address   : ${WG_ADDR} (interface ${WG_IF})"
echo "  Relay pull key   : ${PULL_KEY} (owner ${WEB_USER})"
echo ""
echo "Provision a relay from /plugins/mailbox/admin/admin_mailbox_relay - the"
echo "job peers both ends automatically."
