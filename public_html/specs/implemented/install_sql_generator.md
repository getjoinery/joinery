# Specification: Install SQL Generation Script

## Overview

Create a PHP script that generates a fresh install SQL file (`joinery-install-sql.sql`) on demand. This script will be called during the publish_upgrade process to ensure the install SQL is always up-to-date with the current database schema and essential seed data.

**Key Principle:** A fresh install should contain ONLY the minimum data needed to:
- Log in (one default admin user)
- Navigate the system (menu structures)
- Have functioning core features (settings, email templates, reference data)
- NO sample data, logs, or transaction history that would need to be deleted

**Total tables with data:** 7 essential tables + 1 default admin user (not 68 tables with sample data)

## Current State

- Existing install SQL file: `/home/user1/joinery/joinery/maintenance_scripts/joinery-install-sql.sql`
- File is outdated (6.3MB, last updated Aug 25 13:16)
- Used by `new_account.sh` for fresh installations
- Contains full schema + seed data for ~68 tables

## Requirements

### Script Location and Name

- **Path:** `/var/www/html/SITENAME/public_html/utils/create_install_sql.php`
- **Callable from:** Command line (`php utils/create_install_sql.php`) or via publish_upgrade
- **Output file location:** `/var/www/html/SITENAME/uploads/`
- **Output file naming:**
  - With compression (default): `joinery-install-VERSION.sql.gz`
  - Without compression: `joinery-install-VERSION.sql`
- **Examples:**
  - `/var/www/html/joinerytest/uploads/joinery-install-0.67.sql.gz` (default)
  - `/var/www/html/joinerytest/uploads/joinery-install-0.67.sql` (with --no-compress)

### Database Connection

The script runs in the web context and should:
1. Load PathHelper (which loads Globalvars and DbConnector automatically)
2. Load the `$settings` singleton to get database credentials from `stg_settings`
3. Use DbConnector to access the database

**Setup:**
```php
// At top of script
require_once(__DIR__ . '/../includes/PathHelper.php');
$settings = Globalvars::get_instance();
$dbconnector = DbConnector::get_instance();
```

**Command-line invocation:**
```bash
# Default usage (creates compressed .sql.gz file)
cd /var/www/html/joinerytest/public_html
php utils/create_install_sql.php 0.67

# Without compression (creates plain .sql file for testing)
php utils/create_install_sql.php 0.67 --no-compress

# OR from parent directory
php public_html/utils/create_install_sql.php 0.67
php public_html/utils/create_install_sql.php 0.67 --no-compress
```

**Parameters:**
- First positional argument: VERSION (e.g., "0.67", "0.68") - required
- `--no-compress` (optional) - Skip gzip compression, output plain SQL file for testing

### Schema Export

Use `pg_dump` to export the full database schema (structure only):

```bash
pg_dump -U postgres \
    --schema-only \
    --no-owner \
    --no-privileges \
    --no-tablespaces \
    --no-security-labels \
    --no-comments \
    -d database_name \
    > /tmp/schema.sql
```

**Schema export must include:**
- All table CREATE statements
- All sequence definitions
- All primary key constraints
- All unique constraints
- All foreign key constraints
- All indexes
- Column comments (for enum-like fields)

**Schema export must exclude:**
- Ownership (OWNER TO postgres)
- Privileges and grants
- Tablespace assignments
- Security labels

### Data Export - Essential Tables

The script must export ONLY the minimal data required for a fresh installation to function. A new user installing the system needs:
1. To be able to log in (one default admin user)
2. To navigate the system (menu structures)
3. To have functioning features (settings, email templates, reference data)
4. NO sample data, logs, or transaction history

#### Required Tables (System Cannot Function Without These)

These tables MUST be included with their data:

1. **stg_settings** - System configuration settings
   - Contains: Database version, site settings, email config, payment settings
   - Why needed: Core configuration - nothing works without this

2. **amu_admin_menus** - Admin interface menu structure
   - Contains: Menu items and hierarchy for admin panel
   - Why needed: Admins cannot navigate without menu structure

3. **cco_country_codes** - ISO country codes reference data
   - Contains: Standard country codes and names
   - Why needed: Required for address/phone number dropdowns

4. **zone** - IANA timezone names
   - Contains: Standard timezone definitions
   - Why needed: Required for timezone selection dropdowns

5. **ety_event_types** - Event type definitions
   - Contains: Basic event types (e.g., "Conference", "Meeting", "Webinar")
   - Why needed: Event system won't function without type definitions

6. **emt_email_templates** - System email templates
   - Contains: Templates for password resets, welcome emails, notifications
   - Why needed: Email functionality won't work without templates

