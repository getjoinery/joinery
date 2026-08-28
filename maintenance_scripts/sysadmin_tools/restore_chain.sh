#!/usr/bin/env bash

# restore_chain.sh - Restore a project from an incremental backup chain
# Version: 1.2.1 - the sudo capability probe asks with -v instead of running true,
#                  which sudo mails root about when the account may not. Same
#                  change in the sibling scripts.
# Version: 1.2.0 - the chain is reconciled to the machine it lands on, exactly as the archive
#                  path is: this machine's own config and site key survive the extraction, the
#                  captured virtualhost is never installed, and reconcile_site.sh settles the
#                  domain, the shape and the serving config. --domain names the result.
# Version: 1.1.0 - a target whose last segment is not the directory the archive
#                  carries is refused. It used to restore to dirname(target) plus
#                  the archive's own name and still report success, so a restore
#                  aimed at a scratch directory could land on the live site --
#                  and extraction deletes files, so that is destructive, not
#                  merely surprising. --help no longer truncates its own options.
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
#                     Its LAST SEGMENT must be the directory name the archive
#                     carries (the one backup_files.sh archived), because tar
#                     recreates that directory itself. A mismatch is refused,
#                     naming the path to use.
#   --artifacts DIR   Directory holding manifest.json and the downloaded artifacts
#   --key-file PATH   The chain data key (recover it with backup_envelope.php open)
#   --seq N           Restore as at run N. Default: the newest run in the chain.
#   --domain DOMAIN   The domain the restored site is to answer to. Defaults to
#                     the domain THIS machine's config already names — a restore
#                     run by hand has an operator present, so "leave this
#                     machine's identity alone" is the right default. A restore
#                     run as a job requires the value.
#   --skip-database   Files only
#   --skip-reconcile  Do not reconcile to this machine (files-only rehearsals and
#                     restores into a scratch --target-dir)
#   --dry-run         Verify the chain and report the plan; change nothing
#   --force           Skip the confirmation prompt
#   --help
#
# Two files are the MACHINE's, not the chain's, and survive the extraction:
# config/Globalvars_site.php (this machine's database password and secret_box_key)
# and config/backup_site_key (one machine's identity as a recipient of its own
# backups). Inheriting either is how a clean-looking restore ends in
# SQLSTATE[08006] on every page.

set -euo pipefail

SCRIPT_VERSION="1.2.1"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

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
DOMAIN=""
SKIP_RECONCILE=false

while [[ $# -gt 0 ]]; do
    case $1 in
        --artifacts)      ARTIFACT_DIR="$2"; shift 2 ;;
        --target-dir)     TARGET_DIR_OVERRIDE="$2"; shift 2 ;;
        --key-file)       KEY_FILE="$2"; shift 2 ;;
        --seq)            SEQ="$2"; shift 2 ;;
        --domain)         DOMAIN="$2"; shift 2 ;;
        --domain=*)       DOMAIN="${1#*=}"; shift ;;
        --skip-reconcile) SKIP_RECONCILE=true; shift ;;
        --skip-database)  SKIP_DATABASE=true; shift ;;
        --dry-run|-n)    DRY_RUN=true; shift ;;
        --force|-f)      FORCE=true; shift ;;
        # Print the header block to its end rather than a fixed line range, which
        # silently dropped the last options as the header grew.
        --help|-h)       awk 'NR<3 {next} /^#/ {sub(/^# ?/,""); print; next} {exit}' "$0"; exit 0 ;;
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

