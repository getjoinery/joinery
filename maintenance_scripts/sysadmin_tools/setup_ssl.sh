#!/bin/bash
#
# setup_ssl.sh — (re-)provision the origin SSL cert for a single domain.
#
# Wraps install.sh's `provision_origin_cert` function so an operator can
# upgrade a self-signed fallback to Let's Encrypt later (after dropping a
# DNS-API credential file in place), or re-issue when something changes.
#
# Always leaves a working cert at /etc/letsencrypt/live/<domain>/.
#
# Usage:
#   sudo ./setup_ssl.sh <domain>
#
# Examples:
#   sudo ./setup_ssl.sh dev.getjoinery.com
#   sudo ./setup_ssl.sh app.example.com

set -euo pipefail

if [ "$#" -lt 1 ]; then
    echo "Usage: $0 <domain>"
    exit 1
fi

DOMAIN="$1"

if [ "$EUID" -ne 0 ]; then
    echo "ERROR: this script writes /etc/letsencrypt/ and reloads Apache. Re-run with sudo."
    exit 1
fi

# Locate install.sh relative to this script. Layout assumption:
#   maintenance_scripts/install_tools/install.sh
#   maintenance_scripts/sysadmin_tools/setup_ssl.sh
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALL_SH="${SCRIPT_DIR}/../install_tools/install.sh"

if [ ! -f "$INSTALL_SH" ]; then
    echo "ERROR: could not find install.sh at $INSTALL_SH"
    exit 1
fi

# Source install.sh — it returns early when sourced, so we get just the
# helper functions (print_*, provision_origin_cert, detect_dns_provider,
# write_self_signed_cert).
# shellcheck source=/dev/null
. "$INSTALL_SH"

provision_origin_cert "$DOMAIN"

# Reload Apache so the :443 vhost picks up whatever cert just got written.
if apache2ctl configtest > /dev/null 2>&1; then
    systemctl reload apache2 || true
    echo "Apache reloaded."
else
    echo "WARNING: apache2ctl configtest failed — review the vhost manually."
    exit 1
fi