7. **pmu_public_menus** - Public website menu structure
   - Contains: Navigation structure for public-facing site
   - Why needed: Site has no navigation without this

#### Special Handling - Default Admin User

The **usr_users** table needs special handling:
- Current install SQL has 4,294 sample users - DO NOT INCLUDE THESE
- Instead, generate ONE default admin user programmatically
- See "Default Admin User" section for details

#### Optional Tables (Include Only If System Requires)

Determine at implementation time if these are truly required:

- **grp_groups** - Include ONLY if membership groups are required for basic functionality
- **pag_pages** - Include ONLY if there are required system pages (e.g., Terms of Service)
- **pac_page_contents** - Include ONLY if pag_pages is included
- **mlt_mailing_lists** - Include ONLY if default mailing lists are required
- **ctt_contact_types** - Include ONLY if contact types are required for basic functionality

#### Excluded Tables (Sample Data, Logs, Transactional)

ALL other tables should be EXCLUDED, including but not limited to:
- User data: phn_phone_numbers, usa_users_addrs, act_activation_codes
- Products/Commerce: pro_products, prv_product_versions, ord_orders, odi_order_items
- Events: evt_events, evs_event_sessions, evr_event_registrants
- Content: pst_posts, cmt_comments, fil_files, vid_videos
- Email/Marketing: eml_emails, mlr_mailing_list_registrants, equ_queued_emails
- Logs/Analytics: log_logins, cls_cart_logs, err_general_errors, vse_visitor_events
- All other transactional or user-generated data

### Data Export Process

For each INCLUDE table:

```bash
pg_dump -U postgres \
    --data-only \
    --no-owner \
    --no-privileges \
    --column-inserts \
    --table=stg_settings \
    -d database_name
```

**Alternative approach (using COPY format like original):**
```bash
pg_dump -U postgres \
    --data-only \
    --no-owner \
    --no-privileges \
    --table=stg_settings \
    -d database_name
```

The COPY format is preferred as it's more compact and matches the existing install SQL format.

### Hardcoded Table Configuration

The script should have a hardcoded array of tables to export data from:

```php
// REQUIRED tables - system cannot function without these
$essential_data_tables = [
    'stg_settings',       // System configuration
    'amu_admin_menus',    // Admin menu structure
    'cco_country_codes',  // Country reference data
    'zone',               // Timezone names (IANA)
    'ety_event_types',    // Event type definitions
    'emt_email_templates', // Email templates
    'pmu_public_menus',   // Public menu structure
];

// OPTIONAL tables - include these if your installation needs them
// Uncomment as needed:
// $optional_tables = [
//     'grp_groups',         // If membership groups are required
//     'pag_pages',          // If system pages are required
//     'pac_page_contents',  // If pag_pages is included
//     'mlt_mailing_lists',  // If default mailing lists are required
//     'ctt_contact_types',  // If contact types are required
// ];

// Note: usr_users is handled separately - only the default admin user is inserted
```

**CRITICAL - Default Admin User:**

The install SQL MUST include a default admin user with the following:
- **Name:** Admin
- **Email:** admin@example.com
- **Password:** admin (hashed with bcrypt)
- **Permission Level:** 10 (superadmin)
- **Status:** Activated

This user serves as the bootstrap admin for initial setup. The password should be hashed using PHP's `password_hash($password, PASSWORD_BCRYPT)`. The script should generate this hash at runtime using PHP, not hardcode it. Example:

```php
// Generate default admin user password hash
$admin_password = 'admin';
$admin_password_hash = password_hash($admin_password, PASSWORD_BCRYPT);

// This hash will be different each time due to bcrypt salt, so insert it directly:
// INSERT INTO usr_users (usr_first_name, usr_last_name, usr_email, usr_permission, usr_is_activated, usr_password, usr_signup_date)
// VALUES ('Admin', '', 'admin@example.com', 10, true, '$2y$10$...', CURRENT_DATE);
```

Since bcrypt generates different hashes each time, the script must:
1. Generate a fresh bcrypt hash of 'admin' for each install
2. Insert the default admin user record into usr_users directly via INSERT (not via pg_dump)
3. Do this after the schema is loaded but before the general usr_users data is imported

### SQL File Assembly

The final SQL file should be assembled in this order:

1. PostgreSQL header (SET statements for encoding, timeouts, etc.)
2. Full schema export (tables, sequences, constraints, indexes)
3. **Default admin user INSERT** (must be inserted before general usr_users data)
4. Data exports for essential tables (in dependency order if possible)
5. Sequence value resets (to match imported data)
6. Final constraint additions

