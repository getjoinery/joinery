#!/usr/bin/env bash
#Version 3.7 - Config parsing anchored to the assignment statement; a commented-out
#              line or a trailing comment can no longer supply the database name
#Version 3.6 - The all-databases sweep skips each site's configured dbname_test,
#              matched exactly (a *_test glob would drop real test SITES)
#Version 3.5 - Help reads its version from the header rather than restating it; the
#              restated copy had drifted to 3.0 while the file carried 3.4
#Version 3.4 - --key-file names the key to use; envelope runs mint one per backup
#Version 3.3 - Stale-staging sweep at startup; jy_backup_ temp prefix
#Version 3.2 - Encryption key passed via fd (never in argv/ps); pipefail on the encrypted pipeline
#Version 3.1 - Full-timestamp filenames; plaintext path writes .sql.gz

# This script's own version, read from the newest #Version header above rather
# than restated in the help text, where a second copy stops being updated.
BACKUP_SCRIPT_VERSION="$(sed -n 's/^#Version \([0-9][0-9.]*\).*/\1/p' "${BASH_SOURCE[0]}" | head -1)"
[ -n "$BACKUP_SCRIPT_VERSION" ] || BACKUP_SCRIPT_VERSION="unknown"

# Global flags
ENCRYPT_BACKUPS=true
NON_INTERACTIVE=false
ENCRYPTION_KEY=""
KEY_FILE=""

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

# Full timestamp so same-day backups never overwrite each other (locally or in
# the bucket); matches backup_project.sh granularity.
now=$(date +"%Y%m%d_%H%M%S")

