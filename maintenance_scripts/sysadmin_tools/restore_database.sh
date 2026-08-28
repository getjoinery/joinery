#!/usr/bin/env bash
#Version 3.4 - An envelope-sealed archive restores unattended: when no --key-file is given, the
#              <archive>.keys.json sidecar beside it is opened with this machine's own
#              backup_site_key, the way restore_project.sh 1.3.0 already does
#Version 3.3 - A dump from a newer PostgreSQL is refused before the schema is dropped, not
#              after the load fails on it. An 18 dump into a 16 server died on line 13 with
#              the target already emptied
#Version 3.2 - Stale-staging sweep at startup (SIGKILL strands run no trap); jy_restore_ temp prefix
#Version 3.1 - Staging writes checked (a truncated staged file can never load); plain-SQL sanity
#              check before destroy; key via stdin (never argv); DB probe failures distinguished
#              (DB_UNREACHABLE) from load failures; every exit path emits a marker
#Version 3.0 - Single restore engine: verify-before-destroy, schema-replace, machine-readable markers

# The one implementation of PostgreSQL restore used by every path (dashboard
# jobs, project restore, from-backup install, manual ops). Contract:
#
#   restore_database.sh DB_NAME FILE [--non-interactive] [--key-file PATH] [--db-user USER]
#
#   * Verify before destroy: the archive is decrypted (if .enc) and integrity-
#     checked into a temp file BEFORE the target database is touched. A bad key,
#     truncated file, or corrupt archive exits with the database untouched.
#   * Replace semantics: DROP SCHEMA public CASCADE; CREATE SCHEMA public; then
#     load under ON_ERROR_STOP=1 as --db-user (no dropdb/createdb superuser need).
#   * All informational output goes to stderr. stdout carries ONLY one terminal
#     marker so callers (JobResultProcessor) can parse the outcome:
#         RESTORE_OK | BACKUP_KEY_MISSING | DECRYPT_FAILED
#         ARCHIVE_CORRUPT | RESTORE_LOAD_FAILED | DB_UNREACHABLE
#         RESTORE_USAGE_ERROR | RESTORE_SERVER_TOO_OLD
#     Only RESTORE_LOAD_FAILED can leave the database modified; every other
#     failure exits with it untouched.
#   * Key resolution order: --key-file -> the envelope sidecar beside the
#     archive (opened with this machine's own config/backup_site_key) ->
#     $BACKUP_ENCRYPTION_KEY -> ~/.joinery_backup_key -> interactive prompt
#     (only when not --non-interactive).

set -o pipefail

# --- Everything informational goes to stderr; stdout is reserved for markers ---
info() { echo "$@" >&2; }

# --- Parse arguments -----------------------------------------------------------
NON_INTERACTIVE=false
KEY_FILE=""
DB_USER="postgres"
POSITIONAL=()

while [[ $# -gt 0 ]]; do
    case "$1" in
        --non-interactive|-n) NON_INTERACTIVE=true; shift ;;
        --key-file)           KEY_FILE="$2"; shift 2 ;;
        --key-file=*)         KEY_FILE="${1#*=}"; shift ;;
        --db-user)            DB_USER="$2"; shift 2 ;;
        --db-user=*)          DB_USER="${1#*=}"; shift ;;
        --help|-h)
            info "Usage: $0 DB_NAME FILE [--non-interactive] [--key-file PATH] [--db-user USER]"
            info ""
            info "Supported formats: .sql  .sql.gz  .sql.gz.enc"
            info "Markers (stdout): RESTORE_OK BACKUP_KEY_MISSING DECRYPT_FAILED ARCHIVE_CORRUPT RESTORE_LOAD_FAILED DB_UNREACHABLE RESTORE_USAGE_ERROR"
            exit 0
            ;;
        -*) info "✗ Unknown option: $1"; exit 1 ;;
        *)  POSITIONAL+=("$1"); shift ;;
    esac
done
set -- "${POSITIONAL[@]}"

DB_NAME="$1"
INPUT_FILE="$2"

