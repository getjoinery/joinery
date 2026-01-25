<?php
/**
 * Create Install SQL Generator Script
 *
 * Generates a fresh install SQL file with database schema and essential seed data.
 * Called during publish_upgrade to ensure install SQL is always up-to-date.
 *
 * Usage:
 *   php utils/create_install_sql.php VERSION [--no-compress]
 *
 * Arguments:
 *   VERSION        Required. Version number for the install SQL (e.g., 0.67)
 *   --no-compress  Optional. Skip gzip compression (creates .sql instead of .sql.gz)
 *
 * Output:
 *   /var/www/html/SITENAME/uploads/joinery-install-VERSION.sql.gz (default)
 *   /var/www/html/SITENAME/uploads/joinery-install-VERSION.sql (with --no-compress)
 *
 * @version 1.0
 */

// ============================================================================
// SETUP AND ARGUMENT PARSING
// ============================================================================

// Disable PHP timeout for this script
set_time_limit(0);

// Parse command-line arguments
$version = null;
$compress = true;  // Default to compressed output

// Process arguments (check if running from command line)
if (isset($argc) && isset($argv)) {
    for ($i = 1; $i < $argc; $i++) {
        if ($argv[$i] === '--no-compress') {
            $compress = false;
        } else if (!$version) {
            $version = trim($argv[$i]);
        }
    }
}

if (!$version) {
    die("ERROR: Version argument required\n" .
        "Usage: php utils/create_install_sql.php VERSION [--no-compress]\n" .
        "Example: php utils/create_install_sql.php 0.67\n");
}

// Validate version format (should be like X.XX)
if (!preg_match('/^\d+\.\d+$/', $version)) {
    die("ERROR: Invalid version format '$version'. Expected format: X.XX (e.g., 0.67)\n");
}

echo "Creating install SQL file (version $version)...\n";
if (!$compress) {
    echo "Compression disabled (--no-compress flag detected)\n";
}

// ============================================================================
// LOAD WEB CONTEXT - BOOTSTRAP
// ============================================================================

// Bootstrap PathHelper by requiring it from the includes directory
// This is the proper way to load it for maintenance scripts
$includes_path = dirname(__DIR__) . '/includes/PathHelper.php';
if (!file_exists($includes_path)) {
    die("ERROR: Cannot find PathHelper at: $includes_path\n");
}
require_once($includes_path);

// Also explicitly require DbConnector and Globalvars
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));

// Get singleton instances
$settings = Globalvars::get_instance();
$dbconnector = DbConnector::get_instance();

echo "[1/10] Validating version format...\n";

// ============================================================================
// DATABASE CONNECTION VALIDATION
// ============================================================================

echo "[2/10] Connecting to database...\n";

try {
    $dblink = $dbconnector->get_db_link();

    // Test connection with a simple query
    $test_sql = "SELECT version() as version";
    $q = $dblink->prepare($test_sql);
    $q->execute();
    $result = $q->fetch(PDO::FETCH_OBJ);

    if (!$result) {
        die("ERROR: Could not connect to database or retrieve version\n");
    }

    // Get database name from settings
    $db_name = $settings->get_setting('database_name') ?: 'joinerytest';
    echo "   Connected to database '$db_name'\n";

} catch (Exception $e) {
    die("ERROR: Database connection failed: " . $e->getMessage() . "\n");
}

// ============================================================================
// DETERMINE OUTPUT PATH AND CREATE DIRECTORIES
// ============================================================================

echo "[3/10] Setting up output directory...\n";

// Get site root directory
$site_root = dirname(dirname(__DIR__));  // Go up from /public_html to site root
$uploads_dir = $site_root . '/uploads';

// Create uploads directory if it doesn't exist
if (!is_dir($uploads_dir)) {
    if (!mkdir($uploads_dir, 0755, true)) {
        die("ERROR: Could not create uploads directory at $uploads_dir\n");
    }
}

// Verify directory is writable
if (!is_writable($uploads_dir)) {
    die("ERROR: Uploads directory is not writable: $uploads_dir\n");
}

// Determine output filename based on compression flag
if ($compress) {
    $output_filename = "joinery-install-{$version}.sql.gz";
} else {
    $output_filename = "joinery-install-{$version}.sql";
}
$final_output_path = $uploads_dir . '/' . $output_filename;

echo "   Output will be written to: $final_output_path\n";

