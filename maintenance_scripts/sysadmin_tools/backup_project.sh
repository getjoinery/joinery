#!/usr/bin/env bash

# backup_project.sh - Complete project backup script
# Version: 2.8.0 - `--exclude-from FILE` (stream mode): paths relative to the project directory,
#                  one per line, left out of the archive — the local paths of every file the
#                  backup's object store accounts for (specs/backup_offloaded_files.md). Literal
#                  and unanchored, evaluated on the member name before the rename.
# Version: 2.7.1 - the stream-mode rename applies to hard-link targets as well as member names
#                  (transform flags rh, not r): a hard link inside the live tree pointed at the
#                  untransformed name and the whole archive failed to extract. Symlink targets
#                  are still left as they are.
# Version: 2.7.0 - stream mode: `--archive -` writes the encrypted archive to stdout and nothing
#                  else does; `--report FILE` records TAR_RC and ENC_RC once the stream has been
#                  fully produced. In stream mode the site tree is NOT copied: tar archives the
#                  staged dump, apache_config/ and shape.json together with the LIVE tree under
#                  project_files/ (a second -C and a --transform on member names only), so a
#                  run holds one compressed dump on disk instead of a second copy of the site.
#                  --name NAME sets the archive name (the caller may need to know it up front);
#                  --project-dir DIR archives that tree, for tests against a throwaway one.
#                  The timestamp is YYYYMMDD_HHMMSS, the stamp the database dumps and the chain
#                  directories carry, so a standalone project archive sorts by date on a shelf
#                  a management node prunes instead of as the oldest thing on it. backups/ and
#                  target/ are excluded in both modes: an archive that carries earlier archives
#                  grows without limit, and target/ is regenerable build output.
# Version: 2.6.3 - the sudo probe lists the rules and requires NOPASSWD: ALL; -v said yes to an
#                  account allowed one helper, and the rsync it then ran under sudo was refused
# Version: 2.6.2 - the sudo capability probe asks with -v instead of running true,
#                  which sudo mails root about when the account may not. Same
#                  change in the sibling scripts.
# Version: 2.6.1 - shape.json is recorded however the script was invoked. It resolved its
#                  own directory after cd-ing to the output directory, so a relative call
#                  looked for reconcile_site.sh in /backups, found none, and skipped the
#                  step and its warning together
# Version: 2.6.0 - the backup stages beside its output rather than in /tmp, which on Ubuntu
#                  26.04 is a RAM-sized tmpfs: any site bigger than it failed part-way with
#                  No space left on device, after the database dump had already succeeded
# Version: 2.5.0 - the archive carries shape.json: the machine-readable record of what
#                  this site was running on, which is what lets a restore reconcile a
#                  container backup onto a plain server without an operator
# Version: 2.4.1 - SCRIPT_VERSION is read from this header rather than restated
#                  further down, where a second copy drifts unnoticed
# Version: 2.4.0
#
# Description:
#   Creates a comprehensive backup of a web project including:
#   - PostgreSQL database (using backup_database.sh)
#   - Project files from /var/www/html/PROJECT/
#   - Apache virtualhost configuration
#   All components are combined into a single timestamped tar.gz archive
#
# Dependencies:
#   - backup_database.sh (must be in same directory)
#   - PostgreSQL client tools (psql, pg_dump via backup_database.sh)
#   - Apache web server with virtualhost configs
#   - rsync for efficient file copying
#   - tar and gzip for archive creation
#
# Usage:
#   ./backup_project.sh PROJECT_NAME [--plaintext] [--non-interactive] [--output-dir DIR]
#   ./backup_project.sh PROJECT_NAME --non-interactive --key-file PATH --archive - --report FILE --output-dir DIR
#
# Options:
#   PROJECT_NAME      Name of the project to backup (required)
#                     Must match the directory name in /var/www/html/
#   --project-dir DIR Archive THIS directory instead of /var/www/html/PROJECT_NAME
#   --name NAME       Archive name without extension (default: PROJECT-YYYYMMDD_HHMMSS)
#   --plaintext       Create an unencrypted backup (default: encrypted)
#   --non-interactive Use encryption key from env var or file (no prompts)
#   --key-file PATH   Read the encryption key from PATH
#   --output-dir DIR  Directory to create backup in (default: current directory).
#                     In stream mode: where the small staging directory lives.
#   --archive -       Stream mode: the encrypted archive goes to stdout and NOTHING
#                     else does (every human line is on stderr). The site tree is
#                     not copied; the staging directory holds only the database
#                     dump, apache_config/ and shape.json. Requires --report.
#   --report FILE     Stream mode: once the stream has been fully produced, the
#                     TAR_RC and ENC_RC lines are written to FILE. The reader has
#                     the byte count and the hash from what it read.
#   --exclude-from F  Stream mode: a file of paths relative to the project
#                     directory, one per line, to leave out of the archive. The
#                     runner writes the local paths of every offloaded file here;
#                     the objects index accounts for them. Literal names.
#   --help            Show help message
#
# Output:
#   Creates PROJECT-YYYYMMDD_HHMMSS.tar.gz.enc in output directory
#   (.tar.gz when --plaintext is passed); the last stdout line is
#   BACKUP_ARCHIVE=<path>. In stream mode stdout is the archive.
#
# Report (stream mode, written to --report FILE):
#   TAR_RC=<n>        0 ok; 1 a file changed while being read (accepted); >=2 failed
#   ENC_RC=<n>        openssl's exit status (0 ok)
#
# Examples:
#   ./backup_project.sh myproject                          # Encrypted, interactive
#   ./backup_project.sh myproject --plaintext              # Plaintext database backup
#   ./backup_project.sh myproject --non-interactive        # Automated backup
#   ./backup_project.sh myproject --output-dir /tmp        # Output to /tmp
#
# Author: Joinery Maintenance Scripts
# License: Same as Joinery project
# Date: 2025-01-18

