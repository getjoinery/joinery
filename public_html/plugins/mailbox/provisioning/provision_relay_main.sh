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
#   - installs /usr/local/sbin/joinery-mail-listener (off|on|status), the guarded
#     local-listener decommission switch the Setup tab's Relay section drives
#     (specs/mailbox_listener_decommission.md) — re-run this script on an
#     existing relay-fronted box to add it
#   - installs /usr/local/sbin/joinery-dkim-remove <domain>, which destroys one
#     domain's ordinary on-disk signing key once its sending is locked to a
#     vault-sealed key (specs/mailbox_relay_surface_simplification.md) — also
#     added by re-running this script
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

# --- 2. interface (dial-out only; the peer is set by the helper) --------------
if [[ ! -f "/etc/wireguard/${WG_IF}.conf" ]]; then
    cat > "/etc/wireguard/${WG_IF}.conf" <<WGCONF
[Interface]
# Main-box side of the Joinery relay tunnel. No ListenPort: this box dials out.
# The relay peer is set by ${PEER_HELPER}.
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
# Makes the named relay THE WireGuard peer of jyrelay0 and applies the config
# live, replacing whatever peer was there before. Installed by
# provision_relay_main.sh; invoked via sudo by the provision job's result
# processor.
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
# The tunnel carries exactly ONE relay peer, pinned at 10.99.0.1/32. A rebuild
# hands us a new key for that same address, so the peer set is REPLACED rather
# than added to: two peers claiming one AllowedIPs address leaves WireGuard with
# no deterministic route, and appending would strand a dead peer on every
# rotation until the config held nothing but corpses.
TMP="$(mktemp)"
trap 'rm -f "${TMP}"' EXIT
awk '/^[[:space:]]*\[Peer\]/{exit} {print}' "${CONF}" > "${TMP}"
printf '\n[Peer]\nPublicKey = %s\nEndpoint = %s\nAllowedIPs = 10.99.0.1/32\nPersistentKeepalive = 25\n' \
    "${KEY}" "${ENDPOINT}" >> "${TMP}"
install -m 600 "${TMP}" "${CONF}"
systemctl enable "wg-quick@${WG_IF}" >/dev/null 2>&1 || true
if wg show "${WG_IF}" >/dev/null 2>&1; then
    wg syncconf "${WG_IF}" <(wg-quick strip "${WG_IF}")
else
    wg-quick up "${WG_IF}"
fi
echo "PEERED ${ENDPOINT}"
HELPER
chmod 755 "${PEER_HELPER}"

# Second narrow helper: set the interface's own tunnel address. Self-hosted
# keeps the 10.99.0.2 default (the first-tenant allocation); a HOSTED fleet
# slot receives an allocated address at enrollment, and the web user applies
# it through this helper (FleetClient::applyCoordinates).
ADDR_HELPER="/usr/local/sbin/joinery-relay-addr"
cat > "${ADDR_HELPER}" <<'ADDRHELPER'
#!/usr/bin/env bash
# joinery-relay-addr <tunnel-ip>
# Sets the jyrelay0 interface Address to <tunnel-ip>/24 (the fleet-allocated
# tenant address) and re-applies the config. Installed by provision_relay_main.sh.
set -euo pipefail
WG_IF="jyrelay0"
CONF="/etc/wireguard/${WG_IF}.conf"
IP="${1:-}"
if [[ ! "${IP}" =~ ^10\.99\.0\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-4])$ ]]; then
    echo "joinery-relay-addr: bad tunnel ip" >&2; exit 2
fi
if [[ ! -f "${CONF}" ]]; then
    echo "joinery-relay-addr: ${CONF} missing - run provision_relay_main.sh first" >&2; exit 3
fi
sed -i "s#^Address = .*#Address = ${IP}/24#" "${CONF}"
if wg show "${WG_IF}" >/dev/null 2>&1; then
    wg-quick down "${WG_IF}" >/dev/null 2>&1 || true
fi
wg-quick up "${WG_IF}" >/dev/null 2>&1 || true
echo "ADDR_SET ${IP}/24"
ADDRHELPER
chmod 755 "${ADDR_HELPER}"

# Third narrow helper: the local mail listener switch
# (specs/mailbox_listener_decommission.md). Once a relay fronts the deployment
# the box's own public Postfix is dead weight with live attack surface; the
# platform's guarded Decommission action (Setup tab's Relay section) runs
# 'off', its Restore runs 'on'. Idempotent verbs, machine-readable markers.
LISTENER_HELPER="/usr/local/sbin/joinery-mail-listener"
cat > "${LISTENER_HELPER}" <<'LISTENERHELPER'
#!/usr/bin/env bash
# joinery-mail-listener off|on|status
# off:    stop+disable postfix/opendkim/opendmarc, close 25/tcp at the firewall -> LISTENER_OFF
# on:     enable+start them, reopen 25/tcp                                     -> LISTENER_ON
# status: report unit + firewall + port state                                  -> LISTENER_STATUS ...
# rspamd is deliberately untouched (deferred ingest still scores pulled mail).
# Installed by provision_relay_main.sh; invoked via sudo by the web user.
set -euo pipefail
UNITS="postfix opendkim opendmarc"
VERB="${1:-}"

unit_known() {
    systemctl list-unit-files "$1.service" --no-legend 2>/dev/null | grep -q "$1" || return 1
}

