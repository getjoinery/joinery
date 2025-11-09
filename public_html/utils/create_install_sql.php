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
    'pmu_public_menus'    // Public menu structure
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
// GENERATE DEFAULT ADMIN USER
// ============================================================================

echo "[7/10] Generating default admin user...\n";

// Generate bcrypt hash for default password
$default_password = 'changeme123';
$bcrypt_hash = password_hash($default_password, PASSWORD_BCRYPT);

// Create admin user SQL (without usr_active which doesn't exist in table)
$admin_user_sql = sprintf(
    "-- Default admin user (email: admin@example.com, password: %s)\n" .
    "INSERT INTO public.usr_users (usr_first_name, usr_last_name, usr_email, usr_permission, " .
    "usr_is_activated, usr_email_is_verified, usr_password, usr_signup_date) " .
    "VALUES ('Admin', '', 'admin@example.com', 10, true, true, '%s', CURRENT_DATE);\n",
    $default_password,
    $bcrypt_hash
);

$admin_file = $temp_dir . '/admin_user.sql';
file_put_contents($admin_file, $admin_user_sql);
echo "   Generated admin user (admin@example.com / $default_password)\n";

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

// Write admin user data
fwrite($output_handle, "\n-- ============================================================================\n");
fwrite($output_handle, "-- DEFAULT ADMIN USER\n");
fwrite($output_handle, "-- ============================================================================\n\n");
fwrite($output_handle, file_get_contents($admin_file));

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

// ============================================================================
// COPY TO MAINTENANCE SCRIPTS FOR DISTRIBUTION (OPTIONAL)
// ============================================================================

$maintenance_scripts_dir = '/home/user1/joinery/joinery/maintenance scripts';
if (is_dir($maintenance_scripts_dir) && is_writable($maintenance_scripts_dir)) {
    // Always copy as uncompressed .sql for maintenance scripts
    $maintenance_dest = $maintenance_scripts_dir . '/joinery-install-sql.sql';

    if ($compress) {
        // Need to decompress for maintenance scripts
        $gunzip_cmd = sprintf(
            'gunzip -c %s > %s',
            escapeshellarg($final_output_path),
            escapeshellarg($maintenance_dest)
        );
        exec($gunzip_cmd, $output, $exit_code);
        if ($exit_code === 0) {
            echo "   Copied uncompressed version to maintenance scripts\n";
        } else {
            echo "   WARNING: Could not decompress for maintenance scripts\n";
        }
    } else {
        // Just copy the uncompressed file
        if (copy($final_output_path, $maintenance_dest)) {
            echo "   Copied to maintenance scripts for distribution\n";
        } else {
            echo "   WARNING: Could not copy to maintenance scripts\n";
        }
    }
}

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