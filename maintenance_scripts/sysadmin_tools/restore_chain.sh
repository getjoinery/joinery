#!/usr/bin/env bash

# restore_chain.sh - Restore a project from an incremental backup chain
# Version: 1.8.0 - reads manifest version 3, whose runs each write their own manifest-NNNN.json:
#                  --artifacts may hold manifest.json or the chain's run manifests, and the newest
#                  is read (specs/storage_targets.md F9). --manifest-sha256 refuses a manifest that is
#                  not the one recorded when it was written, and the hash used is always printed
# Version: 1.7.0 - --adopt-secret-key: this machine keeps its own Globalvars_site.php and takes
#                  only the chain's secret_box_key into it, so what the source sealed opens here
#                  (a copy onto new hardware; specs/site_copy.md WP2). --skip-ssl is passed to
#                  the reconcile.
# Version: 1.6.0 - holds the host runner lock (host_runner_lock.sh) from before the first write
#                  until exit, so the host converger never runs installers against a half-restored
#                  tree
# Version: 1.5.0 - reads manifest versions 1 and 2. The plan is per kind, as BackupChain::restore_plan
#                  makes it: each kind from its newest level 0 at or before the run. A version-2
#                  chain's data archives are applied as a version-1 chain's files are; its code
#                  archives (rooted at public_html) are applied into the site directory after them.
#                  A physical database backup (pgdata) is refused before anything is written, unless
#                  --skip-database.
# Version: 1.4.1 - the offloaded-files step checks that the uploads owner can read the tree and
#                  the index before running as that account, and says what to fix when not
# Version: 1.4.0 - --objects DIR brings the run's offloaded files home after the database is
#                  loaded (utils/restore_objects.php over the shelf's objects/ tree, downloaded
#                  with the site's own credential); --objects-mode and --epoch-key go with it
# Version: 1.3.1 - the sudo probe lists the rules and requires NOPASSWD: ALL (see backup_files.sh 1.1.2)
# Version: 1.3.0 - config/backup-ledger is held across the extraction. The incremental extract
#                  replays deletions, and the directory is absent from the listings of runs
#                  taken before it existed — so a chain restore DELETED the record every later
#                  restore checks its archives against, and the next one refused everything
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
#   ./restore_chain.sh PROJECT --artifacts DIR --key-file PATH [--manifest-sha256 HEX] [--seq N] [--dry-run] [--force]
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
#   --artifacts DIR   Directory holding the chain's manifest (manifest.json, or a
#                     version-3 chain's newest manifest-NNNN.json) and the
#                     downloaded artifacts
#   --key-file PATH   The chain data key (recover it with backup_envelope.php open)
#   --manifest-sha256 HEX
#                     The manifest's sha256 as recorded when it was written (the
#                     management node shows it beside the run; the run's ledger
#                     file in the bucket holds it). A manifest that differs is
#                     refused before anything is read. Without it, the hash of
#                     the manifest used is printed for the operator to compare.
#   --seq N           Restore as at run N. Default: the newest run in the chain.
#   --domain DOMAIN   The domain the restored site is to answer to. Defaults to
#                     the domain THIS machine's config already names — a restore
#                     run by hand has an operator present, so "leave this
#                     machine's identity alone" is the right default. A restore
#                     run as a job requires the value.
#   --skip-database   Files only
#   --objects DIR     Bring the run's offloaded files home after the database is
#                     loaded: DIR is the shelf's objects/ tree ({epoch}/envelope.json
#                     and {epoch}/{name}.enc), downloaded with the site's own
#                     credential. Runs the restored tree's utils/restore_objects.php
#                     over the run's index (objects-NNNN.json.gz in --artifacts).
#   --objects-mode M  missing (default): only what the file store cannot serve;
#                     all: every offloaded file (a site leaving its bucket).
#   --epoch-key E=F   A recovered key file for epoch E, where this machine's own
#                     key does not open its envelope (repeatable).
#   --adopt-secret-key  Take the chain's secret_box_key into this machine's own
#                     config, keeping everything else in it (the database
#                     settings, the paths). For a copy of another machine's
#                     site: without it, every secret the source sealed is dead
#                     here. Refused when this machine has no config of its own.
#   --skip-ssl        Passed to the reconcile: do not arm the certificate retry
#                     (a copy that must not ask Let's Encrypt for anything)
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
# SQLSTATE[08006] on every page. --adopt-secret-key is the one exception, and it
# takes one value out of the first, never the file.

