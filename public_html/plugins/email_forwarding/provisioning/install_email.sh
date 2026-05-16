#!/usr/bin/env bash
#
# install_email.sh - idempotent host installer for the Email Forwarding plugin.
#
# Installs the mail software the plugin needs to RECEIVE inbound mail: Postfix
# (the MTA that listens on port 25 and pipes mail to the forwarder script) and
# opendkim (for outbound DKIM signing). Re-running it is safe: already-installed
# packages are left untouched and no existing Postfix configuration is rewritten.
#
# This script installs software only. Per-domain Postfix and DNS configuration
# is described in plugins/email_forwarding/docs/overview.md and is done by hand
# after this runs.
#
# Usage:  sudo bash install_email.sh
#
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
    echo "This script must run as root (installs system packages)." >&2
    echo "Re-run with: sudo bash $0" >&2
    exit 1
fi

if ! command -v apt-get >/dev/null 2>&1; then
    echo "This installer supports apt-based systems (Debian/Ubuntu) only." >&2
    echo "Install 'postfix' and 'opendkim' with your platform's package manager instead." >&2
    exit 1
fi

PACKAGES=(postfix opendkim opendkim-tools)
MISSING=()
for pkg in "${PACKAGES[@]}"; do
    if dpkg -s "${pkg}" >/dev/null 2>&1; then
        echo "Already installed: ${pkg}"
    else
        MISSING+=("${pkg}")
    fi
done

if [[ ${#MISSING[@]} -eq 0 ]]; then
    echo "All mail packages are already installed."
else
    echo "Installing: ${MISSING[*]}"
    # Non-interactive so the Postfix configuration prompt does not block.
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y "${MISSING[@]}"
fi

# Ensure Postfix is enabled and running (idempotent).
if command -v systemctl >/dev/null 2>&1; then
    systemctl enable postfix >/dev/null 2>&1 || true
    systemctl start postfix >/dev/null 2>&1 || true
    if systemctl is-active --quiet postfix; then
        echo "Postfix is running."
    else
        echo "WARNING: Postfix is installed but not active — check 'systemctl status postfix'." >&2
    fi
fi

echo
echo "Mail software installed."
echo "Next: configure Postfix per-domain (virtual_transport, the 'joinery'"
echo "pipe transport, and DNS records). See:"
echo "  plugins/email_forwarding/docs/overview.md"