// ============================================================================
// CREATE TEMPORARY WORKING DIRECTORY
// ============================================================================

echo "[4/10] Creating temporary working directory...\n";

$temp_dir = '/tmp/joinery-install-' . uniqid();
if (!mkdir($temp_dir, 0700, true)) {
    die("ERROR: Could not create temporary directory at $temp_dir\n");
}

echo "   Working directory: $temp_dir\n";

// Register cleanup function to remove temp directory on exit
register_shutdown_function(function() use ($temp_dir) {
    if (is_dir($temp_dir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temp_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file) : unlink($file);
        }
        rmdir($temp_dir);
    }
});

// ============================================================================
// EXPORT DATABASE SCHEMA
// ============================================================================

echo "[5/10] Exporting database schema...\n";

$schema_file = $temp_dir . '/schema.sql';

// Get database credentials from settings
$db_user = $settings->get_setting('database_user') ?: 'postgres';
$db_name = $settings->get_setting('database_name') ?: 'joinerytest';

// Build pg_dump command for schema export
$pg_dump_cmd = sprintf(
    'pg_dump -U %s -d %s --schema-only --no-owner --no-privileges --no-tablespaces --no-security-labels --no-comments',
    escapeshellarg($db_user),
    escapeshellarg($db_name)
);

// Use shell_exec to preserve exact output (exec() strips trailing whitespace)
$schema_output = shell_exec($pg_dump_cmd . ' 2>&1');

if (strpos($schema_output, 'ERROR') !== false || strpos($schema_output, 'FATAL') !== false) {
    die("ERROR: pg_dump schema export failed:\n$schema_output\n");
}

if (empty($schema_output)) {
    die("ERROR: Schema export produced empty output\n");
}

file_put_contents($schema_file, $schema_output);
echo "   Schema export complete (" . filesize($schema_file) . " bytes)\n";

// ============================================================================
// EXPORT DATA FROM ESSENTIAL TABLES
// ============================================================================

echo "[6/10] Exporting essential table data...\n";

// List of essential tables to export (from spec)
$essential_tables = [
    'stg_settings',       // System configuration
    'amu_admin_menus',    // Admin menu structure
    'cco_country_codes',  // Country reference data
    'zone',               // Timezone names (IANA)
    'ety_event_types',    // Event type definitions
    'emt_email_templates', // Email templates
    'pmu_public_menus',   // Public menu structure
    'com_components'      // Page components (all Bootstrap-based, work with falcon theme)
];

$data_files = [];

foreach ($essential_tables as $table) {
    // Get row count for this table
    $count_sql = "SELECT COUNT(*) as count FROM $table";
    $q = $dblink->prepare($count_sql);

    try {
        $q->execute();
        $count_result = $q->fetch(PDO::FETCH_OBJ);
        $row_count = $count_result->count ?? 0;
    } catch (Exception $e) {
        echo "   WARNING: Could not count rows in table '$table': " . $e->getMessage() . "\n";
        $row_count = 0;
    }

    // Export table data using pg_dump with COPY format
    $table_file = $temp_dir . '/' . $table . '.sql';

    $pg_dump_cmd = sprintf(
        'pg_dump -U %s -d %s --data-only --no-owner --no-privileges --table=%s',
        escapeshellarg($db_user),
        escapeshellarg($db_name),
        escapeshellarg($table)
    );

    // Use shell_exec to preserve trailing whitespace (exec() strips it)
    $table_output = shell_exec($pg_dump_cmd . ' 2>/dev/null');

    if ($table_output === null || $table_output === '') {
        echo "   Skipping table '$table' (no data or export failed)\n";
        continue;
    }

    file_put_contents($table_file, $table_output);
    $data_files[$table] = $table_file;
    echo "   Exported $table ($row_count rows)\n";
}

// ============================================================================
// GENERATE DEFAULT USERS (Admin, System, Deleted)
// ============================================================================

echo "[7/10] Generating default users...\n";

// Generate bcrypt hash for default password
$default_password = 'changeme123';
$bcrypt_hash = password_hash($default_password, PASSWORD_BCRYPT);

// Create admin user SQL (user_id 1) - explicit ID to ensure correct ordering
$admin_user_sql = sprintf(
    "-- Default admin user (email: admin@example.com, password: %s)\n" .
    "INSERT INTO public.usr_users (usr_user_id, usr_first_name, usr_last_name, usr_email, usr_permission, " .
    "usr_is_activated, usr_email_is_verified, usr_password, usr_signup_date, usr_force_password_change, usr_timezone) " .
    "VALUES (1, 'Admin', '', 'admin@example.com', 10, true, true, '%s', CURRENT_DATE, true, 'America/New_York');\n\n",
    $default_password,
    $bcrypt_hash
);

