#!/usr/bin/env bash

# backup_files.sh - Archive a project's files, optionally as an incremental
# Version: 1.4.0 - a snapshot describes one code tree: SNAR.tree records its identity (the inodes of
#                  public_html and of each directory directly inside it), and a snapshot whose
#                  recorded identity is missing or differs is discarded and the run is a level 0.
#                  An upgrade swaps those directories, freed inodes are reused by new ones, and tar
#                  records renames across the swap that no extraction can apply.
#                  `--print-tree-id` prints the current identity (TREE_ID=) and archives nothing.
# Version: 1.3.0 - `--exclude-from FILE`: paths relative to the project directory, one per line,
#                  left out of the archive — the local paths of every file the backup's object
#                  store accounts for (specs/backup_offloaded_files.md). Literal, unanchored:
#                  a line matches the member whose trailing components it names, and nothing
#                  with a wildcard in it is a pattern.
# Version: 1.2.0 - stream mode: `--archive -` writes the encrypted archive to stdout and nothing
#                  else does; `--report FILE` records LEVEL, TAR_RC and ENC_RC once the stream
#                  has been fully produced. The runner uploads stdout as it flows and completes
#                  the upload only after reading the report, so no archive lands on the node.
# Version: 1.1.2 - the sudo probe asks for the RULE, not the credential: `sudo -n -v` succeeds for an
#                  account holding any NOPASSWD rule, however narrow, after which `sudo tar` is refused
#                  with exit 1 and no output — which 1.1.0 read as tar's own "a file changed" status,
#                  so the run shipped a 32-byte envelope around nothing and reported success (a week
#                  of empty site backups on the dev box, found 2026-09-05). Now `sudo -n -l` must show
#                  a NOPASSWD: ALL rule before sudo is used, and an archive too small to hold even one
#                  entry fails the run instead of being reported as a backup. An unprivileged run
#                  leaves config/agent_signing_key (root-only by design on a publishing box) out
#                  of the archive and says so, rather than failing every night or lying.
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
#   ./backup_files.sh PROJECT --archive - --report FILE --key-file PATH [--snar PATH]
#
# Options:
#   PROJECT           Name of the project under /var/www/html
#   --project-dir DIR Archive THIS directory instead of /var/www/html/PROJECT.
#                     The default covers every real deployment; the override is
#                     what lets the incremental and deletion-replay behaviour be
#                     tested against a throwaway tree rather than a live site.
#   --output-dir DIR  Where to write the archive (required)
#   --name NAME       Archive filename, without extension (required)
#   --snar PATH       Snapshot file. Present and non-empty, with PATH.tree
#                     naming this code tree -> incremental; otherwise this run
#                     starts a chain and writes both.
#   --print-tree-id   Print TREE_ID=<hex>, the identity of the code tree a
#                     snapshot of this project would describe, and exit.
#   --key-file PATH   Encryption key. Omit only with --plaintext.
#   --exclude NAME    Additional directory name to skip. Repeatable.
#   --exclude-from F  A file of paths, relative to the project directory, one
#                     per line, to leave out — the runner writes the local
#                     paths of every offloaded file here, since the objects
#                     index accounts for them. Lines are literal names, not
#                     patterns.
#   --plaintext       Do not encrypt (the archive carries config/; think first)
#   --archive -       Stream mode: the archive goes to stdout and NOTHING else
#                     does (every human line is on stderr). --output-dir and
#                     --name are not needed. Requires --report.
#   --report FILE     Stream mode: once the stream has been fully produced, the
#                     LEVEL, TAR_RC and ENC_RC lines are written to FILE. The
#                     reader has the byte count and the hash from what it read;
#                     this is how it learns, after the bytes, whether tar and
#                     openssl succeeded. The exit status says the same thing.
#   --help
#
# Output (stdout, machine-readable; file mode only):
#   LEVEL=0|1         0 = started a chain (full), 1 = incremental
#   ARCHIVE=<path>
#   BYTES=<n>
#   SHA256=<hex>
#
# Report (stream mode, written to --report FILE):
#   LEVEL=0|1
#   TAR_RC=<n>        0 ok; 1 a file changed while being read (accepted); >=2 failed
#   ENC_RC=<n>        openssl's exit status (0 ok)