set -euo pipefail

# Version information, taken from the header above so there is only one copy.
SCRIPT_VERSION="$(sed -n 's/^# Version: \([0-9][0-9.]*\).*/\1/p' "${BASH_SOURCE[0]}" | head -1)"
[ -n "$SCRIPT_VERSION" ] || SCRIPT_VERSION="unknown"

# Deployment environment (Docker or bare metal) — read from the project's
# Globalvars_site.php once PROJECT_DIR is known (spec deployment_environment_flag).
IS_DOCKER=false

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Function to show help
show_help() {
    echo "Project Backup Script v${SCRIPT_VERSION}"
    echo "Combines database backup, project files, and Apache configuration into a single archive"
    echo ""
    echo "Usage:"
    echo "  $0 PROJECT_NAME [options]"
    echo ""
    echo "Options:"
    echo "  PROJECT_NAME              Name of the project to backup (required)"
    echo "  --plaintext, -p           Create an unencrypted backup (default: encrypted)"
    echo "  --non-interactive, -n     Use encryption key from env var or file (no prompts)"
    echo "  --key-file PATH           Read the encryption key from PATH"
    echo "  --output-dir DIR, -o DIR  Directory to create backup in (default: current directory)"
    echo "  --project-dir DIR         Archive this directory instead of /var/www/html/PROJECT_NAME"
    echo "  --name NAME               Archive name without extension"
    echo "  --archive -               Stream the encrypted archive to stdout (requires --report FILE)"
    echo "  --report FILE             Stream mode: TAR_RC and ENC_RC are written here after the stream"
    echo "  --exclude-from FILE       Stream mode: leave out the paths listed in FILE (relative to the project)"
    echo "  --help, -h                Show this help message"
    echo ""
    echo "Non-Interactive Mode:"
    echo "  Encryption key sources (in order of precedence):"
    echo "  1. --key-file PATH"
    echo "  2. \$BACKUP_ENCRYPTION_KEY environment variable"
    echo "  3. ~/.joinery_backup_key file (must have 600 permissions)"
    echo ""
    echo "Examples:"
    echo "  $0 joinerytest                         # Backup with encrypted database (interactive)"
    echo "  $0 joinerytest --plaintext             # Backup with plaintext database"
    echo "  $0 joinerytest --non-interactive       # Automated backup using key from env/file"
    echo "  $0 joinerytest --output-dir /tmp       # Create backup in /tmp"
    echo "  $0 joinerytest -n -o /tmp              # Automated backup to /tmp"
    echo ""
    echo "The script will create:"
    echo "  - An encrypted archive named: PROJECT-YYYYMMDD_HHMMSS.tar.gz.enc"
    echo "  - Contents: database backup, /var/www/html/PROJECT/, Apache virtualhost config"
}

# Parse arguments
PROJECT_NAME=""
ENCRYPT_DB=true
NON_INTERACTIVE=false
OUTPUT_DIR=""
KEY_FILE=""
PROJECT_DIR_OVERRIDE=""
NAME_OVERRIDE=""
STREAM=false
REPORT_FILE=""
EXCLUDE_FROM=""

