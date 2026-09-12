#!/usr/bin/env bash
#VERSION 4.3 - config/release_verify_keys is pinned root:root 0644 and pruned
#              from the config/ data sweep, which would otherwise hand it to
#              the web user 0770 - a key file the pool can write is one the
#              pool can add a key to (specs/package_signing.md WP1).
#VERSION 4.2 - The record is authoritative once written. --dev and --production
#              decide only what is recorded the first time. upgrade.php passes
#              --production on every site it upgrades, developer checkout
#              included, so a mode read as an instruction would hand the
#              developer's tree to root at the next upgrade. A box is told once
#              whose it is, and it stays told.
#VERSION 4.1 - Records the tree owner in {site}/config/tree_owner, because the
#              root actor has to know the answer at a moment when it cannot be
#              read off the tree. The host converger asserts ownership before it
#              executes anything, and the state it fires in - public_html owned
#              by www-data - is exactly the state where "look at who owns
#              public_html" returns the wrong answer. Hardcoding root there took
#              a developer checkout to root the first time it ran. The record is
#              written here and only here: this script is already told which
#              mode the install is in, so it needs no cleverness, and nothing on
#              the web side can create or change the file. Root and the tree
#              owner are the only accounts that can (config/ is 0750 and this
#              file is root-owned 0644).
#VERSION 4.0 - Two sets, one rule each (specs/read_only_tree.md, S10).
#
#              EXECUTABLE SET - the site directory itself, public_html,
#              maintenance_scripts, vendor, RELEASE_MANIFEST(.sig) and
#              config/*.php: everything the PHP pool executes or includes. It is
#              owned by the TREE OWNER (root on a node, the developer account on
#              the developer box) at 755/644, and the pool has read and nothing
#              else. A bug that makes the web server write one file can no
#              longer put PHP where the web server will run it.
#
#              DATA SET - uploads, static_files, cache, logs, storage, backups
#              and the non-PHP contents of config/: www-data:www-data 0770 in
#              BOTH modes. Nothing here is ever executed, so the pool keeps
#              write access to exactly the files that are data. The dev-mode 777
#              sweep is gone with it, which also closes uploads/ to every other
#              local account on the box.
#
#              config/ itself belongs to the tree owner (group www-data, 0750):
#              the pool reads and traverses it, and only the tree owner can
#              create or remove a file in it. Directory write would be enough to
#              unlink Globalvars_site.php and put a new one in its place, and
#              that file is `require`d PHP.
#
#              Anything else under the site root - a developer checkout's .git,
#              android/, ios/, sync/ - is in neither set and is left alone.
#VERSION 3.3 - config/agent_signing_key is pinned 600 root:root. Publishing is a job of the
#              management node's own agent, which runs as root, so root is the key's only
#              reader; an operator login on the box can no longer copy the fleet trust root
#              (specs/agent_local_queue_retirement.md, G1). The CLI publish is sudo.
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
#   --production  (default) The tree owner is root. Use for ALL sites on
#                 production/staging servers (including _test sites).
#   --dev         The tree owner is the developer account that already owns
#                 public_html (falling back to the invoking sudo user). Use ONLY
#                 on the single development server (e.g., joinerytest).
#
# The two modes differ in exactly one thing: who owns the executable set. The
# modes and the data set are identical in both.
#
# Examples:
#   sudo ./fix_permissions.sh mysite              # Production mode (root owns the tree)
#   sudo ./fix_permissions.sh mysite --production # Same as above
#   sudo ./fix_permissions.sh mysite --dev        # Dev mode (the developer owns the tree)

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
    echo "  --production  (default) root owns the executable set (prod & staging)"
    echo "  --dev         the developer account owns it (dev server only)"
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
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

