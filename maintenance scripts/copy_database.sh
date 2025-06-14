#!/usr/bin/env bash
#Version 1.03
# For passwordless operation, create ~/.pgpass file with:
# hostname:port:database:username:password
# Example: localhost:5432:*:postgres:your_password
# Then run: chmod 600 ~/.pgpass

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
if [ "$( psql -U postgres -XtAc "SELECT 1 FROM pg_database WHERE datname='$SOURCE_DB'" 2>/dev/null )" != '1' ]
then
    echo "Error: Source database '$SOURCE_DB' does not exist."
    exit 1
fi

# Check if target database already exists
if [ "$( psql -U postgres -XtAc "SELECT 1 FROM pg_database WHERE datname='$TARGET_DB'" 2>/dev/null )" = '1' ]
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
    psql -U postgres -XtAc "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='$TARGET_DB' AND pid <> pg_backend_pid();" 2>/dev/null
    echo "Dropping existing database..."
    dropdb $TARGET_DB -U postgres
fi

# Terminate existing connections to source database
echo "Terminating existing connections to source database..."
psql -U postgres -XtAc "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='$SOURCE_DB' AND pid <> pg_backend_pid();" 2>/dev/null

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