set -euo pipefail

SCRIPT_VERSION="1.4.0"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
print_info()    { echo -e "${BLUE}[INFO]${NC} $1" >&2; }
print_success() { echo -e "${GREEN}[SUCCESS]${NC} $1" >&2; }
print_warning() { echo -e "${YELLOW}[WARNING]${NC} $1" >&2; }
print_error()   { echo -e "${RED}[ERROR]${NC} $1" >&2; }

show_help() {
    sed -n '3,95p' "$0" | sed 's/^# \{0,1\}//'
}

PROJECT_NAME=""
OUTPUT_DIR=""
ARCHIVE_NAME=""
SNAR=""
KEY_FILE=""
PROJECT_DIR_OVERRIDE=""
EXTRA_EXCLUDES=()
EXCLUDE_FROM=""
ENCRYPT=true
STREAM=false
REPORT_FILE=""
PRINT_TREE_ID=false

while [[ $# -gt 0 ]]; do
    case $1 in
        --output-dir)  OUTPUT_DIR="$2"; shift 2 ;;
        --project-dir) PROJECT_DIR_OVERRIDE="$2"; shift 2 ;;
        --name)       ARCHIVE_NAME="$2"; shift 2 ;;
        --snar)       SNAR="$2"; shift 2 ;;
        --key-file)   KEY_FILE="$2"; shift 2 ;;
        --exclude)    EXTRA_EXCLUDES+=("$2"); shift 2 ;;
        --exclude-from) EXCLUDE_FROM="$2"; shift 2 ;;
        --plaintext)  ENCRYPT=false; shift ;;
        --archive)
            if [ "$2" != "-" ]; then print_error "--archive only accepts '-' (stdout)."; exit 1; fi
            STREAM=true; shift 2 ;;
        --report)     REPORT_FILE="$2"; shift 2 ;;
        --print-tree-id) PRINT_TREE_ID=true; shift ;;
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

PROJECT_DIR="${PROJECT_DIR_OVERRIDE:-/var/www/html/${PROJECT_NAME}}"
PROJECT_DIR="${PROJECT_DIR%/}"
[ -d "$PROJECT_DIR" ] || { print_error "Project directory does not exist: $PROJECT_DIR"; exit 1; }

# The identity of the code tree a snapshot describes: the inode of public_html
# and the name and inode of every directory directly inside it (the project
# directory itself when it has no public_html). utils/upgrade.php deploys by
# moving every child of public_html out and the staged children in; the staged
# directories are created while the live ones still exist, so none of them can
# carry the inode of the directory it replaces, and this changes on EVERY swap —
# a same-version redeploy included, which VERSION would miss. A restore that
# lays the tree down again changes it the same way. Ordinary edits never do.
tree_identity() {
    local code="${PROJECT_DIR}/public_html"
    [ -d "$code" ] || code="$PROJECT_DIR"
    local listing
    listing="$(stat -c '.:%i' "$code" && find "$code" -mindepth 1 -maxdepth 1 -type d -printf '%f:%i\n' | LC_ALL=C sort)" || return 1
    printf '%s\n' "$listing" | sha256sum | cut -d' ' -f1
}

if [ "$PRINT_TREE_ID" = true ]; then
    ID="$(tree_identity)" || { print_error "Could not read the code tree under $PROJECT_DIR"; exit 1; }
    echo "TREE_ID=${ID}"
    exit 0
fi

if [ "$STREAM" = true ]; then
    [ -n "$REPORT_FILE" ] || { print_error "--archive - requires --report FILE."; exit 1; }
    [ -d "$(dirname "$REPORT_FILE")" ] || { print_error "Report directory does not exist: $(dirname "$REPORT_FILE")"; exit 1; }
    rm -f "$REPORT_FILE"
else
    [ -n "$OUTPUT_DIR" ]   || { print_error "--output-dir is required."; exit 1; }
    [ -n "$ARCHIVE_NAME" ] || { print_error "--name is required."; exit 1; }
    [ -d "$OUTPUT_DIR" ]   || { print_error "Output directory does not exist: $OUTPUT_DIR"; exit 1; }
