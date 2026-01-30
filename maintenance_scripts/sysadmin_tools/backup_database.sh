#!/usr/bin/env bash
#Version 3.0 - Added --non-interactive mode for automated backups

# Global flags
ENCRYPT_BACKUPS=true
NON_INTERACTIVE=false
ENCRYPTION_KEY=""

# Authentication order: 1) .pgpass, 2) config file, 3) interactive prompt

# Check for .pgpass file first
if [[ -f ~/.pgpass ]]; then
    echo "✓ Found .pgpass file - using passwordless authentication."
elif [[ -n "$PGPASSWORD" ]]; then
    echo "✓ Found PGPASSWORD environment variable - using passwordless authentication."
else
    # Try to find and load password from config file
    CONFIG_FILE=""
    CONFIG_PASSWORD=""
    
    # Strategy 1: Try database names with _test suffix removed
    # Note: For backup script, we might only have one database name, so check if it's available
    if [[ -n "$DATABASE_NAME" ]]; then
        # Single database backup - try the specified database name
        for DB_NAME in "$DATABASE_NAME"; do
            # Remove _test suffix if present
            SITENAME="${DB_NAME%_test}"
            if [[ -f "/var/www/html/${SITENAME}/config/Globalvars_site.php" ]]; then
                CONFIG_FILE="/var/www/html/${SITENAME}/config/Globalvars_site.php"
                break
            fi
        done
        
        # Strategy 2: Try original database name as-is
        if [[ -z "$CONFIG_FILE" ]] && [[ -f "/var/www/html/${DATABASE_NAME}/config/Globalvars_site.php" ]]; then
            CONFIG_FILE="/var/www/html/${DATABASE_NAME}/config/Globalvars_site.php"
        fi
    fi
    
    # Strategy 3: Look for any config file in /var/www/html/*/config/
    if [[ -z "$CONFIG_FILE" ]]; then
        CONFIG_FILE=$(find /var/www/html/*/config/Globalvars_site.php 2>/dev/null | head -1)
    fi
    
    if [[ -n "$CONFIG_FILE" ]] && [[ -f "$CONFIG_FILE" ]]; then
        echo "✓ Found config file: $CONFIG_FILE"
        # Extract password from config file
        CONFIG_PASSWORD=$(grep "dbpassword.*=" "$CONFIG_FILE" | head -1 | sed "s/.*'\(.*\)'.*/\1/")
        if [[ -n "$CONFIG_PASSWORD" ]]; then
            export PGPASSWORD="$CONFIG_PASSWORD"
            echo "✓ Using database password from config file."
        fi
    fi
    
    # If still no password, fall back to interactive prompt (unless non-interactive)
    if [[ -z "$PGPASSWORD" ]]; then
        echo "⚠️  No .pgpass file found, no config file found, and PGPASSWORD not set."
        echo "You will be prompted for the postgres password multiple times."
        echo ""
        echo "To avoid this in the future, either:"
        echo "  1) Create a .pgpass file: echo 'localhost:5432:*:postgres:YOUR_PASSWORD' > ~/.pgpass && chmod 600 ~/.pgpass"
        echo "  2) Set PGPASSWORD: export PGPASSWORD='your_password'"
        echo "  3) Ensure config file exists at /var/www/html/SITENAME/config/Globalvars_site.php"
        echo ""
        # Note: NON_INTERACTIVE not set yet during initial config loading
        # This prompt will be skipped if running via automated scripts that set PGPASSWORD
        if [[ "${NON_INTERACTIVE:-false}" != "true" ]]; then
            read -p "Press Enter to continue with password prompts..."
            echo ""
        fi
    fi
fi

now=$(date +"%m_%d_%Y")

# Function to backup a single database
backup_database() {
    local db_name="$1"
    local backup_file

    if [ "$ENCRYPT_BACKUPS" = true ]; then
        backup_file="${db_name}-${now}.sql.gz.enc"
        echo "📦 Backing up database (encrypted): $db_name"
        echo ""

        # Create compressed + encrypted backup using temporary file
        local temp_file=$(mktemp --suffix=.sql)

        if pg_dump -U postgres "$db_name" > "$temp_file"; then
            echo "✓ Database dump completed"

            local encrypt_result=1
            if [ -n "$ENCRYPTION_KEY" ]; then
                # Non-interactive: use key from variable
                if gzip -9 < "$temp_file" | openssl enc -aes-256-cbc -salt -pbkdf2 -pass pass:"$ENCRYPTION_KEY" -out "$backup_file" 2>/dev/null; then
                    encrypt_result=0
                fi
            else
                # Interactive: prompt for password
                echo ""
                echo "🔐 Enter encryption password for backup file:"
                if gzip -9 < "$temp_file" | openssl enc -aes-256-cbc -salt -pbkdf2 -out "$backup_file"; then
                    encrypt_result=0
                fi
            fi

            if [ $encrypt_result -eq 0 ]; then
                rm -f "$temp_file"
                # Set restrictive permissions on encrypted file
                chmod 600 "$backup_file"
                echo "✓ Encrypted backup of '$db_name' complete: $backup_file"
                echo "  File size: $(ls -lh "$backup_file" | awk '{print $5}')"
                echo "  To decrypt: openssl enc -aes-256-cbc -d -pbkdf2 -in $backup_file | gunzip > ${db_name}-restored.sql"
            else
                rm -f "$temp_file"
                echo "✗ Error during compression/encryption of '$db_name'"
                return 1
            fi
        else
            rm -f "$temp_file"
            echo "✗ Error during pg_dump of '$db_name'"
            return 1
        fi
    else
        backup_file="${db_name}-${now}.sql"
        echo "📦 Backing up database (plaintext): $db_name"
        echo "⚠️  WARNING: Creating unencrypted backup file!"
        echo ""
        
        # Create plaintext backup
        if pg_dump -U postgres "$db_name" > "$backup_file"; then
            # Set restrictive permissions on plaintext file
            chmod 600 "$backup_file"
            echo "✓ Plaintext backup of '$db_name' complete: $backup_file"
            echo "  File size: $(ls -lh "$backup_file" | awk '{print $5}')"
        else
            echo "✗ Error backing up '$db_name'"
            return 1
        fi
    fi
}

# Function to backup all databases
backup_all_databases() {
    local backup_type
    if [ "$ENCRYPT_BACKUPS" = true ]; then
        backup_type="ENCRYPTED"
    else
        backup_type="PLAINTEXT"
    fi
    
    echo "========================================="
    echo "BACKING UP ALL DATABASES ($backup_type)"
    echo "Date: $(date)"
    echo "========================================="
    
    if [ "$ENCRYPT_BACKUPS" = false ]; then
        echo "⚠️  WARNING: Creating unencrypted backup files!"
        echo ""
    fi
    
    # Get list of all databases (excluding system databases)
    echo "Getting list of databases..."
    databases=$(psql -U postgres -t -c "SELECT datname FROM pg_database WHERE datistemplate = false AND datname NOT IN ('postgres', 'template0', 'template1');" 2>/dev/null)
    
    if [ $? -ne 0 ]; then
        echo "✗ Error: Could not connect to PostgreSQL or retrieve database list."
        echo "Please check your PostgreSQL connection and credentials."
        exit 1
    fi
    
    # Remove leading/trailing whitespace and convert to array
    databases=$(echo "$databases" | tr -d ' ')
    
    if [ -z "$databases" ]; then
        echo "No user databases found to backup."
        exit 0
    fi
    
    echo "✓ Found databases to backup:"
    echo "$databases" | sed 's/^/  - /'
    echo ""
    
    if [ "$NON_INTERACTIVE" = false ]; then
        if [ "$ENCRYPT_BACKUPS" = true ] && [ -z "$ENCRYPTION_KEY" ]; then
            echo "💡 Each database will prompt for an encryption password."
            echo "For convenience, you may want to use the same password for all databases."
            echo ""
        fi

        read -p "Continue with backup of all databases? (y/N): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            echo "Backup cancelled."
            exit 0
        fi
    fi
    
    echo ""
    
    # Backup each database
    backup_count=0
    error_count=0
    
    for db in $databases; do
        if [ -n "$db" ]; then
            echo "----------------------------------------"
            backup_database "$db"
            if [ $? -eq 0 ]; then
                ((backup_count++))
            else
                ((error_count++))
            fi
            echo ""
        fi
    done
    
    echo "========================================="
    echo "BACKUP SUMMARY"
    echo "Backup type: $backup_type"
    echo "Successful backups: $backup_count"
    echo "Failed backups: $error_count"
    echo "Backup files created with date: $now"
    if [ "$ENCRYPT_BACKUPS" = true ]; then
        echo "File extension: .sql.gz.enc"
        echo "To decrypt: openssl enc -aes-256-cbc -d -pbkdf2 -in [file] | gunzip > restored.sql"
    else
        echo "File extension: .sql"
        echo "⚠️  Remember: These files contain unencrypted database data!"
    fi
    echo "========================================="
    
    if [ $error_count -gt 0 ]; then
        exit 1
    fi
}

# Function to get encryption key for non-interactive mode
get_encryption_key() {
    # Priority 1: Environment variable
    if [ -n "$BACKUP_ENCRYPTION_KEY" ]; then
        ENCRYPTION_KEY="$BACKUP_ENCRYPTION_KEY"
        echo "✓ Using encryption key from BACKUP_ENCRYPTION_KEY environment variable"
        return 0
    fi

    # Priority 2: Key file
    local key_file="$HOME/.joinery_backup_key"
    if [ -f "$key_file" ]; then
        # Check file permissions (should be 600)
        local perms=$(stat -c '%a' "$key_file" 2>/dev/null || stat -f '%Lp' "$key_file" 2>/dev/null)
        if [ "$perms" != "600" ]; then
            echo "⚠️  Warning: $key_file has permissions $perms (should be 600)"
        fi
        ENCRYPTION_KEY=$(cat "$key_file" | head -1 | tr -d '\n\r')
        if [ -n "$ENCRYPTION_KEY" ]; then
            echo "✓ Using encryption key from $key_file"
            return 0
        fi
    fi

    # No key available
    return 1
}

# Function to show help
show_help() {
    echo "PostgreSQL Database Backup Script v3.0"
    echo ""
    echo "Usage:"
    echo "  $0                             # Backup all databases (encrypted, interactive)"
    echo "  $0 [database_name]             # Backup specific database (encrypted, interactive)"
    echo "  $0 --non-interactive [db_name] # Backup with key from env/file (for automation)"
    echo "  $0 --plaintext [db_name]       # Backup specific database (unencrypted)"
    echo ""
    echo "Options:"
    echo "  --non-interactive, -n     Use encryption key from env var or file (no prompts)"
    echo "  --plaintext, -p           Create unencrypted backups"
    echo "  --help, -h                Show this help message"
    echo ""
    echo "Non-Interactive Mode:"
    echo "  Encryption key sources (in order of precedence):"
    echo "  1. \$BACKUP_ENCRYPTION_KEY environment variable"
    echo "  2. ~/.joinery_backup_key file (must have 600 permissions)"
    echo ""
    echo "Examples:"
    echo "  $0                              # Backup all databases (encrypted, prompts for password)"
    echo "  $0 myapp                        # Backup 'myapp' database (encrypted, prompts)"
    echo "  $0 --non-interactive myapp      # Backup 'myapp' using key from env/file"
    echo "  $0 --plaintext myapp            # Backup 'myapp' database (unencrypted)"
    echo ""
    echo "  # Set encryption key via environment:"
    echo "  BACKUP_ENCRYPTION_KEY='secretkey' $0 --non-interactive myapp"
    echo ""
    echo "Security Notes:"
    echo "  • Encrypted backups use AES-256-CBC + gzip compression"
    echo "  • System databases (postgres, template0, template1) are excluded from 'all' backup"
    echo "  • All backup files are created with 600 permissions (owner read/write only)"
    echo "  • Use strong passwords for encrypted backups"
    echo "  • Key file should have 600 permissions"
    echo ""
    echo "Decryption:"
    echo "  openssl enc -aes-256-cbc -d -pbkdf2 -in backup.sql.gz.enc | gunzip > restored.sql"
}

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --plaintext|-p)
            ENCRYPT_BACKUPS=false
            shift
            ;;
        --non-interactive|-n)
            NON_INTERACTIVE=true
            shift
            ;;
        --help|-h)
            show_help
            exit 0
            ;;
        -*)
            echo "Error: Unknown option $1"
            echo "Use --help for usage information"
            exit 1
            ;;
        *)
            # This is a database name, store it and break
            DATABASE_NAME="$1"
            shift
            break
            ;;
    esac