set -euo pipefail

SCRIPT_VERSION="1.7.0"
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
MANIFEST_SHA256=""
SEQ=""
TARGET_DIR_OVERRIDE=""
DRY_RUN=false
FORCE=false
SKIP_DATABASE=false
DOMAIN=""
SKIP_RECONCILE=false
SKIP_SSL=false
ADOPT_SECRET_KEY=false
OBJECTS_DIR=""
OBJECTS_MODE="missing"
EPOCH_KEYS=()

while [[ $# -gt 0 ]]; do
    case $1 in
        --artifacts)      ARTIFACT_DIR="$2"; shift 2 ;;
        --target-dir)     TARGET_DIR_OVERRIDE="$2"; shift 2 ;;
        --key-file)       KEY_FILE="$2"; shift 2 ;;
        --manifest-sha256) MANIFEST_SHA256="$(echo "$2" | tr 'A-F' 'a-f')"; shift 2 ;;
        --seq)            SEQ="$2"; shift 2 ;;
        --domain)         DOMAIN="$2"; shift 2 ;;
        --domain=*)       DOMAIN="${1#*=}"; shift ;;
        --skip-reconcile) SKIP_RECONCILE=true; shift ;;
        --skip-ssl)       SKIP_SSL=true; shift ;;
        --adopt-secret-key) ADOPT_SECRET_KEY=true; shift ;;
        --skip-database)  SKIP_DATABASE=true; shift ;;
        --objects)        OBJECTS_DIR="$2"; shift 2 ;;
        --objects-mode)   OBJECTS_MODE="$2"; shift 2 ;;
        --epoch-key)      EPOCH_KEYS+=("$2"); shift 2 ;;
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
if [ -n "$OBJECTS_DIR" ]; then
    [ -d "$OBJECTS_DIR" ] || { print_error "Offloaded-files directory not found: $OBJECTS_DIR"; exit 1; }
    case "$OBJECTS_MODE" in missing|all) ;; *) print_error "--objects-mode must be missing or all."; exit 1 ;; esac
fi

# The chain's manifest: manifest.json, or for a version-3 chain the newest of
# its run manifests (manifest-0000.json, manifest-0001.json, ...), each of
# which names every run before it.
MANIFEST="${ARTIFACT_DIR}/manifest.json"
if [ ! -f "$MANIFEST" ]; then
    MANIFEST=$(ls -1 "$ARTIFACT_DIR" 2>/dev/null | grep -E '^manifest-[0-9]{4,}\.json$' | sort -t- -k2 -n | tail -n 1 || true)
    [ -n "$MANIFEST" ] && MANIFEST="${ARTIFACT_DIR}/${MANIFEST}"
fi
[ -n "$MANIFEST" ] && [ -f "$MANIFEST" ] || { print_error "No manifest.json or manifest-NNNN.json in $ARTIFACT_DIR"; exit 1; }
# The manifest names every archive's hash, so the archives are only as good as
# it is: checked against the hash recorded when it was written, when given.
MANIFEST_ACTUAL="$(sha256sum "$MANIFEST" | cut -d' ' -f1)"
if [ -n "$MANIFEST_SHA256" ] && [ "$MANIFEST_SHA256" != "$MANIFEST_ACTUAL" ]; then
    print_error "$(basename "$MANIFEST")'s sha256 is ${MANIFEST_ACTUAL}, not the ${MANIFEST_SHA256} recorded when it was written."
    print_error "It is not the manifest that run wrote; nothing is restored from it."
    exit 1
fi
if [ -n "$MANIFEST_SHA256" ]; then
    echo "Manifest: $(basename "$MANIFEST") sha256 ${MANIFEST_ACTUAL} (matches the recorded hash)"
else
    echo "Manifest: $(basename "$MANIFEST") sha256 ${MANIFEST_ACTUAL} -- nothing vouches for it here: no --manifest-sha256 was"
    echo "  given. Compare it with the hash recorded when it was written before trusting this restore."
fi
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

version = int(m.get('version', 0))
if version not in (1, 2, 3):
    sys.exit("unsupported chain manifest version %s; this script reads versions 1, 2 and 3" % m.get('version'))

runs = m.get('runs') or []
if not runs:
    sys.exit("this chain manifest lists no runs")

