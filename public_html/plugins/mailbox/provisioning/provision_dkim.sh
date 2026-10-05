#!/usr/bin/env bash
#
# provision_dkim.sh - generate and wire one domain's DKIM signing key.
#
# Version: 2.1 - --remove also deletes the copy opendkim signed with, where one is still
#                on the box; a failed key generation cleans up after itself.
# Version: 2.0 - rspamd signs (mail_checking_in_rspamd.md). The key lives at
#                /etc/rspamd/dkim/<domain>/mail.{private,txt}, made by rspamadm, and
#                the list of domains rspamd signs for is written from that directory.
#                rspamd re-reads the list itself, so nothing is restarted.
# Version: 1.5 - The key path is built with ${KEY_ROOT:?}/${DOMAIN:?}, so --remove can never
#                name the whole key root.
# Version: 1.4 - Never START a stopped opendkim: both the add and remove paths
#                restart it through one guard that skips a unit systemd reports
#                as disabled. A box whose local listener is decommissioned has
#                opendkim stopped and disabled on purpose, and `systemctl
#                restart` resurrects a disabled unit - so generating or removing
#                a key there used to leave a live daemon behind and the setup
#                check reporting "recorded as decommissioned, but opendkim is
#                running".
#          1.3 - Removal mode deletes table lines in place (sed -E -i) instead
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
# install_email.sh installs rspamd, which signs what this box sends for every
# domain that has a key and nothing else. This script gives ONE domain a key:
#   - generates a 2048-bit key at /etc/rspamd/dkim/<domain>/mail.{private,txt}
#   - rewrites the list of signing domains from the key directory
#   - prints the DNS TXT record to publish at mail._domainkey.<domain>
#
# rspamd re-reads the list on its own within seconds; nothing is restarted, so
# running this on a box whose local mail listener is decommissioned starts
# nothing.
#
# Idempotent: re-running for a domain that already has a key changes nothing
# and just reprints the DNS record. An existing key is never regenerated -
# that would invalidate the already-published DNS record.
#
# Usage:  sudo bash provision_dkim.sh <domain>
#         sudo bash provision_dkim.sh --remove <domain>
#
set -euo pipefail

# --- preconditions -----------------------------------------------------------
if [[ "${EUID}" -ne 0 ]]; then
    echo "This script must run as root (writes /etc/rspamd/dkim)." >&2
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
# This value lands in file paths and the signing list - accept only a plain,
# dotted DNS domain so nothing shell-special or path-traversing slips through.
if [[ ! "${DOMAIN}" =~ ^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$ || "${DOMAIN}" != *.* ]]; then
    echo "ERROR: '${DOMAIN}' is not a valid domain name." >&2
    exit 1
fi
DOMAIN="$(printf '%s' "${DOMAIN}" | tr 'A-Z' 'a-z')"

# Where keys live, the list rspamd reads, and the helpers that write it.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=rspamd_stateless.sh
source "${SCRIPT_DIR}/rspamd_stateless.sh"

SELECTOR="${RSPAMD_DKIM_SELECTOR}"
KEY_ROOT="${RSPAMD_DKIM_DIR}"
KEY_DIR="${KEY_ROOT:?}/${DOMAIN:?}"
PRIVATE_KEY="${KEY_DIR}/${SELECTOR}.private"
TXT_FILE="${KEY_DIR}/${SELECTOR}.txt"
KEY_NAME="${SELECTOR}._domainkey.${DOMAIN}"

# The list of signing domains is whatever has a key in the directory. Written
# through a temporary file and renamed, so rspamd never reads half a list.
write_signing_map() {
    local tmp
    mkdir -p "${KEY_ROOT}"
    tmp="$(mktemp "${RSPAMD_DKIM_MAP}.joinery-XXXXXX")"
    rspamd_dkim_render_map > "${tmp}"
    chmod 644 "${tmp}"
    mv -f "${tmp}" "${RSPAMD_DKIM_MAP}"
}

# --- removal mode ------------------------------------------------------------
# Stop rspamd signing this domain: destroy the on-disk key and take the domain
# off the list. Checking arriving mail is unaffected.
if [[ "${MODE}" == "remove" ]]; then
    # A resting private key is a resting send capability. Confined to a path
    # under KEY_ROOT built from the validated domain, so no traversal is
    # possible.
    if [[ -d "${KEY_DIR}" && "${KEY_DIR}" == "${KEY_ROOT}/"* ]]; then
        rm -rf "${KEY_DIR}"
        echo "dkim: destroyed on-disk key at ${KEY_DIR}."
    else
        echo "dkim: no key for ${DOMAIN} (already absent)."
    fi
    # And the copy opendkim signed with, on a box that still has it: left
    # there, the app would read it and the next installer run copy it back.
    rspamd_dkim_forget_old_key "${DOMAIN}"
    write_signing_map
    echo "rspamd no longer signs ${DOMAIN}; checking of arriving mail is unaffected."
    exit 0
fi

# --- add mode ----------------------------------------------------------------
if ! command -v rspamadm >/dev/null 2>&1 || ! id _rspamd >/dev/null 2>&1; then
    echo "ERROR: rspamd is not installed - run install_email.sh first." >&2
    exit 1
fi

# --- 1. generate the key (only if absent) ------------------------------------
if [[ -f "${PRIVATE_KEY}" ]]; then
    echo "DKIM key already exists for ${DOMAIN} - leaving it (reprinting DNS record)."
else
    mkdir -p "${KEY_DIR}"
    # rspamadm writes the private key to -k and prints the DNS record. Both go
    # to temporary names first: a key that exists is a key that signs, so it
    # must not appear before its record does.
    if ! rspamadm dkim_keygen -b 2048 -s "${SELECTOR}" -d "${DOMAIN}" \
            -k "${PRIVATE_KEY}.new" > "${TXT_FILE}.new" 2>/dev/null \
       || [[ ! -s "${PRIVATE_KEY}.new" ]] || ! grep -q 'p=' "${TXT_FILE}.new"; then
        rm -f "${PRIVATE_KEY}.new" "${TXT_FILE}.new"
        echo "ERROR: rspamadm did not produce a key for ${DOMAIN}." >&2
        exit 1
    fi
    chmod 600 "${PRIVATE_KEY}.new"
    mv -f "${TXT_FILE}.new" "${TXT_FILE}"
    mv -f "${PRIVATE_KEY}.new" "${PRIVATE_KEY}"
    echo "dkim: generated 2048-bit key at ${PRIVATE_KEY}"
fi

# rspamd must read the private key; www-data (the Setup tab) must be able to
# traverse in and read the public mail.txt. The private key is group-readable
# by the web server: on a relay-fronted deployment the app signs in-app with
# this same key (MailboxDkimSigner::standardFilesystemSigner) — 600 would leave
# those sends silently unsigned. Asserted on every run, so a key generated
# under older permissions is corrected by re-running.
rspamd_dkim_secure_keys

# --- 2. put the domain on the list rspamd signs for ---------------------------
write_signing_map
echo "rspamd: signs for ${DOMAIN} (${RSPAMD_DKIM_MAP})"

# --- 3. assemble + print the DNS record --------------------------------------
# rspamadm writes mail.txt as a BIND fragment: the key is split across
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