// Create system user SQL (user_id 2) - used for system-generated actions
$system_user_sql = "-- System user (user_id 2) - used for system-generated actions\n" .
    "INSERT INTO public.usr_users (usr_user_id, usr_first_name, usr_last_name, usr_email, usr_permission, " .
    "usr_is_activated, usr_email_is_verified, usr_password, usr_signup_date, usr_force_password_change, usr_timezone) " .
    "VALUES (2, 'System', 'User', 'system-user@joinery.local', 10, false, false, '', CURRENT_DATE, false, 'America/New_York');\n\n";

// Create deleted user SQL (user_id 3) - placeholder for reassigning ownership when users are deleted
$deleted_user_sql = "-- Deleted user (user_id 3) - placeholder for reassigning ownership when users are deleted\n" .
    "INSERT INTO public.usr_users (usr_user_id, usr_first_name, usr_last_name, usr_email, usr_permission, " .
    "usr_is_activated, usr_email_is_verified, usr_password, usr_signup_date, usr_force_password_change, usr_timezone) " .
    "VALUES (3, 'Deleted', 'User', 'deleted-user@joinery.local', 0, false, false, '', CURRENT_DATE, false, 'America/New_York');\n\n" .
    "-- Reset usr_users sequence to start after default users\n" .
    "SELECT setval('usr_users_usr_user_id_seq', 3, true);\n";

$admin_file = $temp_dir . '/admin_user.sql';
file_put_contents($admin_file, $admin_user_sql . $system_user_sql . $deleted_user_sql);
echo "   Generated admin user (admin@example.com / $default_password)\n";
echo "   Generated system user (user_id 2)\n";
echo "   Generated deleted user (user_id 3)\n";

// ============================================================================
// ASSEMBLE FINAL SQL FILE
// ============================================================================

echo "[8/10] Assembling final SQL file...\n";

// Create temporary uncompressed file first
$temp_output = $temp_dir . '/complete.sql';
$output_handle = fopen($temp_output, 'w');
if (!$output_handle) {
    die("ERROR: Could not open temporary output file for writing\n");
}

// Write header with metadata
$header = <<<SQL
--
-- PostgreSQL database dump
--
-- Generated by: create_install_sql.php v1.0
-- Database version: {$version}
-- Generated at:
SQL;
$header .= date('Y-m-d H:i:s T') . "\n";
$header .= <<<SQL
--
-- This file contains the complete database schema and essential seed data
-- for a fresh Joinery installation. It is generated on demand during the
-- publish_upgrade process to ensure install SQL is always up-to-date.
--
-- Default admin credentials:
--   Email: admin@example.com
--   Password: changeme123
--
-- SECURITY WARNING: Change the default password immediately after installation!
--

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SET check_function_bodies = false;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: public; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA IF NOT EXISTS public;

--
-- Name: SCHEMA public; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON SCHEMA public IS 'standard public schema';

SET search_path = public, pg_catalog;

SQL;

fwrite($output_handle, $header);

// Write schema
$schema_content = file_get_contents($schema_file);
if ($schema_content) {
    fwrite($output_handle, "\n-- ============================================================================\n");
    fwrite($output_handle, "-- TABLE AND CONSTRAINT DEFINITIONS\n");
    fwrite($output_handle, "-- ============================================================================\n\n");
    fwrite($output_handle, $schema_content);
}

// Write data for each essential table
if (!empty($data_files)) {
    fwrite($output_handle, "\n-- ============================================================================\n");
    fwrite($output_handle, "-- ESSENTIAL SEED DATA\n");
    fwrite($output_handle, "-- ============================================================================\n\n");

    foreach ($data_files as $table => $file) {
        $data_content = file_get_contents($file);
        if ($data_content) {
            fwrite($output_handle, "-- Data for table: $table\n");
            fwrite($output_handle, $data_content);
            fwrite($output_handle, "\n");
        }
    }
}

// Write default users data (admin, system, deleted)
fwrite($output_handle, "\n-- ============================================================================\n");
fwrite($output_handle, "-- DEFAULT USERS (Admin, System, Deleted)\n");
fwrite($output_handle, "-- ============================================================================\n\n");
fwrite($output_handle, file_get_contents($admin_file));