seq = (len(runs) - 1) if want == '' else int(want)
if seq < 0 or seq >= len(runs):
    sys.exit("this chain has no run %d" % seq)
if version == 1 and int(runs[0].get('level', 1)) != 0:
    sys.exit("this chain does not begin with a full backup")

# The same rules as BackupChain::restore_plan. Version 1 has one level per
# run, the files archive's; version 2 records a level on every artifact of a
# kind that increments, so each kind goes back to its own newest full.
TREES = {1: ['files'], 2: ['data', 'code'], 3: ['data', 'code']}
INCREMENTS = ('files', 'code', 'data', 'pgdata')

def level(run, kind):
    if kind not in INCREMENTS:
        return 0
    if version < 2:
        return int(run.get('level', 1)) if kind == 'files' else 0
    return int(((run.get('artifacts') or {}).get(kind) or {}).get('level', 1))

def chain(kind):
    start = None
    for i in range(seq, -1, -1):
        arts = runs[i].get('artifacts') or {}
        if kind not in arts:
            sys.exit("run %d has no %s artifact" % (i, kind))
        if level(runs[i], kind) == 0:
            start = i
            break
    if start is None:
        sys.exit("this chain has no full %s backup at or before run %d" % (kind, seq))
    return [runs[i]['artifacts'][kind] for i in range(start, seq + 1)]

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

# A version-2 chain's data archive is rooted at the site directory, as a
# version-1 files archive is, and is applied the same way; its code archive is
# rooted at public_html and applied into the site directory after it.
TAG = {'files': 'FILES', 'data': 'FILES', 'code': 'CODE'}
lines = []
for kind in TREES[version]:
    for entry in chain(kind):
        lines.append("%s\t%s" % (TAG[kind], check(entry)))

last = runs[seq].get('artifacts') or {}
if 'pgdata' in last:
    for entry in chain('pgdata'):
        lines.append("PGDATA\t%s" % check(entry))
elif 'db' in last:
    lines.append("DB\t%s" % check(last['db']))
if 'meta' in last:
    lines.append("META\t%s" % check(last['meta']))

lines.append("SEQ\t%d" % seq)
lines.append("CHAIN\t%s" % m.get('chain_id', ''))
print("\n".join(lines))
PY
) || { print_error "$PLAN"; exit 1; }

FILES_ARCHIVES=()
CODE_ARCHIVES=()
PGDATA_ARCHIVES=()
DB_ARCHIVE=""
META_ARCHIVE=""
CHAIN_ID=""
RESTORE_SEQ=""
while IFS=$'\t' read -r kind value; do
    case "$kind" in
        FILES) FILES_ARCHIVES+=("$value") ;;
        CODE)  CODE_ARCHIVES+=("$value") ;;
        PGDATA) PGDATA_ARCHIVES+=("$value") ;;
        DB)    DB_ARCHIVE="$value" ;;
        META)  META_ARCHIVE="$value" ;;
        SEQ)   RESTORE_SEQ="$value" ;;
        CHAIN) CHAIN_ID="$value" ;;
    esac
done <<< "$PLAN"

print_success "Chain ${CHAIN_ID} verified: $(( ${#FILES_ARCHIVES[@]} + ${#CODE_ARCHIVES[@]} )) archive(s) to apply, restoring as at run ${RESTORE_SEQ}"

# Refused before anything is written: a restore that brought the files back and
# then could not load the database would leave the site half-restored.
if [ "${#PGDATA_ARCHIVES[@]}" -gt 0 ] && [ "$SKIP_DATABASE" = false ]; then
    print_error "This run's database is a physical backup (pgdata), which this script cannot restore."
    print_error "Restore the files with --skip-database, or use a build that restores physical backups."
    exit 1
fi

if [ "$DRY_RUN" = true ]; then
    print_dry "Would apply, in order:"
    for a in "${FILES_ARCHIVES[@]}"; do print_dry "  $(basename "$a")"; done
    for a in ${CODE_ARCHIVES[@]+"${CODE_ARCHIVES[@]}"}; do print_dry "  $(basename "$a") (into the site directory)"; done
    [ -n "$DB_ARCHIVE" ] && print_dry "Then restore database from $(basename "$DB_ARCHIVE")"
    [ -n "$OBJECTS_DIR" ] && print_dry "Then bring offloaded files home from ${OBJECTS_DIR} (${OBJECTS_MODE})"
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
    print_error "  backup_envelope.php open --sidecar <manifest> --manifest-sha256 <recorded hash> --private <recovery key>"
    exit 1
