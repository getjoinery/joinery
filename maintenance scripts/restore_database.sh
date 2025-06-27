#!/usr/bin/env bash
#Version 2.00 - Added encryption support and improved functionality

# Function to show help
show_help() {
    echo "PostgreSQL Database Restore Script v2.00"
    echo ""
    echo "Usage:"
    echo "  $0 DB_NAME FILE_TO_RESTORE"
    echo ""
    echo "Supported file formats:"
    echo "  • .sql                 - Plain SQL dump"
    echo "  • .sql.gz.enc          - Encrypted compressed dump (from backup script v2.00)"
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
    echo "  • Safe database recreation"
}

# Function to decrypt and decompress file if needed
prepare_restore_file() {
    local input_file="$1"
    local output_var="$2"
    
    if [[ ! -f "$input_file" ]]; then
        echo "Error: File '$input_file' does not exist."
        exit 1
    fi
    
    # Determine file type and prepare accordingly
    if [[ "$input_file" == *.sql.gz.enc ]]; then
        echo "Detected encrypted compressed file."
        echo "You will be prompted for the decryption password."
        
        local temp_file=$(mktemp --suffix=.sql)
        if openssl enc -aes-256-cbc -d -pbkdf2 -in "$input_file" | gunzip > "$temp_file"; then
            echo "✓ File decrypted and decompressed successfully."
            eval "$output_var='$temp_file'"
            return 0
        else
            rm -f "$temp_file"
            echo "✗ Error decrypting file. Please check your password."
            exit 1
        fi
        
    elif [[ "$input_file" == *.sql.gz ]]; then
        echo "Detected compressed file."
        
        local temp_file=$(mktemp --suffix=.sql)
        if gunzip < "$input_file" > "$temp_file"; then
            echo "✓ File decompressed successfully."
            eval "$output_var='$temp_file'"
            return 0
        else
            rm -f "$temp_file"
            echo "✗ Error decompressing file."
            exit 1
        fi
        
    elif [[ "$input_file" == *.sql ]]; then
        echo "Detected plain SQL file."
        eval "$output_var='$input_file'"
        return 0
        
    else
        echo "Warning: Unknown file format. Treating as plain SQL file."
        eval "$output_var='$input_file'"
        return 0
    fi
}

# Function to create backup of existing database
backup_existing_database() {
    local db_name="$1"
    local now=$(date +"%m_%d_%Y_%H%M%S")
    local backup_file="${db_name}-${now}-auto.sql.gz.enc"
    
    echo "Creating backup of existing database..."
    echo "You will be prompted for PostgreSQL password, then encryption password."
    
    local temp_file=$(mktemp)
    if pg_dump -U postgres -W "$db_name" > "$temp_file"; then
        if gzip -9 < "$temp_file" | openssl enc -aes-256-cbc -salt -pbkdf2 -out "$backup_file"; then
            rm -f "$temp_file"
            chmod 600 "$backup_file"
            echo "✓ Backup of existing '$db_name' complete: $backup_file"
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

# Check command line arguments
if [ "$1" == "--help" ] || [ "$1" == "-h" ]; then
    show_help
    exit 0
fi

if [ -z "$1" ] || [ -z "$2" ]; then
    echo "Error: Missing required arguments."
    echo ""
    show_help
    exit 1
fi

DB_NAME="$1"
INPUT_FILE="$2"
RESTORE_FILE=""

echo "========================================="
echo "POSTGRESQL DATABASE RESTORE"
echo "Database: $DB_NAME"
echo "Source file: $INPUT_FILE"
echo "Date: $(date)"
echo "========================================="

# Check if OpenSSL is available for encrypted files
if [[ "$INPUT_FILE" == *.enc ]] && ! command -v openssl &> /dev/null; then
    echo "Error: OpenSSL is required to decrypt encrypted backup files."
    echo "Please install OpenSSL first."
    exit 1
fi

# Prepare the restore file (decrypt/decompress if needed)
echo "Preparing restore file..."
prepare_restore_file "$INPUT_FILE" RESTORE_FILE

# Check if database exists
echo "Checking if database '$DB_NAME' exists..."
if [ "$( psql -U postgres -XtAc "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'" 2>/dev/null )" = '1' ]; then
    echo "Database '$DB_NAME' exists."
    
    read -p "Create backup before restore? (Y/n): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Nn]$ ]]; then
        if ! backup_existing_database "$DB_NAME"; then
            echo "Backup failed. Aborting restore."
            # Clean up temporary file if created
            if [[ "$RESTORE_FILE" != "$INPUT_FILE" ]]; then
                rm -f "$RESTORE_FILE"
            fi
            exit 1
        fi
    fi
    
    echo "Dropping existing database..."
    echo "You will be prompted for PostgreSQL password."
    if dropdb "$DB_NAME" -U postgres; then
        echo "✓ Database '$DB_NAME' dropped successfully."
    else
        echo "✗ Error dropping database."
        # Clean up temporary file if created
        if [[ "$RESTORE_FILE" != "$INPUT_FILE" ]]; then
            rm -f "$RESTORE_FILE"
        fi
        exit 1
    fi
else
    echo "Database '$DB_NAME' does not exist. Will create new database."
fi

# Create database
echo "Creating database '$DB_NAME'..."
echo "You will be prompted for PostgreSQL password."
if createdb -T template0 "$DB_NAME" -U postgres; then
    echo "✓ Database '$DB_NAME' created successfully."
else
    echo "✗ Error creating database."
    # Clean up temporary file if created
    if [[ "$RESTORE_FILE" != "$INPUT_FILE" ]]; then
        rm -f "$RESTORE_FILE"
    fi
    exit 1
fi

# Restore database
echo "Restoring database from file..."
echo "You will be prompted for PostgreSQL password."
if psql -U postgres -W -d "$DB_NAME" -f "$RESTORE_FILE" > /dev/null; then
    echo "✓ Restore of '$DB_NAME' completed successfully."
    
    # Clean up temporary file if created
    if [[ "$RESTORE_FILE" != "$INPUT_FILE" ]]; then
        rm -f "$RESTORE_FILE"
        echo "✓ Temporary files cleaned up."
    fi
    
    echo "========================================="
    echo "RESTORE COMPLETE"
    echo "Database: $DB_NAME"
    echo "Restored from: $INPUT_FILE"
    echo "========================================="
    exit 0
else
    echo "✗ Error restoring database."
    
    # Clean up temporary file if created
    if [[ "$RESTORE_FILE" != "$INPUT_FILE" ]]; then
        rm -f "$RESTORE_FILE"
    fi
    exit 1
fi