# Function to backup a single database
backup_database() {
    local db_name="$1"
    local backup_file

    if [ "$ENCRYPT_BACKUPS" = true ]; then
        backup_file="${db_name}-${now}.sql.gz.enc"
        echo "📦 Backing up database (encrypted): $db_name"
        echo ""

        # Create compressed + encrypted backup using temporary file. The
        # jy_backup_ prefix makes a stranded dump identifiable; a hard kill
        # (SIGKILL) runs no cleanup, so every run sweeps its own stale
        # leftovers instead.
        find /tmp -maxdepth 1 -name 'jy_backup_*' -user "$(id -un)" -mmin +1440 -delete 2>/dev/null
        local temp_file=$(mktemp --suffix=.sql /tmp/jy_backup_XXXXXXXX)

        if pg_dump -U postgres "$db_name" > "$temp_file"; then
            echo "✓ Database dump completed"

            local encrypt_result=1
            if [ -n "$ENCRYPTION_KEY" ]; then
                # Non-interactive: key crosses on fd 3, never argv (visible in ps
                # for the whole encrypt on shared boxes). pipefail in a subshell
                # so a gzip read failure can't yield a valid-looking .enc of a
                # truncated stream reported as success.
                if ( set -o pipefail; gzip -9 < "$temp_file" | openssl enc -aes-256-cbc -salt -pbkdf2 -pass fd:3 -out "$backup_file" 2>/dev/null ) 3< <(printf '%s\n' "$ENCRYPTION_KEY"); then
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
        # Plaintext still means compressed (.sql.gz) so it matches every backup
        # glob the dashboard uses to find and upload the newest archive.
        backup_file="${db_name}-${now}.sql.gz"
        echo "📦 Backing up database (plaintext, compressed): $db_name"
        echo "⚠️  WARNING: Creating unencrypted backup file!"
        echo ""

        # Create compressed plaintext backup. pipefail in a subshell so a pg_dump
        # failure isn't masked by gzip succeeding on empty input.
        if ( set -o pipefail; pg_dump -U postgres "$db_name" | gzip -9 > "$backup_file" ); then
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

# Databases that are some site's configured test copy.
#
# A test copy holds no content of its own — it is rebuilt from live on demand —
# so backing one up ships a second encrypted copy of the site's data to the
# bucket for nothing.
#
# The match must be EXACT, read from each site's own config. A `*_test` glob
# would look equivalent and is a data-loss trap: install.sh's create_test_site()
# provisions a whole separate SITE named "${main_site}_test", with its own
# database of that name and its own real content. Dropping that from backups
# silently would be far worse than the waste being avoided here.
#
# Exact matching is still not enough on its own, because the two names can
# COLLIDE: a site whose dbname_test is "foo_test" sitting beside a real site
# whose dbname is "foo_test" describes one database that is a throwaway copy to
# one config and a live database to the other. Live always wins — any name that
# is some site's dbname is never treated as a test copy, whatever another
# config calls it. Backing up a copy needlessly costs disk; skipping a live
# database costs the site.
test_copy_databases() {
    local cfg name live_names
    live_names=""
    for cfg in /var/www/html/*/config/Globalvars_site.php; do
        [ -f "$cfg" ] || continue
        name=$(sed -n "s/^[[:space:]]*\$this->settings\['dbname'\][[:space:]]*=[[:space:]]*'\([^']*\)'.*/\1/p" "$cfg" | head -1)
        [ -n "$name" ] && live_names="${live_names}${name}"$'\n'
    done

    for cfg in /var/www/html/*/config/Globalvars_site.php; do
        [ -f "$cfg" ] || continue
        name=$(sed -n "s/^[[:space:]]*\$this->settings\['dbname_test'\][[:space:]]*=[[:space:]]*'\([^']*\)'.*/\1/p" "$cfg" | head -1)
        [ -z "$name" ] && continue
        echo "$live_names" | grep -qxF "$name" && continue
        echo "$name"
    done
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

    # Drop any database that is a site's configured test copy. Skips are named
    # in the output — a database missing from a backup summary with no
    # explanation is how a real backup gap survives unnoticed.
    local test_copies keep skipped db_candidate
    test_copies=$(test_copy_databases)
    keep=""
    skipped=""
    for db_candidate in $databases; do
        if [ -n "$test_copies" ] && echo "$test_copies" | grep -qxF "$db_candidate"; then
            skipped="${skipped}${db_candidate}"$'\n'
        else
            keep="${keep}${db_candidate}"$'\n'
        fi
    done
    databases=$(echo "$keep" | sed '/^$/d')

    if [ -n "$skipped" ]; then
        echo "↷ Skipping test copies (rebuilt from live on demand, nothing of their own):"
        echo "$skipped" | sed '/^$/d' | sed 's/^/  - /'
        echo ""
    fi

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
        echo "File extension: .sql.gz"
        echo "⚠️  Remember: These files contain unencrypted database data!"
    fi
    echo "========================================="
    
    if [ $error_count -gt 0 ]; then
        exit 1
    fi
}

# Function to get encryption key for non-interactive mode
get_encryption_key() {
    # Priority 0: an explicitly named key file. This is how envelope backups
    # run: the caller mints a key for this backup alone, points here at it, and
    # shreds it afterwards, so no key on this machine outlives the run.
    if [ -n "$KEY_FILE" ]; then
        if [ ! -f "$KEY_FILE" ]; then
            echo "✗ Error: --key-file '$KEY_FILE' does not exist"
            return 1
        fi
        ENCRYPTION_KEY=$(head -1 "$KEY_FILE" | tr -d '\n\r')
        if [ -z "$ENCRYPTION_KEY" ]; then
            echo "✗ Error: --key-file '$KEY_FILE' is empty"
            return 1
        fi
        echo "✓ Using encryption key from $KEY_FILE"
        return 0
    fi

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
    echo "PostgreSQL Database Backup Script v${BACKUP_SCRIPT_VERSION}"
    echo ""
    echo "Usage:"
    echo "  $0                             # Backup all databases (encrypted, interactive)"
    echo "  $0 [database_name]             # Backup specific database (encrypted, interactive)"
    echo "  $0 --non-interactive [db_name] # Backup with key from env/file (for automation)"
    echo "  $0 --plaintext [db_name]       # Backup specific database (unencrypted)"
    echo ""
    echo "Options:"
    echo "  --non-interactive, -n     Use encryption key from env var or file (no prompts)"
    echo "  --key-file PATH           Read the encryption key from PATH"
    echo "  --plaintext, -p           Create unencrypted backups"
    echo "  --help, -h                Show this help message"
    echo ""
    echo "Non-Interactive Mode:"
    echo "  Encryption key sources (in order of precedence):"
    echo "  1. --key-file PATH"
    echo "  2. \$BACKUP_ENCRYPTION_KEY environment variable"
    echo "  3. ~/.joinery_backup_key file (must have 600 permissions)"
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
        --key-file)
            KEY_FILE="$2"
            shift 2
            ;;
        --key-file=*)
            KEY_FILE="${1#*=}"
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