while [[ $# -gt 0 ]]; do
    case $1 in
        --plaintext|-p)
            ENCRYPT_DB=false
            shift
            ;;
        --non-interactive|-n)
            NON_INTERACTIVE=true
            shift
            ;;
        --key-file)
            if [[ -n "${2:-}" && ! "$2" =~ ^- ]]; then
                KEY_FILE="$2"
                shift 2
            else
                print_error "--key-file requires a path argument"
                exit 1
            fi
            ;;
        --key-file=*)
            KEY_FILE="${1#*=}"
            shift
            ;;
        --output-dir|-o)
            if [[ -n "${2:-}" && ! "$2" =~ ^- ]]; then
                OUTPUT_DIR="$2"
                shift 2
            else
                print_error "--output-dir requires a directory argument"
                exit 1
            fi
            ;;
        --project-dir)
            if [[ -n "${2:-}" && ! "$2" =~ ^- ]]; then
                PROJECT_DIR_OVERRIDE="$2"
                shift 2
            else
                print_error "--project-dir requires a directory argument"
                exit 1
            fi
            ;;
        --name)
            if [[ -n "${2:-}" && ! "$2" =~ ^- ]]; then
                NAME_OVERRIDE="$2"
                shift 2
            else
                print_error "--name requires a name argument"
                exit 1
            fi
            ;;
        --archive)
            if [ "${2:-}" != "-" ]; then
                print_error "--archive only accepts '-' (stdout)"
                exit 1
            fi
            STREAM=true
            shift 2
            ;;
        --report)
            if [[ -n "${2:-}" && ! "$2" =~ ^- ]]; then
                REPORT_FILE="$2"
                shift 2
            else
                print_error "--report requires a file argument"
                exit 1
            fi
            ;;
        --exclude-from)
            if [[ -n "${2:-}" && ! "$2" =~ ^- ]]; then
                EXCLUDE_FROM="$2"
                shift 2
            else
                print_error "--exclude-from requires a file argument"
                exit 1
            fi
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
            else
                print_error "Multiple project names provided. Only one is allowed."
                exit 1
            fi
            shift
            ;;
    esac
done

# Check if project name was provided
if [ -z "$PROJECT_NAME" ]; then
    print_error "Project name is required"
    echo ""
    show_help
    exit 1
fi

# Stream mode: stdout IS the archive, so everything this script says moves to
# stderr wholesale — the engines it calls print to stdout too — and the real
# stdout is kept on fd 4 for the one pipeline that writes the archive.
if [ "$STREAM" = true ]; then
    # Said on stderr: a stream-mode caller is not reading stdout for text.
    if [ -z "$REPORT_FILE" ]; then
        print_error "--archive - requires --report FILE" >&2
        exit 1
    fi
    if [ ! -d "$(dirname "$REPORT_FILE")" ]; then
        print_error "Report directory does not exist: $(dirname "$REPORT_FILE")" >&2
        exit 1
    fi
    rm -f "$REPORT_FILE"
    exec 4>&1 1>&2
fi

