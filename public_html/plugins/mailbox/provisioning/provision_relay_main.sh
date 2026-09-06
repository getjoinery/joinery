#!/usr/bin/env bash
#
# provision_relay_main.sh - the MAIN BOX half of a relay-fronted deployment
# (specs/relay_without_a_shell.md). The relay itself is born from first-boot
# user-data and reached only through its own HTTPS API, so the main box needs no
# tunnel, no key and no identity for it: everything the plane holds for a relay
# lives in the database (the relay client identity, the relay's pin). What the
# main box DOES need are two narrow root helpers the Setup tab drives through
# the web user, installed here with their sudoers rule. One-time root bootstrap,
# idempotent:
#
#   - /usr/local/sbin/joinery-mail-listener (off|on|status), the guarded
#     local-listener decommission switch the Setup tab's Relay section drives
#     (specs/mailbox_listener_decommission.md)
#   - /usr/local/sbin/joinery-dkim-remove <domain>, which destroys one domain's
#     ordinary on-disk signing key once its sending is locked to a vault-sealed
#     key (specs/mailbox_relay_surface_simplification.md)
#
# Version: 3.0 - the ssh era is over: no WireGuard keypair, no jyrelay0 interface,
#                no peer or address helper, no relay pull key, no registered tunnel
#                key. A box that has them from an earlier run keeps them until the
#                owner removes them as root (specs/relay_without_a_shell.md,
#                cutover step 3).
#
# Usage: sudo bash provision_relay_main.sh
set -euo pipefail

SUDOERS_FILE="/etc/sudoers.d/joinery-relay"

if [[ ${EUID} -ne 0 ]]; then
    echo "ERROR: run as root (sudo bash $0)." >&2
    exit 1
fi

WEB_USER="www-data"
if ! id "${WEB_USER}" >/dev/null 2>&1; then
    WEB_USER="apache"
fi

# --- 1. local mail listener switch ---------------------------------------------
# The Setup tab's Relay section decommissions and restores this box's own mail
# listener through it (specs/mailbox_listener_decommission.md). Its Decommission
# runs 'off', its Restore runs 'on'. Idempotent verbs, machine-readable markers.
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

# --- 2. DKIM key removal -----------------------------------------------------------
# Once a domain's sending is locked to a vault-sealed key, its ordinary on-disk
# signing key is destroyed through this helper
# (specs/mailbox_relay_surface_simplification.md).
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

# --- 3. sudoers: the web user may run exactly these two helpers -----------------
# Validated before it is installed: a rule that fails visudo must never sit in
# /etc/sudoers.d at all - sudo reads the directory, and a broken file there
# breaks sudo for everyone. sudo ignores names containing a dot, so the staging
# file is inert while it exists.
SUDOERS_TMP="$(mktemp /etc/sudoers.d/.joinery-relay.XXXXXX)"
{
    echo "# joinery-managed - the main box's relay helpers (provision_relay_main.sh)"
    echo "${WEB_USER} ALL=(root) NOPASSWD: ${LISTENER_HELPER} off, ${LISTENER_HELPER} on, ${LISTENER_HELPER} status"
    echo "${WEB_USER} ALL=(root) NOPASSWD: ${DKIM_REMOVE_HELPER} *"
} > "${SUDOERS_TMP}"
chmod 440 "${SUDOERS_TMP}"
if ! visudo -cf "${SUDOERS_TMP}" >/dev/null; then
    rm -f "${SUDOERS_TMP}"
    echo "ERROR: generated sudoers rule failed validation - not installed." >&2
    exit 1
fi
mv -f "${SUDOERS_TMP}" "${SUDOERS_FILE}"
chmod 440 "${SUDOERS_FILE}"
echo "sudoers: ${WEB_USER} may run ${LISTENER_HELPER} and ${DKIM_REMOVE_HELPER}"

echo ""
echo "Main box relay helpers are ready. Create a relay from the mailbox Setup tab's"
echo "Relay section; the relay is born from first-boot user-data and reports in over HTTPS."
