<?php
/**
 * Clone Export Endpoint
 *
 * Secure endpoint for exporting site data for cloning to another server.
 *
 * Security:
 * - HTTPS required
 * - Disabled by default (requires clone_export_key setting)
 * - Authentication via Authorization header (Bearer token)
 * - Rate limiting (one request per minute per IP)
 * - All requests logged
 *
 * Actions:
 * - manifest: Returns metadata about what will be cloned
 * - database: Streams encrypted, gzipped PostgreSQL dump
 * - uploads: Streams tar.gz archive of uploads directory
 *
 * @version 1.0
 */

// Standalone script - load minimal requirements
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));

// =============================================================================
// SECURITY CHECKS
// =============================================================================

// Require HTTPS in production
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    // Allow non-HTTPS for localhost/testing
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!preg_match('/^(localhost|127\.0\.0\.1|192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $host)) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['status' => 'error', 'message' => 'HTTPS required']));
    }
}

// Get settings
$settings = Globalvars::get_instance();

// Check if clone export is enabled
$clone_key_setting = $settings->get_setting('clone_export_key');
if (empty($clone_key_setting)) {
    http_response_code(403);
    header('Content-Type: application/json');
    die(json_encode(['status' => 'error', 'message' => 'Clone export not enabled on this site']));
}

// Validate key from Authorization header (timing-safe comparison)
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$provided_key = '';
if (preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches)) {
    $provided_key = $matches[1];
}
if (!hash_equals($clone_key_setting, $provided_key)) {
    // Log failed attempt
    log_clone_request('auth_failed', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    http_response_code(403);
    header('Content-Type: application/json');
    die(json_encode(['status' => 'error', 'message' => 'Invalid or missing clone key']));
}

// Rate limiting (one request per minute per IP)
$client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rate_limit_file = sys_get_temp_dir() . '/clone_export_' . md5($client_ip) . '.rate';

if (file_exists($rate_limit_file)) {
    $last_request = (int) file_get_contents($rate_limit_file);
    if (time() - $last_request < 60) {
        log_clone_request('rate_limited', $client_ip);
        http_response_code(429);
        header('Content-Type: application/json');
        header('Retry-After: ' . (60 - (time() - $last_request)));
        die(json_encode(['status' => 'error', 'message' => 'Rate limit exceeded. Try again in 60 seconds.']));
    }
}
file_put_contents($rate_limit_file, time());

// =============================================================================
// ACTION HANDLING
// =============================================================================

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'manifest':
        handle_manifest($settings, $client_ip);
        break;

    case 'database':
        handle_database_export($settings, $client_ip, $provided_key);
        break;

    case 'uploads':
        handle_uploads_export($settings, $client_ip);
        break;

    default:
        http_response_code(400);
        header('Content-Type: application/json');
        die(json_encode(['status' => 'error', 'message' => 'Invalid action']));
}

// =============================================================================
// ACTION HANDLERS
// =============================================================================

/**
 * Handle manifest request - returns metadata about the site
 */