# Generate timestamp for backup filename. YYYYMMDD_HHMMSS: the same stamp the
# database dumps and the chain directories carry, which is what a management
# node's retention sorts a shelf by.
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_DIR="${OUTPUT_DIR:-$(pwd)}"
BACKUP_NAME="${NAME_OVERRIDE:-${PROJECT_NAME}-${TIMESTAMP}}"
case "$BACKUP_NAME" in
    */*|.*|"") print_error "--name must be a plain file name"; exit 1 ;;
esac

# The archive carries the whole site tree, config/ included — the database
# password, the secret box key, the agent signing key, the relay pull key. It is
# encrypted unless --plaintext is passed explicitly.
#
# Key sources, in order: --key-file (how envelope backups run — a key minted for
# this backup alone), $BACKUP_ENCRYPTION_KEY, then ~/.joinery_backup_key.
ARCHIVE_KEY_SOURCE=""
if [ "$ENCRYPT_DB" = true ]; then
    if [ -n "$KEY_FILE" ]; then
        if [ ! -f "$KEY_FILE" ]; then
            print_error "--key-file '$KEY_FILE' does not exist"
            exit 1
        fi
        ARCHIVE_KEY_SOURCE="$KEY_FILE"
    elif [ -n "${BACKUP_ENCRYPTION_KEY:-}" ]; then
        ARCHIVE_KEY_SOURCE="env"
    elif [ -f "$HOME/.joinery_backup_key" ]; then
        ARCHIVE_KEY_SOURCE="$HOME/.joinery_backup_key"
    elif [ "$NON_INTERACTIVE" = true ]; then
        # Never silently downgrade to a plaintext archive: an automated run that
        # cannot encrypt must fail, not quietly ship config/ in the clear.
        print_error "Encryption is on but no key is available"
        echo "Pass --key-file PATH, set \$BACKUP_ENCRYPTION_KEY, or use --plaintext deliberately."
        exit 1
    fi
fi

if [ "$ENCRYPT_DB" = true ]; then
    FINAL_ARCHIVE="${BACKUP_NAME}.tar.gz.enc"
else
    FINAL_ARCHIVE="${BACKUP_NAME}.tar.gz"
fi

# Validate output directory exists
if [ -n "$OUTPUT_DIR" ]; then
    if [ ! -d "$OUTPUT_DIR" ]; then
        print_error "Output directory does not exist: $OUTPUT_DIR"
        exit 1
    fi
    # Convert to absolute path
    BACKUP_DIR=$(cd "$OUTPUT_DIR" && pwd)
fi

# Verify project directory exists
PROJECT_DIR="${PROJECT_DIR_OVERRIDE:-/var/www/html/${PROJECT_NAME}}"
PROJECT_DIR="${PROJECT_DIR%/}"
if [ ! -d "$PROJECT_DIR" ]; then
    print_error "Project directory does not exist: $PROJECT_DIR"
    exit 1
fi

# Read the deployment environment flag from the project's config (single source of truth)
if [ "$(grep -oP "settings\['deployment_environment'\]\s*=\s*'\K[^']+" "$PROJECT_DIR/config/Globalvars_site.php" 2>/dev/null)" = docker ]; then
    IS_DOCKER=true
fi

# Find Apache virtualhost configuration
print_info "Looking for Apache virtualhost configuration..."

# Common locations for virtualhost configs
VHOST_PATHS=(
    "/etc/apache2/sites-available/${PROJECT_NAME}.conf"
    "/etc/apache2/sites-enabled/${PROJECT_NAME}.conf"
    "/etc/apache2/sites-available/${PROJECT_NAME}"
    "/etc/apache2/sites-enabled/${PROJECT_NAME}"
    "/etc/httpd/conf.d/${PROJECT_NAME}.conf"
)

VHOST_FILE=""
for vhost_path in "${VHOST_PATHS[@]}"; do
    if [ -f "$vhost_path" ]; then
        VHOST_FILE="$vhost_path"
        break
    fi
done

# If not found in standard locations, search for it
if [ -z "$VHOST_FILE" ]; then
    # Search in Apache config directories
    if [ -d "/etc/apache2" ]; then
        VHOST_FILE=$(find /etc/apache2 -name "*${PROJECT_NAME}*.conf" -type f 2>/dev/null | head -1)
    elif [ -d "/etc/httpd" ]; then
        VHOST_FILE=$(find /etc/httpd -name "*${PROJECT_NAME}*.conf" -type f 2>/dev/null | head -1)
    fi
fi

# Handle missing virtualhost config
if [ -z "$VHOST_FILE" ] || [ ! -f "$VHOST_FILE" ]; then
    if [ "$IS_DOCKER" = true ]; then
        print_warning "Virtualhost config not found (normal for Docker - config is baked into image)"
        VHOST_FILE=""
    else
        print_error "Could not find Apache virtualhost configuration for project: $PROJECT_NAME"
        print_error "Please ensure the virtualhost config exists in /etc/apache2/sites-available/ or /etc/httpd/conf.d/"
        exit 1
    fi
else
    print_success "Found virtualhost config: $VHOST_FILE"
fi

# Create the staging directory for the backup.
#
# Beside the OUTPUT, not in /tmp. A whole copy of the site passes through here,
# and on Ubuntu 26.04 /tmp is a tmpfs sized from RAM — so staging there fails
# with "No space left on device" for any site larger than about half the box's
# memory, part-way through, after the database dump has already succeeded.
# The output directory is on real disk by definition: it is where a backup
# several times this size is about to be written.
TEMP_DIR=$(mktemp -d "${BACKUP_DIR}/.staging_XXXXXX" 2>/dev/null) || TEMP_DIR=""
if [ -z "$TEMP_DIR" ] || [ ! -d "$TEMP_DIR" ]; then
    print_warning "Could not stage beside the output directory; falling back to \$TMPDIR."
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

print_info "Starting backup process for project: $PROJECT_NAME"
echo "========================================="

# Create backup structure in temp directory
mkdir -p "${TEMP_DIR}/${BACKUP_NAME}"

# Step 1: Backup database
print_info "Backing up database..."

# Get the directory of this script
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_DB_SCRIPT="${SCRIPT_DIR}/backup_database.sh"

if [ ! -f "$BACKUP_DB_SCRIPT" ]; then
    print_error "backup_database.sh not found at: $BACKUP_DB_SCRIPT"
    exit 1
fi

# Load database password from config if not already set
if [ -z "${PGPASSWORD:-}" ]; then
    CONFIG_FILE="${PROJECT_DIR}/config/Globalvars_site.php"
    if [ -f "$CONFIG_FILE" ]; then
        PGPASSWORD=$(grep "dbpassword.*=" "$CONFIG_FILE" | head -1 | sed "s/.*'\(.*\)'.*/\1/")
        export PGPASSWORD
    fi
