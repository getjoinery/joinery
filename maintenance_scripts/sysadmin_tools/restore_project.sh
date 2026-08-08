#!/usr/bin/env bash

# restore_project.sh - Complete project restore script
# Version: 1.4.1 - extraction stages beside the archive, not in /tmp (a RAM-sized tmpfs on
#                  26.04 made a good archive report itself corrupt); the encryption sniff
#                  no longer warns about null bytes on every plaintext restore
# Version: 1.4.0 - the restore reconciles the backup to the machine it lands on: the target's
#                  own config and site key are kept (a backup's database password belongs to
#                  the machine it came from), the captured virtualhost is never installed, and
#                  reconcile_site.sh regenerates the serving config and settles the identity
# Version: 1.3.1 - SCRIPT_VERSION is read from this header rather than restated
#                  further down, where a second copy drifts unnoticed
# Version: 1.3.0 - Encrypted archives (.tar.gz.enc) are opened by reading the openssl
#                  magic, not the filename; the key comes from --key-file, the envelope
#                  sidecar beside the archive, or ~/.joinery_backup_key, and whatever
#                  opened the archive is forwarded to the database engine
# Version: 1.2.1 - mkdir/fix_permissions failures inside perform_restore are checked
#                  (set -e is suppressed there by the if-condition call) — a failed
#                  permission fix no longer ends in "RESTORE COMPLETE"
# Version: 1.2.0 - Informational output to stderr; verify_archive returns the
#                  path cleanly; --key-file/--db-user forwarded to the DB engine
#
# Description:
#   Restores a web project from a backup archive created by backup_project.sh
#   Extracts and restores:
#   - PostgreSQL database (using restore_database.sh)
#   - Project files to /var/www/html/PROJECT/
#   - Then RECONCILES the result to this machine (reconcile_site.sh): the domain,
#     the deployment shape, and a freshly generated virtualhost.
#
#   Two files in the archive are never written over the target's own:
#   config/Globalvars_site.php (it holds THIS machine's database password and
#   secret_box_key) and config/backup_site_key (it identifies one machine as a
#   recipient of its own backups; two machines must not share one).
#
# Dependencies:
#   - restore_database.sh (must be in same directory)
#   - PostgreSQL client tools (psql via restore_database.sh)
#   - Apache web server
#   - tar and gzip for archive extraction
#
# Usage:
#   ./restore_project.sh PROJECT_NAME BACKUP_FILE.tar.gz.enc [--dry-run]
#
# Options:
#   PROJECT_NAME    Name of the project to restore (required)
#   BACKUP_FILE     Path to the backup tar.gz file (required)
#   --dry-run       Verify archive contents without restoring
#   --force         Skip confirmation prompts
#   --help          Show help message
#
# Output:
#   Restores project files and database from archive
#
# Examples:
#   ./restore_project.sh myproject myproject-2025-01-18-120000.tar.gz
#   ./restore_project.sh myproject backup.tar.gz --dry-run
#   ./restore_project.sh myproject backup.tar.gz --force
#
# Author: Joinery Maintenance Scripts
# License: Same as Joinery project
# Date: 2025-01-18

set -euo pipefail

# Version information, taken from the header above so there is only one copy.
SCRIPT_VERSION="$(sed -n 's/^# Version: \([0-9][0-9.]*\).*/\1/p' "${BASH_SOURCE[0]}" | head -1)"
[ -n "$SCRIPT_VERSION" ] || SCRIPT_VERSION="unknown"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Function to print colored output.
#
# Every informational helper writes to STDERR. stdout is reserved so that
# verify_archive can hand back the backup directory path as its single clean
# line of output — the capture `BACKUP_DIR=$(verify_archive ...)` must not pick
# up any of these lines, or every downstream directory test fails while the
# script still exits 0 (a restore that restores nothing).
print_info() {
    echo -e "${BLUE}[INFO]${NC} $1" >&2
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1" >&2
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1" >&2
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1" >&2
}

print_dry_run() {
    echo -e "${CYAN}[DRY-RUN]${NC} $1" >&2
}