if [ -z "$DB_NAME" ] || [ -z "$INPUT_FILE" ]; then
    info "✗ Error: Missing required arguments."
    info "Usage: $0 DB_NAME FILE [--non-interactive] [--key-file PATH] [--db-user USER]"
    echo "RESTORE_USAGE_ERROR"
    exit 1
fi

if [ ! -f "$INPUT_FILE" ]; then
    info "✗ Error: File '$INPUT_FILE' does not exist."
    echo "RESTORE_USAGE_ERROR"
    exit 1
fi

# --- Temp-file bookkeeping / cleanup ------------------------------------------
# Staging files use a recognizable jy_restore_ prefix so stranded ones are
# identifiable. The EXIT trap covers every normal exit, but a hard kill
# (SIGKILL — the agent's step timeout) runs no trap and can strand a decrypted
# plaintext dump. Self-healing: every run deletes its own stale leftovers, so
# an orphan survives at most until the next backup/restore touches the box.
find /tmp -maxdepth 1 -name 'jy_restore_*' -user "$(id -un)" -mmin +1440 -delete 2>/dev/null
GZ_TMP=""
SQL_TMP=""
KEY_TMP=""
cleanup() {
    [ -n "$GZ_TMP" ]  && rm -f "$GZ_TMP"
    [ -n "$SQL_TMP" ] && rm -f "$SQL_TMP"
    # An unsealed archive key must not outlive the restore that needed it.
    [ -n "$KEY_TMP" ] && rm -f "$KEY_TMP"
}
trap cleanup EXIT

# --- Database authentication (standalone convenience) --------------------------
# The dashboard always exports PGPASSWORD in a creds preamble before invoking us,
# so this block is a no-op there. For manual/standalone use it loads the site
# password from the local config when PGPASSWORD/.pgpass are absent.
if [[ -f ~/.pgpass ]]; then
    info "✓ Using .pgpass authentication."
elif [[ -n "$PGPASSWORD" ]]; then
    info "✓ Using PGPASSWORD from environment."
