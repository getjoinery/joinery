#!/usr/bin/env bash
#VERSION 2.5 - Pin config/backup_site_key alongside the relay SSH key (the dev-mode 777
#              sweep would otherwise expose the key that opens this site's backups)
#VERSION 2.4 - Re-pin config/admin_credentials.txt to 600 root:root after the sweep, alongside the SSH key
#VERSION 2.3 - Re-pin SSH private keys to 600 after the blanket sweep (ssh refuses group-accessible keys; the relay mail pull broke on every deploy)
#
# Fix permissions for a Joinery site
#
# Usage:
#   ./fix_permissions.sh site_name [--production|--dev]
#
# Modes:
#   --production  (default) Secure permissions: 770 for dirs/files, 777 for uploads
#                 Use for ALL sites on production/staging servers (including _test sites)
#   --dev         Permissive permissions: 777 for everything
#                 Use ONLY on the single development server (e.g., joinerytest)
#
# Ownership is always set to www-data:user1
#
# Examples:
#   sudo ./fix_permissions.sh mysite              # Production mode (770)
#   sudo ./fix_permissions.sh mysite --production # Same as above
#   sudo ./fix_permissions.sh mysite --dev        # Dev mode (777)

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check for root
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}ERROR: You must run as sudo or root.${NC}"
    exit 1
fi

# Check for site name argument
if [ -z "$1" ]; then
    echo "Usage: sudo ./fix_permissions.sh site_name [--production|--dev]"
    echo ""
    echo "Modes:"
    echo "  --production  (default) Secure: 770 dirs/files, 777 uploads (prod & staging)"
    echo "  --dev         Permissive: 777 everything (dev server only)"
    exit 1
fi

SITE_NAME="$1"
MODE="production"  # Default mode

# Parse optional mode argument
if [ "$2" == "--dev" ]; then
    MODE="dev"
elif [ "$2" == "--production" ]; then
    MODE="production"
elif [ -n "$2" ]; then
    echo -e "${RED}ERROR: Unknown option '$2'. Use --production or --dev.${NC}"
    exit 1
fi

# Verify site exists
SITE_ROOT="/var/www/html/$SITE_NAME"
if [ ! -d "$SITE_ROOT" ]; then
    echo -e "${RED}ERROR: Site directory $SITE_ROOT does not exist.${NC}"
    exit 1
fi

echo -e "${GREEN}Fixing permissions for $SITE_NAME (mode: $MODE)${NC}"

# Set ownership: www-data (web server) as owner, user1 (developer) as group
echo "  Setting ownership to www-data:user1..."
chown -R www-data:user1 "$SITE_ROOT"

if [ "$MODE" == "production" ]; then
    # Production mode: 770 (owner+group full access, others nothing)
    echo "  Setting permissions to 770 (secure)..."
    chmod -R 770 "$SITE_ROOT"

    # Uploads: www-data (owner) and user1 (group) can write; others cannot
    if [ -d "$SITE_ROOT/uploads" ]; then
        echo "  Setting uploads to 770..."
        chmod -R 770 "$SITE_ROOT/uploads"
    fi

    # Storage: durable runtime data (offloaded inbound-mail raw .eml) — same
    # writable treatment as uploads; never web-served.
    if [ -d "$SITE_ROOT/storage" ]; then
        echo "  Setting storage to 770..."
        chmod -R 770 "$SITE_ROOT/storage"
    fi
else
    # Dev mode: 777 (everyone full access) - for development server only
    echo "  Setting permissions to 777 (dev mode)..."
    chmod -R 777 "$SITE_ROOT"
fi

# SSH private keys demand 0600 and caller-only ownership — the blanket sweep
# above would make ssh refuse them, silently breaking the relay mail pull on
# every deploy. Re-pin them last, in both modes.
#
# config/backup_site_key rides along for a different reason: it opens this
# site's own backups, so the dev-mode 777 sweep above would hand every backup
# this site ever made to anyone with a shell on the box.
for keyfile in "$SITE_ROOT/config/relay_pull_key" "$SITE_ROOT/config/backup_site_key"; do
    if [ -f "$keyfile" ]; then
        echo "  Pinning key $keyfile to 600 www-data:www-data..."
        chown www-data:www-data "$keyfile"
        chmod 600 "$keyfile"
    fi
done

# The install-time admin password, for whoever can already reach the server as
# root. The sweep above would hand it to the web server user and, in dev mode,
# to everyone — so re-pin it last, in both modes, same as the SSH key.
CRED_FILE="$SITE_ROOT/config/admin_credentials.txt"
if [ -f "$CRED_FILE" ]; then
    echo "  Pinning $CRED_FILE to 600 root:root..."
    chown root:root "$CRED_FILE" 2>/dev/null || true
    chmod 600 "$CRED_FILE"
fi

echo -e "${GREEN}Done. Permissions fixed for $SITE_NAME.${NC}"
