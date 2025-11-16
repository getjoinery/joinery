#!/usr/bin/env bash

# backup_project.sh - Complete project backup script
# Version: 1.0.0
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
#   ./backup_project.sh PROJECT_NAME [--plaintext]
#
# Options:
#   PROJECT_NAME    Name of the project to backup (required)
#                   Must match the directory name in /var/www/html/
#   --plaintext     Create unencrypted database backup (default: encrypted)
#   --help          Show help message
#
# Output:
#   Creates PROJECT-YYYY-MM-DD-HHMMSS.tar.gz in current directory
#
# Examples:
#   ./backup_project.sh myproject           # Encrypted database backup
#   ./backup_project.sh myproject --plaintext # Plaintext database backup
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
    echo "  $0 PROJECT_NAME [--plaintext]"
    echo ""
    echo "Options:"
    echo "  PROJECT_NAME              Name of the project to backup (required)"
    echo "  --plaintext, -p           Create unencrypted database backup (default: encrypted)"
    echo "  --help, -h                Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0 joinerytest            # Backup joinerytest project with encrypted database"
    echo "  $0 joinerytest --plaintext # Backup joinerytest project with plaintext database"
    echo ""
    echo "The script will create:"
    echo "  - A tar.gz archive named: PROJECT-YYYY-MM-DD-HHMMSS.tar.gz"
    echo "  - Contents: database backup, /var/www/html/PROJECT/, Apache virtualhost config"
}

# Parse arguments
PROJECT_NAME=""
ENCRYPT_DB=true