# Function to show help
show_help() {
    echo "Project Restore Script v${SCRIPT_VERSION}"
    echo "Restores project from backup archive created by backup_project.sh"
    echo ""
    echo "Usage:"
    echo "  $0 PROJECT_NAME BACKUP_FILE.tar.gz [options]"
    echo ""
    echo "Options:"
    echo "  PROJECT_NAME              Name of the project to restore (required)"
    echo "  BACKUP_FILE               Path to the backup tar.gz file (required)"
    echo "  --dry-run, -n            Verify archive contents without restoring"
    echo "  --force, -f              Skip confirmation prompts"
    echo "  --domain DOMAIN          The domain this site is to answer to after the restore."
    echo "                           Defaults to the domain the TARGET's config already names."
    echo "  --skip-database          Skip database restoration"
    echo "  --skip-files             Skip project files restoration"
    echo "  --skip-apache            Do not regenerate the serving config or arm SSL"
    echo "  --help, -h               Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0 joinerytest backup.tar.gz          # Full restore with prompts"
    echo "  $0 joinerytest backup.tar.gz --dry-run # Verify archive contents only"
    echo "  $0 joinerytest backup.tar.gz --force   # Restore without prompts"
    echo ""
    echo "The script will:"
    echo "  1. Extract the backup archive to a temporary location"
    echo "  2. Verify all required components are present"
    echo "  3. Backup existing project (if present)"
    echo "  4. Restore database, files, and Apache configuration"
    echo "  5. Set proper permissions and reload services"
}

# Parse arguments
PROJECT_NAME=""
BACKUP_FILE=""
DRY_RUN=false
FORCE=false
SKIP_DATABASE=false
SKIP_FILES=false
SKIP_APACHE=false
KEY_FILE=""
DB_USER=""
DOMAIN=""

while [[ $# -gt 0 ]]; do
    case $1 in
        --dry-run|-n)
            DRY_RUN=true
            shift
            ;;
        --force|-f)
            FORCE=true
            shift
            ;;
        --key-file)
            KEY_FILE="$2"
            shift 2
            ;;
        --key-file=*)
            KEY_FILE="${1#*=}"
            shift
            ;;
        --db-user)
            DB_USER="$2"
            shift 2
            ;;
        --db-user=*)
            DB_USER="${1#*=}"
            shift
            ;;
        --skip-database)
            SKIP_DATABASE=true
            shift
            ;;
        --skip-files)
            SKIP_FILES=true
            shift
            ;;
        --skip-apache)
            SKIP_APACHE=true
            shift
            ;;
        --domain)
            DOMAIN="$2"
            shift 2
            ;;
        --domain=*)
            DOMAIN="${1#*=}"
            shift
            ;;
        --help|-h)
            show_help
            exit 0
            ;;
        -*)
            print_error "Unknown option: $1"
            echo "Use --help for usage information"
            exit 1
            ;;
        *)
            if [ -z "$PROJECT_NAME" ]; then
                PROJECT_NAME="$1"
            elif [ -z "$BACKUP_FILE" ]; then
                BACKUP_FILE="$1"
            else
                print_error "Too many arguments provided"
                show_help
                exit 1
            fi
            shift
            ;;
    esac
done

# Validate required arguments
if [ -z "$PROJECT_NAME" ] || [ -z "$BACKUP_FILE" ]; then
    print_error "Both PROJECT_NAME and BACKUP_FILE are required"
    echo ""
    show_help
    exit 1
fi

# Check if backup file exists
if [ ! -f "$BACKUP_FILE" ]; then
    print_error "Backup file not found: $BACKUP_FILE"
    exit 1
fi

# Get absolute path of backup file
BACKUP_FILE=$(readlink -f "$BACKUP_FILE")

# Project directory
PROJECT_DIR="/var/www/html/${PROJECT_NAME}"

# Create the extraction directory, beside the ARCHIVE rather than in /tmp.
#
# The whole site is unpacked here before anything is copied into place. On
# Ubuntu 26.04 /tmp is a tmpfs sized from RAM, so extracting there fails with
# "Failed to extract archive. File may be corrupted." for any site larger than
# it — which is a lie: the archive is fine, the disk is not. Wherever the
# archive itself is sitting is real disk by definition.
TEMP_DIR=$(mktemp -d "$(dirname "$BACKUP_FILE")/.restore_XXXXXX" 2>/dev/null) || TEMP_DIR=""
if [ -z "$TEMP_DIR" ] || [ ! -d "$TEMP_DIR" ]; then
    print_warning "Could not stage beside the archive; falling back to \$TMPDIR."
    print_warning "On a box where /tmp is a tmpfs, a site larger than it will not fit."
    TEMP_DIR=$(mktemp -d)
