#!/usr/bin/env bash
#
# provision_dkim.sh - generate and wire one domain's DKIM signing key.
#
# Version: 1.3 - Removal mode deletes table lines in place (sed -E -i) instead
#                of a grep -v pipeline: under set -e, grep -v exits 1 when it
#                selects zero lines, which aborted --remove on any box where the
#                target domain was the only table entry (the common single-domain
#                case), leaving opendkim still signing the domain.
#          1.2 - Add a removal mode: `provision_dkim.sh --remove <domain>` strips
#                the domain's signing.table and key.table lines and destroys its
#                on-disk key, then restarts opendkim (verify duty is untouched).
#                The outbound-send-protection cutover calls this so opendkim stops
#                signing a protected domain, leaving the in-app per-send signer as
#                the sole signer (specs/mailbox_outbound_send_protection.md,
#                Phase 4). A resting on-disk key is itself a resting send
#                capability, so it is deleted, not merely unwired.
#                1.1 - Assemble the DNS record into ONE unbroken line before
#                printing it, instead of dumping opendkim-genkey's raw
#                multi-line quoted BIND fragment - that was error-prone to
#                copy out of a terminal.
#                1.0 - spec inbound_email_guided_setup: collapses the three
#                manual DKIM steps (opendkim-genkey, two table edits, reload)
#                into a single idempotent command the Setup tab hands the
#                operator.
#
# install_email.sh installs opendkim with empty key/signing tables and runs it
# keyless. This script adds ONE domain's signing key on top of that base:
#   - generates a 2048-bit key at /etc/opendkim/keys/<domain>/mail.{private,txt}
#   - appends the key.table / signing.table lines (only if absent)
#   - restarts opendkim so it picks the new key up
#   - prints the DNS TXT record to publish at mail._domainkey.<domain>
#
# Idempotent: re-running for a domain that already has a key changes nothing
# and just reprints the DNS record. An existing key is never regenerated -
# that would invalidate the already-published DNS record.
#
# Usage:  sudo bash provision_dkim.sh <domain>
#         sudo bash provision_dkim.sh --remove <domain>
#
set -euo pipefail

SELECTOR="mail"

# --- preconditions -----------------------------------------------------------
if [[ "${EUID}" -ne 0 ]]; then
    echo "This script must run as root (writes /etc/opendkim, restarts opendkim)." >&2
    echo "Re-run with: sudo bash $0 $*" >&2
    exit 1
fi

# Mode: `--remove <domain>` strips signing; a bare `<domain>` provisions signing.
MODE="add"
if [[ "${1:-}" == "--remove" ]]; then
    MODE="remove"
    DOMAIN="${2:-}"
else
    DOMAIN="${1:-}"
fi

if [[ -z "${DOMAIN}" ]]; then
    echo "Usage: sudo bash $0 <domain>" >&2
    echo "       sudo bash $0 --remove <domain>" >&2
    exit 1
fi
# This value lands in file paths and opendkim config - accept only a plain,
# dotted DNS domain so nothing shell-special or path-traversing slips through.
if [[ ! "${DOMAIN}" =~ ^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$ || "${DOMAIN}" != *.* ]]; then
    echo "ERROR: '${DOMAIN}' is not a valid domain name." >&2
    exit 1
fi
DOMAIN="$(printf '%s' "${DOMAIN}" | tr 'A-Z' 'a-z')"

KEY_ROOT="/etc/opendkim/keys"
KEY_DIR="${KEY_ROOT}/${DOMAIN}"
PRIVATE_KEY="${KEY_DIR}/${SELECTOR}.private"
TXT_FILE="${KEY_DIR}/${SELECTOR}.txt"
KEY_TABLE="/etc/opendkim/key.table"
SIGNING_TABLE="/etc/opendkim/signing.table"
KEY_NAME="${SELECTOR}._domainkey.${DOMAIN}"

restart_opendkim() {
    if command -v systemctl >/dev/null 2>&1 && systemctl restart opendkim 2>/dev/null; then
        echo "opendkim: restarted (systemd)."
    elif command -v service >/dev/null 2>&1 && service opendkim restart >/dev/null 2>&1; then
        echo "opendkim: restarted (service)."
    else
        echo "WARNING: could not restart opendkim automatically - restart it manually." >&2
    fi
}

