#!/usr/bin/env bash

# verify_backup.sh - Prove a downloaded backup chain can be recovered, without restoring it
# Version: 1.0.0
#
# Description:
#   Runs the platform's backup verification engine (includes/BackupVerifier.php)
#   over a chain directory you downloaded by hand, with a chain key you
#   recovered yourself. This is the one verification path that exercises the
#   RECOVERY PRIVATE KEY: the scheduled verify on a node opens a chain with the
#   node's own site key, and the recovery key never travels — so proving that
#   the key in your password manager still opens a backup is a thing only a
#   person can do, here.
#
#     Level 2 — opened and read: every artifact a restore of the run would apply
#               is checked against the manifest, decrypted to a pipe and read to
#               the end. Nothing is written except the report.
#     Level 3 — rehearsed: level 2, then the files are replayed into a scratch
#               directory under the chain directory and the dump is loaded into
#               a throwaway database on this machine's PostgreSQL; both are
#               counted and removed.
#
#   Nothing on any live site is touched at either level. The chain directory is
#   yours and is left as it was (a rehearsal's scratch/ subdirectory is removed).
#
# Usage:
#   ./verify_backup.sh --artifacts DIR --key-file PATH [--seq N] [--level 2|3] [--project NAME]
#
# Options:
#   --artifacts DIR   Directory holding manifest.json and the artifacts it names
#                     (download the chain's whole directory from the bucket)
#   --key-file PATH   The chain data key. Recover it with the recovery private key:
#                       php backup_envelope.php open --sidecar DIR/manifest.json \
#                           --private /path/to/recovery.key --key-out /tmp/chain.key
#   --seq N           Verify as at run N. Default: the newest run in the chain.
#   --level 2|3       2 opens and reads (default); 3 rehearses a restore.
#   --project NAME    The directory name the archive carries, for a rehearsal.
#                     Read from the archive when not given; a wrong name is refused.
#   --help
#
# Runs from a site's maintenance_scripts directory: the engine is PHP in the
# public_html beside it, and a rehearsal uses restore_chain.sh and
# restore_database.sh from this directory. Prints the VERIFY_* report the
# scheduled verify prints, and exits 0 on pass or skipped, 1 on fail, 2 on a
# request it could not understand.

set -euo pipefail

SCRIPT_VERSION="1.0.0"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SITE_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
ENGINE="${SITE_ROOT}/public_html/utils/verify_backup.php"

RED='\033[0;31m'; GREEN='\033[0;32m'; BLUE='\033[0;34m'; NC='\033[0m'
print_info()    { echo -e "${BLUE}[INFO]${NC} $1" >&2; }
print_success() { echo -e "${GREEN}[SUCCESS]${NC} $1" >&2; }
print_error()   { echo -e "${RED}[ERROR]${NC} $1" >&2; }

ARTIFACT_DIR=""
KEY_FILE=""
SEQ=""
LEVEL="2"
PROJECT=""

while [[ $# -gt 0 ]]; do
    case $1 in
        --artifacts)   ARTIFACT_DIR="$2"; shift 2 ;;
        --artifacts=*) ARTIFACT_DIR="${1#*=}"; shift ;;
        --key-file)    KEY_FILE="$2"; shift 2 ;;
        --key-file=*)  KEY_FILE="${1#*=}"; shift ;;
        --seq)         SEQ="$2"; shift 2 ;;
        --seq=*)       SEQ="${1#*=}"; shift ;;
        --level)       LEVEL="$2"; shift 2 ;;
        --level=*)     LEVEL="${1#*=}"; shift ;;
        --project)     PROJECT="$2"; shift 2 ;;
        --project=*)   PROJECT="${1#*=}"; shift ;;
        --help|-h)     awk 'NR<3 {next} /^#/ {sub(/^# ?/,""); print; next} {exit}' "$0"; exit 0 ;;
        *)             print_error "Unknown option: $1"; exit 2 ;;
    esac
done

[ -n "$ARTIFACT_DIR" ] || { print_error "--artifacts is required."; exit 2; }
[ -d "$ARTIFACT_DIR" ] || { print_error "Artifact directory not found: $ARTIFACT_DIR"; exit 2; }
[ -f "$ARTIFACT_DIR/manifest.json" ] || { print_error "No manifest.json in $ARTIFACT_DIR"; exit 2; }
[ -n "$KEY_FILE" ] || { print_error "--key-file is required (recover it with backup_envelope.php open)."; exit 2; }
[ -f "$KEY_FILE" ] || { print_error "--key-file '$KEY_FILE' does not exist"; exit 2; }
case "$LEVEL" in 2|3) ;; *) print_error "--level must be 2 or 3"; exit 2 ;; esac
if [ -n "$SEQ" ] && ! [[ "$SEQ" =~ ^[0-9]+$ ]]; then print_error "--seq must be a run number"; exit 2; fi
[ -f "$ENGINE" ] || { print_error "The verification engine is not at $ENGINE; run this from a site's maintenance_scripts/sysadmin_tools."; exit 2; }
command -v php >/dev/null 2>&1 || { print_error "php is required."; exit 2; }

ARTIFACT_DIR="$(cd "$ARTIFACT_DIR" && pwd)"
KEY_FILE="$(cd "$(dirname "$KEY_FILE")" && pwd)/$(basename "$KEY_FILE")"

if [ "$LEVEL" = "3" ]; then
    print_info "Rehearsing a restore of $(basename "$ARTIFACT_DIR")${SEQ:+ as at run $SEQ}: the files replay into ${ARTIFACT_DIR}/scratch and the dump loads into a throwaway database; both are removed afterwards."
else
    print_info "Opening and reading $(basename "$ARTIFACT_DIR")${SEQ:+ as at run $SEQ} with the key at $(basename "$KEY_FILE")."
fi

# The request crosses on stdin as JSON, the way every caller of the engine's
# script talks to it. Paths are JSON-encoded by php itself so a directory with
# a quote or a backslash in its name cannot break the request.
REQUEST=$(php -r '
    $seq = $argv[3] === "" ? null : (int)$argv[3];
    echo json_encode(array(
        "artifacts_dir" => $argv[1], "key_file" => $argv[2], "seq" => $seq,
        "level" => (int)$argv[4], "project" => $argv[5]));
' -- "$ARTIFACT_DIR" "$KEY_FILE" "$SEQ" "$LEVEL" "$PROJECT")

set +e
REPORT=$(printf '%s' "$REQUEST" | php "$ENGINE")
RC=$?
set -e

printf '%s\n' "$REPORT"

RESULT=$(printf '%s\n' "$REPORT" | sed -n 's/^VERIFY_RESULT=//p' | head -1)
REASON=$(printf '%s\n' "$REPORT" | sed -n 's/^VERIFY_REASON=//p' | head -1)
case "$RESULT" in
    pass)
        if [ "$LEVEL" = "3" ]; then
            print_success "Rehearsed: the chain restores with this key. It is verified restorable."
        else
            print_success "Opened and read to the end with this key. It is verified restorable at this level."
        fi
        ;;
    skipped) print_info "Could not verify: ${REASON}" ;;
    *)       print_error "Verification failed: ${REASON:-no reason reported}" ;;
esac
exit "$RC"
