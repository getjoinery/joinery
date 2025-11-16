#!/usr/bin/env bash
#Version 1.03
# For passwordless operation, create ~/.pgpass file with:
# hostname:port:database:username:password
# Example: localhost:5432:*:postgres:your_password
# Then run: chmod 600 ~/.pgpass

# Authentication order: 1) .pgpass, 2) config file, 3) interactive prompt

# Check for .pgpass file first
if [[ -f ~/.pgpass ]]; then
    echo "Found .pgpass file - using passwordless authentication."
elif [[ -n "$PGPASSWORD" ]]; then
    echo "Found PGPASSWORD environment variable - using passwordless authentication."
else
    # Try to find and load password from config file
    CONFIG_FILE=""
    CONFIG_PASSWORD=""
    
    # Strategy 1: Try database names with _test suffix removed
    for DB_NAME in "$SOURCE_DB" "$TARGET_DB"; do
        # Remove _test suffix if present
        SITENAME="${DB_NAME%_test}"
        if [[ -f "/var/www/html/${SITENAME}/config/Globalvars_site.php" ]]; then
            CONFIG_FILE="/var/www/html/${SITENAME}/config/Globalvars_site.php"
            break
        fi
    done
    
    # Strategy 2: Try original database names as-is
    if [[ -z "$CONFIG_FILE" ]]; then
        for DB_NAME in "$SOURCE_DB" "$TARGET_DB"; do
            if [[ -f "/var/www/html/${DB_NAME}/config/Globalvars_site.php" ]]; then
                CONFIG_FILE="/var/www/html/${DB_NAME}/config/Globalvars_site.php"
                break
            fi
        done
    fi
    
    # Strategy 3: Look for any config file in /var/www/html/*/config/
    if [[ -z "$CONFIG_FILE" ]]; then
        CONFIG_FILE=$(find /var/www/html/*/config/Globalvars_site.php 2>/dev/null | head -1)
    fi
    
    if [[ -n "$CONFIG_FILE" ]] && [[ -f "$CONFIG_FILE" ]]; then
        echo "Found config file: $CONFIG_FILE"
        # Extract password from config file
        CONFIG_PASSWORD=$(grep "dbpassword.*=" "$CONFIG_FILE" | head -1 | sed "s/.*'\(.*\)'.*/\1/")
        if [[ -n "$CONFIG_PASSWORD" ]]; then
            export PGPASSWORD="$CONFIG_PASSWORD"
            echo "Using database password from config file."
        fi
    fi
    
    # If still no password, fall back to interactive prompt
    if [[ -z "$PGPASSWORD" ]]; then
        echo "No .pgpass file found, no config file found, and PGPASSWORD not set."
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

if [ "$1" == "" ]
then
echo "Usage: ./copy_database.sh SOURCE_DB_NAME TARGET_DB_NAME"
exit 1
fi

if [ "$2" == "" ]
then
echo "Usage: ./copy_database.sh SOURCE_DB_NAME TARGET_DB_NAME"
exit 1
fi

SOURCE_DB=$1
TARGET_DB=$2

# Check if source database exists
echo "Checking if source database '$SOURCE_DB' exists..."
if [ "$( psql -U postgres -XtAc "SELECT 1 FROM pg_database WHERE datname='$SOURCE_DB'" )" != '1' ]
then
    echo "Error: Source database '$SOURCE_DB' does not exist."
    exit 1
fi

# Check if target database already exists
if [ "$( psql -U postgres -XtAc "SELECT 1 FROM pg_database WHERE datname='$TARGET_DB'" )" = '1' ]
then
    echo "Warning: Target database '$TARGET_DB' already exists."
    read -p "Do you want to drop it and continue? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]
    then
        echo "Operation cancelled."
        exit 1
    fi
    echo "Terminating connections to target database..."
    psql -U postgres -XtAc "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='$TARGET_DB' AND pid <> pg_backend_pid();"
    echo "Dropping existing database..."
    dropdb $TARGET_DB -U postgres
fi

# Terminate existing connections to source database
echo "Terminating existing connections to source database..."
psql -U postgres -XtAc "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='$SOURCE_DB' AND pid <> pg_backend_pid();"

# Create the new database as a copy of the source
echo "Copying database (this may take a moment)..."
createdb -U postgres -T $SOURCE_DB $TARGET_DB

if [ $? -eq 0 ]; then
    echo "Database '$SOURCE_DB' successfully copied to '$TARGET_DB'."
else
    echo "Error: Failed to copy database."
    exit 1
fi

exit 0