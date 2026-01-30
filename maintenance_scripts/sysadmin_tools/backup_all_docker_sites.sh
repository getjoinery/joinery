#!/usr/bin/env bash
# backup_all_docker_sites.sh - Orchestrate backups of all Joinery Docker sites
# Version: 1.0.0
#
# Description:
#   Discovers all Joinery Docker containers on the server and creates encrypted
#   backups of each, then uploads to Backblaze B2 cloud storage.
#
# Prerequisites:
#   - Docker installed and running
#   - b2 CLI installed (pip install b2) - unless using --skip-upload
#   - Encryption key configured (env var or key file)
#   - B2 credentials configured (~/.joinery_b2_config or /etc/joinery/b2_config)
#
# Usage:
#   ./backup_all_docker_sites.sh [options]
#
# Options:
#   --non-interactive     Use encryption key from env var or file (required for cron)
#   --site SITENAME       Backup only the specified site
#   --dry-run             List sites that would be backed up without actually backing up
#   --skip-upload         Create backups but don't upload to B2
#   --keep-local          Don't delete local backup files after upload
#   --help                Show help message
#
# Author: Joinery Maintenance Scripts
# License: Same as Joinery project
# Date: 2026-01-30

set -uo pipefail

# Version
SCRIPT_VERSION="1.0.0"

# Configuration defaults
NON_INTERACTIVE=false
SITE_FILTER=""
DRY_RUN=false
SKIP_UPLOAD=false
KEEP_LOCAL=false

# B2 Configuration
B2_CONFIG_FILE=""
B2_APPLICATION_KEY_ID=""
B2_APPLICATION_KEY=""
B2_BUCKET_NAME=""
B2_PATH_PREFIX="joinery-backups"

# Encryption key
ENCRYPTION_KEY=""

# Statistics
BACKUP_COUNT=0
UPLOAD_COUNT=0
ERROR_COUNT=0
TOTAL_SIZE=0

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Functions for colored output
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

print_header() {
    echo ""
    echo "========================================="
    echo "$1"
    echo "========================================="
}

# Function to show help
show_help() {
    echo "Docker Sites Backup Script v${SCRIPT_VERSION}"
    echo "Backs up all Joinery Docker containers to Backblaze B2"
    echo ""
    echo "Usage:"
    echo "  $0 [options]"
    echo ""
    echo "Options:"
    echo "  --non-interactive     Use encryption key from env var or file (no prompts)"
    echo "  --site SITENAME       Backup only the specified site"
    echo "  --dry-run             List sites that would be backed up, don't backup"
    echo "  --skip-upload         Create backups but don't upload to B2"
    echo "  --keep-local          Don't delete local backup files after upload"
    echo "  --help                Show this help message"
    echo ""
    echo "Configuration:"
    echo "  Encryption key sources (in order of precedence):"
    echo "  1. \$BACKUP_ENCRYPTION_KEY environment variable"
    echo "  2. ~/.joinery_backup_key file (must have 600 permissions)"
    echo ""
    echo "  B2 config file locations (in order of precedence):"
    echo "  1. ~/.joinery_b2_config"
    echo "  2. /etc/joinery/b2_config"
    echo ""
    echo "Examples:"
    echo "  $0 --non-interactive               # Full backup of all sites (for cron)"
    echo "  $0 --site empoweredhealthtn        # Backup single site"
    echo "  $0 --dry-run                       # Show what would be backed up"
    echo "  $0 --skip-upload --keep-local      # Local backup only (testing)"
    echo ""
    echo "Cron example:"
    echo "  0 3 * * * /path/to/backup_all_docker_sites.sh --non-interactive >> /var/log/joinery_backup.log 2>&1"
}

# Function to get encryption key
get_encryption_key() {
    # Priority 1: Environment variable
    if [ -n "${BACKUP_ENCRYPTION_KEY:-}" ]; then
        ENCRYPTION_KEY="$BACKUP_ENCRYPTION_KEY"
        print_info "Using encryption key from BACKUP_ENCRYPTION_KEY environment variable"
        return 0
    fi

    # Priority 2: Key file
    local key_file="$HOME/.joinery_backup_key"
    if [ -f "$key_file" ]; then
        # Check file permissions (should be 600)
        local perms=$(stat -c '%a' "$key_file" 2>/dev/null || stat -f '%Lp' "$key_file" 2>/dev/null)
        if [ "$perms" != "600" ]; then
            print_warning "$key_file has permissions $perms (should be 600)"
        fi
        ENCRYPTION_KEY=$(head -1 "$key_file" | tr -d '\n\r')
        if [ -n "$ENCRYPTION_KEY" ]; then
            print_info "Using encryption key from $key_file"
            return 0
        fi
    fi

    # No key available
    return 1
}