fi

# Check if database exists
DB_EXISTS=$(psql -U postgres -lqt 2>/dev/null | cut -d \| -f 1 | grep -qw "$PROJECT_NAME" && echo "yes" || echo "no")

if [ "$DB_EXISTS" = "yes" ]; then
    # Run backup_database.sh in the temp directory
    cd "${TEMP_DIR}/${BACKUP_NAME}"

    # Build backup command arguments. An array, not a word-split string: the key
    # path is caller-supplied and may contain spaces.
    BACKUP_ARGS=()
    if [ "$ENCRYPT_DB" = false ]; then
        BACKUP_ARGS+=(--plaintext)
        print_info "Creating plaintext database backup..."
    else
        print_info "Creating encrypted database backup..."
        if [ "$NON_INTERACTIVE" = true ]; then
            BACKUP_ARGS+=(--non-interactive)
        fi
        # The dump is encrypted with the same key as the archive around it, so a
        # restore needs exactly one key however far in it has to reach.
        if [ -n "$KEY_FILE" ]; then
            BACKUP_ARGS+=(--key-file "$KEY_FILE")
        fi
    fi

    if bash "$BACKUP_DB_SCRIPT" "${BACKUP_ARGS[@]}" "$PROJECT_NAME"; then
        print_success "Database backup completed"
    else
        print_error "Database backup failed"
        exit 1
    fi

    # Return to original directory
    cd "$BACKUP_DIR"
else
    print_warning "Database '$PROJECT_NAME' not found, skipping database backup"
    echo "NO_DATABASE_FOUND" > "${TEMP_DIR}/${BACKUP_NAME}/NO_DATABASE.txt"
fi

# Step 2: Backup project directory
print_info "Backing up project files from: $PROJECT_DIR"
if [ "$IS_DOCKER" = true ]; then
    print_info "Environment: Docker container"
else
    print_info "Environment: Bare metal"
fi

# The excludes, shared by the copy (file mode) and the tar (stream mode).
# backups/ is where this archive is being staged and where earlier archives
# wait to be uploaded: an archive that carries them grows without limit.
# target/ is Cargo's build output, regenerable and large.
TREE_EXCLUDES=(vendor node_modules .git logs cache tmp sessions backups target)

if [ "$STREAM" = true ]; then
    # Stream mode archives the LIVE tree straight into the tar below (a second
    # -C into the site root, member names prefixed with --transform). Nothing
    # is copied: the staging directory holds only what is not in the tree.
    print_info "Stream mode: the site tree is archived in place, not copied"
else

# Create project files subdirectory
mkdir -p "${TEMP_DIR}/${BACKUP_NAME}/project_files"

# A site tree contains files the invoking account is not meant to read. The
# relay pull key (config/relay_pull_key) is the standing example: ssh refuses an
# identity file that is readable by anyone but its owner, so it is pinned to 600
# and owned by the web user, while this script runs over SSH as the deploy
# account. Copying only what that account happens to be able to read would make
# the backup quietly partial, so the copy is elevated and handed straight back —
# tar and encryption below stay unprivileged.
#
# Ownership inside the archive is not load-bearing: restore_project.sh re-derives
# it by running fix_permissions.sh, which re-pins the relay key itself.
#
# Lists the rules and requires NOPASSWD: ALL — an account holding one narrow
# NOPASSWD rule validates with -v and is then refused the real command; see
# backup_files.sh 1.1.2. Listing sends none of the mail an attempt would.
SUDO=""
if [ "$(id -u)" -ne 0 ]; then
    if command -v sudo >/dev/null 2>&1 && sudo -n -l 2>/dev/null | grep -Eq 'NOPASSWD:([[:space:]]*[A-Z]+:)*[[:space:]]*ALL([[:space:]]|$)'; then
        SUDO="sudo"
    else
        print_warning "No passwordless sudo — copying as $(whoami); any unreadable file will fail the backup"
    fi
fi

