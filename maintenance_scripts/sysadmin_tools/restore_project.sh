#!/usr/bin/env bash

# restore_project.sh - Complete project restore script
# Version: 1.1.0 - Centralized permissions to fix_permissions.sh
#
# Description:
#   Restores a web project from a backup archive created by backup_project.sh
#   Extracts and restores:
#   - PostgreSQL database (using restore_database.sh)
#   - Project files to /var/www/html/PROJECT/
#   - Apache virtualhost configuration
#
# Dependencies:
#   - restore_database.sh (must be in same directory)
#   - PostgreSQL client tools (psql via restore_database.sh)
#   - Apache web server
#   - tar and gzip for archive extraction
#
# Usage:
#   ./restore_project.sh PROJECT_NAME BACKUP_FILE.tar.gz [--dry-run]
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

# Version information
SCRIPT_VERSION="1.0.0"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
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

print_dry_run() {
    echo -e "${CYAN}[DRY-RUN]${NC} $1"
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
    echo "  --skip-database          Skip database restoration"
    echo "  --skip-files             Skip project files restoration"
    echo "  --skip-apache            Skip Apache config restoration"
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

# Create temporary directory for extraction
TEMP_DIR=$(mktemp -d)
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

# Function to verify archive contents
verify_archive() {
    local archive_path="$1"
    local temp_extract="$2"

    print_info "Extracting archive for verification..."

    # Extract archive
    if ! tar -xzf "$archive_path" -C "$temp_extract" 2>/dev/null; then
        print_error "Failed to extract archive. File may be corrupted."
        return 1
    fi

    # Find the backup directory (should be PROJECT-TIMESTAMP format)
    local backup_dir=$(find "$temp_extract" -maxdepth 1 -type d ! -path "$temp_extract" | head -1)

    if [ -z "$backup_dir" ] || [ ! -d "$backup_dir" ]; then
        print_error "Invalid archive structure - no backup directory found"
        return 1
    fi

    echo "$backup_dir"

    print_info "Archive structure:"
    echo "----------------------------------------"

    # Check for backup info file
    if [ -f "$backup_dir/backup_info.txt" ]; then
        print_success "✓ Backup info file found"
        if [ "$DRY_RUN" = true ]; then
            echo ""
            echo "=== Backup Information ==="
            head -20 "$backup_dir/backup_info.txt"
            echo "==========================="
            echo ""
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
            echo "  Size: $(ls -lh "$db_file" | awk '{print $5}')"
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
            echo "  Files: $file_count, Directories: $dir_count"

            if [ "$DRY_RUN" = true ]; then
                echo "  Top-level contents:"
                ls -la "$backup_dir/project_files" | head -10 | sed 's/^/    /'
            fi
        else
            print_error "✗ Project files directory not found"
            return 1
        fi
    fi

    # Check for Apache config
    if [ "$SKIP_APACHE" = false ]; then
        if [ -d "$backup_dir/apache_config" ]; then
            local apache_conf=$(find "$backup_dir/apache_config" -name "*.conf" 2>/dev/null | head -1)
            if [ -n "$apache_conf" ] && [ -f "$apache_conf" ]; then
                print_success "✓ Apache config found: $(basename "$apache_conf")"
            else
                print_warning "⚠ No Apache config file found"
            fi
        else
            print_warning "⚠ Apache config directory not found"
        fi
    fi

    echo "----------------------------------------"

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

            if [ "$DB_EXISTS" = "yes" ] && [ "$FORCE" = false ]; then
                print_warning "Database '$PROJECT_NAME' already exists!"
                echo "The restore_database.sh script will backup the existing database before restoring."
                read -p "Continue with database restore? (y/N): " -n 1 -r
                echo
                if [[ ! $REPLY =~ ^[Yy]$ ]]; then
                    print_info "Skipping database restore"
                    return 0
                fi
            fi

            # Run restore_database.sh
            if bash "$RESTORE_DB_SCRIPT" "$PROJECT_NAME" "$db_file"; then
                print_success "Database restored successfully"
            else
                print_error "Database restoration failed"
                return 1
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

                # Create new project directory and restore files
                sudo mkdir -p "$PROJECT_DIR"
            fi
        else
            # Create project directory if it doesn't exist
            sudo mkdir -p "$PROJECT_DIR"
        fi

        # Copy files from backup to project directory
        if sudo cp -r "$backup_dir/project_files/"* "$PROJECT_DIR/" 2>/dev/null || \
           sudo cp -r "$backup_dir/project_files/".[^.]* "$PROJECT_DIR/" 2>/dev/null; then

            # Set proper permissions using centralized script (production mode)
            sudo "$SCRIPT_DIR/fix_permissions.sh" "$PROJECT_NAME" --production

            # Make maintenance scripts executable
            if [ -d "$PROJECT_DIR/maintenance_scripts" ]; then
                sudo find "$PROJECT_DIR/maintenance_scripts" -type f -name "*.sh" -exec chmod 755 {} \;
            fi

            print_success "Project files restored to: $PROJECT_DIR"
        else
            print_warning "No files to restore or restore partially failed"
        fi
    fi

    # Step 3: Restore Apache configuration
    if [ "$SKIP_APACHE" = false ] && [ -d "$backup_dir/apache_config" ]; then
        print_info "Restoring Apache configuration..."

        local apache_conf=$(find "$backup_dir/apache_config" -name "*.conf" 2>/dev/null | head -1)

        if [ -n "$apache_conf" ] && [ -f "$apache_conf" ]; then
            local conf_name=$(basename "$apache_conf")
            local target_conf="/etc/apache2/sites-available/$conf_name"

            # Check if config already exists
            if [ -f "$target_conf" ] && [ "$FORCE" = false ]; then
                print_warning "Apache config already exists: $target_conf"
                read -p "Replace existing Apache configuration? (y/N): " -n 1 -r
                echo
                if [[ ! $REPLY =~ ^[Yy]$ ]]; then
                    print_info "Skipping Apache config restore"
                    return 0
                fi

                # Backup existing config
                sudo cp "$target_conf" "${target_conf}.backup.$(date +%Y%m%d_%H%M%S)"
                print_info "Existing config backed up"
            fi

            # Copy Apache config
            if sudo cp "$apache_conf" "$target_conf"; then
                print_success "Apache config restored: $target_conf"

                # Enable the site
                local site_name="${conf_name%.conf}"
                print_info "Enabling Apache site: $site_name"

                if sudo a2ensite "$site_name" 2>/dev/null; then
                    print_success "Site enabled successfully"

                    # Test Apache configuration
                    if sudo apache2ctl configtest 2>/dev/null; then
                        print_success "Apache configuration test passed"

                        # Reload Apache
                        if [ "$DRY_RUN" = false ]; then
                            print_info "Reloading Apache..."
                            if sudo systemctl reload apache2; then
                                print_success "Apache reloaded successfully"
                            else
                                print_warning "Failed to reload Apache - please reload manually"
                            fi
                        fi
                    else
                        print_warning "Apache configuration test failed - please check configuration"
                    fi
                else
                    print_warning "Failed to enable site - please enable manually"
                fi
            else
                print_error "Failed to copy Apache configuration"
                return 1
            fi
        else
            print_warning "No Apache configuration found to restore"
        fi
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

# Extract and verify archive
BACKUP_DIR=$(verify_archive "$BACKUP_FILE" "$TEMP_DIR")

if [ $? -ne 0 ]; then
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