# Function to load B2 configuration
load_b2_config() {
    # Find B2 config file
    local config_locations=(
        "$HOME/.joinery_b2_config"
        "/etc/joinery/b2_config"
    )

    for config in "${config_locations[@]}"; do
        if [ -f "$config" ]; then
            B2_CONFIG_FILE="$config"
            break
        fi
    done

    if [ -z "$B2_CONFIG_FILE" ]; then
        return 1
    fi

    # Check permissions
    local perms=$(stat -c '%a' "$B2_CONFIG_FILE" 2>/dev/null || stat -f '%Lp' "$B2_CONFIG_FILE" 2>/dev/null)
    if [ "$perms" != "600" ]; then
        print_warning "$B2_CONFIG_FILE has permissions $perms (should be 600)"
    fi

    # Load config
    source "$B2_CONFIG_FILE"

    # Validate required values
    if [ -z "${B2_APPLICATION_KEY_ID:-}" ] || [ -z "${B2_APPLICATION_KEY:-}" ] || [ -z "${B2_BUCKET_NAME:-}" ]; then
        print_error "B2 config file missing required values (B2_APPLICATION_KEY_ID, B2_APPLICATION_KEY, B2_BUCKET_NAME)"
        return 1
    fi

    return 0
}

# Function to discover Joinery Docker containers
discover_containers() {
    local containers=()

    # Get all running containers
    while IFS= read -r container; do
        if [ -n "$container" ]; then
            # Check if this looks like a Joinery container
            # Look for /var/www/html/SITENAME/maintenance_scripts directory
            if docker exec "$container" test -d "/var/www/html/${container}/maintenance_scripts" 2>/dev/null; then
                containers+=("$container")
            fi
        fi
    done < <(docker ps --format '{{.Names}}' 2>/dev/null)

    echo "${containers[@]}"
}

# Function to check disk space
check_disk_space() {
    local required_gb=5
    local available_gb=$(df /tmp --output=avail -BG 2>/dev/null | tail -1 | tr -d 'G ')

    if [ -n "$available_gb" ] && [ "$available_gb" -lt "$required_gb" ]; then
        print_error "Insufficient disk space in /tmp: ${available_gb}GB available, ${required_gb}GB required"
        return 1
    fi
    return 0
}

# Function to clean up old backups in container
cleanup_container_tmp() {
    local container="$1"

    print_info "Cleaning up old backup files in container $container..."
    docker exec "$container" find /tmp -name "${container}-*.tar.gz" -mmin +60 -delete 2>/dev/null || true
}

# Function to backup a single site
backup_site() {
    local container="$1"
    local temp_dir="$2"

    print_header "Backing up: $container"

    # Check if container is running
    if ! docker ps --format '{{.Names}}' | grep -q "^${container}$"; then
        print_warning "Container $container is not running, skipping"
        return 1
    fi

    # Clean up any old backups in the container
    cleanup_container_tmp "$container"

    # Determine the site directory inside the container
    local site_dir="/var/www/html/${container}"

    # Check if backup scripts exist in container
    if ! docker exec "$container" test -f "${site_dir}/maintenance_scripts/sysadmin_tools/backup_project.sh"; then
        print_error "backup_project.sh not found in container $container"
        return 1
    fi

    # Run backup inside container
    print_info "Running backup_project.sh inside container..."

    # Pass encryption key via environment variable
    local backup_cmd="${site_dir}/maintenance_scripts/sysadmin_tools/backup_project.sh"
    backup_cmd="$backup_cmd ${container} --non-interactive --output-dir /tmp"

    if ! docker exec -e BACKUP_ENCRYPTION_KEY="$ENCRYPTION_KEY" "$container" bash -c "$backup_cmd"; then
        print_error "Backup failed inside container $container"
        return 1
    fi

    # Find the created backup file (use bash -c to expand glob inside container)
    local backup_file=$(docker exec "$container" bash -c "ls -t /tmp/${container}-*.tar.gz 2>/dev/null | head -1")

    if [ -z "$backup_file" ]; then
        print_error "No backup file found in container $container"
        return 1
    fi

    local backup_filename=$(basename "$backup_file")
    print_info "Backup created: $backup_filename"

    # Copy backup out of container
    print_info "Copying backup from container..."
    if ! docker cp "${container}:${backup_file}" "${temp_dir}/${backup_filename}"; then
        print_error "Failed to copy backup from container $container"
        # Clean up inside container
        docker exec "$container" rm -f "$backup_file" 2>/dev/null || true
        return 1
    fi

    # Clean up inside container
    docker exec "$container" rm -f "$backup_file" 2>/dev/null || true

    # Get file size
    local file_size=$(ls -lh "${temp_dir}/${backup_filename}" | awk '{print $5}')
    local file_bytes=$(stat -c %s "${temp_dir}/${backup_filename}" 2>/dev/null || stat -f %z "${temp_dir}/${backup_filename}" 2>/dev/null)
    TOTAL_SIZE=$((TOTAL_SIZE + file_bytes))

    print_success "Backup copied: ${backup_filename} (${file_size})"
    ((BACKUP_COUNT++))

    # Upload to B2
    if [ "$SKIP_UPLOAD" = false ]; then
        upload_to_b2 "${temp_dir}/${backup_filename}" "$container"
    fi

    # Clean up local file (unless --keep-local)
    if [ "$KEEP_LOCAL" = false ] && [ "$SKIP_UPLOAD" = false ]; then
        print_info "Removing local backup file..."
        rm -f "${temp_dir}/${backup_filename}"
    elif [ "$KEEP_LOCAL" = true ]; then
        print_info "Keeping local backup: ${temp_dir}/${backup_filename}"
    fi

    return 0
}

