#!/usr/bin/env bash
#Version 2.01 - Fixed decryption, password prompts, and connection handling

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
    # Note: For restore script, we get the database name from command line argument
    if [[ -n "$1" ]] && [[ "$1" != "--help" ]] && [[ "$1" != "-h" ]]; then
        DB_NAME="$1"
        # Remove _test suffix if present
        SITENAME="${DB_NAME%_test}"
        if [[ -f "/var/www/html/${SITENAME}/config/Globalvars_site.php" ]]; then
            CONFIG_FILE="/var/www/html/${SITENAME}/config/Globalvars_site.php"
        fi
        
        # Strategy 2: Try original database name as-is
        if [[ -z "$CONFIG_FILE" ]] && [[ -f "/var/www/html/${DB_NAME}/config/Globalvars_site.php" ]]; then
            CONFIG_FILE="/var/www/html/${DB_NAME}/config/Globalvars_site.php"
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
    
    # If still no password, fall back to interactive prompt
    if [[ -z "$PGPASSWORD" ]]; then
        echo "⚠️  No .pgpass file found, no config file found, and PGPASSWORD not set."
        echo "You will be prompted for the postgres password multiple times."
        echo ""
        echo "To avoid this in the future, either:"
        echo "  1) Create a .pgpass file: echo 'localhost:5432:*:postgres:YOUR_PASSWORD' > ~/.pgpass && chmod 600 ~/.pgpass"
        echo "  2) Set PGPASSWORD: export PGPASSWORD='your_password'"
        echo "  3) Ensure config file exists at /var/www/html/SITENAME/config/Globalvars_site.php"
        echo ""
        read -p "Press Enter to continue with password prompts..."
        echo ""
    fi
fi

# Function to show help
show_help() {
    echo "PostgreSQL Database Restore Script v2.01"
    echo ""
    echo "Usage:"
    echo "  $0 DB_NAME FILE_TO_RESTORE"
    echo ""
    echo "Supported file formats:"
    echo "  • .sql                 - Plain SQL dump"
    echo "  • .sql.gz.enc          - Encrypted compressed dump (from backup script v2.00+)"
    echo "  • .sql.gz              - Compressed SQL dump"
    echo ""
    echo "Examples:"
    echo "  $0 myapp myapp-06_26_2025.sql"
    echo "  $0 myapp myapp-06_26_2025.sql.gz.enc"
    echo "  $0 myapp myapp-06_26_2025.sql.gz"
    echo ""
    echo "Features:"
    echo "  • Automatic backup of existing database before restore"
    echo "  • Support for encrypted backup files"
    echo "  • Automatic file format detection"
    echo "  • Safe database recreation with connection handling"
    echo "  • Clear password prompts"
}

# Function to decrypt and decompress file if needed
prepare_restore_file() {
    local input_file="$1"
    local output_var="$2"
    
    if [[ ! -f "$input_file" ]]; then
        echo "✗ Error: File '$input_file' does not exist."
        exit 1
    fi
    
    echo "📁 Analyzing file: $input_file"
    echo "   File size: $(ls -lh "$input_file" | awk '{print $5}')"
    
    # Determine file type and prepare accordingly
    if [[ "$input_file" == *.sql.gz.enc ]]; then
        echo "🔍 Detected encrypted compressed file."
        echo ""
        
        local temp_file=$(mktemp --suffix=.sql)
        echo "🔐 Enter decryption password for backup file:"
        if openssl enc -aes-256-cbc -d -pbkdf2 -in "$input_file" 2>/dev/null | gunzip > "$temp_file" 2>/dev/null; then
            echo "✓ File decrypted and decompressed successfully."
            echo "   Decompressed size: $(ls -lh "$temp_file" | awk '{print $5}')"
            eval "$output_var='$temp_file'"
            return 0
        else
            rm -f "$temp_file"
            echo "✗ Error decrypting file. Please check your password."
            exit 1
        fi
        
    elif [[ "$input_file" == *.sql.gz ]]; then
        echo "🔍 Detected compressed file."
        
        local temp_file=$(mktemp --suffix=.sql)
        if gunzip < "$input_file" > "$temp_file" 2>/dev/null; then
            echo "✓ File decompressed successfully."
            echo "   Decompressed size: $(ls -lh "$temp_file" | awk '{print $5}')"
            eval "$output_var='$temp_file'"
            return 0
        else
            rm -f "$temp_file"
            echo "✗ Error decompressing file."
            exit 1
        fi
        
    elif [[ "$input_file" == *.sql ]]; then
        echo "🔍 Detected plain SQL file."
        eval "$output_var='$input_file'"
        return 0
        
    else
        echo "⚠️  Warning: Unknown file format. Treating as plain SQL file."
        eval "$output_var='$input_file'"
        return 0
    fi
}

# Function to create backup of existing database
backup_existing_database() {
    local db_name="$1"
    local now=$(date +"%m_%d_%Y_%H%M%S")
    local backup_file="${db_name}-${now}-pre-restore.sql.gz.enc"
    
    echo "📦 Creating backup of existing database before restore..."
    echo ""
    
    local temp_file=$(mktemp --suffix=.sql)
    if pg_dump -U postgres "$db_name" > "$temp_file" 2>/dev/null; then
        echo "✓ Database dump completed"
        echo ""
        echo "🔐 Enter encryption password for backup file:"
        if gzip -9 < "$temp_file" | openssl enc -aes-256-cbc -salt -pbkdf2 -out "$backup_file" 2>/dev/null; then
            rm -f "$temp_file"
            chmod 600 "$backup_file"
            echo "✓ Pre-restore backup complete: $backup_file"
            echo "   File size: $(ls -lh "$backup_file" | awk '{print $5}')"
            return 0
        else
            rm -f "$temp_file"
            echo "✗ Error encrypting backup."
            return 1
        fi
    else
        rm -f "$temp_file"
        echo "✗ Error creating backup of existing database."
        return 1
    fi
}