while [[ $# -gt 0 ]]; do
    case $1 in
        --plaintext|-p)
            ENCRYPT_DB=false
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

# Generate timestamp for backup filename
TIMESTAMP=$(date +"%Y-%m-%d-%H%M%S")
BACKUP_DIR=$(pwd)
BACKUP_NAME="${PROJECT_NAME}-${TIMESTAMP}"
FINAL_ARCHIVE="${BACKUP_NAME}.tar.gz"

# Verify project directory exists
PROJECT_DIR="/var/www/html/${PROJECT_NAME}"
if [ ! -d "$PROJECT_DIR" ]; then
    print_error "Project directory does not exist: $PROJECT_DIR"
    exit 1
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
    print_warning "Virtualhost config not found in standard locations, searching..."

    # Search in Apache config directories
    if [ -d "/etc/apache2" ]; then
        VHOST_FILE=$(find /etc/apache2 -name "*${PROJECT_NAME}*.conf" -type f 2>/dev/null | head -1)
    elif [ -d "/etc/httpd" ]; then
        VHOST_FILE=$(find /etc/httpd -name "*${PROJECT_NAME}*.conf" -type f 2>/dev/null | head -1)
    fi
fi

# Exit if virtualhost config not found
if [ -z "$VHOST_FILE" ] || [ ! -f "$VHOST_FILE" ]; then
    print_error "Could not find Apache virtualhost configuration for project: $PROJECT_NAME"
    print_error "Please ensure the virtualhost config exists in /etc/apache2/sites-available/ or /etc/httpd/conf.d/"
    exit 1
fi

print_success "Found virtualhost config: $VHOST_FILE"

# Create temporary directory for backup
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

# Check if database exists
DB_EXISTS=$(psql -U postgres -lqt 2>/dev/null | cut -d \| -f 1 | grep -qw "$PROJECT_NAME" && echo "yes" || echo "no")

if [ "$DB_EXISTS" = "yes" ]; then
    # Run backup_database.sh in the temp directory
    cd "${TEMP_DIR}/${BACKUP_NAME}"

    if [ "$ENCRYPT_DB" = false ]; then
        print_info "Creating plaintext database backup..."
        if bash "$BACKUP_DB_SCRIPT" --plaintext "$PROJECT_NAME"; then
            print_success "Database backup completed"
        else
            print_error "Database backup failed"
            exit 1
        fi
    else
        print_info "Creating encrypted database backup..."
        if bash "$BACKUP_DB_SCRIPT" "$PROJECT_NAME"; then
            print_success "Database backup completed"
        else
            print_error "Database backup failed"
            exit 1
        fi
    fi

    # Return to original directory
    cd "$BACKUP_DIR"
else
    print_warning "Database '$PROJECT_NAME' not found, skipping database backup"
    echo "NO_DATABASE_FOUND" > "${TEMP_DIR}/${BACKUP_NAME}/NO_DATABASE.txt"
fi

# Step 2: Backup project directory
print_info "Backing up project files from: $PROJECT_DIR"

# Create project files subdirectory
mkdir -p "${TEMP_DIR}/${BACKUP_NAME}/project_files"

# Use rsync to copy project files (excluding certain directories)
rsync -a \
    --exclude='vendor/' \
    --exclude='node_modules/' \
    --exclude='*.log' \
    --exclude='.git/' \
    --exclude='cache/' \
    --exclude='tmp/' \
    "$PROJECT_DIR/" "${TEMP_DIR}/${BACKUP_NAME}/project_files/"

if [ $? -eq 0 ]; then
    print_success "Project files backed up successfully"
else
    print_error "Failed to backup project files"
    exit 1
fi

# Step 3: Backup Apache virtualhost configuration
print_info "Backing up Apache virtualhost configuration"

mkdir -p "${TEMP_DIR}/${BACKUP_NAME}/apache_config"
cp "$VHOST_FILE" "${TEMP_DIR}/${BACKUP_NAME}/apache_config/"

if [ $? -eq 0 ]; then
    print_success "Apache config backed up: $(basename "$VHOST_FILE")"
else
    print_error "Failed to backup Apache configuration"
    exit 1
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

Contents:
---------
1. Database Backup: $(if [ "$DB_EXISTS" = "yes" ]; then echo "Included ($(if [ "$ENCRYPT_DB" = true ]; then echo "Encrypted"; else echo "Plaintext"; fi))"; else echo "Not included (database not found)"; fi)
2. Project Files: $PROJECT_DIR
3. Apache Config: $VHOST_FILE

Excluded from project files:
- vendor/
- node_modules/
- *.log
- .git/
- cache/
- tmp/

Restoration Instructions:
========================
1. Extract archive: tar -xzf $FINAL_ARCHIVE
2. Restore database (if included):
   - Encrypted: openssl enc -aes-256-cbc -d -pbkdf2 -in [database_file].sql.gz.enc | gunzip | psql -U postgres -d $PROJECT_NAME
   - Plaintext: psql -U postgres -d $PROJECT_NAME < [database_file].sql
3. Restore project files: cp -r project_files/* /var/www/html/$PROJECT_NAME/
4. Restore Apache config: cp apache_config/$(basename "$VHOST_FILE") /etc/apache2/sites-available/
5. Enable site: a2ensite $PROJECT_NAME
6. Reload Apache: systemctl reload apache2
EOF

print_success "Metadata file created"

# Step 5: Create final tar.gz archive
print_info "Creating final archive: $FINAL_ARCHIVE"

cd "$TEMP_DIR"
tar -czf "${BACKUP_DIR}/${FINAL_ARCHIVE}" "$BACKUP_NAME"

if [ $? -eq 0 ]; then
    cd "$BACKUP_DIR"
    ARCHIVE_SIZE=$(ls -lh "$FINAL_ARCHIVE" | awk '{print $5}')
    print_success "Backup archive created successfully!"
    echo ""
    echo "========================================="
    echo "BACKUP COMPLETE"
    echo "========================================="
    echo "Archive: $FINAL_ARCHIVE"
    echo "Size: $ARCHIVE_SIZE"
    echo "Location: $(pwd)/$FINAL_ARCHIVE"
    echo ""
    echo "To extract: tar -xzf $FINAL_ARCHIVE"
    echo "========================================="
else
    print_error "Failed to create archive"
    exit 1
fi

# Cleanup is handled by trap
exit 0