# Function to upload to B2 with retry
upload_to_b2() {
    local file="$1"
    local sitename="$2"
    local filename=$(basename "$file")
    local hostname=$(hostname)

    # B2 path: bucket/prefix/hostname/sitename/filename
    local b2_path
    if [ -n "$B2_PATH_PREFIX" ]; then
        b2_path="${B2_PATH_PREFIX}/${hostname}/${sitename}/${filename}"
    else
        b2_path="${hostname}/${sitename}/${filename}"
    fi

    print_info "Uploading to B2: $b2_path"

    local max_retries=3
    local retry_count=0
    local wait_time=5

    while [ $retry_count -lt $max_retries ]; do
        if b2 upload-file "$B2_BUCKET_NAME" "$file" "$b2_path"; then
            print_success "Uploaded to B2: $b2_path"
            ((UPLOAD_COUNT++))
            return 0
        fi

        ((retry_count++))
        if [ $retry_count -lt $max_retries ]; then
            print_warning "B2 upload failed, retrying in ${wait_time}s... (attempt $retry_count/$max_retries)"
            sleep $wait_time
            wait_time=$((wait_time * 2))  # Exponential backoff
        fi
    done

    print_error "Failed to upload $filename to B2 after $max_retries attempts"
    ((ERROR_COUNT++))
    return 1
}

# Function to format bytes to human readable
format_bytes() {
    local bytes=$1
    if [ $bytes -ge 1073741824 ]; then
        echo "$(echo "scale=2; $bytes / 1073741824" | bc)GB"
    elif [ $bytes -ge 1048576 ]; then
        echo "$(echo "scale=2; $bytes / 1048576" | bc)MB"
    elif [ $bytes -ge 1024 ]; then
        echo "$(echo "scale=2; $bytes / 1024" | bc)KB"
    else
        echo "${bytes}B"
    fi
}

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --non-interactive|-n)
            NON_INTERACTIVE=true
            shift
            ;;
        --site|-s)
            if [[ -n "${2:-}" && ! "$2" =~ ^- ]]; then
                SITE_FILTER="$2"
                shift 2
            else
                print_error "--site requires a site name argument"
                exit 1
            fi
            ;;
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --skip-upload)
            SKIP_UPLOAD=true
            shift
            ;;
        --keep-local)
            KEEP_LOCAL=true
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
            print_error "Unexpected argument: $1"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

# Main script
print_header "Joinery Docker Backup v${SCRIPT_VERSION}"
echo "Date: $(date)"
echo "Host: $(hostname)"

# Check prerequisites
print_info "Checking prerequisites..."

# Check Docker
if ! command -v docker &> /dev/null; then
    print_error "Docker is not installed"
    exit 1
fi

if ! docker ps &> /dev/null; then
    print_error "Docker is not running or you don't have permission"
    exit 1
fi
print_success "Docker is available"