# Use rsync to copy project files
# Included: uploads/, static_files/, config/, public_html/, maintenance_scripts/
# Excluded: TREE_EXCLUDES, as directories at any depth
RSYNC_EXCLUDES=()
for x in "${TREE_EXCLUDES[@]}"; do RSYNC_EXCLUDES+=(--exclude="${x}/"); done
RSYNC_STATUS=0
$SUDO rsync -a "${RSYNC_EXCLUDES[@]}" \
    "$PROJECT_DIR/" "${TEMP_DIR}/${BACKUP_NAME}/project_files/" || RSYNC_STATUS=$?

# set -e would abort at the rsync line and skip this entirely, so the status is
# captured above instead — an operator reading the log needs the script's own
# account of what went wrong, not a bare rsync exit code.
if [ "$RSYNC_STATUS" -ne 0 ]; then
    print_error "Failed to backup project files (rsync exit ${RSYNC_STATUS})"
    if [ "$RSYNC_STATUS" -eq 23 ]; then
        print_error "Exit 23 means at least one file could not be read. The copy is"
        print_error "incomplete, so it is being discarded rather than stored as a backup."
    fi
    exit 1
fi

# Elevated rsync leaves root-owned files behind; the rest of the run is unprivileged.
if [ -n "$SUDO" ]; then
    $SUDO chown -R "$(id -u):$(id -g)" "${TEMP_DIR}/${BACKUP_NAME}/project_files"
fi

print_success "Project files backed up successfully"
# Show what key directories were backed up
for dir in uploads static_files config public_html; do
    if [ -d "${TEMP_DIR}/${BACKUP_NAME}/project_files/${dir}" ]; then
        dir_size=$(du -sh "${TEMP_DIR}/${BACKUP_NAME}/project_files/${dir}" 2>/dev/null | cut -f1)
        print_info "  - ${dir}/: ${dir_size}"
    fi
done

fi   # end of the file-mode copy

# Step 3: Backup Apache virtualhost configuration (if available)
if [ -n "$VHOST_FILE" ]; then
    print_info "Backing up Apache virtualhost configuration"

    mkdir -p "${TEMP_DIR}/${BACKUP_NAME}/apache_config"
    cp "$VHOST_FILE" "${TEMP_DIR}/${BACKUP_NAME}/apache_config/"

    if [ $? -eq 0 ]; then
        print_success "Apache config backed up: $(basename "$VHOST_FILE")"
    else
        print_error "Failed to backup Apache configuration"
        exit 1
    fi
else
    print_info "Skipping Apache config backup (not applicable for Docker)"
fi

# Step 3b: Record the site's SHAPE — the facts a restore has to reconcile
#
# backup_info.txt says the same things in prose, for a human reading the archive.
# This is the copy a restore reads, from the same generator the chain path uses,
# so the two can never describe a site differently.
# SCRIPT_DIR, not a fresh dirname of $0: by this point the script has cd'd into
# the backup directory, so a relative invocation ("bash backup_project.sh") would
# resolve "." to the OUTPUT directory and find no reconcile_site.sh there. That
# failure is silent in the worst way — the [ -f ] guard skips the warning too, so
# the archive simply comes out with no shape and nothing says so.
SHAPER="${SCRIPT_DIR}/reconcile_site.sh"
if [ -f "$SHAPER" ]; then
    if bash "$SHAPER" "$PROJECT_NAME" --print-shape --site-dir "$PROJECT_DIR" \
            --vhost-captured "$(if [ -n "$VHOST_FILE" ]; then echo yes; else echo no; fi)" \
            --out "${TEMP_DIR}/${BACKUP_NAME}/shape.json" 2>/dev/null; then
        print_success "Site shape recorded"
    else
        # An archive with no shape is still restorable — the restore treats it as
        # shape-unknown and reconciles against the target anyway.
        print_warning "Could not record the site shape; continuing without it"
    fi
else
    print_warning "reconcile_site.sh is not beside this script; no shape recorded"
fi

# Step 4: Create metadata file
print_info "Creating backup metadata..."

cat > "${TEMP_DIR}/${BACKUP_NAME}/backup_info.txt" <<EOF
Project Backup Information
==========================
Project Name: $PROJECT_NAME
Backup Date: $(date)
Backup Timestamp: $TIMESTAMP
Hostname: $(hostname)
User: $(whoami)
Environment: $(if [ "$IS_DOCKER" = true ]; then echo "Docker container"; else echo "Bare metal"; fi)

Contents:
---------
1. Database Backup: $(if [ "$DB_EXISTS" = "yes" ]; then echo "Included ($(if [ "$ENCRYPT_DB" = true ]; then echo "Encrypted"; else echo "Plaintext"; fi))"; else echo "Not included (database not found)"; fi)
2. Project Files: $PROJECT_DIR
   - uploads/ (user uploaded files)
   - static_files/ (static assets)
   - config/ (site configuration)
   - public_html/ (web root)
   - maintenance_scripts/ (admin tools)
