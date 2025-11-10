# Specification: Archive Structure Changes

## Overview

Modify publish_upgrade.php to include all necessary directories and files for complete deployment, including maintenance scripts and fresh install SQL.

## Current Structure

Current upgrade archives only include:
```
archive.zip
└── public_html/
    ├── serve.php
    ├── composer.json
    └── ...
```

Missing critical directories like config/ and maintenance scripts.

## Required New Structure

New archives should include all necessary directories:
```
joinery.tar.gz
├── public_html/
│   ├── serve.php
│   ├── composer.json
│   └── ...
├── config/
│   └── Globalvars_site_default.php
└── maintenance_scripts/
    ├── server_setup.sh
    ├── deploy.sh
    ├── joinery-install.sql.gz
    └── ...

## Changes Required

### 1. publish_upgrade.php

**Modifications needed:**
- Add config/ directory with default configuration template
- Include maintenance scripts from `/home/user1/` in the archive
- Include generated install SQL file from uploads
- Create tar.gz format instead of zip for better compression and consistency

**Files to include from /home/user1 (explicit include list):**
```
maintenance_scripts/
├── server_setup.sh           # REQUIRED - main installation script
├── deploy.sh                 # REQUIRED - deployment/update script
├── joinery-install.sql       # REQUIRED - fresh install database SQL (or .sql.gz)
├── backup_database.sh        # Database backup utility
├── restore_database.sh       # Database restore utility
├── restore_project.sh        # Complete project restore utility
├── copy_database.sh          # Database copy utility
├── new_account.sh            # Create new site/account
├── remove_account.sh         # Remove site/account
├── fix_permissions.sh        # Fix file permissions
├── fix_postgres_auth.sh      # PostgreSQL auth fixes
├── Globalvars_site_default.php # Default config template
└── default_virtualhost.conf  # Apache VirtualHost template
```

**Note:** Only these specific files should be included. No other files from /home/user1 should be added to the archive.

**Script must generate install SQL before creating archive:**
```php
// Generate fresh install SQL file (compressed by default)
$version = $settings->get_setting('database_version') ?: '0.1';
$create_sql_cmd = sprintf(
    'php %s %s',
    escapeshellarg('/var/www/html/SITENAME/public_html/utils/create_install_sql.php'),
    escapeshellarg($version)
);

$output = [];
$exit_code = 0;
exec($create_sql_cmd, $output, $exit_code);

if ($exit_code !== 0) {
    die("ERROR: Failed to generate install SQL file:\n" . implode("\n", $output) . "\n");
}

// The generated file is in uploads with version number
$sql_source = '/var/www/html/SITENAME/uploads/joinery-install-' . $version . '.sql.gz';

if (!file_exists($sql_source)) {
    die("ERROR: Generated SQL file not found at $sql_source\n");
}

echo "Generated install SQL file version $version (compressed)\n";

// Note: The file will be added to archive directly from uploads
// with simplified name during archive creation
```

**Script must check for required files:**
```php
// Check maintenance scripts exist
$required_files = [
    '/home/user1/joinery/joinery/maintenance scripts/server_setup.sh',
    '/home/user1/joinery/joinery/maintenance scripts/deploy.sh'
];

// Also check that we have the generated SQL file
$required_files[] = $sql_source; // The versioned file in uploads

foreach ($required_files as $file) {
    if (!file_exists($file)) {
        die("ERROR: Required file $file not found. Cannot create archive.\n");
    }
}
```

**Implementation approach:**
```php
// When creating archive - no prefix needed

// Add site files
$archive->addFile('public_html/serve.php', $actual_file);
$archive->addFile('config/Globalvars_site_default.php', $actual_file);

// Add maintenance scripts from /home/user1
$maintenance_files = [
    'server_setup.sh',
    'deploy.sh',
    'backup_database.sh',
    'restore_database.sh',
    'restore_project.sh',
    'copy_database.sh',
    'new_account.sh',
    'remove_account.sh',
    'fix_permissions.sh',
    'fix_postgres_auth.sh',
    'Globalvars_site_default.php',
    'default_virtualhost.conf'
];

$maintenance_dir = '/home/user1/joinery/joinery/maintenance scripts/';
foreach ($maintenance_files as $file) {
    if (file_exists($maintenance_dir . $file)) {
        $archive->addFile(
            'maintenance_scripts/' . $file,
            $maintenance_dir . $file
        );
    }
}

