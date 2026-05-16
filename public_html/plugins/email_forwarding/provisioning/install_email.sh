#!/usr/bin/env bash
#
# install_email.sh - host installer + base configurator for Email Forwarding.
#
# Installs the mail software the plugin needs and applies the FIXED Postfix
# configuration so inbound mail is piped to the forwarder. Fully idempotent:
# re-running adds nothing twice and is safe.
#
# What it configures (fixed, deployment-independent):
#   - Installs postfix, opendkim, opendkim-tools.
#   - master.cf : the `joinery` pipe transport (appended once).
#   - main.cf   : virtual_transport = joinery
#   - main.cf   : mydestination = localhost, localhost.localdomain
#                 (forwarding domains must NOT appear here, or Postfix rejects
#                  them with "User unknown in local recipient table").
#   - Opens port 25 if ufw is active.
#
# What it does NOT do (genuinely per-deployment - handled elsewhere):
#   - virtual_mailbox_domains: the list of forwarding domains changes over
#     time and is managed per domain under Admin > Emails > Incoming > Domains.
#   - DNS records (MX, SPF, DKIM).
#   - Per-domain opendkim DKIM keys (see the plugin overview doc).
#
# Usage:  sudo bash install_email.sh
#
set -euo pipefail

# --- preconditions -----------------------------------------------------------
if [[ "${EUID}" -ne 0 ]]; then
    echo "This script must run as root (installs packages, edits /etc/postfix)." >&2
    echo "Re-run with: sudo bash $0" >&2
    exit 1
fi

if ! command -v apt-get >/dev/null 2>&1; then
    echo "This installer supports apt-based systems (Debian/Ubuntu) only." >&2
    echo "Install postfix and opendkim with your platform's package manager instead." >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PIPE_SCRIPT="$(cd "${SCRIPT_DIR}/.." && pwd)/utils/email_forwarder.php"
if [[ ! -f "${PIPE_SCRIPT}" ]]; then
    echo "ERROR: forwarder script not found at ${PIPE_SCRIPT}" >&2
    echo "Run this script from inside the email_forwarding plugin directory." >&2
    exit 1
fi

# --- 1. install packages -----------------------------------------------------
PACKAGES=(postfix opendkim opendkim-tools)
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
    # Non-interactive so the Postfix configuration prompt does not block.
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y "${MISSING[@]}"
else
    echo "All mail packages already installed."
fi

systemctl enable postfix >/dev/null 2>&1 || true
systemctl start postfix  >/dev/null 2>&1 || true

# --- 2. master.cf: joinery pipe transport (append once) ----------------------
# Append-once guard: only add the service if no `joinery` service already
# exists, so repeated runs never accumulate duplicate blocks.
if postconf -M 2>/dev/null | grep -q '^joinery'; then
    echo "master.cf: joinery transport already defined - leaving it."
else
    printf '\njoinery   unix  -       n       n       -       5       pipe\n  flags=DRhu user=www-data\n  argv=/usr/bin/php %s ${recipient}\n' \
        "${PIPE_SCRIPT}" >> /etc/postfix/master.cf
    echo "master.cf: added joinery pipe transport -> ${PIPE_SCRIPT}"
fi

# --- 3. main.cf: fixed settings (postconf -e is idempotent) ------------------
postconf -e "virtual_transport = joinery"
echo "main.cf: virtual_transport = joinery"

SAFE_MYDEST="localhost, localhost.localdomain"
CURRENT_MYDEST="$(postconf -h mydestination 2>/dev/null || true)"
if [[ "${CURRENT_MYDEST}" == "${SAFE_MYDEST}" ]]; then
    echo "main.cf: mydestination already safe."
else
    echo "main.cf: mydestination was '${CURRENT_MYDEST}'"
    postconf -e "mydestination = ${SAFE_MYDEST}"
    echo "main.cf: mydestination = ${SAFE_MYDEST}"
fi

# --- 4. firewall -------------------------------------------------------------
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
    ufw allow 25/tcp >/dev/null 2>&1 || true
    echo "firewall: ufw allow 25/tcp"
fi

# --- 5. validate + reload ----------------------------------------------------
if postfix check; then
    systemctl reload-or-restart postfix
    echo "Postfix configuration validated and reloaded."
else
    echo "WARNING: 'postfix check' reported problems - NOT reloading. Review above." >&2
    exit 1
fi

# --- summary -----------------------------------------------------------------
echo
echo "Base mail setup complete. Still required, per deployment:"
echo "  - Add each forwarding domain under Admin > Emails > Incoming > Domains."
echo "    That registers the domain and shows the virtual_mailbox_domains line"
echo "    and DNS records to apply for it."
echo "  - Publish DNS: MX -> this server, plus SPF and DKIM TXT records."
echo "  - Generate per-domain opendkim keys for outbound DKIM signing."
echo "    See plugins/email_forwarding/docs/overview.md"
