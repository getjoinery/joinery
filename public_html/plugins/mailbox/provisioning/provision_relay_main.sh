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
#   - /usr/local/sbin/joinery-dkim-add <domain>, which gives one domain a fresh
#     ordinary signing key when it has none, so switching send protection off
#     leaves something signing (specs/send_protection_one_press.md WP2)
#
# Version: 3.5 - joinery-dkim-add: a fresh ordinary key for a domain whose key was destroyed.
# Version: 3.4 - both helpers start #!/bin/bash and set their own PATH, so a sudoers file without
#                secure_path cannot hand root a tool from the caller's path (shell_command_audit D8).
# Version: 3.3 - rspamd is the only mail checker (mail_checking_in_rspamd.md):
#                joinery-mail-listener switches postfix alone, and joinery-dkim-remove
#                deletes the key under /etc/rspamd/dkim and rewrites rspamd's list of
#                signing domains. It also deletes the copy opendkim signed with.
# Version: 3.2 - joinery-dkim-remove builds its key path with ${DOMAIN:?}, so an empty
#                domain can never name the whole key directory.
# Version: 3.1 - joinery-dkim-remove no longer starts a stopped opendkim: its
#                reload fell through to `systemctl restart`, which starts a unit
#                the listener decommission had disabled, so removing a signing
#                key on a decommissioned box left opendkim running.
#          3.0 - the ssh era is over: no WireGuard keypair, no jyrelay0 interface,
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
#!/bin/bash
# Its own search path, the one stock sudo hands it (secure_path): the web user
# runs this as root, so no tool it calls by name is looked up where the caller says.
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/snap/bin; export PATH
# joinery-mail-listener off|on|status
# off:    stop+disable postfix, close 25/tcp at the firewall -> LISTENER_OFF
# on:     enable+start it, reopen 25/tcp                     -> LISTENER_ON
# status: report unit + firewall + port state                -> LISTENER_STATUS ...
# rspamd is deliberately untouched: with Postfix stopped nothing hands it mail.
# Installed by provision_relay_main.sh; invoked via sudo by the web user.
set -euo pipefail
UNITS="postfix"
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
#!/bin/bash
# Its own search path, the one stock sudo hands it (secure_path): the web user
# runs this as root, so no tool it calls by name is looked up where the caller says.
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/snap/bin; export PATH
# joinery-dkim-remove <domain>
# Deletes <domain>'s signing key directory and rewrites the list of domains
# rspamd signs for, which is whatever still has a key (the same list
# provision_dkim.sh writes). rspamd re-reads it on its own; nothing is restarted.
#                                              -> DKIM_REMOVED <domain>
# Installed by provision_relay_main.sh; invoked via sudo by the web user, which
# validates the domain against the registered set before calling.
set -euo pipefail
DOMAIN="${1:-}"

# The argument reaches a path, so it is checked here too rather than trusted
# from the caller: letters, digits, dots and hyphens, no leading dot.
if [[ ! "${DOMAIN}" =~ ^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$ ]]; then
    echo "joinery-dkim-remove: refusing malformed domain" >&2
    exit 2
fi
DOMAIN="$(echo "${DOMAIN}" | tr '[:upper:]' '[:lower:]')"

KEY_ROOT="/etc/rspamd/dkim"
KEY_DIR="${KEY_ROOT:?}/${DOMAIN:?}"
SIGNING_MAP="${KEY_ROOT}/signing.map"

if [[ -d "${KEY_DIR}" ]]; then
    rm -rf "${KEY_DIR}"
fi
# The copy opendkim signed with, on a box that still has it: left there, the
# app would read it and the next installer run copy it back.
OLD_KEY_ROOT="/etc/opendkim/keys"
if [[ -d "${OLD_KEY_ROOT}/${DOMAIN}" ]]; then
    rm -rf "${OLD_KEY_ROOT:?}/${DOMAIN:?}"
fi
if [[ -d "${KEY_DIR}" ]]; then
    echo "joinery-dkim-remove: ${KEY_DIR} still present" >&2
    exit 4