else
    CONFIG_FILE=""
    SITENAME="${DB_NAME%_test}"
    if [[ -f "/var/www/html/${SITENAME}/config/Globalvars_site.php" ]]; then
        CONFIG_FILE="/var/www/html/${SITENAME}/config/Globalvars_site.php"
    elif [[ -f "/var/www/html/${DB_NAME}/config/Globalvars_site.php" ]]; then
        CONFIG_FILE="/var/www/html/${DB_NAME}/config/Globalvars_site.php"
    else
        CONFIG_FILE=$(find /var/www/html/*/config/Globalvars_site.php 2>/dev/null | head -1)
    fi
    if [[ -n "$CONFIG_FILE" ]] && [[ -f "$CONFIG_FILE" ]]; then
        CONFIG_PASSWORD=$(grep "dbpassword.*=" "$CONFIG_FILE" | head -1 | sed "s/.*'\(.*\)'.*/\1/")
        if [[ -n "$CONFIG_PASSWORD" ]]; then
            export PGPASSWORD="$CONFIG_PASSWORD"
            info "✓ Loaded database password from $CONFIG_FILE"
        fi
    fi
    if [[ -z "$PGPASSWORD" ]] && [ "$NON_INTERACTIVE" = true ]; then
        info "✗ Error: no PGPASSWORD, .pgpass, or config password found (non-interactive)."
        echo "DB_UNREACHABLE"
        exit 7
    fi
fi

# --- Encryption key resolution -------------------------------------------------
ENCRYPTION_KEY=""

# The key sealed to THIS machine, in the envelope beside THIS archive.
#
# A backup encrypted under an envelope key is not openable with the legacy
# standing key, and the plane used to bridge that gap itself: it opened
# <archive>.keys.json with the node's backup_site_key and passed the result as
# --key-file. A node restoring on its own behalf has no such helper, so without
# this an envelope-sealed archive is restorable over SSH and not otherwise —
# and the archives that matter most are envelope-sealed.
#
# ONLY REACHED WHEN NO --key-file WAS GIVEN. An explicit key is the caller
# saying which key to use, and this must not second-guess it; every existing
# caller passes one, so for them this function does not exist. It is tried
# ahead of the standing keys below because it is the key that provably belongs
# to this archive, while $BACKUP_ENCRYPTION_KEY and ~/.joinery_backup_key are
# defaults that happen to be present — and those return success on existence
# alone, so a later attempt would never be reached.
#
# Failure to open is not an error here: it means this archive was sealed to a
# different machine, and the standing keys are still worth trying.
resolve_key_from_envelope() {
    local sidecar="${INPUT_FILE}.keys.json"
    [ -f "$sidecar" ] || return 1

    local tools_dir; tools_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    local envelope_tool="${tools_dir}/backup_envelope.php"
    # {site root}/maintenance_scripts/sysadmin_tools -> {site root}/config.
    local site_key; site_key="$(dirname "$(dirname "$tools_dir")")/config/backup_site_key"

    [ -f "$envelope_tool" ] || return 1
    [ -f "$site_key" ] || return 1
    command -v php >/dev/null 2>&1 || return 1

    KEY_TMP=$(mktemp --suffix=.key /tmp/jy_restore_XXXXXXXX)
    if ! php "$envelope_tool" open --sidecar "$sidecar" --private "$site_key" \
            --key-out "$KEY_TMP" >/dev/null 2>&1; then
        info "⚠️  The envelope beside this archive did not open with this machine's key."
        rm -f "$KEY_TMP"; KEY_TMP=""
        return 1
    fi

    ENCRYPTION_KEY=$(head -1 "$KEY_TMP" | tr -d '\n\r')
    rm -f "$KEY_TMP"; KEY_TMP=""
    if [ -n "$ENCRYPTION_KEY" ]; then
        info "✓ Archive key recovered from $(basename "$sidecar") using this machine's key."
        return 0
    fi
    return 1
}

resolve_key() {
    if [ -n "$KEY_FILE" ]; then
        [ -f "$KEY_FILE" ] || return 1
        ENCRYPTION_KEY=$(head -1 "$KEY_FILE" | tr -d '\n\r')
        [ -n "$ENCRYPTION_KEY" ] && return 0
        return 1
    fi
    if resolve_key_from_envelope; then
        return 0
    fi
    if [ -n "$BACKUP_ENCRYPTION_KEY" ]; then
        ENCRYPTION_KEY="$BACKUP_ENCRYPTION_KEY"
        return 0
    fi
    local kf="$HOME/.joinery_backup_key"
    if [ -f "$kf" ]; then
        ENCRYPTION_KEY=$(head -1 "$kf" | tr -d '\n\r')
        [ -n "$ENCRYPTION_KEY" ] && return 0
    fi
    if [ "$NON_INTERACTIVE" != true ]; then
        read -rsp "Decryption key: " ENCRYPTION_KEY < /dev/tty 2>/dev/null
        info ""
        [ -n "$ENCRYPTION_KEY" ] && return 0
    fi
    return 1
}

info "========================================="
info "POSTGRESQL DATABASE RESTORE"
info "Database:  $DB_NAME (user: $DB_USER)"
info "Source:    $INPUT_FILE"
info "========================================="

# --- Stage 1: produce a verified plaintext SQL temp file, DB UNTOUCHED ---------
SQL_TMP=$(mktemp --suffix=.sql /tmp/jy_restore_XXXXXXXX)

stage_failed() {
    info "✗ Could not stage the restore file (disk full or I/O error?). Database untouched."
    echo "ARCHIVE_CORRUPT"
    exit 5
}

case "$INPUT_FILE" in
    *.enc)
        info "🔍 Encrypted archive — resolving key and decrypting."
        if ! resolve_key; then
            info "✗ No decryption key available (--key-file / the envelope sidecar beside the archive / \$BACKUP_ENCRYPTION_KEY / ~/.joinery_backup_key)."
            echo "BACKUP_KEY_MISSING"
            exit 3
        fi
        GZ_TMP=$(mktemp --suffix=.sql.gz /tmp/jy_restore_XXXXXXXX)
        # Key crosses on stdin, never argv (visible in ps for the whole decrypt).
        if ! printf '%s\n' "$ENCRYPTION_KEY" | openssl enc -aes-256-cbc -d -pbkdf2 -pass stdin -in "$INPUT_FILE" -out "$GZ_TMP" 2>/dev/null; then
            info "✗ Decryption failed (wrong key or corrupt archive). Database untouched."
            echo "DECRYPT_FAILED"
            exit 4
        fi
        if ! gunzip -t "$GZ_TMP" 2>/dev/null; then
            info "✗ Decrypted stream is not a valid gzip — almost certainly the wrong key. Database untouched."
            echo "DECRYPT_FAILED"
            exit 4
        fi
        # Checked: gunzip -t validated the ARCHIVE, but this write produces the
        # file that actually loads — a disk-full/I/O failure here would otherwise
        # stage a silently truncated dump that passes the non-empty check.
        gunzip -c "$GZ_TMP" > "$SQL_TMP" || stage_failed
        ;;
    *.sql.gz)
        info "🔍 Compressed archive — verifying integrity."
        if ! gunzip -t "$INPUT_FILE" 2>/dev/null; then
            info "✗ Archive failed gzip integrity check (truncated or corrupt). Database untouched."
            echo "ARCHIVE_CORRUPT"
            exit 5
        fi
        gunzip -c "$INPUT_FILE" > "$SQL_TMP" || stage_failed
        ;;
    *.sql)
        info "🔍 Plain SQL file."
        cp "$INPUT_FILE" "$SQL_TMP" || stage_failed
        ;;
    *)
        info "⚠️  Unknown extension — treating as plain SQL."
        cp "$INPUT_FILE" "$SQL_TMP" || stage_failed
        ;;