fi
if [ ! -d "$TEMP_DIR" ]; then
    print_error "Failed to create temporary directory"
    exit 1
fi

# Cleanup function
cleanup() {
    if [ -d "$TEMP_DIR" ]; then
        print_info "Cleaning up temporary files..."
        rm -rf "$TEMP_DIR"
    fi
}

# Set trap to cleanup on exit
trap cleanup EXIT

# The archive key, once resolved. Encrypted archives and the database dump
# inside them share one key, so whatever opens the outer archive is also what
# the database engine is handed.
ARCHIVE_KEY_FILE=""

# An openssl -salt stream begins with the literal bytes "Salted__". Reading the
# format rather than trusting the filename means a renamed archive still
# restores, and a plaintext one is never fed through a decrypt that would only
# fail confusingly.
archive_is_encrypted() {
    # Compared with cmp rather than through $( ). A gzip header contains null
    # bytes, and command substitution strips them with a warning on every
    # plaintext restore — noise in the one operation whose output has to be
    # readable when something has gone wrong.
    printf 'Salted__' | cmp -s -n 8 - "$1" 2>/dev/null
}

# Key sources, in order: the envelope sidecar beside the archive, opened with
# this site's own key (how an unattended restore works — no operator, no
# password manager); then the caller's --key-file; then ~/.joinery_backup_key
# for archives made before envelope keys existed.
#
# The sidecar comes first because it is the key that provably belongs to THIS
# archive, while --key-file is usually a standing default a caller passes for
# every restore. A sidecar that does not open falls through, so an explicit key
# is never shut out — it just stops being tried first.
resolve_archive_key() {
    local archive_path="$1"

    local sidecar="${archive_path}.keys.json"
    local envelope_tool="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/backup_envelope.php"
    local site_key="/var/www/html/${PROJECT_NAME}/config/backup_site_key"
    if [ -f "$sidecar" ] && [ -f "$site_key" ] && [ -f "$envelope_tool" ] && command -v php >/dev/null 2>&1; then
        local unsealed="${TEMP_DIR}/archive.key"
        if php "$envelope_tool" open --sidecar "$sidecar" --private "$site_key" \
                --key-out "$unsealed" >/dev/null 2>&1; then
            print_success "Archive key recovered from $(basename "$sidecar") using this site's key"
            ARCHIVE_KEY_FILE="$unsealed"
            return 0
        fi
        print_warning "The envelope beside this archive did not open with this site's key"
    fi

    if [ -n "$KEY_FILE" ] && [ -f "$KEY_FILE" ]; then
        ARCHIVE_KEY_FILE="$KEY_FILE"
        return 0
    fi

    if [ -f "$HOME/.joinery_backup_key" ]; then
        ARCHIVE_KEY_FILE="$HOME/.joinery_backup_key"
        return 0
    fi

    return 1
}