fi
if [[ -d "${KEY_ROOT}" ]]; then
    MAP_TMP="$(mktemp "${SIGNING_MAP}.joinery-XXXXXX")"
    for key in "${KEY_ROOT}"/*/mail.private; do
        [[ -f "${key}" ]] || continue
        printf '%s %s\n' "$(basename "$(dirname "${key}")")" "${key}"
    done | LC_ALL=C sort > "${MAP_TMP}"
    chmod 644 "${MAP_TMP}"
    mv -f "${MAP_TMP}" "${SIGNING_MAP}"
fi
echo "DKIM_REMOVED ${DOMAIN}"
DKIMREMOVEHELPER
chmod 755 "${DKIM_REMOVE_HELPER}"

# --- 2b. DKIM key, put back ---------------------------------------------------------
# Switching send protection off after the ordinary key was destroyed leaves
# nothing signing the domain with a key of its own. This puts one back, in the
# layout provision_dkim.sh makes, and the Setup tab prescribes its record.
DKIM_ADD_HELPER="/usr/local/sbin/joinery-dkim-add"
cat > "${DKIM_ADD_HELPER}" <<'DKIMADDHELPER'
#!/bin/bash
# Its own search path, the one stock sudo hands it (secure_path): the web user
# runs this as root, so no tool it calls by name is looked up where the caller says.
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/snap/bin; export PATH
# joinery-dkim-add <domain>
# Gives <domain> an ordinary signing key when it has none: a 2048-bit RSA key at
# /etc/rspamd/dkim/<domain>/mail.private with its DNS record beside it in
# mail.txt, then rewrites the list of domains rspamd signs for. A key already
# there is kept, since its record may be published. Nothing is restarted.
#                                              -> DKIM_ADDED <domain>
# Installed by provision_relay_main.sh; invoked via sudo by the web user, which
# validates the domain against the registered set before calling.
set -euo pipefail
DOMAIN="${1:-}"

# The argument reaches a path, so it is checked here too rather than trusted
# from the caller: letters, digits, dots and hyphens, no leading dot, one dot at least.
if [[ ! "${DOMAIN}" =~ ^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$ || "${DOMAIN}" != *.* ]]; then
    echo "joinery-dkim-add: refusing malformed domain" >&2
    exit 2
fi
DOMAIN="$(echo "${DOMAIN}" | tr '[:upper:]' '[:lower:]')"

KEY_ROOT="/etc/rspamd/dkim"
KEY_DIR="${KEY_ROOT:?}/${DOMAIN:?}"
PRIVATE_KEY="${KEY_DIR}/mail.private"
TXT_FILE="${KEY_DIR}/mail.txt"
SIGNING_MAP="${KEY_ROOT}/signing.map"
# rspamd reads the key; the web server's group reads it too, because the app
# signs with it where a relay fronts the box (MailboxDkimSigner).
WEB_GROUP="www-data"
getent group "${WEB_GROUP}" >/dev/null 2>&1 || WEB_GROUP="apache"
KEY_OWNER="_rspamd"
id "${KEY_OWNER}" >/dev/null 2>&1 || KEY_OWNER="root"

mkdir -p "${KEY_DIR}"
chown root:root "${KEY_ROOT}" "${KEY_DIR}"
chmod 755 "${KEY_ROOT}" "${KEY_DIR}"
if [[ ! -f "${PRIVATE_KEY}" ]]; then
    # Made under a name the signing list never reads, and renamed in only after
    # its record is written: a key that exists is a key that signs.
    NEW_KEY="$(mktemp "${KEY_DIR}/.mail.private.XXXXXX")"
    trap 'rm -f "${NEW_KEY:-}" "${TXT_FILE}.new"' EXIT
    if ! openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "${NEW_KEY}" 2>/dev/null; then
        echo "joinery-dkim-add: openssl did not make a key" >&2
        exit 3
    fi
    PUBLIC="$(openssl pkey -in "${NEW_KEY}" -pubout -outform DER 2>/dev/null | base64 -w0)"
    if [[ -z "${PUBLIC}" ]]; then
        echo "joinery-dkim-add: could not read the new key's public half" >&2
        exit 3
    fi
    printf 'mail._domainkey IN TXT ( "v=DKIM1; k=rsa; p=%s" ) ;\n' "${PUBLIC}" > "${TXT_FILE}.new"
    chown root:root "${TXT_FILE}.new"
    chmod 644 "${TXT_FILE}.new"
    mv -f "${TXT_FILE}.new" "${TXT_FILE}"
    chown "${KEY_OWNER}:${WEB_GROUP}" "${NEW_KEY}"
    chmod 640 "${NEW_KEY}"
    mv -f "${NEW_KEY}" "${PRIVATE_KEY}"
    trap - EXIT
fi
if [[ ! -s "${TXT_FILE}" ]]; then
    echo "joinery-dkim-add: ${PRIVATE_KEY} has no record file beside it" >&2
    exit 4
fi
MAP_TMP="$(mktemp "${SIGNING_MAP}.joinery-XXXXXX")"
for key in "${KEY_ROOT}"/*/mail.private; do
    [[ -f "${key}" ]] || continue
    printf '%s %s\n' "$(basename "$(dirname "${key}")")" "${key}"
done | LC_ALL=C sort > "${MAP_TMP}"
chmod 644 "${MAP_TMP}"
mv -f "${MAP_TMP}" "${SIGNING_MAP}"
echo "DKIM_ADDED ${DOMAIN}"
DKIMADDHELPER
chmod 755 "${DKIM_ADD_HELPER}"

# --- 3. sudoers: the web user may run exactly these three helpers ---------------
# Validated before it is installed: a rule that fails visudo must never sit in
# /etc/sudoers.d at all - sudo reads the directory, and a broken file there
# breaks sudo for everyone. sudo ignores names containing a dot, so the staging
# file is inert while it exists.
SUDOERS_TMP="$(mktemp /etc/sudoers.d/.joinery-relay.XXXXXX)"
{
    echo "# joinery-managed - the main box's relay helpers (provision_relay_main.sh)"
    echo "${WEB_USER} ALL=(root) NOPASSWD: ${LISTENER_HELPER} off, ${LISTENER_HELPER} on, ${LISTENER_HELPER} status"
    echo "${WEB_USER} ALL=(root) NOPASSWD: ${DKIM_REMOVE_HELPER} *"
    echo "${WEB_USER} ALL=(root) NOPASSWD: ${DKIM_ADD_HELPER} *"
} > "${SUDOERS_TMP}"
chmod 440 "${SUDOERS_TMP}"
if ! visudo -cf "${SUDOERS_TMP}" >/dev/null; then
    rm -f "${SUDOERS_TMP}"
    echo "ERROR: generated sudoers rule failed validation - not installed." >&2
    exit 1
fi
mv -f "${SUDOERS_TMP}" "${SUDOERS_FILE}"
chmod 440 "${SUDOERS_FILE}"
echo "sudoers: ${WEB_USER} may run ${LISTENER_HELPER}, ${DKIM_REMOVE_HELPER} and ${DKIM_ADD_HELPER}"

echo ""
echo "Main box relay helpers are ready. Create a relay from the mailbox Setup tab's"
echo "Relay section; the relay is born from first-boot user-data and reports in over HTTPS."