esac

if [ ! -s "$SQL_TMP" ]; then
    info "✗ Prepared restore file is empty. Database untouched."
    echo "ARCHIVE_CORRUPT"
    exit 5
fi

# Gzip formats were integrity-checked above; a plain (or unknown-extension)
# file has had NO verification yet, and this is the last moment before the
# schema drop. A head-of-file shape check catches the wrong file entirely —
# an HTML error page, a tarball, a log — though not a tail-truncated dump
# (ON_ERROR_STOP catches those unless the cut lands on a statement boundary).
case "$INPUT_FILE" in
    *.enc|*.sql.gz) : ;;
    *)
        if ! head -n 50 "$SQL_TMP" | grep -qiE '^(--|SET |CREATE |INSERT |COPY |ALTER |BEGIN|START |\\)' ; then
            info "✗ File does not look like SQL (no dump header or SQL statement in the first 50 lines). Database untouched."
            echo "ARCHIVE_CORRUPT"
            exit 5
        fi
        ;;
esac
info "✓ Archive verified and staged ($(ls -lh "$SQL_TMP" | awk '{print $5}'))."

# --- Stage 1b: refuse a dump this server is too old to load --------------------
# pg_dump emits the settings and meta-commands of the version that WROTE the
# dump, and each major adds some. An 18 dump opens with a \restrict command and
# SET transaction_timeout; PostgreSQL 16 rejects the latter on line 13. That
# rejection would otherwise arrive after DROP SCHEMA, leaving the target with
# neither its old schema nor the new one — the one outcome this script's
# verify-before-destroy contract exists to prevent.
#
# Both numbers are knowable here, with nothing yet touched. Older-into-newer is
# allowed and is the normal upgrade direction (a PG 16 dump loads into 18); only
# newer-into-older is refused.
DUMP_PG_MAJOR="$(sed -n 's/^-- Dumped by pg_dump version \([0-9]\{1,\}\).*/\1/p' "$SQL_TMP" | head -1)"
if [ -z "$DUMP_PG_MAJOR" ]; then
    DUMP_PG_MAJOR="$(sed -n 's/^-- Dumped from database version \([0-9]\{1,\}\).*/\1/p' "$SQL_TMP" | head -1)"