// Add the install SQL file from uploads with simplified name
// $sql_source was set earlier when generating the SQL
$archive->addFile(
    'maintenance_scripts/joinery-install.sql.gz',
    $sql_source  // This is the versioned file from uploads
);
```

### 2. upgrade.php

**Modifications needed:**
- Handle the new tar.gz format (currently uses zip)
- Extract all directories properly including maintenance_scripts
- No backward compatibility needed (nobody uses this publicly yet)

**Implementation approach:**
```php
// Use tar command for extraction
$extract_cmd = sprintf(
    'tar -xzf %s -C /var/www/html/SITENAME/',
    escapeshellarg($archive_path)
);
exec($extract_cmd, $output, $exit_code);
```

### 3. server_setup.sh

**Modifications needed:**
- Remove the generic composer.json creation in /home/user1
- Add conditional composer install when project files exist
- Add fresh database installation support using joinery-install-sql.sql

**Remove this section:**
```bash
# Create a composer.json with common dependencies for membership applications
tee composer.json > /dev/null << 'EOF'
{
    "require": {
        "stripe/stripe-php": "^10.0",
        "phpmailer/phpmailer": "^6.0",
        "monolog/monolog": "^3.0",
        "mailchimp/marketing": "^3.0",
        "guzzlehttp/guzzle": "^7.0"
    }
}
EOF
```

**Add this section after Composer installation:**
```bash
# If project files exist (e.g., when extracted before server_setup.sh runs)
# install composer dependencies now. Otherwise they'll be installed after deployment.
if [ -n "$SITENAME" ] && [ -f "/var/www/html/$SITENAME/public_html/composer.json" ]; then
    log "Project files detected - installing composer dependencies"
    cd /var/www/html/$SITENAME/public_html
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader
    log "Composer dependencies installed"
else
    log "No project files yet - composer dependencies will be installed after deployment"
fi
```

### 4. deploy.sh or new_account.sh

**Modifications needed for fresh installations:**
- Detect if this is a fresh install (no existing database)
- Use joinery-install-sql.sql for fresh database setup

**Add database initialization logic:**
```bash
# Check if database exists
DB_EXISTS=$(sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'")

if [ "$DB_EXISTS" != "1" ]; then
    log "Database $DB_NAME does not exist - performing fresh installation"

    # Create the database
    sudo -u postgres createdb -O postgres "$DB_NAME"

    # Check for install SQL file (compressed or uncompressed)
    INSTALL_SQL="/home/user1/joinery/joinery/maintenance scripts/joinery-install.sql.gz"
    if [ ! -f "$INSTALL_SQL" ]; then
        # Try uncompressed
        INSTALL_SQL="/home/user1/joinery/joinery/maintenance scripts/joinery-install.sql"
    fi
    if [ ! -f "$INSTALL_SQL" ]; then
        # Try alternate location (compressed)
        INSTALL_SQL="/var/www/html/$SITENAME/maintenance_scripts/joinery-install.sql.gz"
    fi
    if [ ! -f "$INSTALL_SQL" ]; then
        # Try alternate location (uncompressed)
        INSTALL_SQL="/var/www/html/$SITENAME/maintenance_scripts/joinery-install.sql"
    fi

    if [ -f "$INSTALL_SQL" ]; then
        log "Loading fresh install database schema and seed data..."

        # Check if file is compressed
        if [[ "$INSTALL_SQL" == *.gz ]]; then
            gunzip -c "$INSTALL_SQL" | sudo -u postgres psql -d "$DB_NAME"
        else
            sudo -u postgres psql -d "$DB_NAME" -f "$INSTALL_SQL"
        fi

        if [ $? -eq 0 ]; then
            log "Database initialized successfully"
            log "Default admin credentials: admin@example.com / changeme123"
        else
            log "ERROR: Failed to initialize database"
            exit 1
        fi
    else
        log "ERROR: Install SQL file not found"
        exit 1
    fi
else
    log "Database $DB_NAME exists - running migrations only"
    # Normal upgrade process
fi
```

## Testing

1. Run publish_upgrade.php to create an archive
2. Verify archive structure:
   ```bash
   tar -tzf joinery.tar.gz | head -20
   # Should show top-level directories: public_html/, config/, maintenance_scripts/, etc.

   # Verify install SQL is included
   tar -tzf joinery.tar.gz | grep joinery-install.sql
   # Should show: maintenance_scripts/joinery-install.sql.gz
   ```
3. Test upgrade.php extracts correctly
4. Test deployment works with new archive structure
5. Test fresh installation creates database from joinery-install.sql.gz
6. Verify default admin login works (admin@example.com / changeme123)

## Notes

- This change improves archive organization and deployment flexibility
- Regular upgrade process remains unchanged
- No backward compatibility needed as this is not publicly used yet
- The install SQL file is generated fresh during each publish_upgrade to ensure it's always current

## Complete Installation Flow

1. **Publishing Phase** (on development server):
   - Developer runs `publish_upgrade.php`
   - Script generates fresh `joinery-install-{VERSION}.sql.gz` in `/uploads/`
   - Script pulls SQL from uploads and adds to archive as `joinery-install.sql.gz`
   - Script creates archive with all code + maintenance scripts + install SQL

2. **Deployment Phase** (on target server):
   - Extract archive
   - Run `server_setup.sh` to configure system
   - Run `deploy.sh` or `new_account.sh` which:
     - Detects if database exists
     - If not, creates database and loads `joinery-install.sql.gz`
     - If yes, runs normal migrations
   - System is ready with either fresh install or upgrade

3. **First Login** (for fresh installs):
   - Browse to site URL
   - Login with: admin@example.com / changeme123
   - Change password immediately
   - Configure site settings