**Example structure:**
```sql
--
-- PostgreSQL database dump
--

SET statement_timeout = 0;
SET lock_timeout = 0;
SET client_encoding = 'UTF8';
-- ... other SET statements

-- Table definitions
CREATE TABLE public.stg_settings ( ... );
CREATE TABLE public.amu_admin_menus ( ... );
-- ... all tables

-- Sequences
CREATE SEQUENCE public.stg_settings_stg_setting_id_seq ...;
-- ... all sequences

--
-- DEFAULT ADMIN USER
-- Email: admin@example.com
-- Password: admin
-- IMPORTANT: Change this password immediately after first login!
--
INSERT INTO public.usr_users (usr_first_name, usr_last_name, usr_email, usr_permission, usr_is_activated, usr_email_is_verified, usr_password, usr_signup_date)
VALUES ('Admin', '', 'admin@example.com', 10, true, true, '$2y$10$...bcrypt_hash_here...', CURRENT_DATE);

-- Data for essential tables
COPY public.stg_settings (...) FROM stdin;
-- data rows
\.

COPY public.amu_admin_menus (...) FROM stdin;
-- data rows
\.

COPY public.usr_users (...) FROM stdin;
-- data rows (these are the sample users, NOT including the default admin)
\.

-- Primary keys and constraints
ALTER TABLE ONLY public.stg_settings
    ADD CONSTRAINT stg_settings_pkey PRIMARY KEY (stg_setting_id);

-- Indexes
CREATE INDEX idx_... ON public.stg_settings ...;

-- PostgreSQL database dump complete
```

### Script Workflow

1. **Parse command-line arguments:**
   - Extract VERSION from first positional argument (e.g., "0.67")
   - Check for --no-compress flag (optional)
   - Validate VERSION format (should be like X.XX where X are numbers)
2. **Load web context:**
   - Load PathHelper (which auto-loads Globalvars, DbConnector, SessionControl, ThemeHelper, PluginHelper)
   - Get $settings singleton
   - Get $dbconnector singleton
3. **Validate database connection** (test connection before proceeding)
4. **Determine output path:**
   - Use `__DIR__` to determine site root: `dirname(__DIR__ . '/../../../')`
   - Construct filename:
     - With compression: `joinery-install-VERSION.sql.gz`
     - Without compression: `joinery-install-VERSION.sql`
   - Verify uploads directory exists (create if needed with proper permissions)
5. **Create temporary directory** for intermediate files (`/tmp/joinery-install-XXXX/`)
6. **Export full schema** to temp file using pg_dump
7. **For each essential table:**
   - Export data to temp file using pg_dump
   - Validate export succeeded
8. **Generate default admin user:**
   - Generate bcrypt hash of password 'admin'
   - Create INSERT statement for default admin user with hashed password
9. **Assemble final SQL file:**
   - Write PostgreSQL header with VERSION info
   - Append schema export
   - Append default admin user INSERT statement
   - Append data exports in order
   - Write footer
10. **Compress file (unless --no-compress):**
   - If compression enabled: gzip the SQL file to create .sql.gz
   - If --no-compress: leave as plain .sql file
11. **Move final file** to uploads directory
12. **Clean up temp files**
13. **Report success** with file size, compression status, version, and location

### Error Handling

The script must:
- Validate database connection before starting
- Check pg_dump exit codes for each operation
- Halt on any pg_dump failure and report which step failed
- Verify output file was created and has content
- Clean up temp files even on failure
- Provide clear error messages for common failures:
  - Database connection failed
  - pg_dump not found
  - Permission denied on output directory
  - Disk space issues

### Additional Specifications

#### File Overwriting
- If output file already exists (e.g., `joinery-install-0.67.sql.gz`), overwrite it
- No need to keep old versions in uploads directory
- The version in the filename provides version tracking

#### Default Admin User Details
```sql
-- Let the sequence auto-assign the ID (don't hardcode usr_user_id)
INSERT INTO public.usr_users (
    usr_first_name,
    usr_last_name,
    usr_email,
    usr_permission,
    usr_is_activated,
    usr_email_is_verified,
    usr_password,
    usr_signup_date
) VALUES (
    'Admin',                    -- first name
    '',                        -- last name (empty)
    'admin@example.com',       -- email
    10,                        -- superadmin permission
    true,                      -- is_activated
    true,                      -- email_is_verified
    '$2y$10$...',             -- bcrypt hash of 'admin'
    CURRENT_DATE              -- signup_date
);
```

#### Security Note
- Default password 'admin' is intentionally simple for initial setup
- Admin MUST change this password after first login
- Consider adding a note in the SQL comment about changing the password

#### PostgreSQL Compatibility
- Ensure all SQL is compatible with PostgreSQL 14+
- Use standard SQL where possible
- Avoid PostgreSQL-specific features unless necessary