# --- removal mode ------------------------------------------------------------
# Stop opendkim signing this domain: drop its signing.table + key.table lines
# and destroy the on-disk key. opendkim keeps VERIFY duty for inbound (Mode sv
# in opendkim.conf is untouched); only this domain's SIGNING entry is removed.
if [[ "${MODE}" == "remove" ]]; then
    # Escape dots so the domain matches literally in the ERE below.
    DOMAIN_RE="${DOMAIN//./\\.}"
    removed_any=0

    # signing.table line shape: `*@<domain> <selector>._domainkey.<domain>`.
    # Anchor on `@<domain>` followed by whitespace so `example.com` never also
    # strips `mail.example.com`.
    if [[ -f "${SIGNING_TABLE}" ]] && grep -qE "@${DOMAIN_RE}[[:space:]]" "${SIGNING_TABLE}"; then
        # In-place delete: exits 0 even when every line matches, unlike a
        # grep -v pipeline, which exits 1 and dies under set -e when the domain
        # is the table's only entry.
        sed -E -i "/@${DOMAIN_RE}[[:space:]]/d" "${SIGNING_TABLE}"
        echo "signing.table: removed *@${DOMAIN}"
        removed_any=1
    else
        echo "signing.table: no entry for ${DOMAIN} (already absent)."
    fi

    # key.table line shape: `<selector>._domainkey.<domain> <domain>:<sel>:<path>`.
    if [[ -f "${KEY_TABLE}" ]] && grep -qE "\._domainkey\.${DOMAIN_RE}[[:space:]]" "${KEY_TABLE}"; then
        sed -E -i "/\._domainkey\.${DOMAIN_RE}[[:space:]]/d" "${KEY_TABLE}"
        echo "key.table: removed ${DOMAIN} entry."
        removed_any=1
    else
        echo "key.table: no entry for ${DOMAIN} (already absent)."
    fi

    # Destroy the on-disk key - a resting private key is a resting send
    # capability. Confined to a path under KEY_ROOT built from the validated
    # domain, so no traversal is possible.
    if [[ -d "${KEY_DIR}" && "${KEY_DIR}" == "${KEY_ROOT}/"* ]]; then
        rm -rf "${KEY_DIR}"
        echo "opendkim: destroyed on-disk key at ${KEY_DIR}."
        removed_any=1
    fi

    if [[ "${removed_any}" -eq 1 ]]; then
        restart_opendkim
    fi
    echo "opendkim no longer signs ${DOMAIN}; inbound verify is unaffected."
    exit 0
fi

# --- add mode ----------------------------------------------------------------
if ! command -v opendkim-genkey >/dev/null 2>&1; then
    echo "ERROR: opendkim-genkey not found - run install_email.sh first." >&2
    exit 1
fi

# --- 1. generate the key (only if absent) ------------------------------------
if [[ -f "${PRIVATE_KEY}" ]]; then
    echo "DKIM key already exists for ${DOMAIN} - leaving it (reprinting DNS record)."
else
    mkdir -p "${KEY_DIR}"
    # opendkim-genkey writes <selector>.private and <selector>.txt into -D.
    opendkim-genkey -b 2048 -s "${SELECTOR}" -d "${DOMAIN}" -D "${KEY_DIR}"
    chown -R opendkim:opendkim "${KEY_DIR}"
    # opendkim must read the private key; www-data (the Setup tab) must be able
    # to traverse in and read the public mail.txt. The private key stays 600.
    chmod 755 "${KEY_ROOT}" "${KEY_DIR}"
    chmod 600 "${PRIVATE_KEY}"
    chmod 644 "${TXT_FILE}"
    echo "opendkim: generated 2048-bit key at ${PRIVATE_KEY}"
fi

# --- 2. wire key.table / signing.table (append once) -------------------------
KEY_LINE="${KEY_NAME} ${DOMAIN}:${SELECTOR}:${PRIVATE_KEY}"
if grep -qF "${KEY_LINE}" "${KEY_TABLE}" 2>/dev/null; then
    echo "key.table: entry for ${DOMAIN} already present."
else
    echo "${KEY_LINE}" >> "${KEY_TABLE}"
    echo "key.table: added ${KEY_NAME}"
fi

SIGNING_LINE="*@${DOMAIN} ${KEY_NAME}"
if grep -qF "${SIGNING_LINE}" "${SIGNING_TABLE}" 2>/dev/null; then
    echo "signing.table: entry for ${DOMAIN} already present."
else
    echo "${SIGNING_LINE}" >> "${SIGNING_TABLE}"
    echo "signing.table: added *@${DOMAIN}"
fi

# --- 3. restart opendkim so it loads the new key -----------------------------
if command -v systemctl >/dev/null 2>&1 && systemctl restart opendkim 2>/dev/null; then
    echo "opendkim: restarted (systemd)."
elif command -v service >/dev/null 2>&1 && service opendkim restart >/dev/null 2>&1; then
    echo "opendkim: restarted (service)."
else
    echo "WARNING: could not restart opendkim automatically - restart it manually." >&2
fi

# --- 4. assemble + print the DNS record --------------------------------------
# opendkim-genkey writes mail.txt as a BIND fragment: the key is split across
# several double-quoted strings over multiple lines, wrapped in parentheses,
# with a trailing comment. A DNS provider wants the value as ONE unbroken
# string, so concatenate every quoted segment with the quotes and newlines
# stripped - the same assembly the Setup tab's readDkimKey() performs.
RECORD_VALUE="$(grep -oP '"[^"]*"' "${TXT_FILE}" | tr -d '"\n')"

echo
if [[ -z "${RECORD_VALUE}" ]]; then
    echo "Key installed, but ${TXT_FILE} could not be parsed automatically." >&2
    echo "Open the Setup tab and press Re-check - it shows the DNS record to publish." >&2
    exit 0
fi
echo "DKIM key is installed. Publish this DNS TXT record:"
echo
echo "  Name: ${KEY_NAME}"
echo "  Type: TXT"
echo
echo "Value (one line - copy exactly, no surrounding quotes):"
echo "${RECORD_VALUE}"
echo
echo "Then press Re-check on the Setup tab - it also shows this record with a"
echo "copy button once the key is detected."