ufw_active() {
    command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "^Status: active"
}

case "${VERB}" in
    off)
        for u in ${UNITS}; do
            if unit_known "$u"; then
                systemctl disable --now "$u" >/dev/null 2>&1 || true
            fi
        done
        if ufw_active; then
            ufw delete allow 25/tcp >/dev/null 2>&1 || true
            ufw deny 25/tcp >/dev/null 2>&1 || true
        fi
        echo "LISTENER_OFF"
        ;;
    on)
        if ufw_active; then
            ufw delete deny 25/tcp >/dev/null 2>&1 || true
            ufw allow 25/tcp >/dev/null 2>&1 || true
        fi
        for u in ${UNITS}; do
            if unit_known "$u"; then
                systemctl enable --now "$u" >/dev/null 2>&1 || true
            fi
        done
        if ! systemctl is-active --quiet postfix; then
            echo "joinery-mail-listener: postfix failed to start" >&2
            exit 4
        fi
        echo "LISTENER_ON"
        ;;
    status)
        for u in ${UNITS}; do
            if unit_known "$u"; then
                echo "${u}=$(systemctl is-active "$u" 2>/dev/null || true)"
            else
                echo "${u}=absent"
            fi
        done
        if ufw_active; then
            if ufw status 2>/dev/null | grep -q "^25/tcp.*ALLOW"; then
                echo "firewall_25=open"
            else
                echo "firewall_25=closed"
            fi
        else
            echo "firewall_25=no-ufw"
        fi
        if (exec 3<>/dev/tcp/127.0.0.1/25) 2>/dev/null; then
            exec 3>&- 3<&-
            echo "port25=listening"
            echo "LISTENER_STATUS active"
        else
            echo "port25=closed"
            echo "LISTENER_STATUS off"
        fi
        ;;
    *)
        echo "usage: joinery-mail-listener off|on|status" >&2
        exit 2
        ;;
esac
LISTENERHELPER
chmod 755 "${LISTENER_HELPER}"

# Fourth narrow helper: destroy one domain's ordinary on-disk signing key
# (specs/mailbox_relay_surface_simplification.md). Once a domain's sending is
# locked to a vault-sealed key, an opendkim key left on the box is a second way
# to sign as that domain with no unlock involved. The Setup tab's Destroy action
# runs this; it never runs on a schedule.
DKIM_REMOVE_HELPER="/usr/local/sbin/joinery-dkim-remove"
cat > "${DKIM_REMOVE_HELPER}" <<'DKIMREMOVEHELPER'
#!/usr/bin/env bash
# joinery-dkim-remove <domain>
# Strips <domain> from opendkim's signing and key tables and deletes its key
# directory, then reloads opendkim.            -> DKIM_REMOVED <domain>
# Installed by provision_relay_main.sh; invoked via sudo by the web user, which
# validates the domain against the registered set before calling.
set -euo pipefail
DOMAIN="${1:-}"

# The argument reaches a path and a regex, so it is checked here too rather than
# trusted from the caller: letters, digits, dots and hyphens, no leading dot.
if [[ ! "${DOMAIN}" =~ ^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$ ]]; then
    echo "joinery-dkim-remove: refusing malformed domain" >&2
    exit 2
fi
DOMAIN="$(echo "${DOMAIN}" | tr '[:upper:]' '[:lower:]')"

SIGNING_TABLE="/etc/opendkim/signing.table"
KEY_TABLE="/etc/opendkim/key.table"
KEY_DIR="/etc/opendkim/keys/${DOMAIN}"

if [[ -f "${SIGNING_TABLE}" ]]; then
    sed -i "\#^\*@${DOMAIN}[[:space:]]#d" "${SIGNING_TABLE}"
fi
if [[ -f "${KEY_TABLE}" ]]; then
    sed -i "\#^${DOMAIN}[[:space:]]#d" "${KEY_TABLE}"
fi
if [[ -d "${KEY_DIR}" ]]; then
    rm -rf "${KEY_DIR}"
fi
if [[ -d "${KEY_DIR}" ]]; then
    echo "joinery-dkim-remove: ${KEY_DIR} still present" >&2
    exit 4
fi
systemctl reload opendkim >/dev/null 2>&1 || systemctl restart opendkim >/dev/null 2>&1 || true
echo "DKIM_REMOVED ${DOMAIN}"
DKIMREMOVEHELPER
chmod 755 "${DKIM_REMOVE_HELPER}"

{
    echo "${WEB_USER} ALL=(root) NOPASSWD: ${PEER_HELPER}"
    echo "${WEB_USER} ALL=(root) NOPASSWD: ${ADDR_HELPER}"
    echo "${WEB_USER} ALL=(root) NOPASSWD: ${LISTENER_HELPER}"
    echo "${WEB_USER} ALL=(root) NOPASSWD: ${DKIM_REMOVE_HELPER}"
} > "${SUDOERS_FILE}"
chmod 440 "${SUDOERS_FILE}"
if ! visudo -cf "${SUDOERS_FILE}" >/dev/null; then
    rm -f "${SUDOERS_FILE}"
    echo "ERROR: generated sudoers rule failed validation - removed." >&2
    exit 1
fi
echo "sudoers: ${WEB_USER} may run ${PEER_HELPER}, ${ADDR_HELPER}, ${LISTENER_HELPER} and ${DKIM_REMOVE_HELPER}"

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