// ============================================================================
// GENERATE SEQUENCE RESET STATEMENTS
// ============================================================================

echo "[8.5/10] Generating sequence reset statements...\n";

fwrite($output_handle, "\n-- ============================================================================\n");
fwrite($output_handle, "-- SEQUENCE RESETS\n");
fwrite($output_handle, "-- Reset sequences to match imported data to prevent duplicate key errors\n");
fwrite($output_handle, "-- ============================================================================\n\n");

$sequence_count = 0;

// Query to find sequences and their associated tables/columns
$seq_sql = "
    SELECT
        s.relname as sequence_name,
        t.relname as table_name,
        a.attname as column_name
    FROM pg_class s
    JOIN pg_depend d ON d.objid = s.oid
    JOIN pg_class t ON d.refobjid = t.oid
    JOIN pg_attribute a ON (a.attrelid = t.oid AND a.attnum = d.refobjsubid)
    WHERE s.relkind = 'S'
    AND t.relname = ANY(:tables)
";

try {
    $q = $dblink->prepare($seq_sql);
    $q->execute([':tables' => '{' . implode(',', $essential_tables) . '}']);
    $sequences = $q->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sequences as $seq) {
        $table = $seq['table_name'];
        $column = $seq['column_name'];
        $sequence = $seq['sequence_name'];

        // Get max value for this column
        $max_sql = "SELECT COALESCE(MAX($column), 0) as max_val FROM $table";
        $max_q = $dblink->prepare($max_sql);
        $max_q->execute();
        $max_result = $max_q->fetch(PDO::FETCH_ASSOC);
        $max_val = $max_result['max_val'] ?? 0;

        if ($max_val > 0) {
            $setval_sql = "SELECT pg_catalog.setval('public.$sequence', $max_val, true);\n";
            fwrite($output_handle, $setval_sql);
            echo "   Reset $sequence to $max_val\n";
            $sequence_count++;
        }
    }
} catch (Exception $e) {
    echo "   WARNING: Could not query sequences: " . $e->getMessage() . "\n";
}

// Note: usr_users sequence is reset to 3 in the DEFAULT USERS section
// after inserting the three default users (admin, system, deleted) with explicit IDs

echo "   Generated $sequence_count sequence reset statements\n";

// ============================================================================
// GENERATE MIGRATION BASELINE
// ============================================================================

echo "[8.6/10] Generating migration baseline...\n";

fwrite($output_handle, "\n-- ============================================================================\n");
fwrite($output_handle, "-- MIGRATION BASELINE\n");
fwrite($output_handle, "-- Mark all historical migrations as 'already applied' for fresh installs\n");
fwrite($output_handle, "-- This prevents old migrations from running on new installations\n");
fwrite($output_handle, "-- ============================================================================\n\n");

// Load migrations from migrations.php
$migrations = [];
require_once(PathHelper::getIncludePath('migrations/migrations.php'));

$migration_count = 0;

/**
 * Normalize SQL before hashing (same logic as Migration class)
 */
function normalize_sql_for_hash($sql) {
    $sql = preg_replace('/\s+/', ' ', $sql);
    $sql = trim($sql);
    $sql = rtrim($sql, ';');
    return $sql;
}

foreach ($migrations as $migration) {
    $migration_hash = null;
    $migration_sql = null;
    $migration_file = null;

    // Generate hash based on migration type (same logic as Migration::shouldRunMigration)
    if (isset($migration['migration_sql']) && !empty($migration['migration_sql'])) {
        $normalized_sql = normalize_sql_for_hash($migration['migration_sql']);
        $migration_hash = md5($normalized_sql);
        $migration_sql = addslashes($migration['migration_sql']);
    } elseif (isset($migration['migration_file']) && !empty($migration['migration_file'])) {
        $migration_file_path = PathHelper::getIncludePath('migrations/' . $migration['migration_file']);
        if (file_exists($migration_file_path)) {
            $migration_hash = md5_file($migration_file_path);
            $migration_file = addslashes($migration['migration_file']);
        } else {
            echo "   WARNING: Migration file not found: {$migration['migration_file']}\n";
            continue;
        }
    } else {
        // Skip empty migrations
        continue;
    }

    $db_version = floatval($migration['database_version']);

    // Generate INSERT statement for this migration
    $insert_sql = "INSERT INTO mig_migrations (mig_version, mig_name, mig_hash, mig_success, mig_output, mig_create_time";
    if ($migration_sql) {
        $insert_sql .= ", mig_sql";
    }
    if ($migration_file) {
        $insert_sql .= ", mig_file";
    }
    $insert_sql .= ") VALUES ($db_version, 'Baseline migration $db_version', '$migration_hash', true, 'Pre-applied during fresh install', CURRENT_TIMESTAMP";
    if ($migration_sql) {
        $insert_sql .= ", '$migration_sql'";
    }
    if ($migration_file) {
        $insert_sql .= ", '$migration_file'";
    }
    $insert_sql .= ");\n";

    fwrite($output_handle, $insert_sql);
    $migration_count++;
}