fi

if [ "$ARCHIVE_ROOT" != "$(basename "$PROJECT_DIR")" ]; then
    print_error "This chain restores a directory named '${ARCHIVE_ROOT}', so it can only be written to a path ending in that name."
    print_error "Requested: ${PROJECT_DIR}"
    print_error "Use:       --target-dir ${PARENT}/${ARCHIVE_ROOT}"
    exit 1
fi

# A code archive is rooted at public_html and extracted into the site
# directory; anything else in that position would write somewhere unasked for.
if [ "${#CODE_ARCHIVES[@]}" -gt 0 ]; then
    CODE_ROOT="$( { ( openssl enc -aes-256-cbc -d -pbkdf2 -pass fd:3 \
                         -in "${CODE_ARCHIVES[0]}" 2>/dev/null \
                       | tar tzf - 2>/dev/null ) 3< "$KEY_FILE" || true; } \
                  | head -n 1 | cut -d/ -f1 || true )"
    if [ "$CODE_ROOT" != "public_html" ]; then
        print_error "The code archive $(basename "${CODE_ARCHIVES[0]}") is not rooted at public_html (it reads '${CODE_ROOT}'); not restoring."
        exit 1
    fi
fi

# The key is spliced into this machine's own config, so there has to be one:
# without it there is no database password to keep, and a restore that ends
# with the source's config (or none) is the SQLSTATE[08006] this script exists
# to prevent. Asked before anything is written.
if [ "$ADOPT_SECRET_KEY" = true ] && [ ! -e "${PROJECT_DIR}/config/Globalvars_site.php" ]; then
    print_error "--adopt-secret-key needs this machine's own ${PROJECT_DIR}/config/Globalvars_site.php to"
    print_error "take the key into, and there is none. Install the site first; nothing was restored."
    exit 1
fi

if [ "$FORCE" != true ]; then
    echo "About to restore ${PROJECT_DIR} from chain ${CHAIN_ID} at run ${RESTORE_SEQ}." >&2
    echo "Files deleted since the full backup will be deleted here too." >&2
    read -p "Continue? (y/N): " -n 1 -r; echo >&2
    [[ $REPLY =~ ^[Yy]$ ]] || { print_info "Cancelled."; exit 0; }
fi

# Lists the rules and requires NOPASSWD: ALL — an account holding one narrow
# NOPASSWD rule validates with -v and is then refused the real command; see
# backup_files.sh 1.1.2. Listing sends none of the mail an attempt would.
SUDO=""
if [ "$(id -u)" -ne 0 ]; then
    if command -v sudo >/dev/null 2>&1 && sudo -n -l 2>/dev/null | grep -Eq 'NOPASSWD:([[:space:]]*[A-Z]+:)*[[:space:]]*ALL([[:space:]]|$)'; then SUDO="sudo"; fi
fi

# One runner at a time: the host converger runs the plugin installers and
# site_housekeeping.sh against this tree when the release changes, and must
# never do it against a half-restored one. Held until this script exits.
# shellcheck source=host_runner_lock.sh
source "${SCRIPT_DIR}/host_runner_lock.sh"
hold_host_runner_lock "$(basename "$PROJECT_DIR")" "$PROJECT_DIR" || exit 1

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
cleanup_kept() { rm -rf "${KEEP_TMP:?}"; }
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