done

# Handle non-interactive mode encryption key
if [ "$NON_INTERACTIVE" = true ] && [ "$ENCRYPT_BACKUPS" = true ]; then
    if ! get_encryption_key; then
        echo "✗ Error: Non-interactive mode requires encryption key"
        echo ""
        echo "Please set one of the following:"
        echo "  1. Environment variable: export BACKUP_ENCRYPTION_KEY='your_key'"
        echo "  2. Key file: echo 'your_key' > ~/.joinery_backup_key && chmod 600 ~/.joinery_backup_key"
        exit 1
    fi
fi

# Check if OpenSSL is available when encryption is enabled
if [ "$ENCRYPT_BACKUPS" = true ]; then
    if ! command -v openssl &> /dev/null; then
        echo "✗ Error: OpenSSL is required for encrypted backups but is not installed."
        echo "Please install OpenSSL or use --plaintext for unencrypted backups."
        echo ""
        echo "To install OpenSSL:"
        echo "  Ubuntu/Debian: sudo apt-get install openssl"
        echo "  CentOS/RHEL:   sudo yum install openssl"
        echo "  macOS:         OpenSSL is pre-installed"
        exit 1
    fi
fi

# Main script logic
if [ -z "$DATABASE_NAME" ]; then
    # No database name specified - backup all databases
    backup_all_databases
else
    # Single database backup
    if [ "$ENCRYPT_BACKUPS" = true ]; then
        echo "📦 Backing up single database (encrypted): $DATABASE_NAME"
    else
        echo "📦 Backing up single database (plaintext): $DATABASE_NAME"
        echo "⚠️  WARNING: Creating unencrypted backup file!"
    fi
    echo ""
    
    backup_database "$DATABASE_NAME"
    
    if [ $? -eq 0 ]; then
        echo ""
        echo "✅ Backup complete."
        exit 0
    else
        echo ""
        echo "❌ Backup failed."
        exit 1
    fi
fi