# --- Who owns the executable set ---------------------------------------------
# The record wins wherever there is one. {site}/config/tree_owner is written
# here, by root, and by nothing else; once it exists it is the answer, and --dev
# and --production decide only what gets recorded the first time.
#
# That matters because this script is run by things that pass a mode without
# knowing the box: upgrade.php passes --production on every site it upgrades,
# including a developer checkout, and honouring that would hand the developer's
# tree to root on the next upgrade. The mode is a default, not an instruction.
#
# With no record: on a node it is root. On the developer box it is whichever
# account already owns public_html — a checkout the developer edits with their
# own editor, git and CLI test runner. www-data is not an answer in either mode:
# it is the account the whole spec is taking write access away from, so finding
# it there means the tree has not been re-owned yet and the invoking sudo user
# is the best available stand-in.
TREE_OWNER=""
TRUST_HELPER="$SCRIPT_DIR/_tree_trust.sh"
if [ -f "$TRUST_HELPER" ]; then
    # shellcheck source=_tree_trust.sh
    . "$TRUST_HELPER"
    if RECORDED="$(joinery_tree_owner_record "$SITE_ROOT")"; then
        TREE_OWNER="$RECORDED"
        if { [ "$MODE" == "production" ] && [ "$TREE_OWNER" != "root" ]; } \
           || { [ "$MODE" == "dev" ] && [ "$TREE_OWNER" == "root" ]; }; then
            echo "  Tree owner is recorded as '${TREE_OWNER}'; --${MODE} does not change it."
        fi
    fi
fi

if [ -z "$TREE_OWNER" ]; then
    if [ "$MODE" == "production" ]; then
        TREE_OWNER="root"
    else
        TREE_OWNER="$(stat -c '%U' "$SITE_ROOT/public_html" 2>/dev/null || true)"
        if [ -z "$TREE_OWNER" ] || [ "$TREE_OWNER" == "www-data" ] || [ "$TREE_OWNER" == "UNKNOWN" ]; then
            TREE_OWNER="${SUDO_USER:-root}"
        fi
    fi
fi
if ! id "$TREE_OWNER" >/dev/null 2>&1; then
    echo -e "${YELLOW}  WARNING: '$TREE_OWNER' is not an account here; falling back to root.${NC}"
    TREE_OWNER="root"
fi
TREE_GROUP="$(id -gn "$TREE_OWNER")"
echo "  Tree owner: ${TREE_OWNER}:${TREE_GROUP}"

# The page cache the code reads is {site root}/cache/static_pages. Create it
# before the sweep so the ownership and mode fixes below always cover it —
# a missing or root-owned cache dir silently disables page caching.
mkdir -p "$SITE_ROOT/cache/static_pages"