# config/backup-ledger is held across the extraction for a DIFFERENT reason, and
# it is a directory rather than a file.
#
# The incremental extract below replays deletions: each archive carries its
# directories' full listings and tar removes anything local the listing does not
# mention. This directory did not exist when the chain's earlier runs were taken,
# so config/'s listing does not mention it — and tar deletes it. Not overwrites:
# deletes. The first chain restore would therefore destroy the record every
# LATER restore checks its archives against, and the second restore would refuse
# everything with "this node has no upload ledger". A safety file that is only
# missing on the day it is needed is worse than no safety file at all.
#
# Best effort, deliberately, and this is the difference from the two files
# above. Losing Globalvars_site.php or backup_site_key is unrecoverable or
# site-breaking, so failing to hold those aborts the restore. Losing the ledger
# costs the NEXT restore, not this one, and it rebuilds itself from the next
# backup run — so it must never be the reason a recovery in progress stops.
LEDGER_REL="config/backup-ledger"
LEDGER_KEPT=false
if [ -d "${PROJECT_DIR}/${LEDGER_REL}" ]; then
    mkdir -p "${KEEP_TMP}/config"
    if ${SUDO} cp -a "${PROJECT_DIR}/${LEDGER_REL}" "${KEEP_TMP}/${LEDGER_REL}" 2>/dev/null; then
        LEDGER_KEPT=true
        print_info "Holding this machine's own ${LEDGER_REL} across the extraction"
    else
        print_warning "Could not hold ${LEDGER_REL} across the extraction. The restore continues;"
        print_warning "archives uploaded before the next backup run may not be restorable over the"
        print_warning "agent channel until then."
    fi
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
i=0
for archive in ${CODE_ARCHIVES[@]+"${CODE_ARCHIVES[@]}"}; do
    print_info "Applying $(basename "$archive") into ${PROJECT_DIR} ($((i+1))/${#CODE_ARCHIVES[@]})"
    STATUS=0
    ( set -o pipefail
      openssl enc -aes-256-cbc -d -pbkdf2 -pass fd:3 -in "$archive" 2>/dev/null \
        | ${SUDO} tar --incremental --warning=no-timestamp -xzf - -C "$PROJECT_DIR" \
    ) 3< "$KEY_FILE" || STATUS=$?
    if [ "$STATUS" -ne 0 ]; then
        print_error "Failed applying $(basename "$archive") (exit ${STATUS})."
        print_error "The tree is part-restored; re-run from the start once the cause is fixed."
        exit 1
    fi
    i=$((i+1))
done
print_success "Files restored to ${PROJECT_DIR}"

# The ledger goes back first, and wholesale: this machine's record of what IT
# uploaded is the only correct one, and a chain from another machine carries
# hashes of files this machine never wrote. The destination is removed rather
# than merged for the same reason — a merge would leave the archive's stale
# entries sitting beside the live ones.
if [ "$LEDGER_KEPT" = true ]; then
    ${SUDO} rm -rf "${PROJECT_DIR:?}/${LEDGER_REL:?}"
    ${SUDO} mkdir -p "${PROJECT_DIR}/config"
    if ${SUDO} cp -a "${KEEP_TMP}/${LEDGER_REL}" "${PROJECT_DIR}/${LEDGER_REL}"; then
        print_info "Put this machine's own ${LEDGER_REL} back"
    else
        print_warning "Could not put ${LEDGER_REL} back; a copy is at ${KEEP_TMP}/${LEDGER_REL}"
        print_warning "until this script exits. Restores over the agent channel will refuse until"
        print_warning "the next backup run rebuilds it."
    fi
fi

# The chain's copy of the site config, taken before this machine's is put back
# over it: --adopt-secret-key reads one value out of it below. Copied, not
# moved, and into KEEP_TMP (this user's, 0700), because it holds the source
# machine's database password too and nothing else of it is kept.
CHAIN_CONFIG=""
if [ "$ADOPT_SECRET_KEY" = true ] && ${SUDO} test -f "${PROJECT_DIR}/config/Globalvars_site.php"; then
    CHAIN_CONFIG="${KEEP_TMP}/chain_Globalvars_site.php"
    ( umask 077; ${SUDO} cat "${PROJECT_DIR}/config/Globalvars_site.php" > "$CHAIN_CONFIG" ) || CHAIN_CONFIG=""
fi

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