// Reset mig_migrations sequence
fwrite($output_handle, "\n-- Reset mig_migrations sequence\n");
fwrite($output_handle, "SELECT pg_catalog.setval('public.mig_migrations_mig_migration_id_seq', $migration_count, true);\n");

echo "   Generated baseline for $migration_count migrations\n";

// ============================================================================
// PORTABILITY FIXES
// TODO: Eventually the composerAutoLoad value in the source database should
// be set to a portable relative path, eliminating the need for this fix.
// ============================================================================

fwrite($output_handle, "\n-- ============================================================================\n");
fwrite($output_handle, "-- PORTABILITY FIXES\n");
fwrite($output_handle, "-- These UPDATE statements ensure settings work across different environments\n");
fwrite($output_handle, "-- ============================================================================\n\n");

// Set composerAutoLoad to relative path for portability
fwrite($output_handle, "-- Set composerAutoLoad to relative vendor path (works in Docker and bare-metal)\n");
fwrite($output_handle, "UPDATE stg_settings SET stg_value = '../vendor/' WHERE stg_name = 'composerAutoLoad';\n\n");

echo "   Added portability fix for composerAutoLoad\n";

// Write footer
$footer = <<<SQL

--
-- PostgreSQL database dump complete
--

SQL;

fwrite($output_handle, $footer);
fclose($output_handle);

// ============================================================================
// COMPRESS OUTPUT IF REQUESTED
// ============================================================================

if ($compress) {
    echo "[9/10] Compressing output file...\n";

    // Use gzip command to compress the file
    $gzip_cmd = sprintf(
        'gzip -c %s > %s',
        escapeshellarg($temp_output),
        escapeshellarg($final_output_path)
    );

    $exit_code = 0;
    exec($gzip_cmd, $output, $exit_code);

    if ($exit_code !== 0) {
        die("ERROR: Failed to compress output file (exit code $exit_code)\n");
    }

    $file_size = filesize($final_output_path);
    echo "   Compressed to " . number_format($file_size) . " bytes\n";
} else {
    // Just copy the uncompressed file
    if (!copy($temp_output, $final_output_path)) {
        die("ERROR: Failed to copy output file to final location\n");
    }
    $file_size = filesize($final_output_path);
}

// ============================================================================
// VERIFY OUTPUT FILE
// ============================================================================

echo "[10/10] Verifying output file...\n";

if (!file_exists($final_output_path)) {
    die("ERROR: Output file was not created\n");
}

if ($file_size === 0) {
    die("ERROR: Output file is empty\n");
}

echo "   File created successfully (" . number_format($file_size) . " bytes)\n";

// Note: No copy to maintenance scripts needed
// publish_upgrade.php will pull directly from uploads directory

// ============================================================================
// REPORT SUCCESS
// ============================================================================

$table_count = count($data_files) + 1; // +1 for admin user
$file_size_mb = round($file_size / 1024 / 1024, 2);

echo "\n" . str_repeat("=", 70) . "\n";
echo "SUCCESS: Install SQL created\n";
echo str_repeat("=", 70) . "\n";
echo "Location:          $final_output_path\n";
echo "File size:         $file_size_mb MB (" . number_format($file_size) . " bytes)\n";
echo "Database version:  $version\n";
echo "Tables with data:  " . count($data_files) . "\n";
echo "Compression:       " . ($compress ? "Enabled (gzip)" : "Disabled") . "\n";
echo "Default admin:     admin@example.com / changeme123\n";
echo "Generated at:      " . date('Y-m-d H:i:s T') . "\n";
echo str_repeat("=", 70) . "\n\n";

exit(0);
?>