# The archive carries its own top-level directory — backup_files.sh tars
# `basename` of what it archived, from that directory's parent — so extraction
# necessarily lands at PARENT/<that name>, and the last segment of the target is
# not free. Left unchecked, --target-dir silently restored somewhere else and
# still reported success: pointing it at /var/www/html/scratch would extract over
# /var/www/html/<project>, the live site, and extraction runs with
# `tar --incremental`, which DELETES local files the archive's listing does not
# mention. So read the name out of the archive and refuse a target that does not
# match, rather than writing to a directory nobody asked for.
ARCHIVE_ROOT=""
if [ "${#FILES_ARCHIVES[@]}" -gt 0 ]; then
    ARCHIVE_ROOT="$( { ( openssl enc -aes-256-cbc -d -pbkdf2 -pass fd:3 \
                            -in "${FILES_ARCHIVES[0]}" 2>/dev/null \
                          | tar tzf - 2>/dev/null ) 3< "$KEY_FILE" || true; } \
                     | head -n 1 | cut -d/ -f1 || true )"
fi

if [ -z "$ARCHIVE_ROOT" ]; then
    print_error "Could not read the archive's contents with this key."
    print_error "Check --key-file: it must be the chain data key, recovered with"
    print_error "  backup_envelope.php open --sidecar <manifest.json> --private <recovery key>"
    exit 1
fi

if [ "$ARCHIVE_ROOT" != "$(basename "$PROJECT_DIR")" ]; then
    print_error "This chain restores a directory named '${ARCHIVE_ROOT}', so it can only be written to a path ending in that name."
    print_error "Requested: ${PROJECT_DIR}"
    print_error "Use:       --target-dir ${PARENT}/${ARCHIVE_ROOT}"
    exit 1
fi

if [ "$FORCE" != true ]; then
    echo "About to restore ${PROJECT_DIR} from chain ${CHAIN_ID} at run ${RESTORE_SEQ}." >&2
    echo "Files deleted since the full backup will be deleted here too." >&2
    read -p "Continue? (y/N): " -n 1 -r; echo >&2
    [[ $REPLY =~ ^[Yy]$ ]] || { print_info "Cancelled."; exit 0; }
fi

# -v asks the question without making the escalation attempt sudo mails root about;
# see backup_files.sh for why. Same test as the sibling scripts use.
SUDO=""
if [ "$(id -u)" -ne 0 ]; then
    if command -v sudo >/dev/null 2>&1 && sudo -n -v 2>/dev/null; then SUDO="sudo"; fi
fi

mkdir -p "$PARENT"

# ── Keep what belongs to this machine ───────────────────────────────────────
#
# Extraction is incremental, which means it overwrites and it deletes — it will
# happily replace this machine's site config with the source machine's. That
# config holds the database password for the PostgreSQL on THIS box and the
# secret_box_key that every secret at rest was encrypted with. Losing either is
# unrecoverable in the second case and a site-wide SQLSTATE[08006] in the first.
#
# So they are copied out first and copied back after, rather than excluded:
# --exclude on an incremental extract governs what is written, not what the
# dumpdir listing causes tar to delete.
KEEP_TMP=$(mktemp -d)
KEPT_FILES=()
cleanup_kept() { rm -rf "$KEEP_TMP"; }
trap cleanup_kept EXIT

for rel in config/Globalvars_site.php config/backup_site_key; do
    if [ -f "${PROJECT_DIR}/${rel}" ]; then
        mkdir -p "${KEEP_TMP}/$(dirname "$rel")"
        if cp -a "${PROJECT_DIR}/${rel}" "${KEEP_TMP}/${rel}" 2>/dev/null \
           || ${SUDO} cp -a "${PROJECT_DIR}/${rel}" "${KEEP_TMP}/${rel}" 2>/dev/null; then
            KEPT_FILES+=("$rel")
        else
            print_error "Could not take a copy of ${PROJECT_DIR}/${rel} before extraction."
            print_error "Refusing to extract over it — it holds this machine's database password"
            print_error "and its secret_box_key, and the chain carries the source machine's."
            exit 1
        fi
    fi
done
if [ "${#KEPT_FILES[@]}" -gt 0 ]; then
    print_info "Holding this machine's own ${KEPT_FILES[*]} across the extraction"
fi

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