# Function to verify archive contents
verify_archive() {
    local archive_path="$1"
    local temp_extract="$2"

    print_info "Extracting archive for verification..."

    if archive_is_encrypted "$archive_path"; then
        if ! resolve_archive_key "$archive_path"; then
            print_error "This archive is encrypted and no key is available."
            print_error "Pass --key-file PATH, or recover the key from the envelope beside it:"
            print_error "  php backup_envelope.php open --sidecar ${archive_path}.keys.json --private RECOVERY_KEY --key-out /tmp/k"
            return 1
        fi
        # Decrypt straight into tar: the plaintext archive never lands on disk,
        # and the key crosses on fd 3 rather than argv.
        if ! ( set -o pipefail
               openssl enc -aes-256-cbc -d -pbkdf2 -pass fd:3 -in "$archive_path" 2>/dev/null \
                 | tar -xz -C "$temp_extract" 2>/dev/null ) 3< "$ARCHIVE_KEY_FILE"; then
            print_error "Failed to open the archive. The key may be wrong, or the file may be corrupted."
            return 1
        fi
    elif ! tar -xzf "$archive_path" -C "$temp_extract" 2>/dev/null; then
        print_error "Failed to extract archive. File may be corrupted."
        return 1
    fi

    # Find the backup directory (should be PROJECT-TIMESTAMP format)
    local backup_dir=$(find "$temp_extract" -maxdepth 1 -type d ! -path "$temp_extract" | head -1)

    if [ -z "$backup_dir" ] || [ ! -d "$backup_dir" ]; then
        print_error "Invalid archive structure - no backup directory found"
        return 1
    fi

    print_info "Archive structure:"
    echo "----------------------------------------" >&2

    # Check for backup info file
    if [ -f "$backup_dir/backup_info.txt" ]; then
        print_success "✓ Backup info file found"
        if [ "$DRY_RUN" = true ]; then
            {
                echo ""
                echo "=== Backup Information ==="
                head -20 "$backup_dir/backup_info.txt"
                echo "==========================="
                echo ""
            } >&2
        fi
    else
        print_warning "⚠ No backup info file found"
    fi

    # Check for database backup
    local db_file=""
    if [ "$SKIP_DATABASE" = false ]; then
        # Look for database backup files
        db_file=$(find "$backup_dir" -maxdepth 1 \( -name "*.sql" -o -name "*.sql.gz.enc" -o -name "*.sql.gz" \) 2>/dev/null | head -1)

        if [ -n "$db_file" ] && [ -f "$db_file" ]; then
            print_success "✓ Database backup found: $(basename "$db_file")"
            echo "  Size: $(ls -lh "$db_file" | awk '{print $5}')" >&2
        elif [ -f "$backup_dir/NO_DATABASE.txt" ]; then
            print_warning "⚠ No database backup (database did not exist during backup)"
        else
            print_warning "⚠ No database backup found in archive"
        fi
    fi

    # Check for project files
    if [ "$SKIP_FILES" = false ]; then
        if [ -d "$backup_dir/project_files" ]; then
            local file_count=$(find "$backup_dir/project_files" -type f | wc -l)
            local dir_count=$(find "$backup_dir/project_files" -type d | wc -l)
            print_success "✓ Project files found"
            echo "  Files: $file_count, Directories: $dir_count" >&2

            if [ "$DRY_RUN" = true ]; then
                {
                    echo "  Top-level contents:"
                    ls -la "$backup_dir/project_files" | head -10 | sed 's/^/    /'
                } >&2
            fi
        else
            print_error "✗ Project files directory not found"
            return 1
        fi
    fi

    # What shape did this backup come off? An archive taken before shape.json
    # existed simply does not say, which the restore handles — it reconciles
    # against the target either way.
    if [ -f "$backup_dir/shape.json" ]; then
        local src_env
        src_env=$(grep -o '"deployment_environment"[[:space:]]*:[[:space:]]*"[^"]*"' "$backup_dir/shape.json" \
                  | sed 's/.*"\([^"]*\)"$/\1/')
        print_success "✓ Shape recorded: taken on ${src_env:-unknown}"
    else
        print_info "This backup does not record its shape (taken before shape.json) — reconciling against this machine."
    fi

    # The captured virtualhost travels for REFERENCE. It is never installed:
    # the restore regenerates the serving config for this box, and keeps this
    # copy beside the live file only when the two differ.
    if [ -d "$backup_dir/apache_config" ]; then
        local apache_conf=$(find "$backup_dir/apache_config" -name "*.conf" 2>/dev/null | head -1)
        if [ -n "$apache_conf" ] && [ -f "$apache_conf" ]; then
            print_info "Archive carries a virtualhost ($(basename "$apache_conf")) — kept for reference, not installed"
        fi
    fi

    echo "----------------------------------------" >&2

    # The backup directory path is this function's ONLY stdout line, emitted
    # last so nothing can append to it. The caller captures exactly this.
    echo "$backup_dir"
    return 0
}