# ── --adopt-secret-key ──────────────────────────────────────────────────────
#
# A copy onto new hardware: this machine keeps its own config and takes the
# source's secret_box_key into it, so every secret the source sealed opens here.
# One value, never the file: the database settings, the paths and
# deployment_environment are this machine's.
#
# The value is read out of the chain's copy by pattern, never by running it
# (it is PHP from another machine), checked to be the 32-byte key SecretBox
# requires, and written with var_export. The new file is linted before it
# replaces the old one, and keeps its owner and mode. The key is never printed.
if [ "$ADOPT_SECRET_KEY" = true ]; then
    CONFIG="${PROJECT_DIR}/config/Globalvars_site.php"
    SPLICED="${CONFIG}.adopt.$$"
    ADOPT_STATUS=""
    if [ -n "$CHAIN_CONFIG" ]; then
        ADOPT_STATUS=$(${SUDO} php -- "$CHAIN_CONFIG" "$CONFIG" "$SPLICED" 2>/dev/null <<'PHP'
<?php
[, $src, $dst, $out] = $argv;
$assign = '/^([ \t]*\$this->settings\[([\'"])secret_box_key\2\][ \t]*=[ \t]*)([\'"])([^\'"\n]*)\3[ \t]*;/m';
$chain = @file_get_contents($src);
$mine = @file_get_contents($dst);
if ($chain === false || $mine === false) { echo "UNREADABLE"; exit(0); }
// The last assignment is the one PHP would leave standing.
if (!preg_match_all($assign, $chain, $m) || $m[4] === array()) { echo "NOKEY"; exit(0); }
$key = end($m[4]);
$raw = base64_decode($key, true);
if ($raw === false || strlen($raw) !== 32) { echo "BADKEY"; exit(0); }
if (preg_match_all($assign, $mine, $t) && $t[4] !== array() && end($t[4]) === $key) { echo "SAME"; exit(0); }
$line = '$this->settings[\'secret_box_key\'] = ' . var_export($key, true) . ';';
$count = 0;
$new = preg_replace_callback($assign, function ($x) use ($line) { return $line; }, $mine, -1, $count);
if ($count === 0) {
	// Before a closing tag, where one exists: anything after it is page output.
	$block = "\n// Key for SecretBox (secrets at rest), taken from the site this is a copy of.\n" . $line . "\n";
	$at = strrpos($new, '?>');
	$new = $at === false ? rtrim($new, "\n") . "\n" . $block : substr($new, 0, $at) . $block . "\n" . substr($new, $at);
}
$old = umask(077);
$ok = file_put_contents($out, $new) === strlen($new);
umask($old);
if (!$ok) { @unlink($out); echo "UNWRITABLE"; exit(0); }
@chmod($out, fileperms($dst) & 0777);
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
	@chown($out, fileowner($dst));
	@chgrp($out, filegroup($dst));
}
echo "SPLICED";
PHP
        ) || ADOPT_STATUS="FAILED"
    else
        ADOPT_STATUS="NOCONFIG"
    fi

    case "$ADOPT_STATUS" in
        SAME)
            print_info "This machine's secret_box_key is already the chain's; nothing to adopt." ;;
        SPLICED)
            if ${SUDO} php -l "$SPLICED" >/dev/null 2>&1 && ${SUDO} mv -f "$SPLICED" "$CONFIG"; then
                print_success "Adopted the chain's secret_box_key into this machine's own config"
            else
                ${SUDO} rm -f "$SPLICED"
                print_error "The config with the chain's secret_box_key did not pass php -l; this machine's"
                print_error "own config is unchanged, so what the source sealed will not open here."
                exit 1
            fi ;;
        *)
            ${SUDO} rm -f "$SPLICED" 2>/dev/null
            case "$ADOPT_STATUS" in
                NOCONFIG) why="the chain carries no config/Globalvars_site.php" ;;
                NOKEY)    why="the chain's config names no secret_box_key" ;;
                BADKEY)   why="the chain's secret_box_key is not 32 base64-encoded bytes" ;;
                *)        why="the configs could not be read or written (${ADOPT_STATUS:-no answer})" ;;
            esac
            print_error "Could not adopt the chain's secret_box_key: ${why}."
            print_error "The files are restored with this machine's own config and key, under which the"
            print_error "source's sealed secrets do not open; the database is not restored."
            exit 1 ;;
    esac
fi

# The meta artifact holds shape.json and the captured virtualhost. Neither is
# installed: it is unpacked so the reconcile step can say what shape this backup
# came off, and keep a differing virtualhost beside the live one for review.
META_TMP=""
if [ -n "$META_ARCHIVE" ]; then
    META_TMP=$(mktemp -d)
    cleanup_kept() { rm -rf "${KEEP_TMP:?}" "${META_TMP:?}"; }
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