fi

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
# In stream mode there is no file; the archive is whatever left stdout.
if [ "$STREAM" = true ]; then ARCHIVE=""; fi

# Stream mode: the report says what happened, after the bytes. Written on
# every exit from here on, so a reader that got end-of-stream always finds
# one — a missing report after a clean exit would otherwise be ambiguous.
write_report() {
    if [ "$STREAM" = true ] && [ -n "$REPORT_FILE" ]; then
        printf 'LEVEL=%s\nTAR_RC=%s\nENC_RC=%s\n' "$LEVEL" "$TAR_RC" "$ENC_RC" > "$REPORT_FILE"
    fi
}

# Level is decided by whether a usable snapshot already exists. A missing or
# empty snar is not an error: it is how a chain starts, and it is what makes
# snapshot loss degrade to "one extra full" instead of "a broken backup".
LEVEL=1
TREE_ID=""
TREE_ID_FILE=""
if [ -z "$SNAR" ]; then
    LEVEL=0
else
    TREE_ID_FILE="${SNAR}.tree"
    TREE_ID="$(tree_identity)" || { print_error "Could not read the code tree under $PROJECT_DIR"; exit 1; }
    if [ ! -s "$SNAR" ]; then
        LEVEL=0
        if [ -e "$SNAR" ]; then
            print_warning "Snapshot file is empty — starting a new chain with a full backup."
        fi
    elif [ ! -f "$TREE_ID_FILE" ] || [ "$(cat "$TREE_ID_FILE" 2>/dev/null)" != "$TREE_ID" ]; then
        # The snapshot describes another code tree (an upgrade or a restore
        # swapped it since), or records none. An incremental across a swap
        # carries renames onto paths that already exist and cannot be
        # extracted, so this run starts over from a full.
        LEVEL=0
        print_warning "The code tree changed since the snapshot was taken — starting a new chain with a full backup."
        rm -f "$SNAR"
        [ ! -e "$SNAR" ] || { print_error "Could not discard the snapshot $SNAR"; exit 1; }
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

# The offloaded files' local paths, from the runner. Literal (--no-wildcards)
# and unanchored: `static_files/uploads/x.jpg` matches the member whose name
# ends in those components, wherever the tree sits under $PARENT. Last among
# the exclude options so the mode switch reaches nothing else.
if [ -n "$EXCLUDE_FROM" ]; then
    if [ ! -r "$EXCLUDE_FROM" ]; then
        print_error "--exclude-from file not readable: $EXCLUDE_FROM"
        exit 1
    fi
    TAR_ARGS+=(--no-wildcards --exclude-from="$EXCLUDE_FROM")
fi

if [ -n "$SNAR" ]; then
    TAR_ARGS+=(--listed-incremental="$SNAR")
fi

# The release signing key on a publishing box is readable by root only, on
# purpose (specs/agent_local_queue_retirement.md, "G1 — decided"): the web user
# must never be able to sign code. An unprivileged run therefore cannot carry
# it, and that is not the silent partial backup the doctrine forbids — it is
# one named file, left out on purpose and said out loud. The root-run manager
# backup of this same box carries it, as does the agent_signing escrow row.
SIGNING_KEY="${PROJECT_DIR}/config/agent_signing_key"
if [ "$(id -u)" -ne 0 ] && [ -e "$SIGNING_KEY" ] && [ ! -r "$SIGNING_KEY" ]; then
    print_warning "config/agent_signing_key is root-only and this run is not root: left out of this archive (the root-run manager backup carries it)"
    TAR_ARGS+=(--exclude='agent_signing_key')
fi

