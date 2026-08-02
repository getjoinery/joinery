#!/usr/bin/env bash

# restore_chain.sh - Restore a project from an incremental backup chain
# Version: 1.0.1 - a failed verification prints its reason (the verifier's stderr was
#                  not captured, so the error line came out empty)
# Version: 1.0.0
#
# Description:
#   Restores a chain: the full, then every incremental up to the run you asked
#   for, applied IN ORDER with tar's incremental extraction so that files
#   deleted between runs are deleted on restore too. Then the database dump
#   belonging to that run.
#
#   Order is the whole point. Applying incrementals out of order, or skipping
#   one, produces a tree that never existed on the original machine — so this
#   script takes the order from the manifest rather than from a directory
#   listing, and verifies every artifact against its recorded size and hash
#   BEFORE it touches anything.
#
# Usage:
#   ./restore_chain.sh PROJECT --artifacts DIR --key-file PATH [--seq N] [--dry-run] [--force]
#
# Options:
#   PROJECT           Project name; restores to /var/www/html/PROJECT
#   --target-dir DIR  Restore into THIS directory instead. Same reason as
#                     backup_files.sh --project-dir: the deletion-replay
#                     behaviour has to be testable without a live site.
#   --artifacts DIR   Directory holding manifest.json and the downloaded artifacts
#   --key-file PATH   The chain data key (recover it with backup_envelope.php open)
#   --seq N           Restore as at run N. Default: the newest run in the chain.
#   --skip-database   Files only
#   --dry-run         Verify the chain and report the plan; change nothing
#   --force           Skip the confirmation prompt
#   --help

set -euo pipefail

SCRIPT_VERSION="1.0.1"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; CYAN='\033[0;36m'; NC='\033[0m'
print_info()    { echo -e "${BLUE}[INFO]${NC} $1" >&2; }
print_success() { echo -e "${GREEN}[SUCCESS]${NC} $1" >&2; }
print_warning() { echo -e "${YELLOW}[WARNING]${NC} $1" >&2; }
print_error()   { echo -e "${RED}[ERROR]${NC} $1" >&2; }
print_dry()     { echo -e "${CYAN}[DRY-RUN]${NC} $1" >&2; }

PROJECT_NAME=""
ARTIFACT_DIR=""
KEY_FILE=""
SEQ=""
TARGET_DIR_OVERRIDE=""
DRY_RUN=false
FORCE=false
SKIP_DATABASE=false

while [[ $# -gt 0 ]]; do
    case $1 in
        --artifacts)     ARTIFACT_DIR="$2"; shift 2 ;;
        --target-dir)    TARGET_DIR_OVERRIDE="$2"; shift 2 ;;
        --key-file)      KEY_FILE="$2"; shift 2 ;;
        --seq)           SEQ="$2"; shift 2 ;;
        --skip-database) SKIP_DATABASE=true; shift ;;
        --dry-run|-n)    DRY_RUN=true; shift ;;
        --force|-f)      FORCE=true; shift ;;
        --help|-h)       sed -n '3,32p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        -*)              print_error "Unknown option: $1"; exit 1 ;;
        *)
            if [ -z "$PROJECT_NAME" ]; then PROJECT_NAME="$1"; else
                print_error "Multiple project names given."; exit 1
            fi
            shift ;;
    esac
done

[ -n "$PROJECT_NAME" ] || { print_error "Project name is required."; exit 1; }
[ -n "$ARTIFACT_DIR" ] || { print_error "--artifacts is required."; exit 1; }
[ -d "$ARTIFACT_DIR" ] || { print_error "Artifact directory not found: $ARTIFACT_DIR"; exit 1; }

MANIFEST="${ARTIFACT_DIR}/manifest.json"
[ -f "$MANIFEST" ] || { print_error "No manifest.json in $ARTIFACT_DIR"; exit 1; }
command -v python3 >/dev/null 2>&1 || { print_error "python3 is required to read the manifest."; exit 1; }

# ── Plan and verify ─────────────────────────────────────────────────────────
#
# Everything is checked before anything is written. A chain that is missing an
# artifact, or has one that does not match its hash, must fail while the live
# site is still intact — not half way through overwriting it.

PLAN=$(python3 - "$MANIFEST" "${SEQ:-}" 2>&1 <<'PY'
import hashlib, json, os, sys

manifest_path, want = sys.argv[1], sys.argv[2]
d = os.path.dirname(manifest_path)

with open(manifest_path) as fh:
    m = json.load(fh)

if int(m.get('version', 0)) != 1:
    sys.exit("unsupported chain manifest version %s" % m.get('version'))

runs = m.get('runs') or []
if not runs:
    sys.exit("this chain manifest lists no runs")

seq = (len(runs) - 1) if want == '' else int(want)
if seq < 0 or seq >= len(runs):
    sys.exit("this chain has no run %d" % seq)
if int(runs[0].get('level', 1)) != 0:
    sys.exit("this chain does not begin with a full backup")

def check(entry):
    path = os.path.join(d, entry['name'])
    if not os.path.isfile(path):
        sys.exit("missing backup artifact: %s" % entry['name'])
    size = os.path.getsize(path)
    if entry.get('bytes') and size != int(entry['bytes']):
        sys.exit("%s is %d bytes but the manifest says %d - it is incomplete, not restoring"
                 % (entry['name'], size, int(entry['bytes'])))
    if entry.get('sha256'):
        h = hashlib.sha256()
        with open(path, 'rb') as fh:
            for chunk in iter(lambda: fh.read(1 << 20), b''):
                h.update(chunk)
        if h.hexdigest() != entry['sha256']:
            sys.exit("%s does not match its recorded hash - it is damaged, not restoring"
                     % entry['name'])
    return path

lines = []
for i in range(seq + 1):
    run = runs[i]
    arts = run.get('artifacts') or {}
    if 'files' not in arts:
        sys.exit("run %d has no files artifact" % i)
    lines.append("FILES\t%s" % check(arts['files']))

last = runs[seq].get('artifacts') or {}
if 'db' in last:
    lines.append("DB\t%s" % check(last['db']))
if 'meta' in last:
    lines.append("META\t%s" % check(last['meta']))

lines.append("SEQ\t%d" % seq)
lines.append("CHAIN\t%s" % m.get('chain_id', ''))
print("\n".join(lines))
PY
) || { print_error "$PLAN"; exit 1; }

