#!/usr/bin/env bash
#Version 2.01 - Fixed password prompts and improved user experience

# Global flag for encryption (default: encrypted)
ENCRYPT_BACKUPS=true

# Check for .pgpass file or PGPASSWORD environment variable
if [[ ! -f ~/.pgpass ]] && [[ -z "$PGPASSWORD" ]]; then
    echo "⚠️  No .pgpass file found and PGPASSWORD not set."
    echo "You will be prompted for the postgres password multiple times."
    echo ""
    echo "To avoid this in the future, create a .pgpass file:"
    echo "  echo 'localhost:5432:*:postgres:YOUR_PASSWORD' > ~/.pgpass"
    echo "  chmod 600 ~/.pgpass"
    echo ""
    read -p "Press Enter to continue with password prompts..."
    echo ""
elif [[ -f ~/.pgpass ]]; then
    echo "✓ Found .pgpass file - using passwordless authentication."
elif [[ -n "$PGPASSWORD" ]]; then
    echo "✓ Found PGPASSWORD environment variable - using passwordless authentication."
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
        
        echo "🔑 Enter PostgreSQL password for user 'postgres':"
        if pg_dump -U postgres -W "$db_name" > "$temp_file"; then
            echo "✓ Database dump completed"
            echo ""
            echo "🔐 Enter encryption password for backup file:"
            if gzip -9 < "$temp_file" | openssl enc -aes-256-cbc -salt -pbkdf2 -out "$backup_file"; then
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
        echo "🔑 Enter PostgreSQL password for user 'postgres':"
        if pg_dump -U postgres -W "$db_name" > "$backup_file"; then
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
    echo "🔑 Enter PostgreSQL password for user 'postgres':"
    databases=$(psql -U postgres -W -t -c "SELECT datname FROM pg_database WHERE datistemplate = false AND datname NOT IN ('postgres', 'template0', 'template1');" 2>/dev/null)
    
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
    
    if [ "$ENCRYPT_BACKUPS" = true ]; then
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

# Function to show help
show_help() {
    echo "PostgreSQL Database Backup Script v2.01"
    echo ""
    echo "Usage:"
    echo "  $0                        # Backup all databases (encrypted)"
    echo "  $0 [database_name]        # Backup specific database (encrypted)"
    echo "  $0 --plaintext            # Backup all databases (unencrypted)"
    echo "  $0 --plaintext [db_name]  # Backup specific database (unencrypted)"
    echo ""
    echo "Options:"
    echo "  --plaintext, -p           Create unencrypted backups"
    echo "  --help, -h                Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0                        # Backup all user databases (encrypted)"
    echo "  $0 myapp                  # Backup only 'myapp' database (encrypted)"
    echo "  $0 --plaintext            # Backup all databases (unencrypted)"
    echo "  $0 --plaintext myapp      # Backup 'myapp' database (unencrypted)"
    echo ""
    echo "Security Notes:"
    echo "  • Encrypted backups use AES-256-CBC + gzip compression"
    echo "  • System databases (postgres, template0, template1) are excluded from 'all' backup"
    echo "  • All backup files are created with 600 permissions (owner read/write only)"
    echo "  • Use strong passwords for encrypted backups"
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