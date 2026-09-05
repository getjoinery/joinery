#!/usr/bin/env bash
#VERSION 3.2 - config/provisioning_key is no longer pinned or pruned: the platform holds no SSH
#              key, so nothing in config/ is one (specs/agent_management_first_principles.md
#              item 5). An operator's troubleshooting key lives in that operator's ~/.ssh.
#VERSION 3.1 - config/backup-ledger is pruned from the sweep and pinned to 700/600. The sweep
#              made it 770 (prod) or 777 (dev), so anyone with a shell could rewrite the one
#              file a restore consults to decide whether bytes are this machine's own
#VERSION 3.0 - The recursive chown -R / chmod -R sweep became a find that changes only files
#              whose owner or mode is actually wrong, and skips symlinks. An unconditional -R
#              bumped every file's ctime on every deploy, and GNU tar incremental re-dumps on
#              ctime, so the first backup after any upgrade re-captured the whole tree unchanged.
#              The individually pinned secret files are pruned from the sweep (it no longer
#              corrects them to 770 then re-tightens them); the pins below are unchanged. The
#              redundant uploads/ and storage/ 770 passes are dropped — the main sweep sets 770.
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

# Files with deliberately tighter, non-770 permissions — the secret keys and the
# admin credentials, each re-pinned individually at the end. They are pruned from
# the recursive sweep below so it does not keep "correcting" them to 770 and then
# re-tightening them on every run, which changed their ctime on every deploy.
PINNED=(
    "$SITE_ROOT/config/relay_pull_key"
    "$SITE_ROOT/config/backup_site_key"
    "$SITE_ROOT/config/agent_signing_key"
    "$SITE_ROOT/config/admin_credentials.txt"
)

# Directories with deliberately tighter permissions. Separate from PINNED
# because a directory needs BOTH itself and its contents pruned — a lone
# -not -path skips the directory and then walks straight into it.
PINNED_DIRS=(
    "$SITE_ROOT/config/backup-ledger"
)

PRUNE=()
for p in "${PINNED[@]}"; do PRUNE+=( -not -path "$p" ); done
for d in "${PINNED_DIRS[@]}"; do PRUNE+=( -not -path "$d" -not -path "$d/*" ); done

# Ownership and permissions are corrected with find, matching only what is
# ALREADY wrong — not a blanket chown -R / chmod -R. A recursive chown/chmod
# updates a file's ctime even when its owner and mode are unchanged, and GNU
# tar's incremental backup treats a ctime change as a content change. So an
# unconditional sweep on every deploy made the very next backup re-capture the
# whole tree, byte-for-byte unchanged. Touching only the files that actually need
# it keeps incrementals proportional to real change. Symlinks are skipped: their
# own mode is meaningless and following one could reach outside the tree (vendor).

# Set ownership: www-data (web server) as owner, user1 (developer) as group
echo "  Setting ownership to www-data:user1 (only where it differs)..."
find "$SITE_ROOT" "${PRUNE[@]}" \( -type f -o -type d \) \
     \( -not -user www-data -o -not -group user1 \) \
     -exec chown www-data:user1 {} +

if [ "$MODE" == "production" ]; then
    # Production mode: 770 (owner+group full access, others nothing). This
    # covers uploads/ and storage/ too — they take the same 770 as the rest, so
    # they no longer need a separate pass.
    echo "  Setting permissions to 770 (secure, only where they differ)..."
    find "$SITE_ROOT" "${PRUNE[@]}" \( -type f -o -type d \) \
         -not -perm 770 -exec chmod 770 {} +
else
    # Dev mode: 777 (everyone full access) - for development server only
    echo "  Setting permissions to 777 (dev mode, only where they differ)..."
    find "$SITE_ROOT" "${PRUNE[@]}" \( -type f -o -type d \) \
         -not -perm 777 -exec chmod 777 {} +
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

# config/backup-ledger records what this machine uploaded, and a restore checks
# an archive against it before loading it as root over live data. The sweep
# above would make it 770 in production and 777 in dev — which is not "the web
# user owns it", it is "anyone with a shell on this box can vouch for any bytes
# they like", on the one file whose entire job is vouching. That turns a
# management node's forged or replayed archive into an accepted one.
#
# 0700/0600 www-data:www-data, the same posture and the same reasoning as
# config/backup_site_key above: backups run under more than one account (the web
# user on a scheduled run, root via the agent on a managed node), and both are
# parties already trusted to make a backup. What is closed is everybody else.
# The agent refuses a ledger that is group- or other-writable, so a future sweep
# that widened this again would fail restores loudly rather than silently
# accepting whatever it found.
LEDGER_DIR="$SITE_ROOT/config/backup-ledger"
if [ -d "$LEDGER_DIR" ]; then
    echo "  Pinning $LEDGER_DIR to 700 www-data:www-data..."
    chown -R www-data:www-data "$LEDGER_DIR"
    chmod 700 "$LEDGER_DIR"
    find "$LEDGER_DIR" -type f -exec chmod 600 {} +
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

# The install-time admin password, for whoever can already reach the server as
# root. The sweep above would hand it to the web server user and, in dev mode,
# to everyone — so re-pin it last, in both modes.
CRED_FILE="$SITE_ROOT/config/admin_credentials.txt"
if [ -f "$CRED_FILE" ]; then
    echo "  Pinning $CRED_FILE to 600 root:root..."
    chown root:root "$CRED_FILE" 2>/dev/null || true
    chmod 600 "$CRED_FILE"
fi

echo -e "${GREEN}Done. Permissions fixed for $SITE_NAME.${NC}"