FILES_ARCHIVES=()
DB_ARCHIVE=""
META_ARCHIVE=""
CHAIN_ID=""
RESTORE_SEQ=""
while IFS=$'\t' read -r kind value; do
    case "$kind" in
        FILES) FILES_ARCHIVES+=("$value") ;;
        DB)    DB_ARCHIVE="$value" ;;
        META)  META_ARCHIVE="$value" ;;
        SEQ)   RESTORE_SEQ="$value" ;;
        CHAIN) CHAIN_ID="$value" ;;
    esac
done <<< "$PLAN"

print_success "Chain ${CHAIN_ID} verified: ${#FILES_ARCHIVES[@]} archive(s) to apply, restoring as at run ${RESTORE_SEQ}"

if [ "$DRY_RUN" = true ]; then
    print_dry "Would apply, in order:"
    for a in "${FILES_ARCHIVES[@]}"; do print_dry "  $(basename "$a")"; done
    [ -n "$DB_ARCHIVE" ] && print_dry "Then restore database from $(basename "$DB_ARCHIVE")"
    echo "RESTORE_PLAN_OK"
    exit 0
fi

[ -n "$KEY_FILE" ] || { print_error "--key-file is required to restore."; exit 1; }
[ -f "$KEY_FILE" ] || { print_error "--key-file '$KEY_FILE' does not exist"; exit 1; }

PROJECT_DIR="${TARGET_DIR_OVERRIDE:-/var/www/html/${PROJECT_NAME}}"
PROJECT_DIR="${PROJECT_DIR%/}"
PARENT="$(dirname "$PROJECT_DIR")"

if [ "$FORCE" != true ]; then
    echo "About to restore ${PROJECT_DIR} from chain ${CHAIN_ID} at run ${RESTORE_SEQ}." >&2
    echo "Files deleted since the full backup will be deleted here too." >&2
    read -p "Continue? (y/N): " -n 1 -r; echo >&2
    [[ $REPLY =~ ^[Yy]$ ]] || { print_info "Cancelled."; exit 0; }
fi

SUDO=""
if [ "$(id -u)" -ne 0 ]; then
    if command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then SUDO="sudo"; fi
fi

mkdir -p "$PARENT"

# ── Apply ───────────────────────────────────────────────────────────────────
#
# --incremental on extraction is what replays deletions: each archive carries
# its directories' full listings, and tar removes anything present locally that
# the listing does not mention. Without it a restore only ever adds, so a file
# deleted a month ago comes back from the dead.
i=0
for archive in "${FILES_ARCHIVES[@]}"; do
    print_info "Applying $(basename "$archive") ($((i+1))/${#FILES_ARCHIVES[@]})"
    STATUS=0
    ( set -o pipefail
      openssl enc -aes-256-cbc -d -pbkdf2 -pass fd:3 -in "$archive" 2>/dev/null \
        | ${SUDO} tar --incremental --warning=no-timestamp -xzf - -C "$PARENT" \
    ) 3< "$KEY_FILE" || STATUS=$?
    if [ "$STATUS" -ne 0 ]; then
        print_error "Failed applying $(basename "$archive") (exit ${STATUS})."
        print_error "The tree is part-restored; re-run from the start once the cause is fixed."
        exit 1
    fi
    i=$((i+1))
done
print_success "Files restored to ${PROJECT_DIR}"

if [ -n "$META_ARCHIVE" ]; then
    META_TMP=$(mktemp -d)
    trap 'rm -rf "$META_TMP"' EXIT
    ( set -o pipefail
      openssl enc -aes-256-cbc -d -pbkdf2 -pass fd:3 -in "$META_ARCHIVE" 2>/dev/null \
        | tar -xzf - -C "$META_TMP" ) 3< "$KEY_FILE" || true
    VHOST=$(find "$META_TMP" -name '*.conf' -type f 2>/dev/null | head -1)
    if [ -n "$VHOST" ] && [ -d /etc/apache2/sites-available ]; then
        if [ -n "$SUDO" ] || [ "$(id -u)" -eq 0 ]; then
            ${SUDO} cp "$VHOST" /etc/apache2/sites-available/
            print_success "Apache config restored: $(basename "$VHOST")"
        else
            print_warning "Apache config in the backup was not restored (needs root): $(basename "$VHOST")"
        fi
    fi
fi

if [ "$SKIP_DATABASE" = false ] && [ -n "$DB_ARCHIVE" ]; then
    ENGINE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/restore_database.sh"
    if [ -f "$ENGINE" ]; then
        print_info "Restoring database from $(basename "$DB_ARCHIVE")"
        if bash "$ENGINE" "$PROJECT_NAME" "$DB_ARCHIVE" --non-interactive --key-file "$KEY_FILE"; then
            print_success "Database restored"
        else
            print_error "Database restore failed. The files are restored; the database is not."
            exit 1
        fi
    else
        print_warning "restore_database.sh not found beside this script; database not restored."
    fi
fi

echo "=========================================" >&2
print_success "RESTORE COMPLETE — chain ${CHAIN_ID} at run ${RESTORE_SEQ}"
echo "RESTORE_OK"
exit 0