# The tree holds files the invoking account is not meant to read (config/ keys
# are 600 and web-user owned). Elevate the read if we can; say so plainly if we
# cannot, because a silently partial backup is worse than a failed one.
#
# The question is "may this account run ANYTHING as root without a password",
# and it is asked by listing the rules (`sudo -n -l`) and looking for NOPASSWD:
# ALL. `sudo -n -v` is not that question: it validates for an account holding
# any NOPASSWD rule at all, so on a box where the web user may run one helper
# as root it said yes, and the `sudo tar` that followed was refused. Listing is
# still not an escalation attempt, so it sends none of the "SECURITY
# information" mail that running a command would (mail_no_user is on by default).
SUDO=""
if [ "$(id -u)" -ne 0 ]; then
    if command -v sudo >/dev/null 2>&1 && sudo -n -l 2>/dev/null | grep -Eq 'NOPASSWD:([[:space:]]*[A-Z]+:)*[[:space:]]*ALL([[:space:]]|$)'; then
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
if [ "$ENCRYPT" = true ] && [ "$STREAM" = true ]; then
    # Stream mode: the ciphertext goes to stdout — the reader's pipe — and
    # nothing lands on disk at all. tar's status is still known only after
    # the stream closes, which is what the report file is for.
    set +e +o pipefail
    ${SUDO} tar "${TAR_ARGS[@]}" -czf - -C "$PARENT" "$BASE" \
        | openssl enc -aes-256-cbc -salt -pbkdf2 -pass fd:3 3< "$KEY_FILE"
    PIPE=("${PIPESTATUS[@]}")
    set -e -o pipefail
    TAR_RC=${PIPE[0]:-2}
    ENC_RC=${PIPE[1]:-1}
elif [ "$ENCRYPT" = true ]; then
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
elif [ "$STREAM" = true ]; then
    set +e
    ${SUDO} tar "${TAR_ARGS[@]}" -czf - -C "$PARENT" "$BASE"
    TAR_RC=$?
    set -e
else
    set +e
    ${SUDO} tar "${TAR_ARGS[@]}" -czf "$ARCHIVE" -C "$PARENT" "$BASE"
    TAR_RC=$?
    set -e
fi

# The stream is fully produced (or as produced as it will be) at this point,
# whatever the statuses say; the report carries them to the reader.
write_report

if [ "$ENC_RC" -ne 0 ]; then
    print_error "Encrypting the archive failed (openssl exit ${ENC_RC})"
    [ -n "$ARCHIVE" ] && rm -f "$ARCHIVE"
    exit 1
fi
if [ "$TAR_RC" -eq 1 ]; then
    print_warning "Some files changed while being read; their settled versions ship with the next run"
elif [ "$TAR_RC" -ne 0 ]; then
    print_error "Archive failed (tar exit ${TAR_RC})"
    [ -n "$ARCHIVE" ] && rm -f "$ARCHIVE"
    exit 1
fi

# The snapshot file is now the state of THIS run. It is the only thing making
# the next run incremental, so it is owner-only: it describes the tree.
if [ -n "$SNAR" ] && [ -f "$SNAR" ]; then
    ${SUDO} chmod 600 "$SNAR" 2>/dev/null || true
    if [ -n "$SUDO" ]; then ${SUDO} chown "$(id -u):$(id -g)" "$SNAR" 2>/dev/null || true; fi
    # The identity read BEFORE tar ran: a swap during this run then reads as
    # a changed tree next time, never as the one this snapshot describes.
    ( umask 077; printf '%s\n' "$TREE_ID" > "$TREE_ID_FILE" )
fi

if [ "$STREAM" = true ]; then
    # The reader counted and hashed the bytes as they left; the 64-byte guard
    # below is applied there, on the count.
    print_success "Streamed the archive (level ${LEVEL})"
    exit 0
fi

chmod 600 "$ARCHIVE" 2>/dev/null || true

BYTES=$(stat -c %s "$ARCHIVE" 2>/dev/null || echo 0)
# A gzipped tar holding even one entry is longer than this; an openssl envelope
# around an empty stream is 32 bytes. Whatever produced fewer bytes than that
# was not tar archiving this tree, whatever its exit status said — and a backup
# of nothing must never be recorded as a backup.
if [ "$BYTES" -lt 64 ]; then
    print_error "Archive is ${BYTES} bytes — nothing was archived (tar exit ${TAR_RC}). Refusing to record an empty backup."
    rm -f "$ARCHIVE"
    exit 1
fi
SHA=$(sha256sum "$ARCHIVE" | cut -d' ' -f1)

print_success "Wrote $(basename "$ARCHIVE")"

echo "LEVEL=${LEVEL}"
echo "ARCHIVE=${ARCHIVE}"
echo "BYTES=${BYTES}"
echo "SHA256=${SHA}"
exit 0
