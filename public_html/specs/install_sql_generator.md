# Specification: Install SQL Generation Script

## Overview

Create a PHP script that generates a fresh install SQL file (`joinery-install-sql.sql`) on demand. This script will be called during the publish_upgrade process to ensure the install SQL is always up-to-date with the current database schema and essential seed data.

## Current State

- Existing install SQL file: `/home/user1/joinery/joinery/maintenance scripts/joinery-install-sql.sql`
- File is outdated (6.3MB, last updated Aug 25 13:16)
- Used by `new_account.sh` for fresh installations
- Contains full schema + seed data for ~68 tables

## Requirements

### Script Location and Name

- **Path:** `/home/user1/joinery/joinery/maintenance scripts/create_install_sql.php`
- **Callable from:** Command line (`php create_install_sql.php`) or via publish_upgrade
- **Output file:** `/home/user1/joinery/joinery/maintenance scripts/joinery-install-sql.sql`

### Database Connection

The script should:
1. Use database credentials from a command-line environment (production database)
2. NOT use PathHelper/Globalvars (maintenance script runs outside web context)
3. Accept database credentials as command-line arguments OR environment variables:
   ```bash
   php create_install_sql.php --db-name=joinery --db-user=postgres --db-password=secret
   # OR
   PGDATABASE=joinery PGUSER=postgres PGPASSWORD=secret php create_install_sql.php
   ```

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

The script must identify and export seed data from **essential configuration tables only**. These are tables that must have data for a fresh install to function.

#### Definite INCLUDE Tables (Essential Seed Data)

These tables contain configuration/reference data required for the system to function:

1. **stg_settings** - System configuration (required for all features)
2. **amu_admin_menus** - Admin menu structure (required for admin interface)
3. **cco_country_codes** - Country reference data (required for addresses/phones)
4. **zone** - Timezone name reference data (required for timezone dropdowns - contains IANA timezone names like "America/New_York")
7. **ety_event_types** - Event type enum data (if used)
8. **emt_email_templates** - Default email templates (required for emails)
9. **pmu_public_menus** - Default public navigation (optional but recommended)
10. **upg_upgrades** - Migration/upgrade tracking (required for update_database)

#### Definite EXCLUDE Tables (Transactional/User Data)

These tables contain user-generated or runtime data that should NOT be in a fresh install:

- **usr_users** - User accounts (fresh install has no users)
- **ord_orders** - Purchase orders
- **odi_order_items** - Order line items
- **evt_events** - Events
- **evr_event_registrants** - Event registrations
- **evs_event_sessions** - Event sessions
- **pro_products** - Products
- **prv_product_versions** - Product versions
- **prd_product_details** - Product usage details
- **grp_groups** - User groups
- **grm_group_members** - Group membership
- **fil_files** - Uploaded files
- **vid_videos** - Video content
- **pst_posts** - Blog posts
- **cmt_comments** - Blog comments
- **pac_page_contents** - Page content
- **pag_pages** - Custom pages
- **eml_emails** - Email campaigns
- **erc_email_recipients** - Email recipients
- **equ_queued_emails** - Email queue
- **ers_recurring_email_logs** - Recurring email logs
- **mlt_mailing_lists** - Mailing lists
- **mlr_mailing_list_registrants** - Mailing list members
- **svy_surveys** - Surveys
- **srq_survey_questions** - Survey questions
- **sva_survey_answers** - Survey responses
- **qst_questions** - Questions
- **qop_question_options** - Question options
- **log_logins** - Login history
- **evl_event_logs** - Event logs
- **err_general_errors** - Error logs
- **lfe_log_form_errors** - Form error logs
- **cls_cart_logs** - Shopping cart logs
- **vse_visitor_events** - Analytics/visitor tracking
- **sev_session_analytics** - Session analytics
- **msg_messages** - User messages
- **act_activation_codes** - Activation codes (temporary)
- **phn_phone_numbers** - User phone numbers
- **usa_users_addrs** - User addresses
- **apk_api_keys** - API keys (site-specific)
- **siv_stripe_invoices** - Stripe invoices
- **url_urls** - URL redirects (site-specific)
- **del_debug_email_logs** - Debug logs
- **ewl_waiting_lists** - Event waiting lists
- **esf_event_session_files** - Session file associations
- **erg_email_recipient_groups** - Email recipient groups
- **cnv_content_versions** - Content version history
- **com_components** - Page components (site-specific)
- **loc_locations** - Location data (site-specific)
- **ctt_contact_types** - Contact types (site-specific if customized)
- **bkn_bookings** - Booking records (Calendly plugin)
- **bkt_booking_types** - Booking types (Calendly plugin)