### Output and Logging

The script should output progress to stdout:

```
Creating install SQL file (version 0.67)...
Compression: ENABLED (use --no-compress to disable)

[1/11] Validating arguments...
[2/11] Connecting to database 'joinery'...
[3/11] Exporting database schema...
[4/11] Generating default admin user...
[5/11] Exporting data from stg_settings (173 rows)...
[6/11] Exporting data from amu_admin_menus (50 rows)...
[7/11] Exporting data from cco_country_codes (225 rows)...
[8/11] Exporting data from zone (425 rows)...
[9/11] Exporting data from remaining tables...
[10/11] Assembling and compressing SQL file...
[11/11] Moving to uploads directory...

SUCCESS: Install SQL created
Location: /var/www/html/joinerytest/uploads/joinery-install-0.67.sql.gz
File size: 1.2 MB (compressed from 6.8 MB)
Compression: 82% reduction
Database version: 0.67
Tables with data: 7 + default admin user
```

### Integration with publish_upgrade

The publish_upgrade script will determine the version number and pass it to create_install_sql.php:

**In publish_upgrade.php:**
```php
// publish_upgrade determines the version (e.g., from config, parameters, or version file)
$version = '0.67'; // Version determined by publish_upgrade

echo "Generating fresh install SQL (version $version)...\n";

$cmd = "cd " . escapeshellarg($site_root . '/public_html') . " && php utils/create_install_sql.php " . escapeshellarg($version);
exec($cmd, $output, $return_code);

if ($return_code !== 0) {
    die("ERROR: Failed to generate install SQL\n" . implode("\n", $output) . "\n");
}

echo "Install SQL generated successfully\n";

// After install SQL is generated, copy it from uploads to maintenance_scripts for distribution
// so new_account.sh can find it (decompress if needed)
$uploads_sql = $site_root . '/uploads/joinery-install-*.sql*'; // Matches both .sql and .sql.gz
$files = glob($uploads_sql);
if (!empty($files)) {
    // Get the most recent file
    usort($files, function($a, $b) { return filemtime($b) - filemtime($a); });
    $source_file = $files[0];
    $dest_file = '/home/user1/joinery/joinery/maintenance_scripts/joinery-install-sql.sql';

    if (substr($source_file, -3) === '.gz') {
        // Decompress for distribution
        exec("gunzip -c " . escapeshellarg($source_file) . " > " . escapeshellarg($dest_file));
        echo "Install SQL decompressed and copied to maintenance scripts\n";
    } else {
        copy($source_file, $dest_file);
        echo "Install SQL copied to maintenance scripts\n";
    }
}
```

**Note:** The version number is determined by publish_upgrade (how it's determined is outside the scope of this script).

### Version Information

The script should add a header comment to the SQL file indicating:
- Generation date/time
- Source database name
- Database version from `stg_settings`
- Script version
- Generator script name

```sql
--
-- PostgreSQL database dump
-- Generated by: create_install_sql.php v1.0
-- Source database: joinery
-- Database version: 0.67
-- Generated on: 2025-11-09 13:45:22 UTC
--
```

## Testing Requirements

1. **Test fresh install:** Run generated SQL on empty database, verify all tables created
2. **Test seed data:** Verify essential tables have correct data after install
3. **Test update_database:** Verify migrations work correctly on fresh install
4. **Test new_account.sh:** Verify new_account.sh works with generated SQL
5. **Compare schema:** Compare generated schema to current production database
6. **File size check:** Verify file size is reasonable (6-8 MB expected)

## Success Criteria

- Script generates valid PostgreSQL SQL file
- Generated SQL creates all tables and constraints
- Generated SQL includes all essential seed data
- Generated SQL excludes all transactional/user data
- new_account.sh can successfully create new sites using generated SQL
- File size is similar to current install SQL (~6-7 MB)
- Script completes in reasonable time (<2 minutes)

## Future Enhancements

These are NOT required for initial implementation but noted for future consideration:

1. **Auto-detect essential tables:** Parse data class files to identify reference/config tables
2. **Incremental updates:** Only regenerate if schema changed
3. **Multiple database support:** Generate install SQL for different configurations
4. **Validation mode:** Compare generated SQL against existing to detect issues
5. **Plugin data:** Handle plugin-specific seed data separately
6. **Data sanitization:** Automatically sanitize sensitive data (API keys, passwords) from included tables

## Notes

- This script runs in a maintenance context, not through web server
- Must work with PostgreSQL only (not MySQL compatible)
- Should be idempotent - can run multiple times safely
- Generated SQL must be compatible with PostgreSQL 14+
- Consider file permissions on generated SQL file (should be readable by new_account.sh)
