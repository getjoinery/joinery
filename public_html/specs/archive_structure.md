# Specification: Archive Structure Changes

## Overview

Modify publish_upgrade.php and upgrade.php to support a new archive structure with an additional directory level for Docker deployment compatibility.

## Current Structure

Current upgrade archives have this structure:
```
archive.zip
├── public_html/
│   ├── serve.php
│   ├── composer.json
│   └── ...
├── config/
│   └── Globalvars_site.php
├── uploads/
├── logs/
└── ...
```

## Required New Structure

New Docker-compatible archives need this structure:
```
joinery-docker.tar.gz
├── joinery/
│   ├── public_html/
│   │   ├── serve.php
│   │   ├── composer.json
│   │   └── ...
│   ├── config/
│   │   └── Globalvars_site.php
│   ├── uploads/
│   ├── logs/
│   ├── maintenance_scripts/
│   │   ├── server_setup.sh
│   │   ├── deploy.sh
│   │   └── ...
│   └── ...
```

## Changes Required

### 1. publish_upgrade.php

**Modifications needed:**
- Add `joinery/` as the top-level directory in the archive
- Include maintenance scripts from `/home/user1/` in the archive
- Create tar.gz format instead of zip for Docker deployment

**Files to include from /home/user1 (explicit include list):**
```
maintenance_scripts/
├── server_setup.sh           # REQUIRED - main installation script
├── deploy.sh                 # REQUIRED - deployment/update script
├── backup_database.sh        # Database backup utility
├── restore_database.sh       # Database restore utility
├── copy_database.sh          # Database copy utility
├── new_account.sh            # Create new site/account
├── remove_account.sh         # Remove site/account
├── fix_permissions_staging.sh # Fix file permissions
├── fix_postgres_auth.sh      # PostgreSQL auth fixes
├── Globalvars_site_default.php # Default config template
└── default_virtualhost.conf  # Apache VirtualHost template
```

**Note:** Only these specific files should be included. No other files from /home/user1 should be added to the archive.

**Script must check for required files:**
```php
$required_files = [
    '/home/user1/server_setup.sh',
    '/home/user1/deploy.sh'
];

foreach ($required_files as $file) {
    if (!file_exists($file)) {
        die("ERROR: Required file $file not found. Cannot create Docker archive.\n");
    }
}
```

**Implementation approach:**
```php
// When creating Docker archive
$archive_root = 'joinery/';

// Add site files with new prefix
$archive->addFile($archive_root . 'public_html/serve.php', $actual_file);
$archive->addFile($archive_root . 'config/Globalvars_site.php', $actual_file);

// Add maintenance scripts
$maintenance_files = [
    'server_setup.sh',
    'deploy.sh',
    'backup_database.sh',
    // ... etc
];

foreach ($maintenance_files as $file) {
    if (file_exists('/home/user1/' . $file)) {
        $archive->addFile(
            $archive_root . 'maintenance_scripts/' . $file,
            '/home/user1/' . $file
        );
    }
}
```

### 2. upgrade.php

**Modifications needed:**
- Expect the new structure with `joinery/` prefix
- Strip the `joinery/` prefix when extracting
- No backward compatibility needed (nobody uses this publicly yet)

**Implementation approach:**
```php
// When extracting
if (substr($file_path, 0, 8) === 'joinery/') {
    // Strip the prefix
    $file_path = substr($file_path, 8);
}
// Continue with normal extraction to /var/www/html/SITENAME/
```

### 3. server_setup.sh

**Modifications needed:**
- Remove the generic composer.json creation in /home/user1
- Add conditional composer install for Docker environments

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
# Only in Docker: project files exist before server_setup.sh runs
# In normal setup: files don't exist yet (deploy.sh gets them later)
if [ -n "$SITENAME" ] && [ -f "/var/www/html/$SITENAME/public_html/composer.json" ]; then
    log "Project files detected - installing composer dependencies"
    cd /var/www/html/$SITENAME/public_html
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader
    log "Composer dependencies installed"
else
    log "No project files yet - composer dependencies will be installed after deployment"
fi
```

## Testing

1. Run publish_upgrade.php to create a Docker archive
2. Verify archive structure:
   ```bash
   tar -tzf joinery-docker.tar.gz | head -20
   # Should show joinery/ prefix on all paths
   ```
3. Test upgrade.php extracts correctly (strips prefix)
4. Test Docker deployment works with new archive structure

## Notes

- This change is specifically for Docker deployment support
- Regular upgrade process remains unchanged
- No backward compatibility needed as this is not publicly used yet