#!/usr/bin/env bash

# backup_files.sh - Archive a project's files, optionally as an incremental
# Version: 1.1.1 - the sudo capability probe asks with -v instead of running true. Running a
#                  command is an escalation attempt sudo mails root about when the account
#                  may not, so every nightly backup on a box whose web user has no sudo sent
#                  a "SECURITY information" alert. The test itself is unchanged.
# Version: 1.1.0 - tar exit 1 (a file changed while being read) is accepted: on a live
#                  tree that is the normal case, and --warning=no-file-changed only
#                  suppresses the message, not the status. Real failures (>=2) still fail.
# Version: 1.0.0
#
# Description:
#   Produces one encrypted tar of a project's file tree. With --snar it uses GNU
#   tar's incremental mode, so a run after a full ships only what changed — and
#   records enough for a restore to replay DELETIONS as well as additions.
#
#   It archives the LIVE tree, deliberately. backup_project.sh stages an rsync
#   copy first, which is correct for a self-contained full archive but fatal for
#   incrementals: a copy gives every file a new ctime, so tar sees the entire
#   site as changed and every "incremental" is a full. Measured, not assumed.
#
#   The database is NOT included. A chain dumps the database in full on every
#   run as its own artifact, because a dump is the small part and a half-applied
#   database is not a thing anyone wants to restore.
#
# Usage:
#   ./backup_files.sh PROJECT --output-dir DIR --name NAME --key-file PATH [--snar PATH]
#
# Options:
#   PROJECT           Name of the project under /var/www/html
#   --project-dir DIR Archive THIS directory instead of /var/www/html/PROJECT.
#                     The default covers every real deployment; the override is
#                     what lets the incremental and deletion-replay behaviour be
#                     tested against a throwaway tree rather than a live site.
#   --output-dir DIR  Where to write the archive (required)
#   --name NAME       Archive filename, without extension (required)
#   --snar PATH       Snapshot file. Present and non-empty -> incremental;
#                     absent -> this run starts a chain and creates it.
#   --key-file PATH   Encryption key. Omit only with --plaintext.
#   --exclude NAME    Additional directory name to skip. Repeatable.
#   --plaintext       Do not encrypt (the archive carries config/; think first)
#   --help
#
# Output (stdout, machine-readable):
#   LEVEL=0|1         0 = started a chain (full), 1 = incremental
#   ARCHIVE=<path>
#   BYTES=<n>
#   SHA256=<hex>

set -euo pipefail

SCRIPT_VERSION="1.1.1"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
print_info()    { echo -e "${BLUE}[INFO]${NC} $1" >&2; }
print_success() { echo -e "${GREEN}[SUCCESS]${NC} $1" >&2; }
print_warning() { echo -e "${YELLOW}[WARNING]${NC} $1" >&2; }
print_error()   { echo -e "${RED}[ERROR]${NC} $1" >&2; }

show_help() {
    sed -n '3,35p' "$0" | sed 's/^# \{0,1\}//'
}

PROJECT_NAME=""
OUTPUT_DIR=""
ARCHIVE_NAME=""
SNAR=""
KEY_FILE=""
PROJECT_DIR_OVERRIDE=""
EXTRA_EXCLUDES=()
ENCRYPT=true

while [[ $# -gt 0 ]]; do
    case $1 in
        --output-dir)  OUTPUT_DIR="$2"; shift 2 ;;
        --project-dir) PROJECT_DIR_OVERRIDE="$2"; shift 2 ;;
        --name)       ARCHIVE_NAME="$2"; shift 2 ;;
        --snar)       SNAR="$2"; shift 2 ;;
        --key-file)   KEY_FILE="$2"; shift 2 ;;
        --exclude)    EXTRA_EXCLUDES+=("$2"); shift 2 ;;
        --plaintext)  ENCRYPT=false; shift ;;
        --help|-h)    show_help; exit 0 ;;
        -*)           print_error "Unknown option: $1"; exit 1 ;;
        *)
            if [ -z "$PROJECT_NAME" ]; then PROJECT_NAME="$1"; else
                print_error "Multiple project names given."; exit 1
            fi
            shift ;;
    esac
done

[ -n "$PROJECT_NAME" ] || { print_error "Project name is required."; exit 1; }
[ -n "$OUTPUT_DIR" ]   || { print_error "--output-dir is required."; exit 1; }
[ -n "$ARCHIVE_NAME" ] || { print_error "--name is required."; exit 1; }
[ -d "$OUTPUT_DIR" ]   || { print_error "Output directory does not exist: $OUTPUT_DIR"; exit 1; }

PROJECT_DIR="${PROJECT_DIR_OVERRIDE:-/var/www/html/${PROJECT_NAME}}"
PROJECT_DIR="${PROJECT_DIR%/}"
[ -d "$PROJECT_DIR" ] || { print_error "Project directory does not exist: $PROJECT_DIR"; exit 1; }

if [ "$ENCRYPT" = true ]; then
    if [ -z "$KEY_FILE" ]; then
        # Never silently downgrade: this archive carries config/ — the database
        # password, the secret box key, the agent signing key.
        print_error "Encryption is on but no --key-file was given."
        echo "Pass --key-file PATH, or --plaintext deliberately." >&2
        exit 1
    fi
    [ -f "$KEY_FILE" ] || { print_error "--key-file '$KEY_FILE' does not exist"; exit 1; }
    ARCHIVE="${OUTPUT_DIR}/${ARCHIVE_NAME}.tar.gz.enc"