# Check B2 CLI (unless skipping upload)
if [ "$SKIP_UPLOAD" = false ]; then
    if ! command -v b2 &> /dev/null; then
        print_error "b2 CLI is not installed (pip install b2)"
        print_error "Use --skip-upload to create local backups without B2"
        exit 1
    fi

    # Load B2 config
    if ! load_b2_config; then
        print_error "B2 configuration not found"
        print_error "Create ~/.joinery_b2_config or /etc/joinery/b2_config with:"
        echo "  B2_APPLICATION_KEY_ID=\"your_key_id\""
        echo "  B2_APPLICATION_KEY=\"your_application_key\""
        echo "  B2_BUCKET_NAME=\"your-backup-bucket\""
        echo "  B2_PATH_PREFIX=\"joinery-backups\"  # optional"
        exit 1
    fi
    print_success "B2 configuration loaded from $B2_CONFIG_FILE"

    # Authenticate with B2
    print_info "Authenticating with B2..."
    if ! b2 authorize-account "$B2_APPLICATION_KEY_ID" "$B2_APPLICATION_KEY" &> /dev/null; then
        print_error "Failed to authenticate with B2"
        exit 1
    fi
    print_success "B2 authentication successful"
fi

# Get encryption key
if ! get_encryption_key; then
    if [ "$NON_INTERACTIVE" = true ]; then
        print_error "Non-interactive mode requires encryption key"
        print_error "Set BACKUP_ENCRYPTION_KEY environment variable or create ~/.joinery_backup_key"
        exit 1
    else
        # Interactive mode: prompt for password
        echo ""
        read -sp "Enter encryption password for backups: " ENCRYPTION_KEY
        echo ""
        if [ -z "$ENCRYPTION_KEY" ]; then
            print_error "Encryption password cannot be empty"
            exit 1
        fi
    fi
fi

# Check disk space
if ! check_disk_space; then
    exit 1
fi
print_success "Sufficient disk space available"

# Discover containers
print_info "Discovering Joinery Docker containers..."

if [ -n "$SITE_FILTER" ]; then
    # Single site mode
    if docker ps --format '{{.Names}}' | grep -q "^${SITE_FILTER}$"; then
        # Verify it's a Joinery container
        if docker exec "$SITE_FILTER" test -d "/var/www/html/${SITE_FILTER}/maintenance_scripts" 2>/dev/null; then
            CONTAINERS=("$SITE_FILTER")
        else
            print_error "Container $SITE_FILTER does not appear to be a Joinery site"
            exit 1
        fi
    else
        print_error "Container $SITE_FILTER not found or not running"
        exit 1
    fi
else
    # Discover all containers
    readarray -t CONTAINERS < <(discover_containers | tr ' ' '\n')
fi

if [ ${#CONTAINERS[@]} -eq 0 ]; then
    print_warning "No Joinery Docker containers found"
    exit 0
fi

echo ""
print_info "Found ${#CONTAINERS[@]} Joinery container(s):"
for c in "${CONTAINERS[@]}"; do
    echo "  - $c"
done

# Dry run mode
if [ "$DRY_RUN" = true ]; then
    print_header "DRY RUN - No backups created"
    echo "Would backup the following containers:"
    for c in "${CONTAINERS[@]}"; do
        echo "  - $c"
    done
    echo ""
    echo "Options:"
    echo "  Upload to B2: $([ "$SKIP_UPLOAD" = false ] && echo "Yes" || echo "No")"
    echo "  Keep local files: $([ "$KEEP_LOCAL" = true ] && echo "Yes" || echo "No")"
    exit 0
fi

# Create temp directory
TEMP_DIR=$(mktemp -d -t joinery_backup_XXXXXX)
print_info "Temp directory: $TEMP_DIR"

# Cleanup function
cleanup() {
    if [ -d "$TEMP_DIR" ]; then
        if [ "$KEEP_LOCAL" = true ]; then
            print_info "Local backups kept in: $TEMP_DIR"
        else
            print_info "Cleaning up temp directory..."
            rm -rf "$TEMP_DIR"
        fi
    fi
}

# Set trap for cleanup
trap cleanup EXIT

# Backup each container
for container in "${CONTAINERS[@]}"; do
    if ! backup_site "$container" "$TEMP_DIR"; then
        ((ERROR_COUNT++))
    fi
done

# Print summary
print_header "BACKUP SUMMARY"
echo "Date: $(date)"
echo "Host: $(hostname)"
echo ""
echo "Results:"
echo "  Backups created:    $BACKUP_COUNT"
if [ "$SKIP_UPLOAD" = false ]; then
    echo "  Uploads successful: $UPLOAD_COUNT"
fi
echo "  Errors:             $ERROR_COUNT"
echo "  Total backup size:  $(format_bytes $TOTAL_SIZE)"
echo ""

if [ $ERROR_COUNT -gt 0 ]; then
    print_warning "Backup completed with $ERROR_COUNT error(s)"
    exit 1
else
    print_success "All backups completed successfully"
    exit 0
fi