3. Apache Config: $(if [ -n "$VHOST_FILE" ]; then echo "$VHOST_FILE"; else echo "Not included (Docker)"; fi)

Excluded from project files:
- vendor/ (reinstall via composer)
- node_modules/ (reinstall via npm)
- .git/ (version control)
- logs/ (regenerated)
- cache/ (regenerated)
- tmp/ (temporary files)
- sessions/ (regenerated)
- backups/ (earlier archives; never inside an archive)
- target/ (build output, regenerated)

Restoration Instructions:
========================
1. $(if [ "$ENCRYPT_DB" = true ]; then echo "Recover the archive key, then extract:
   php backup_envelope.php open --sidecar $FINAL_ARCHIVE.keys.json --private RECOVERY_KEY --key-out /tmp/k
   openssl enc -aes-256-cbc -d -pbkdf2 -pass file:/tmp/k -in $FINAL_ARCHIVE | tar -xz"; else echo "Extract archive: tar -xzf $FINAL_ARCHIVE"; fi)
2. Restore database (if included):
   - Encrypted: openssl enc -aes-256-cbc -d -pbkdf2 -in [database_file].sql.gz.enc | gunzip | psql -U postgres -d $PROJECT_NAME
   - Plaintext: psql -U postgres -d $PROJECT_NAME < [database_file].sql
3. Restore project files: rsync -a project_files/ /var/www/html/$PROJECT_NAME/
$(if [ -n "$VHOST_FILE" ]; then echo "4. Restore Apache config: cp apache_config/$(basename "$VHOST_FILE") /etc/apache2/sites-available/
5. Enable site: a2ensite $PROJECT_NAME
6. Reload Apache: systemctl reload apache2"; else echo "4. For Docker: rebuild container with restored files"; fi)
EOF

print_success "Metadata file created"

# Step 5: Create final tar.gz archive
if [ "$STREAM" = true ]; then
    print_info "Streaming archive: $FINAL_ARCHIVE"

    # One tar over two roots: the staging directory (dump, apache_config/,
    # shape.json, backup_info.txt — already under BACKUP_NAME/) and the live
    # site tree, whose members are renamed under BACKUP_NAME/project_files/ so
    # the archive has exactly the layout restore_project.sh reads. flags=r
    # applies the rename to member names only — a symbolic link's target is
    # left as it is, exactly as the rsync copy left it; h renames hard-link
    # targets too, since they name another member of this same archive.
    TAR_EXCLUDES=()
    for x in "${TREE_EXCLUDES[@]}"; do TAR_EXCLUDES+=(--exclude="$x"); done
    # The offloaded files' local paths, literal and unanchored, matched on the
    # member name before the rename (`./static_files/uploads/x.jpg`). Last, so
    # --no-wildcards reaches nothing else.
    if [ -n "$EXCLUDE_FROM" ]; then
        TAR_EXCLUDES+=(--no-wildcards --exclude-from="$EXCLUDE_FROM")
    fi
    SUDO_TAR=""
    if [ "$(id -u)" -ne 0 ]; then
        if command -v sudo >/dev/null 2>&1 && sudo -n -l 2>/dev/null | grep -Eq 'NOPASSWD:([[:space:]]*[A-Z]+:)*[[:space:]]*ALL([[:space:]]|$)'; then
            SUDO_TAR="sudo"
        else
            print_warning "No passwordless sudo — reading as $(whoami); an unreadable file will fail this backup"
        fi
    fi
    TAR_CMD=(${SUDO_TAR:+$SUDO_TAR} tar --warning=no-file-changed --warning=no-file-removed "${TAR_EXCLUDES[@]}"
             -czf - -C "$TEMP_DIR" "$BACKUP_NAME"
             -C "$PROJECT_DIR" --transform="flags=rh;s,^\.\$,${BACKUP_NAME}/project_files,;s,^\./,${BACKUP_NAME}/project_files/," .)

    TAR_RC=0
    ENC_RC=0
    if [ "$ENCRYPT_DB" = true ]; then
        set +e +o pipefail
        if [ "$ARCHIVE_KEY_SOURCE" = "env" ]; then
            "${TAR_CMD[@]}" | openssl enc -aes-256-cbc -salt -pbkdf2 -pass fd:3 3< <(printf '%s\n' "$BACKUP_ENCRYPTION_KEY") >&4
        else
            "${TAR_CMD[@]}" | openssl enc -aes-256-cbc -salt -pbkdf2 -pass fd:3 3< "$ARCHIVE_KEY_SOURCE" >&4
        fi
        PIPE=("${PIPESTATUS[@]}")
        set -e -o pipefail
        TAR_RC=${PIPE[0]:-2}
        ENC_RC=${PIPE[1]:-1}
    else
        set +e
        "${TAR_CMD[@]}" >&4
        TAR_RC=$?
        set -e
    fi
    # The archive's stdout is done with; from here everything is stderr and
    # the report. Closing fd 4 is what lets a reader see end of stream before
    # this script has exited.
    exec 4>&-
    printf 'TAR_RC=%s\nENC_RC=%s\n' "$TAR_RC" "$ENC_RC" > "$REPORT_FILE"

    if [ "$ENC_RC" -ne 0 ]; then
        print_error "Encrypting the archive failed (openssl exit ${ENC_RC})"
        exit 1
    fi
    if [ "$TAR_RC" -eq 1 ]; then
        print_warning "Some files changed while being read; the archive carries their state at read time"
    elif [ "$TAR_RC" -ne 0 ]; then
        print_error "Archive failed (tar exit ${TAR_RC})"
        exit 1
    fi
    print_success "Streamed ${FINAL_ARCHIVE}"
    exit 0
fi

print_info "Creating final archive: $FINAL_ARCHIVE"

cd "$TEMP_DIR"
TAR_STATUS=0
if [ "$ENCRYPT_DB" = true ]; then
    # tar streams straight into openssl, so the plaintext archive never lands on
    # disk. The key crosses on fd 3 — never argv, which is world-visible in ps
    # for the whole encrypt. pipefail is set at the top of the script, so a tar
    # failure cannot yield a valid-looking .enc of a truncated stream.
    if [ "$ARCHIVE_KEY_SOURCE" = "env" ]; then
        ( tar -czf - "$BACKUP_NAME" \
            | openssl enc -aes-256-cbc -salt -pbkdf2 -pass fd:3 -out "${BACKUP_DIR}/${FINAL_ARCHIVE}" \
        ) 3< <(printf '%s\n' "$BACKUP_ENCRYPTION_KEY") || TAR_STATUS=$?
    elif [ -n "$ARCHIVE_KEY_SOURCE" ]; then
        ( tar -czf - "$BACKUP_NAME" \
            | openssl enc -aes-256-cbc -salt -pbkdf2 -pass fd:3 -out "${BACKUP_DIR}/${FINAL_ARCHIVE}" \
        ) 3< "$ARCHIVE_KEY_SOURCE" || TAR_STATUS=$?
    else
        print_info "Enter the encryption password for the archive:"
        tar -czf - "$BACKUP_NAME" \
            | openssl enc -aes-256-cbc -salt -pbkdf2 -out "${BACKUP_DIR}/${FINAL_ARCHIVE}" || TAR_STATUS=$?
    fi
else
    tar -czf "${BACKUP_DIR}/${FINAL_ARCHIVE}" "$BACKUP_NAME" || TAR_STATUS=$?
fi

if [ "$TAR_STATUS" -ne 0 ]; then
    print_error "Failed to create archive (tar exit ${TAR_STATUS})"
    exit 1
fi

cd "$BACKUP_DIR"

# The archive holds the whole site tree; nobody but its owner should read it
# off this disk while it waits to be uploaded.
chmod 600 "$FINAL_ARCHIVE" 2>/dev/null || true

ARCHIVE_SIZE=$(ls -lh "$FINAL_ARCHIVE" | awk '{print $5}')
print_success "Backup archive created successfully!"
echo ""
echo "========================================="
echo "BACKUP COMPLETE"
echo "========================================="
echo "Archive: $FINAL_ARCHIVE"
echo "Size: $ARCHIVE_SIZE"
echo "Location: $(pwd)/$FINAL_ARCHIVE"
echo "Encrypted: $(if [ "$ENCRYPT_DB" = true ]; then echo "yes"; else echo "NO"; fi)"
echo ""
if [ "$ENCRYPT_DB" = true ]; then
    echo "To extract: openssl enc -aes-256-cbc -d -pbkdf2 -pass file:KEY -in $FINAL_ARCHIVE | tar -xz"
else
    echo "To extract: tar -xzf $FINAL_ARCHIVE"
fi
echo "========================================="
# Machine-readable, so a caller does not have to re-derive the name by globbing
# the directory and hoping the newest file is the one it just made.
echo "BACKUP_ARCHIVE=${BACKUP_DIR}/${FINAL_ARCHIVE}"

# Cleanup is handled by trap
exit 0