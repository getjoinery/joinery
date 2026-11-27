#!/usr/bin/env bash
#Version 1.05 - root signs in as postgres by peer (_pg_admin.sh, specs/postgres_superuser_peer_auth.md); the .pgpass,
#              db_admin.php and config-password lookups and the prompt go
#Version 1.04 - The postgres password is read from the root-only config/db_admin.php before a config still on
#              postgres (mail_access_hardening WP4); the copy keeps the source's owner (createdb -T) until
#              site_db_role.sh TARGET hands it to the target site's role, which build_dev_from_source.sh runs
#Version 1.03

# Root signs in to PostgreSQL as postgres by peer, or on a machine not switched
# over yet with the password it had (_pg_admin.sh). Run as root.
# shellcheck source=../install_tools/_pg_admin.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/../install_tools/_pg_admin.sh"
if ! pg_admin_auth; then
    echo "Cannot sign in to PostgreSQL as postgres. Run this as root (it signs in by peer)."
    exit 1
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