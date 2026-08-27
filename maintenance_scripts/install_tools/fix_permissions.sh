#!/usr/bin/env bash
#VERSION 2.9 - node_exec.php is retired (A1), so the second reader of the provisioning
#              key is gone. The 640 pin is UNCHANGED here on purpose — tightening a
#              live fleet key is a deliberate act, not a side effect of deleting a
#              script — but the reason for the group bit is now one caller short.
#              See the pin's comment.
#VERSION 2.8 - Pin config/agent_signing_key (the fleet trust root — whoever reads it can
#              sign agent releases every node installs as root) to 600 user1:user1, and
#              config/provisioning_key (the fleet SSH key) to 640 www-data:user1.
#              The sweep left both open; the signing key was found 640 group-www-data.
#VERSION 2.7 - Guarantee cache/static_pages exists before the sweep, so page
#              caching is on after every permissions run. It sits under a
#              Docker named volume, is created at run time, and a root-run PHP
#              process getting there first left it unwritable by www-data —
#              StaticPageCache then logged "caching disabled" on every request.
#VERSION 2.6 - Pin config/backup_site_key to 640 www-data:www-data so the deploy account
#              can run a backup from a shell; 600 locked out every caller but the web user
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

# The page cache the code reads is {site root}/cache/static_pages. Create it
# before the sweep so the ownership and mode fixes below always cover it —
# a missing or root-owned cache dir silently disables page caching.
mkdir -p "$SITE_ROOT/cache/static_pages"

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
for keyfile in "$SITE_ROOT/config/relay_pull_key"; do
    if [ -f "$keyfile" ]; then
        echo "  Pinning key $keyfile to 600 www-data:www-data..."
        chown www-data:www-data "$keyfile"
        chmod 600 "$keyfile"
    fi
done

# config/backup_site_key needs the sweep undone for a different reason: it opens
# this site's own backups, so the dev-mode 777 above would hand every backup this
# site ever made to anyone with a shell on the box. It stops at 640 rather than
# 600 because backups run under more than one account — the web user on the
# scheduled run, the deploy account from a shell — and both live in www-data.
BACKUP_KEY="$SITE_ROOT/config/backup_site_key"
if [ -f "$BACKUP_KEY" ]; then
    echo "  Pinning key $BACKUP_KEY to 640 www-data:www-data..."
    chown www-data:www-data "$BACKUP_KEY"
    chmod 640 "$BACKUP_KEY"
fi

# The agent release signing key — the fleet trust root. Anyone who can read it
# can sign agent updates that every node installs and runs as root, so the sweep
# must never leave it readable beyond its owner. Publishing runs as user1 at the
# CLI or as root via an agent job, so ownership goes back to user1 (the sweep's
# chown -R made it www-data); the web stack never reads it (the health panel
# only calls is_file). Exists only on the publishing box.
SIGNING_KEY="$SITE_ROOT/config/agent_signing_key"
if [ -f "$SIGNING_KEY" ]; then
    echo "  Pinning key $SIGNING_KEY to 600 user1:user1..."
    chown user1:user1 "$SIGNING_KEY"
    chmod 600 "$SIGNING_KEY"
fi

# The fleet provisioning SSH key (mgn_ssh_key_path) — reaches every managed
# node as root, so the sweep must not leave it world-readable. It stops at 640
# www-data:user1 rather than 600 because two callers ssh'd with it: the agent as
# root, and node_exec.php as the developer account. OpenSSH's strict-permissions
# check only applies to key files the caller owns, so group-read on a
# www-data-owned file is what let user1 use it at all.
#
# node_exec.php is now retired (A1), leaving root the only caller — and root
# reads the file whatever its mode. So 600 is available, and would take the
# fleet's SSH key out of group-read entirely. It is NOT changed here: narrowing
# a key every managed node trusts belongs in its own deliberate change with the
# fleet watched afterwards, not as a footnote to deleting a script.
PROVISIONING_KEY="$SITE_ROOT/config/provisioning_key"
if [ -f "$PROVISIONING_KEY" ]; then
    echo "  Pinning key $PROVISIONING_KEY to 640 www-data:user1..."
    chown www-data:user1 "$PROVISIONING_KEY"
    chmod 640 "$PROVISIONING_KEY"
fi

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