else
    ARCHIVE="${OUTPUT_DIR}/${ARCHIVE_NAME}.tar.gz"
fi

# Level is decided by whether a usable snapshot already exists. A missing or
# empty snar is not an error: it is how a chain starts, and it is what makes
# snapshot loss degrade to "one extra full" instead of "a broken backup".
LEVEL=1
if [ -z "$SNAR" ]; then
    LEVEL=0
elif [ ! -s "$SNAR" ]; then
    LEVEL=0
    if [ -e "$SNAR" ]; then
        print_warning "Snapshot file is empty — starting a new chain with a full backup."
    fi
fi

TAR_ARGS=(--warning=no-file-changed --warning=no-file-removed)

# backups/ is excluded before anything else: it is where this archive is being
# written, and a backup that contains previous backups grows without limit.
#
# target/ is Cargo's build output, the same category as vendor/ and
# node_modules/: regenerable from source, large (gigabytes for a workspace with
# a few targets), and full of 0600 lock files the backup user cannot read —
# which fails the whole run, since an unreadable file is treated as a backup
# that would silently lie about what it holds.
TAR_ARGS+=(--exclude='backups' --exclude='vendor' --exclude='node_modules'
           --exclude='target' --exclude='.git' --exclude='logs'
           --exclude='cache' --exclude='tmp' --exclude='sessions')

for x in ${EXTRA_EXCLUDES[@]+"${EXTRA_EXCLUDES[@]}"}; do
    TAR_ARGS+=(--exclude="$x")
done

if [ -n "$SNAR" ]; then
    TAR_ARGS+=(--listed-incremental="$SNAR")
fi

# The tree holds files the invoking account is not meant to read (config/ keys
# are 600 and web-user owned). Elevate the read if we can; say so plainly if we
# cannot, because a silently partial backup is worse than a failed one.
#
# The question is asked with -v rather than by running a command. Both return the
# same status, but running one is an escalation ATTEMPT, and sudo mails root about
# an attempt by an account that may not (mail_no_user is on by default) — so the
# probe alone sent a "SECURITY information" alert on every run where the answer
# was no. -l and -v are documented as exempt from that mail, for exactly this.
SUDO=""
if [ "$(id -u)" -ne 0 ]; then
    if command -v sudo >/dev/null 2>&1 && sudo -n -v 2>/dev/null; then
        SUDO="sudo"
    else
        print_warning "No passwordless sudo — reading as $(whoami); an unreadable file will fail this backup"
    fi
fi

PARENT="$(dirname "$PROJECT_DIR")"
BASE="$(basename "$PROJECT_DIR")"

print_info "Archiving ${PROJECT_DIR} (level ${LEVEL})"

# GNU tar exits 1 — not 0 — when a file changed while it was being read, even
# with --warning=no-file-changed (the flag suppresses the message, not the
# status). Archiving a live tree makes that the normal case, not an error: the
# changed file's settled version ships with the next run. So tar's status is
# captured separately from openssl's, 1 is accepted with a note, and >= 2 (a
# real failure) still deletes the archive and fails the run.
TAR_RC=0
ENC_RC=0
if [ "$ENCRYPT" = true ]; then
    # tar streams straight into openssl, so the plaintext archive never lands on
    # disk. The key crosses on fd 3, never argv. A tar failure cannot leave a
    # valid-looking .enc of a truncated stream: TAR_RC >= 2 deletes the archive.
    set +e +o pipefail
    ${SUDO} tar "${TAR_ARGS[@]}" -czf - -C "$PARENT" "$BASE" \
        | openssl enc -aes-256-cbc -salt -pbkdf2 -pass fd:3 -out "$ARCHIVE" 3< "$KEY_FILE"
    PIPE=("${PIPESTATUS[@]}")
    set -e -o pipefail
    TAR_RC=${PIPE[0]:-2}
    ENC_RC=${PIPE[1]:-1}
else
    set +e
    ${SUDO} tar "${TAR_ARGS[@]}" -czf "$ARCHIVE" -C "$PARENT" "$BASE"
    TAR_RC=$?
    set -e
fi

if [ "$ENC_RC" -ne 0 ]; then
    print_error "Encrypting the archive failed (openssl exit ${ENC_RC})"
    rm -f "$ARCHIVE"
    exit 1
fi
if [ "$TAR_RC" -eq 1 ]; then
    print_warning "Some files changed while being read; their settled versions ship with the next run"
elif [ "$TAR_RC" -ne 0 ]; then
    print_error "Archive failed (tar exit ${TAR_RC})"
    rm -f "$ARCHIVE"
    exit 1
fi

# The snapshot file is now the state of THIS run. It is the only thing making
# the next run incremental, so it is owner-only: it describes the tree.
if [ -n "$SNAR" ] && [ -f "$SNAR" ]; then
    ${SUDO} chmod 600 "$SNAR" 2>/dev/null || true
    if [ -n "$SUDO" ]; then ${SUDO} chown "$(id -u):$(id -g)" "$SNAR" 2>/dev/null || true; fi
fi

chmod 600 "$ARCHIVE" 2>/dev/null || true

BYTES=$(stat -c %s "$ARCHIVE" 2>/dev/null || echo 0)
SHA=$(sha256sum "$ARCHIVE" | cut -d' ' -f1)

print_success "Wrote $(basename "$ARCHIVE")"

echo "LEVEL=${LEVEL}"
echo "ARCHIVE=${ARCHIVE}"
echo "BYTES=${BYTES}"
echo "SHA256=${SHA}"
exit 0