# ── Offloaded files ─────────────────────────────────────────────────────────
#
# The archives carry no offloaded file: those are on the shelf under objects/,
# named by the run's index. Now that the database is back, the restored tree's
# own restore_objects.php puts them where the site expects them — only the
# ones the file store cannot serve, unless --objects-mode all. It runs as the
# account that owns the uploads directory, so what lands there is the web
# user's and not root's. A failure here does not stop the reconcile below: a
# site that disagrees with its machine is broken in ways nobody would trace to
# a missing photo, and this step can be run again on its own.
OBJECTS_FAILED=false
if [ -n "$OBJECTS_DIR" ]; then
    INDEX_NAME="objects-$(printf '%04d' "$RESTORE_SEQ").json.gz"
    INDEX_FILE="${ARTIFACT_DIR}/${INDEX_NAME}"
    OBJECTS_ENGINE="${PROJECT_DIR}/public_html/utils/restore_objects.php"
    if [ ! -f "$INDEX_FILE" ]; then
        print_warning "Run ${RESTORE_SEQ} carries no offloaded-files index (${INDEX_NAME} is not in ${ARTIFACT_DIR}),"
        print_warning "so there is nothing to bring home from ${OBJECTS_DIR}."
    elif [ ! -f "$OBJECTS_ENGINE" ]; then
        print_error "The restored tree has no utils/restore_objects.php, so its offloaded files cannot be brought home here."
        print_error "Once the site is upgraded, run: php public_html/utils/restore_objects.php --index ${INDEX_FILE} --objects ${OBJECTS_DIR} --mode ${OBJECTS_MODE}"
        OBJECTS_FAILED=true
    else
        OBJECTS_ARGS=(--index "$INDEX_FILE" --objects "$OBJECTS_DIR" --mode "$OBJECTS_MODE")
        for ek in ${EPOCH_KEYS[@]+"${EPOCH_KEYS[@]}"}; do OBJECTS_ARGS+=(--epoch-key "$ek"); done
        RUN_AS=()
        UPLOADS_OWNER="$(stat -c %U "${PROJECT_DIR}/public_html/static_files/uploads" 2>/dev/null || true)"
        if [ -n "$UPLOADS_OWNER" ] && [ "$UPLOADS_OWNER" != "$(id -un)" ]; then
            if [ "$(id -u)" -eq 0 ]; then RUN_AS=(runuser -u "$UPLOADS_OWNER" --)
            elif [ -n "$SUDO" ]; then RUN_AS=(sudo -n -u "$UPLOADS_OWNER")
            fi
        fi
        # The tree and the index were downloaded by whoever is running this,
        # often root with a private umask; the engine runs as the uploads
        # owner and has to be able to read them, or it refuses the request
        # by a message that looks like a missing file.
        if [ ${#RUN_AS[@]} -gt 0 ] && ! "${RUN_AS[@]}" test -r "$INDEX_FILE" -a -r "$OBJECTS_DIR" -a -x "$OBJECTS_DIR"; then
            print_error "The offloaded files at ${OBJECTS_DIR} (or the index ${INDEX_FILE}) are not readable by ${UPLOADS_OWNER},"
            print_error "who owns the uploads directory and runs this step. Make them readable, then run:"
            print_error "  php ${OBJECTS_ENGINE} --index ${INDEX_FILE} --objects ${OBJECTS_DIR} --mode ${OBJECTS_MODE}"
            OBJECTS_FAILED=true
        else
        print_info "Bringing offloaded files home from ${OBJECTS_DIR} (${OBJECTS_MODE})"
        if ${RUN_AS[@]+"${RUN_AS[@]}"} php "$OBJECTS_ENGINE" "${OBJECTS_ARGS[@]}"; then
            print_success "Offloaded files brought home"
        else
            print_error "Not every offloaded file came home; see the RESTORE_OBJECTS_ lines above."
            print_error "Run it again once the cause is fixed:"
            print_error "  php ${OBJECTS_ENGINE} --index ${INDEX_FILE} --objects ${OBJECTS_DIR} --mode ${OBJECTS_MODE}"
            OBJECTS_FAILED=true
        fi
        fi
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
    [ "$SKIP_SSL" = true ] && RECONCILE_ARGS+=(--skip-ssl)

    print_info "Reconciling the restored site to this machine..."
    if ! ${SUDO} bash "$RECONCILE" "${RECONCILE_ARGS[@]}"; then
        print_error "Reconciliation failed. The files and database are restored, but the site does"
        print_error "not yet agree with this machine — see the RECONCILE_ lines above."
        exit 1
    fi
fi

echo "=========================================" >&2
if [ "$OBJECTS_FAILED" = true ]; then
    print_error "Files and database restored from chain ${CHAIN_ID} at run ${RESTORE_SEQ}, but its offloaded files are not all home (see above)."
    exit 1
fi
print_success "RESTORE COMPLETE — chain ${CHAIN_ID} at run ${RESTORE_SEQ}"
echo "RESTORE_OK"
exit 0