#### Tables Requiring Analysis

These tables may contain essential seed data OR site-specific data - script should check record count and decide:

- **prq_product_requirements** - Product requirements (may be empty or have defaults)
- **pri_product_requirement_instances** - Requirement instances (likely empty)
- **prg_product_groups** - Product groups (may have defaults or be empty)
- **ccd_coupon_codes** - Coupon codes (should be empty)
- **ccp_coupon_code_products** - Coupon code products (should be empty)
- **timezone** - Dead table with 163K rows of timezone offset data - NO CODE REFERENCES, no foreign keys, not used

**Decision rule:** If table has 0 records, exclude. If table has 1-10 records, include (likely defaults). If table has >10 records, analyze manually or exclude.

**Note on timezone vs zone tables:**
- `timezone` table (163K rows) - DEAD TABLE, not used anywhere in code, can be safely excluded from install SQL
- `zone` table (425 rows) - ACTIVE, contains IANA timezone names, used by `Address::get_timezone_drop_array()`, must be included

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
$essential_data_tables = [
    'stg_settings',
    'amu_admin_menus',
    'cco_country_codes',
    'zone',  // IANA timezone names (America/New_York, etc.)
    'ety_event_types',
    'emt_email_templates',
    'pmu_public_menus',
    'upg_upgrades'
];
```

### SQL File Assembly

The final SQL file should be assembled in this order:

1. PostgreSQL header (SET statements for encoding, timeouts, etc.)
2. Full schema export (tables, sequences, constraints, indexes)
3. Data exports for essential tables (in dependency order if possible)
4. Sequence value resets (to match imported data)
5. Final constraint additions

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

-- Data for essential tables
COPY public.stg_settings (...) FROM stdin;
-- data rows
\.

COPY public.amu_admin_menus (...) FROM stdin;
-- data rows
\.

-- Primary keys and constraints
ALTER TABLE ONLY public.stg_settings
    ADD CONSTRAINT stg_settings_pkey PRIMARY KEY (stg_setting_id);

-- Indexes
CREATE INDEX idx_... ON public.stg_settings ...;

-- PostgreSQL database dump complete
```

### Script Workflow

1. **Parse command-line arguments** (database name, user, password)
2. **Validate database connection** (test connection before proceeding)
3. **Create temporary directory** for intermediate files
4. **Export full schema** to temp file
5. **For each essential table:**
   - Export data to temp file
   - Validate export succeeded
6. **Assemble final SQL file:**
   - Write PostgreSQL header
   - Append schema export
   - Append data exports in order
   - Write footer
7. **Move assembled file** to final location
8. **Clean up temp files**
9. **Report success** with file size and location

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

### Output and Logging

The script should output progress to stdout:

```
Creating install SQL file...
[1/8] Connecting to database 'joinery'...
[2/8] Exporting database schema...
[3/8] Exporting data from stg_settings (128 rows)...
[4/8] Exporting data from amu_admin_menus (41 rows)...
[5/8] Exporting data from cco_country_codes (233 rows)...
...
[8/8] Assembling final SQL file...

SUCCESS: Install SQL created at /home/user1/joinery/joinery/maintenance scripts/joinery-install-sql.sql
File size: 6.8 MB
Tables with data: 10
```

### Integration with publish_upgrade

The publish_upgrade script should call this script before creating archives:

```php
// In publish_upgrade.php
echo "Generating fresh install SQL...\n";
$cmd = "php '/home/user1/joinery/joinery/maintenance scripts/create_install_sql.php' " .
       "--db-name=joinery --db-user=postgres --db-password=" . escapeshellarg($db_password);
exec($cmd, $output, $return_code);

if ($return_code !== 0) {
    die("ERROR: Failed to generate install SQL\n" . implode("\n", $output) . "\n");
}

echo "Install SQL generated successfully\n";
```

### Version Information

The script should add a header comment to the SQL file indicating:
- Generation date/time
- Source database name
- Script version
- Generator script name

```sql
--
-- PostgreSQL database dump
-- Generated by: create_install_sql.php v1.0
-- Source database: joinery
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