# Put this machine's own files back over whatever the chain brought.
for rel in ${KEPT_FILES[@]+"${KEPT_FILES[@]}"}; do
    ${SUDO} mkdir -p "${PROJECT_DIR}/$(dirname "$rel")"
    if ! ${SUDO} cp -a "${KEEP_TMP}/${rel}" "${PROJECT_DIR}/${rel}"; then
        # The only copy of this machine's secret_box_key is now the one in
        # KEEP_TMP, so the cleanup trap must not run. Pointing at a path that is
        # about to be deleted would be worse than saying nothing.
        trap - EXIT
        print_error "Could not put this machine's ${rel} back after extraction."
        print_error "The only copy is at ${KEEP_TMP}/${rel} and has been left there deliberately."
        print_error "Put it back at ${PROJECT_DIR}/${rel} before the site is used: it holds this"
        print_error "machine's database password and its secret_box_key, and without the latter"
        print_error "every secret encrypted at rest on this box is unreadable."
        exit 1
    fi
done
if [ "${#KEPT_FILES[@]}" -gt 0 ]; then
    print_success "This machine's own ${KEPT_FILES[*]} kept"
fi

# The meta artifact holds shape.json and the captured virtualhost. Neither is
# installed: it is unpacked so the reconcile step can say what shape this backup
# came off, and keep a differing virtualhost beside the live one for review.
META_TMP=""
if [ -n "$META_ARCHIVE" ]; then
    META_TMP=$(mktemp -d)
    cleanup_kept() { rm -rf "$KEEP_TMP" "$META_TMP"; }
    ( set -o pipefail
      openssl enc -aes-256-cbc -d -pbkdf2 -pass fd:3 -in "$META_ARCHIVE" 2>/dev/null \
        | tar -xzf - -C "$META_TMP" ) 3< "$KEY_FILE" || true
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

# ── Reconcile to this machine ───────────────────────────────────────────────
#
# Same step the archive path runs, for the same reason: the chain came off a
# machine that is not necessarily this one, and a site that disagrees with the
# machine under it fails in ways that look like anything but a bad restore.
#
# Skipped for a --target-dir rehearsal, which is deliberately not a live site.
if [ "$SKIP_RECONCILE" = true ] || [ -n "$TARGET_DIR_OVERRIDE" ]; then
    print_info "Not reconciling (restored to ${PROJECT_DIR} as files only)."
else
    RECONCILE="${SCRIPT_DIR}/reconcile_site.sh"
    if [ ! -f "$RECONCILE" ]; then
        print_error "reconcile_site.sh is not beside this script — the restored site would be left"
        print_error "pointing at the source machine's domain, shape and serving config."
        exit 1
    fi

    USE_DOMAIN="$DOMAIN"
    if [ -z "$USE_DOMAIN" ]; then
        USE_DOMAIN=$(${SUDO} sed -n "s/^[[:space:]]*\$this->settings\['webDir'\][[:space:]]*=[[:space:]]*'\([^']*\)'.*/\1/p" \
            "${PROJECT_DIR}/config/Globalvars_site.php" 2>/dev/null | head -1)
        if [ -z "$USE_DOMAIN" ]; then
            print_error "No --domain was given and this machine's config names none."
            exit 1
        fi
        print_info "No --domain given; keeping this machine's own domain: ${USE_DOMAIN}"
    fi

    RECONCILE_ARGS=("$PROJECT_NAME" --domain "$USE_DOMAIN")
    [ -n "$META_TMP" ] && RECONCILE_ARGS+=(--backup-meta "$META_TMP")

    print_info "Reconciling the restored site to this machine..."
    if ! ${SUDO} bash "$RECONCILE" "${RECONCILE_ARGS[@]}"; then
        print_error "Reconciliation failed. The files and database are restored, but the site does"
        print_error "not yet agree with this machine — see the RECONCILE_ lines above."
        exit 1
    fi
fi

echo "=========================================" >&2
print_success "RESTORE COMPLETE — chain ${CHAIN_ID} at run ${RESTORE_SEQ}"
echo "RESTORE_OK"
exit 0