# Function to perform restore
perform_restore() {
    local backup_dir="$1"

    print_info "Starting restore process..."

    # Get script directory for restore_database.sh
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    RESTORE_DB_SCRIPT="${SCRIPT_DIR}/restore_database.sh"

    # Step 1: Restore database
    if [ "$SKIP_DATABASE" = false ]; then
        local db_file=$(find "$backup_dir" -maxdepth 1 \( -name "*.sql" -o -name "*.sql.gz.enc" -o -name "*.sql.gz" \) 2>/dev/null | head -1)

        if [ -n "$db_file" ] && [ -f "$db_file" ]; then
            print_info "Restoring database..."

            if [ ! -f "$RESTORE_DB_SCRIPT" ]; then
                print_error "restore_database.sh not found at: $RESTORE_DB_SCRIPT"
                return 1
            fi

            # Check if database exists and warn
            DB_EXISTS=$(psql -U postgres -lqt 2>/dev/null | cut -d \| -f 1 | grep -qw "$PROJECT_NAME" && echo "yes" || echo "no")

            # Declining the DB stage must skip ONLY the DB stage — files and
            # Apache config still restore. A bare `return 0` here used to abort
            # the whole restore after the user declined one stage.
            local do_db_restore=true
            if [ "$DB_EXISTS" = "yes" ] && [ "$FORCE" = false ]; then
                print_warning "Database '$PROJECT_NAME' already exists!"
                print_info "The restore engine will back up the existing database before restoring."
                read -p "Continue with database restore? (y/N): " -n 1 -r
                echo
                if [[ ! $REPLY =~ ^[Yy]$ ]]; then
                    print_info "Skipping database restore (files and Apache config still restore)"
                    do_db_restore=false
                fi
            fi

            if [ "$do_db_restore" = true ]; then
                # Run restore_database.sh (the single restore engine). --force ->
                # --non-interactive; forward the caller's key path and DB user so
                # an encrypted archive can decrypt and psql runs as the site user.
                local db_flags=()
                if [ "$FORCE" = true ]; then
                    db_flags+=(--non-interactive)
                fi
                if [ -n "$DB_USER" ]; then
                    db_flags+=(--db-user "$DB_USER")
                fi
                # The dump was encrypted with the same key as the archive around
                # it, so hand over whatever opened the archive — which may have
                # come from the envelope rather than from --key-file.
                if [ -n "$ARCHIVE_KEY_FILE" ]; then
                    db_flags+=(--key-file "$ARCHIVE_KEY_FILE")
                elif [ -n "$KEY_FILE" ]; then
                    db_flags+=(--key-file "$KEY_FILE")
                fi
                if bash "$RESTORE_DB_SCRIPT" "$PROJECT_NAME" "$db_file" ${db_flags[@]+"${db_flags[@]}"}; then
                    print_success "Database restored successfully"
                else
                    print_error "Database restoration failed"
                    return 1
                fi
            fi
        elif [ ! -f "$backup_dir/NO_DATABASE.txt" ]; then
            print_warning "No database backup found to restore"
        fi
    fi

    # Step 2: Restore project files
    if [ "$SKIP_FILES" = false ] && [ -d "$backup_dir/project_files" ]; then
        print_info "Restoring project files..."

        # Check if project directory exists
        if [ -d "$PROJECT_DIR" ] && [ "$FORCE" = false ]; then
            print_warning "Project directory already exists: $PROJECT_DIR"
            read -p "Backup and replace existing project files? (y/N): " -n 1 -r
            echo
            if [[ ! $REPLY =~ ^[Yy]$ ]]; then
                print_info "Skipping project files restore"
            else
                # Backup existing project
                BACKUP_TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
                EXISTING_BACKUP="${PROJECT_DIR}_backup_${BACKUP_TIMESTAMP}"
                print_info "Backing up existing project to: $EXISTING_BACKUP"

                if sudo mv "$PROJECT_DIR" "$EXISTING_BACKUP"; then
                    print_success "Existing project backed up"
                else
                    print_error "Failed to backup existing project"
                    return 1
                fi

                # Create new project directory and restore files.
                # Explicitly checked: perform_restore runs as an `if` condition,
                # which suppresses `set -e` for the whole function body — any
                # command without its own check silently continues on failure.
                if ! sudo mkdir -p "$PROJECT_DIR"; then
                    print_error "Failed to create project directory: $PROJECT_DIR"
                    return 1
                fi
            fi
        else
            # Create project directory if it doesn't exist (checked — see above)
            if ! sudo mkdir -p "$PROJECT_DIR"; then
                print_error "Failed to create project directory: $PROJECT_DIR"
                return 1
            fi
        fi

        # Two files are the MACHINE's, not the backup's, and are dropped from the
        # staged copy before anything is written.
        #
        # config/Globalvars_site.php holds this machine's database password and
        # its secret_box_key. Restoring the source's copy is what makes every
        # page log SQLSTATE[08006] after an otherwise clean rebuild — the
        # database came back fine, but the password in the config belongs to a
        # PostgreSQL on another box. It bites a same-shape rebuild exactly as
        # hard as a cross-shape one, so it is not a shape problem at all.
        #
        # config/backup_site_key identifies ONE machine as a recipient of its own
        # backups. Two machines sharing it means one machine's key opens the
        # other's archives and the envelope stops saying who made a backup.
        # backup_envelope.php mints a fresh one on first use, so absent is the
        # correct state, not a gap.
        for keepmine in config/Globalvars_site.php config/backup_site_key; do
            if [ -f "$PROJECT_DIR/$keepmine" ]; then
                if [ -f "$backup_dir/project_files/$keepmine" ]; then
                    rm -f "$backup_dir/project_files/$keepmine"
                    print_info "Keeping this machine's own $keepmine"
                fi
            elif [ -f "$backup_dir/project_files/$keepmine" ]; then
                if [ "$keepmine" = "config/backup_site_key" ]; then
                    rm -f "$backup_dir/project_files/$keepmine"
                    print_info "Not restoring config/backup_site_key — a fresh one is minted on first use"
                else
                    print_warning "This machine has no site config of its own, so the backup's is being used."
                    print_warning "Its database password is the SOURCE machine's — expect the reconcile step to refuse."
                fi
            fi
        done

        # Copy files from backup to project directory.
        #
        # The trailing /. copies the directory's contents, dotfiles included, in a
        # single pass. The glob form needs a second command for hidden entries, and
        # chaining the two with || reports success whenever the fallback succeeds —
        # even if the first copy died halfway through. Errors are not discarded
        # either: a copy that cannot be trusted has to be visible.
        if ! sudo cp -a "$backup_dir/project_files/." "$PROJECT_DIR/"; then
            print_error "Failed to copy project files to: $PROJECT_DIR"
            return 1
        fi

        # Verify every file landed before declaring success. A partial restore
        # produces a site that serves pages perfectly well while uploaded files are
        # missing from where the restored database says they live, so this is a
        # gate rather than a report.
        print_info "Verifying restored files..."
        missing_list=$(cd "$backup_dir/project_files" && find . -type f | while IFS= read -r f; do
            if [ ! -e "$PROJECT_DIR/${f#./}" ]; then printf '%s\n' "${f#./}"; fi
        done)

        if [ -n "$missing_list" ]; then
            missing_count=$(printf '%s\n' "$missing_list" | grep -c .)
            print_error "Restore verification failed: $missing_count file(s) did not land in $PROJECT_DIR"
            printf '%s\n' "$missing_list" | head -20 | sed 's/^/    /'
            return 1
        fi

        restored_count=$(find "$backup_dir/project_files" -type f | wc -l)

        # Set proper permissions using centralized script (production mode).
        # Checked: a permission-fix failure otherwise ends in "RESTORE COMPLETE"
        # with root-owned files and a site that 500s on every upload path.
        if ! sudo "$SCRIPT_DIR/../install_tools/fix_permissions.sh" "$PROJECT_NAME" --production; then
            print_error "fix_permissions.sh failed — restored files may have wrong ownership/modes"
            return 1
        fi

        # Make maintenance scripts executable
        if [ -d "$PROJECT_DIR/maintenance_scripts" ]; then
            sudo find "$PROJECT_DIR/maintenance_scripts" -type f -name "*.sh" -exec chmod 755 {} \;
        fi

        print_success "Project files restored and verified ($restored_count files): $PROJECT_DIR"
    fi

    # Step 3: Reconcile the restored site to THIS machine.
    #
    # The captured virtualhost is not installed, in any case, same shape or not.
    # It is the one file the installer has just written correctly for this box,
    # this domain and this shape — and the template keeps improving, so
    # installing an old capture quietly reverts whatever arrived since. What the
    # backup carries is passed in as reference: a capture that differs from the
    # generated file is preserved beside it and named, never applied unattended.
    local reconcile="${SCRIPT_DIR}/reconcile_site.sh"
    if [ ! -f "$reconcile" ]; then
        print_error "reconcile_site.sh is not beside this script — the restored site would be left"
        print_error "pointing at the source machine's domain, shape and serving config."
        return 1
    fi

    # The domain is the target's own unless the caller named one. A restore run
    # by hand on the box has an operator present and "leave this machine's
    # identity alone" is the right default; a restore run as a JOB requires the
    # value, because there is nobody there to notice a wrong answer.
    local use_domain="$DOMAIN"
    if [ -z "$use_domain" ]; then
        use_domain=$(sed -n "s/^[[:space:]]*\$this->settings\['webDir'\][[:space:]]*=[[:space:]]*'\([^']*\)'.*/\1/p" \
            "$PROJECT_DIR/config/Globalvars_site.php" 2>/dev/null | head -1)
        if [ -z "$use_domain" ]; then
            print_error "No --domain was given and this machine's config names none."
            return 1
        fi
        print_info "No --domain given; keeping this machine's own domain: $use_domain"
    fi

    local reconcile_args=("$PROJECT_NAME" --domain "$use_domain" --backup-meta "$backup_dir")
    if [ "$SKIP_APACHE" = true ]; then
        reconcile_args+=(--skip-web-config --skip-ssl)
        print_info "--skip-apache given: the serving config is left exactly as it is."
    fi

    print_info "Reconciling the restored site to this machine..."
    if ! bash "$reconcile" "${reconcile_args[@]}"; then
        print_error "Reconciliation failed. The files and database are restored, but the site does"
        print_error "not yet agree with this machine — see the RECONCILE_ lines above."
        return 1
    fi

    return 0
}