# Function to terminate connections to a database
terminate_connections() {
    local db_name="$1"
    echo "🔌 Terminating active connections to database '$db_name'..."
    if psql -U postgres -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$db_name' AND pid <> pg_backend_pid();" > /dev/null 2>&1; then
        echo "✓ Connections terminated successfully."
        sleep 2  # Give a moment for connections to close
        return 0
    else
        echo "✗ Error terminating connections."
        return 1
    fi
}

# Check command line arguments
if [ "$1" == "--help" ] || [ "$1" == "-h" ]; then
    show_help
    exit 0
fi

if [ -z "$1" ] || [ -z "$2" ]; then
    echo "✗ Error: Missing required arguments."
    echo ""
    show_help
    exit 1
fi

DB_NAME="$1"
INPUT_FILE="$2"
RESTORE_FILE=""
TEMP_FILE_CREATED=false

echo "========================================="
echo "POSTGRESQL DATABASE RESTORE"
echo "Database: $DB_NAME"
echo "Source file: $INPUT_FILE"
echo "Date: $(date)"
echo "========================================="
echo ""

# Check if OpenSSL is available for encrypted files
if [[ "$INPUT_FILE" == *.enc ]] && ! command -v openssl &> /dev/null; then
    echo "✗ Error: OpenSSL is required to decrypt encrypted backup files."
    echo "Please install OpenSSL first."
    exit 1
fi

# Prepare the restore file (decrypt/decompress if needed)
echo "🔄 Preparing restore file..."
prepare_restore_file "$INPUT_FILE" RESTORE_FILE

# Mark if we created a temporary file for cleanup
if [[ "$RESTORE_FILE" != "$INPUT_FILE" ]]; then
    TEMP_FILE_CREATED=true
fi

echo ""

# Check if database exists
echo "🔍 Checking if database '$DB_NAME' exists..."
if [ "$( psql -U postgres -XtAc "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'" 2>/dev/null )" = '1' ]; then
    echo "✓ Database '$DB_NAME' exists."
    echo ""
    
    read -p "Create backup before restore? (Y/n): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Nn]$ ]]; then
        if ! backup_existing_database "$DB_NAME"; then
            echo "❌ Backup failed. Aborting restore."
            if [ "$TEMP_FILE_CREATED" = true ]; then
                rm -f "$RESTORE_FILE"
            fi
            exit 1
        fi
        echo ""
    fi
    
    echo "🗑️  Dropping existing database..."
    if dropdb "$DB_NAME" -U postgres 2>/dev/null; then
        echo "✓ Database '$DB_NAME' dropped successfully."
    else
        echo "⚠️  Database drop failed (likely due to active connections)."
        read -p "Terminate active connections and retry? (Y/n): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Nn]$ ]]; then
            if terminate_connections "$DB_NAME"; then
                echo "🗑️  Retrying database drop..."
                if dropdb "$DB_NAME" -U postgres 2>/dev/null; then
                    echo "✓ Database '$DB_NAME' dropped successfully."
                else
                    echo "✗ Error dropping database even after terminating connections."
                    if [ "$TEMP_FILE_CREATED" = true ]; then
                        rm -f "$RESTORE_FILE"
                    fi
                    exit 1
                fi
            else
                echo "✗ Could not terminate connections. Aborting restore."
                if [ "$TEMP_FILE_CREATED" = true ]; then
                    rm -f "$RESTORE_FILE"
                fi
                exit 1
            fi
        else
            echo "❌ Cannot proceed without dropping existing database. Aborting."
            if [ "$TEMP_FILE_CREATED" = true ]; then
                rm -f "$RESTORE_FILE"
            fi
            exit 1
        fi
    fi
else
    echo "ℹ️  Database '$DB_NAME' does not exist. Will create new database."
fi

echo ""

# Create database
echo "🏗️  Creating database '$DB_NAME'..."
if createdb -T template0 "$DB_NAME" -U postgres 2>/dev/null; then
    echo "✓ Database '$DB_NAME' created successfully."
else
    echo "✗ Error creating database."
    if [ "$TEMP_FILE_CREATED" = true ]; then
        rm -f "$RESTORE_FILE"
    fi
    exit 1
fi

echo ""

# Restore database
echo "📥 Restoring database from file..."
echo "   This may take a while for large databases..."
if psql -U postgres -d "$DB_NAME" -f "$RESTORE_FILE" > /dev/null 2>&1; then
    echo "✅ Restore of '$DB_NAME' completed successfully."
    
    # Clean up temporary file if created
    if [ "$TEMP_FILE_CREATED" = true ]; then
        rm -f "$RESTORE_FILE"
        echo "🧹 Temporary files cleaned up."
    fi
    
    echo ""
    echo "========================================="
    echo "✅ RESTORE COMPLETE"
    echo "Database: $DB_NAME"
    echo "Restored from: $INPUT_FILE"
    echo "Completion time: $(date)"
    echo "========================================="
    exit 0
else
    echo "✗ Error restoring database."
    echo "💡 Check that the backup file is compatible with your PostgreSQL version."
    
    # Clean up temporary file if created
    if [ "$TEMP_FILE_CREATED" = true ]; then
        rm -f "$RESTORE_FILE"
    fi
    exit 1
fi