fi
TARGET_VERSION_NUM="$(psql -U "$DB_USER" -XtAc 'SHOW server_version_num' 2>/dev/null | tr -cd '0-9')"
if [ -n "$DUMP_PG_MAJOR" ] && [ -n "$TARGET_VERSION_NUM" ]; then
    TARGET_PG_MAJOR=$(( TARGET_VERSION_NUM / 10000 ))
    if [ "$DUMP_PG_MAJOR" -gt "$TARGET_PG_MAJOR" ]; then
        info "✗ This dump was written by PostgreSQL ${DUMP_PG_MAJOR}; this server is ${TARGET_PG_MAJOR}."
        info "  A newer dump uses syntax an older server rejects, so the load would fail"
        info "  part-way through. Nothing has been changed — the database is untouched."
        info "  Restore this onto PostgreSQL ${DUMP_PG_MAJOR} or newer."
        echo "RESTORE_SERVER_TOO_OLD ${DUMP_PG_MAJOR} ${TARGET_PG_MAJOR}"
        exit 8
    fi
fi

# --- Stage 2: optional pre-restore safety dump (manual runs only) --------------
# The dashboard always prepends its own auto-backup step, so we skip ours in
# --non-interactive mode to avoid a duplicate dump and any openssl prompt.
if [ "$NON_INTERACTIVE" != true ]; then
    DB_EXISTS=$(psql -U "$DB_USER" -XtAc "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'" 2>/dev/null)
    if [ "$DB_EXISTS" = "1" ]; then
        now=$(date +"%Y%m%d_%H%M%S")
        safety="${DB_NAME}-${now}-pre-restore.sql.gz"
        info "📦 Creating pre-restore safety dump: $safety"
        if pg_dump -U "$DB_USER" "$DB_NAME" 2>/dev/null | gzip -9 > "$safety" 2>/dev/null; then
            chmod 600 "$safety" 2>/dev/null
            info "✓ Pre-restore safety dump written."
        else
            rm -f "$safety"
            info "⚠️  Could not write pre-restore safety dump — continuing."
        fi
    fi
fi

# --- Stage 3: terminate connections, replace schema, load ----------------------
info "🔌 Terminating active connections to '$DB_NAME'..."
psql -U "$DB_USER" -d postgres -c \
    "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$DB_NAME' AND pid <> pg_backend_pid();" \
    > /dev/null 2>&1
sleep 1

# Checked probe: a connectivity/auth failure here must NOT read as "database
# absent" — that path runs createdb and misreports a connection problem as a
# load failure. Nothing is destroyed either way, but the marker must be honest.
if ! DB_EXISTS=$(psql -U "$DB_USER" -XtAc "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'" 2>&1); then
    info "✗ Could not query PostgreSQL: $DB_EXISTS"
    echo "DB_UNREACHABLE"
    exit 7
fi
if [ "$DB_EXISTS" = "1" ]; then
    info "🧹 Replacing schema (DROP SCHEMA public CASCADE; CREATE SCHEMA public)..."
    if ! psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 \
            -c "DROP SCHEMA public CASCADE; CREATE SCHEMA public;" 1>&2; then
        info "✗ Schema replace failed. Load not attempted."
        echo "RESTORE_LOAD_FAILED"
        exit 6
    fi
else
    info "🏗️  Database '$DB_NAME' does not exist — creating it."
    if ! createdb -T template0 -U "$DB_USER" "$DB_NAME" 1>&2; then
        info "✗ Could not create database '$DB_NAME'."
        echo "RESTORE_LOAD_FAILED"
        exit 6
    fi
fi

info "📥 Loading dump under ON_ERROR_STOP..."
if psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 -f "$SQL_TMP" 1>&2; then
    info "✅ Restore of '$DB_NAME' complete."
    echo "RESTORE_OK"
    exit 0
else
    info "✗ Load failed under ON_ERROR_STOP — the restore did not complete cleanly."
    info "  The schema was replaced before the failure. Recover from the newest"
    info "  /backups/auto_pre_*.sql.gz (the job's auto-backup step) or the"
    info "  pre-restore safety dump if one was made."
    echo "RESTORE_LOAD_FAILED"
    exit 6
fi