# Files with deliberately tighter, non-sweep permissions — the secret keys and
# the admin credentials, each re-pinned individually at the end. They are pruned
# from the sweeps below so those do not keep "correcting" them and then
# re-tightening them on every run, which changed their ctime on every deploy.
PINNED=(
    # Written at the end of this run, root:root 0644. Pruned so the data-set
    # sweep does not make it group-writable in between — the converger refuses
    # to trust a tree_owner file anyone but root or the owner could have written.
    "$SITE_ROOT/config/tree_owner"
    # Root's alone for the same reason: PackageSignature refuses a key file
    # anyone but root or the tree owner could have written.
    "$SITE_ROOT/config/release_verify_keys"
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
# A developer checkout's object store is not part of either set. Re-owning it
# would rewrite tens of thousands of files' ctimes for nothing, and git is run
# by the developer, not by the web server.
PRUNE+=( -not -path "*/.git" -not -path "*/.git/*" )

# Ownership and permissions are corrected with find, matching only what is
# ALREADY wrong — not a blanket chown -R / chmod -R. A recursive chown/chmod
# updates a file's ctime even when its owner and mode are unchanged, and GNU
# tar's incremental backup treats a ctime change as a content change. So an
# unconditional sweep on every deploy made the very next backup re-capture the
# whole tree, byte-for-byte unchanged. Touching only the files that actually need
# it keeps incrementals proportional to real change. Symlinks are skipped: their
# own mode is meaningless and following one could reach outside the tree (vendor).

# =============================================================================
# THE EXECUTABLE SET
# =============================================================================
# Everything the PHP pool executes or includes. Owned by the tree owner,
# readable by everyone (the pool among them), writable by nobody else.

EXEC_ROOTS=()
for d in public_html maintenance_scripts vendor; do
    [ -d "$SITE_ROOT/$d" ] && EXEC_ROOTS+=( "$SITE_ROOT/$d" )
done

# The site directory itself, and only itself: renaming public_html or dropping a
# replacement RELEASE_MANIFEST beside it needs write here, so the pool must not
# have it. Its other children (a checkout's .git, android/, ios/) are in neither
# set and are not walked.
echo "  Executable set: the site directory, public_html, maintenance_scripts, vendor..."
chown "${TREE_OWNER}:${TREE_GROUP}" "$SITE_ROOT"
chmod 755 "$SITE_ROOT"

for mf in RELEASE_MANIFEST RELEASE_MANIFEST.sig; do
    if [ -f "$SITE_ROOT/$mf" ]; then
        chown "${TREE_OWNER}:${TREE_GROUP}" "$SITE_ROOT/$mf"
        chmod 644 "$SITE_ROOT/$mf"
    fi
done

if [ ${#EXEC_ROOTS[@]} -gt 0 ]; then
    find "${EXEC_ROOTS[@]}" "${PRUNE[@]}" \( -type f -o -type d \) \
         \( -not -user "$TREE_OWNER" -o -not -group "$TREE_GROUP" \) \
         -exec chown "${TREE_OWNER}:${TREE_GROUP}" {} +

    find "${EXEC_ROOTS[@]}" "${PRUNE[@]}" -type d \
         -not -perm 755 -exec chmod 755 {} +

    # 644 for everything, 755 for shell scripts. install.sh, _site_init.sh and
    # this script are invoked as commands rather than as `bash <path>`, so the
    # execute bit is load-bearing on exactly that set. It grants nobody write.
    find "${EXEC_ROOTS[@]}" "${PRUNE[@]}" -type f -name '*.sh' \
         -not -perm 755 -exec chmod 755 {} +
    find "${EXEC_ROOTS[@]}" "${PRUNE[@]}" -type f -not -name '*.sh' \
         -not -perm 644 -exec chmod 644 {} +
fi

# config/ holds `require`d PHP. The directory belongs to the tree owner with
# group www-data at 0750 — the pool reads and traverses, and creating or
# removing a file in it takes the tree owner. Directory write alone would be
# enough to unlink Globalvars_site.php and leave a different one in its place.
CONFIG_DIR="$SITE_ROOT/config"
if [ -d "$CONFIG_DIR" ]; then
    echo "  Executable set: config/*.php to root:www-data 0640..."
    chown "${TREE_OWNER}:www-data" "$CONFIG_DIR"
    chmod 750 "$CONFIG_DIR"
    # root, not the tree owner: the pool has to read it and nobody else needs
    # to. On the developer box the developer is in the www-data group, so the
    # file is still readable from a shell and still not writable from one.
    find "$CONFIG_DIR" -maxdepth 1 -type f -name '*.php' \
         \( -not -user root -o -not -group www-data \) \
         -exec chown root:www-data {} +
    find "$CONFIG_DIR" -maxdepth 1 -type f -name '*.php' \
         -not -perm 640 -exec chmod 640 {} +
fi

# =============================================================================
# THE DATA SET
# =============================================================================
# What the pool writes, and what nothing ever executes. www-data:www-data 0770
# in both modes: the web server owns its own data, and no other local account on
# the box can read an upload.

DATA_ROOTS=()
for d in uploads static_files cache logs storage backups; do
    [ -d "$SITE_ROOT/$d" ] && DATA_ROOTS+=( "$SITE_ROOT/$d" )
done

echo "  Data set: uploads, static_files, cache, logs, storage, backups to www-data:www-data 0770..."
if [ ${#DATA_ROOTS[@]} -gt 0 ]; then
    find "${DATA_ROOTS[@]}" "${PRUNE[@]}" \( -type f -o -type d \) \
         \( -not -user www-data -o -not -group www-data \) \
         -exec chown www-data:www-data {} +
    find "${DATA_ROOTS[@]}" "${PRUNE[@]}" \( -type f -o -type d \) \
         -not -perm 770 -exec chmod 770 {} +
fi

# The non-PHP contents of config/ are data the pool reads and writes: this
# site's backup key, the relay pull key, the ledger. The pins below re-tighten
# the ones that need it.
if [ -d "$CONFIG_DIR" ]; then
    find "$CONFIG_DIR" -mindepth 1 "${PRUNE[@]}" \( -type f -o -type d \) \
         -not -name '*.php' \
         \( -not -user www-data -o -not -group www-data \) \
         -exec chown www-data:www-data {} +
    find "$CONFIG_DIR" -mindepth 1 "${PRUNE[@]}" \( -type f -o -type d \) \
         -not -name '*.php' \
         -not -perm 770 -exec chmod 770 {} +
fi

# =============================================================================
# THE PINS
# =============================================================================

# SSH private keys demand 0600 and caller-only ownership — a group-readable key
# is one ssh refuses, silently breaking the relay mail pull on every deploy.
for keyfile in "$SITE_ROOT/config/relay_pull_key"; do
    if [ -f "$keyfile" ]; then
        echo "  Pinning key $keyfile to 600 www-data:www-data..."
        chown www-data:www-data "$keyfile"
        chmod 600 "$keyfile"
    fi
done

# config/backup_site_key opens this site's own backups, so a wider mode would
# hand every backup this site ever made to anyone with a shell on the box. It
# stops at 640 rather than 600 because backups run under more than one account —
# the web user on the scheduled run, the deploy account from a shell — and both
# live in www-data.
BACKUP_KEY="$SITE_ROOT/config/backup_site_key"
if [ -f "$BACKUP_KEY" ]; then
    echo "  Pinning key $BACKUP_KEY to 640 www-data:www-data..."
    chown www-data:www-data "$BACKUP_KEY"
    chmod 640 "$BACKUP_KEY"
fi

# config/backup-ledger records what this machine uploaded, and a restore checks
# an archive against it before loading it as root over live data. A 770 sweep
# would not be "the web user owns it", it would be "anyone with a shell on this
# box can vouch for any bytes they like", on the one file whose entire job is
# vouching. That turns a management node's forged or replayed archive into an
# accepted one.
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
# can sign agent updates that every node installs and runs as root. Publishing
# is a job of this management node's own agent, which runs as root, so root is
# the only reader the key needs: 600 root:root, and an operator's login on this
# box cannot copy it. The web stack never reads it (the health panel only calls
# is_file), and the CLI publish is `sudo php .../publish_upgrade.php`. Exists
# only on the publishing box.
SIGNING_KEY="$SITE_ROOT/config/agent_signing_key"
if [ -f "$SIGNING_KEY" ]; then
    echo "  Pinning key $SIGNING_KEY to 600 root:root..."
    chown root:root "$SIGNING_KEY"
    chmod 600 "$SIGNING_KEY"
fi

# The install-time admin password, for whoever can already reach the server as
# root. The sweep above would hand it to the web server user, so re-pin it last.
CRED_FILE="$SITE_ROOT/config/admin_credentials.txt"
if [ -f "$CRED_FILE" ]; then
    echo "  Pinning $CRED_FILE to 600 root:root..."
    chown root:root "$CRED_FILE" 2>/dev/null || true
    chmod 600 "$CRED_FILE"
fi

# The keys root verifies every package against before it goes into the tree.
# Public keys, so the pool may read them; root:root 0644 so only root can
# change which keys count. Written by the host converger; re-pinned here.
VERIFY_KEYS="$SITE_ROOT/config/release_verify_keys"
if [ -f "$VERIFY_KEYS" ]; then
    echo "  Pinning $VERIFY_KEYS to 644 root:root..."
    chown root:root "$VERIFY_KEYS" 2>/dev/null || true
    chmod 644 "$VERIFY_KEYS"
fi

# --- Record who owns this tree -----------------------------------------------
# The host converger runs as root every few minutes and asserts the executable
# set's ownership before it executes anything out of the tree. In the state that
# assertion is for — the pool owning the code — the tree itself cannot answer
# "who should own this", so the answer is written down here while it is known.
#
# root:root 0644 inside a 0750 config/ directory: root and the tree owner are
# the only accounts that can write it, the runner can read it before it trusts
# anything, and it survives with no database and no PHP.
OWNER_FILE="$CONFIG_DIR/tree_owner"
if [ -d "$CONFIG_DIR" ]; then
    echo "  Recording tree owner '${TREE_OWNER}' in $OWNER_FILE..."
    printf '%s\n' "$TREE_OWNER" > "$OWNER_FILE"
    chown root:root "$OWNER_FILE"
    chmod 644 "$OWNER_FILE"
fi

echo -e "${GREEN}Done. Permissions fixed for $SITE_NAME.${NC}"