# Main execution
print_info "Project Restore Script v${SCRIPT_VERSION}"
echo "========================================="
echo "Project: $PROJECT_NAME"
echo "Archive: $BACKUP_FILE"
echo "Mode: $(if [ "$DRY_RUN" = true ]; then echo "DRY RUN (verification only)"; else echo "RESTORE"; fi)"
echo "========================================="
echo ""

# Extract and verify archive. Capture inside the `if !` guard so that under
# `set -e` a verification failure actually reaches this branch instead of
# aborting the script at the assignment (and leaving the check unreachable).
if ! BACKUP_DIR=$(verify_archive "$BACKUP_FILE" "$TEMP_DIR"); then
    print_error "Archive verification failed"
    exit 1
fi

# If dry run, we're done
if [ "$DRY_RUN" = true ]; then
    echo ""
    print_dry_run "Dry run complete - no changes were made"
    print_dry_run "Archive appears valid and can be restored"
    print_dry_run "Run without --dry-run to perform actual restore"
    exit 0
fi

# Confirm before restore (unless --force is used)
if [ "$FORCE" = false ]; then
    echo ""
    print_warning "This will restore the project from the backup archive."
    print_warning "Existing data may be overwritten (backups will be created)."
    echo ""
    read -p "Continue with restore? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        print_info "Restore cancelled by user"
        exit 0
    fi
fi

echo ""

# Perform the restore
if perform_restore "$BACKUP_DIR"; then
    echo ""
    echo "========================================="
    print_success "RESTORE COMPLETE"
    echo "========================================="
    echo "Project: $PROJECT_NAME"
    echo "Restored from: $BACKUP_FILE"
    echo "Completion time: $(date)"
    echo ""
    echo "Next steps:"
    echo "  1. Verify the website is working: http://your-domain/"
    echo "  2. Check database connectivity"
    echo "  3. Review application logs for any errors"
    echo "========================================="
    exit 0
else
    echo ""
    print_error "Restore failed or partially completed"
    print_error "Please check the error messages above"
    exit 1
fi