function handle_manifest($settings, $client_ip) {
    log_clone_request('manifest', $client_ip);

    $site_root = PathHelper::get_site_root();
    $site_name = basename(dirname($site_root));

    // Get database size
    $dbconnector = DbConnector::get_instance();
    $dblink = $dbconnector->get_db_link();

    $db_size_mb = 0;
    try {
        $q = $dblink->query("SELECT pg_database_size(current_database()) / 1024 / 1024 as size_mb");
        if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            $db_size_mb = (int) $row['size_mb'];
        }
    } catch (Exception $e) {
        // Ignore errors, just report 0
    }

    // Get uploads size and count
    $uploads_dir = $site_root . '/uploads';
    $uploads_size_mb = 0;
    $uploads_count = 0;

    if (is_dir($uploads_dir)) {
        $uploads_count = count_files_recursive($uploads_dir);
        $uploads_size_mb = (int) (get_directory_size($uploads_dir) / 1024 / 1024);
    }

    // Get themes
    $themes = [];
    $theme_dir = $site_root . '/theme';
    if (is_dir($theme_dir)) {
        foreach (scandir($theme_dir) as $item) {
            if ($item !== '.' && $item !== '..' && is_dir($theme_dir . '/' . $item)) {
                $themes[] = $item;
            }
        }
    }

    // Get plugins
    $plugins = [];
    $plugin_dir = $site_root . '/plugins';
    if (is_dir($plugin_dir)) {
        foreach (scandir($plugin_dir) as $item) {
            if ($item !== '.' && $item !== '..' && is_dir($plugin_dir . '/' . $item)) {
                $plugins[] = $item;
            }
        }
    }

    // Get Joinery version
    $version = '0.0.0';
    $version_file = $site_root . '/version.txt';
    if (file_exists($version_file)) {
        $version = trim(file_get_contents($version_file));
    }

    $manifest = [
        'status' => 'ok',
        'site_name' => $site_name,
        'database_size_mb' => $db_size_mb,
        'uploads_size_mb' => $uploads_size_mb,
        'uploads_count' => $uploads_count,
        'themes' => $themes,
        'plugins' => $plugins,
        'joinery_version' => $version,
        'php_version' => PHP_VERSION,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z')
    ];

    header('Content-Type: application/json');
    echo json_encode($manifest);
}

/**
 * Handle database export - streams encrypted, gzipped PostgreSQL dump
 */
function handle_database_export($settings, $client_ip, $clone_key) {
    log_clone_request('database', $client_ip);

    // Get database credentials from Globalvars
    $db_name = $settings->settings['dbname'];
    $db_password = $settings->settings['dbpassword'];
    $db_user = $settings->settings['dbuser'] ?? 'postgres';
    $db_host = $settings->settings['dbhost'] ?? '127.0.0.1';

    // Set headers for streaming download
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="database.sql.gz.enc"');
    header('X-Content-Type-Options: nosniff');

    // Disable output buffering
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Stream encrypted pg_dump output directly (same format as backup_database.sh)
    // Clone key serves as both authentication and encryption key
    $cmd = sprintf(
        "PGPASSWORD=%s pg_dump -h %s -U %s %s 2>/dev/null | gzip | openssl enc -aes-256-cbc -salt -pbkdf2 -pass pass:%s",
        escapeshellarg($db_password),
        escapeshellarg($db_host),
        escapeshellarg($db_user),
        escapeshellarg($db_name),
        escapeshellarg($clone_key)
    );

    passthru($cmd);
    exit;
}

/**
 * Handle uploads export - streams tar.gz archive of uploads directory
 */
function handle_uploads_export($settings, $client_ip) {
    log_clone_request('uploads', $client_ip);

    $site_root = PathHelper::get_site_root();
    $uploads_dir = $site_root . '/uploads';

    if (!is_dir($uploads_dir)) {
        http_response_code(404);
        header('Content-Type: application/json');
        die(json_encode(['status' => 'error', 'message' => 'Uploads directory not found']));
    }

    // Set headers for streaming download
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="uploads.tar.gz"');
    header('X-Content-Type-Options: nosniff');

    // Disable output buffering
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Stream tar output directly
    $cmd = sprintf(
        "tar -czf - -C %s uploads 2>/dev/null",
        escapeshellarg($site_root)
    );

    passthru($cmd);
    exit;
}

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Log a clone export request
 */
function log_clone_request($action, $client_ip) {
    $site_root = PathHelper::get_site_root();
    $log_dir = dirname($site_root) . '/logs';

    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }

    $log_file = $log_dir . '/clone_export.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = sprintf("[%s] %s - %s\n", $timestamp, $client_ip, $action);

    @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

/**
 * Count files recursively in a directory
 */
function count_files_recursive($dir) {
    $count = 0;

    if (!is_dir($dir)) {
        return 0;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isFile()) {
            $count++;
        }
    }

    return $count;
}

/**
 * Get total size of a directory in bytes
 */
function get_directory_size($dir) {
    $size = 0;

    if (!is_dir($dir)) {
        return 0;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $item) {
        if ($item->isFile()) {
            $size += $item->getSize();
        }
    }

    return $size;
}
