#!/usr/bin/env bash
#Version 1.02

# Check for .pgpass file or PGPASSWORD environment variable
if [[ ! -f ~/.pgpass ]] && [[ -z "$PGPASSWORD" ]]; then
    echo "No .pgpass file found and PGPASSWORD not set."
    echo "You will be prompted for the postgres password multiple times."
    echo ""
    echo "To avoid this in the future, create a .pgpass file:"
    echo "  echo 'localhost:5432:*:postgres:YOUR_PASSWORD' > ~/.pgpass"
    echo "  chmod 600 ~/.pgpass"
    echo ""
    read -p "Press Enter to continue with password prompts..."
    echo ""
elif [[ -f ~/.pgpass ]]; then
    echo "Found .pgpass file - using passwordless authentication."
elif [[ -n "$PGPASSWORD" ]]; then
    echo "Found PGPASSWORD environment variable - using passwordless authentication."
fi

now=$(date +"%m_%d_%Y")

# Function to backup a single database
backup_database() {
    local db_name="$1"
    local backup_file="${db_name}-${now}.sql"
    
    echo "Backing up database: $db_name"
    pg_dump -U postgres -W "$db_name" > "$backup_file"
    
    if [ $? -eq 0 ]; then
        echo "✓ Backup of '$db_name' complete: $backup_file"
    else
        echo "✗ Error backing up '$db_name'"
        return 1
    fi
}

# Function to backup all databases
backup_all_databases() {
    echo "========================================="
    echo "BACKING UP ALL DATABASES"
    echo "Date: $(date)"
    echo "========================================="
    
    # Get list of all databases (excluding system databases)
    echo "Getting list of databases..."
    databases=$(psql -U postgres -t -c "SELECT datname FROM pg_database WHERE datistemplate = false AND datname NOT IN ('postgres', 'template0', 'template1');" 2>/dev/null)
    
    if [ $? -ne 0 ]; then
        echo "Error: Could not connect to PostgreSQL or retrieve database list."
        echo "Please check your PostgreSQL connection and credentials."
        exit 1
    fi
    
    # Remove leading/trailing whitespace and convert to array
    databases=$(echo "$databases" | tr -d ' ')
    
    if [ -z "$databases" ]; then
        echo "No user databases found to backup."
        exit 0
    fi
    
    echo "Found databases to backup:"
    echo "$databases" | sed 's/^/  - /'
    echo ""
    
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
    echo "Successful backups: $backup_count"
    echo "Failed backups: $error_count"
    echo "Backup files created with date: $now"
    echo "========================================="
    
    if [ $error_count -gt 0 ]; then
        exit 1
    fi
}

# Main script logic
if [ "$1" == "" ]; then
    # No arguments - backup all databases
    backup_all_databases
else
    # Single database backup (original functionality)
    if [ "$1" == "--help" ] || [ "$1" == "-h" ]; then
        echo "Usage:"
        echo "  $0                    # Backup all databases"
        echo "  $0 [database_name]    # Backup specific database"
        echo ""
        echo "Examples:"
        echo "  $0                    # Backup all user databases"
        echo "  $0 myapp              # Backup only 'myapp' database"
        echo ""
        echo "Note: System databases (postgres, template0, template1) are excluded from 'all' backup"
        exit 0
    fi
    
    echo "Backing up single database: $1"
    backup_database "$1"
    
    if [ $? -eq 0 ]; then
        echo "Backup complete."
        exit 0
    else
        echo "Backup failed."
        exit 1
    fi
fi