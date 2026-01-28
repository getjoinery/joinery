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
 * - themes: Streams tar.gz archive of themes directory
 * - plugins: Streams tar.gz archive of plugins directory
 *
 * @version 1.2 - Added static_files export (excludes upgrade packages and theme archives)
 */

// Standalone script - load minimal requirements
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));

// =============================================================================
// SECURITY CHECKS
// =============================================================================

// Require HTTPS in production (check both direct HTTPS and proxy headers)
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], '"https"') !== false);

if (!$is_https) {
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
// Check multiple sources as Apache/CGI may not set $_SERVER['HTTP_AUTHORIZATION']
$auth_header = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? '';

// Fallback to getallheaders() if available (works with Apache mod_php and some CGI setups)
if (empty($auth_header) && function_exists('getallheaders')) {
    $headers = getallheaders();
    $auth_header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}

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

    case 'themes':
        handle_themes_export($settings, $client_ip);
        break;

    case 'plugins':
        handle_plugins_export($settings, $client_ip);
        break;

    case 'static_files':
        handle_static_files_export($settings, $client_ip);
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

    $site_root = dirname(dirname(__DIR__));
    $site_name = basename($site_root);

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

    // Get uploads size and count (uploads are in public_html/uploads)
    $uploads_dir = $site_root . '/public_html/uploads';
    $uploads_size_mb = 0;
    $uploads_count = 0;

    if (is_dir($uploads_dir)) {
        $uploads_count = count_files_recursive($uploads_dir);
        $uploads_size_mb = (int) (get_directory_size($uploads_dir) / 1024 / 1024);
    }

    // Get themes (in public_html/theme)
    $themes = [];
    $theme_dir = $site_root . '/public_html/theme';
    if (is_dir($theme_dir)) {
        foreach (scandir($theme_dir) as $item) {
            if ($item !== '.' && $item !== '..' && is_dir($theme_dir . '/' . $item)) {
                $themes[] = $item;
            }
        }
    }

    // Get plugins (in public_html/plugins)
    $plugins = [];
    $plugin_dir = $site_root . '/public_html/plugins';
    if (is_dir($plugin_dir)) {
        foreach (scandir($plugin_dir) as $item) {
            if ($item !== '.' && $item !== '..' && is_dir($plugin_dir . '/' . $item)) {
                $plugins[] = $item;
            }
        }
    }

    // Get static_files size and count
    $static_files_dir = $site_root . '/static_files';
    $static_files_size_mb = 0;
    $static_files_count = 0;

    if (is_dir($static_files_dir)) {
        $static_files_count = count_files_recursive($static_files_dir);
        $static_files_size_mb = (int) (get_directory_size($static_files_dir) / 1024 / 1024);
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
        'static_files_size_mb' => $static_files_size_mb,
        'static_files_count' => $static_files_count,
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

    // Get database credentials from Globalvars (use get_setting accessor)
    $db_name = $settings->get_setting('dbname');
    $db_password = $settings->get_setting('dbpassword');
    $db_user = $settings->get_setting('dbusername') ?: 'postgres';
    $db_host = $settings->get_setting('dbhost') ?: '127.0.0.1';

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

    $site_root = dirname(dirname(__DIR__));
    $uploads_dir = $site_root . '/public_html/uploads';

    // Check if uploads directory exists and has files
    if (!is_dir($uploads_dir)) {
        // Return empty success response - no uploads to transfer
        header('Content-Type: application/json');
        die(json_encode(['status' => 'ok', 'message' => 'No uploads directory', 'count' => 0]));
    }

    // Check if directory has any files
    $file_count = count_files_recursive($uploads_dir);
    if ($file_count === 0) {
        // Return empty success response - no files to transfer
        header('Content-Type: application/json');
        die(json_encode(['status' => 'ok', 'message' => 'Uploads directory is empty', 'count' => 0]));
    }

    // Set headers for streaming download
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="uploads.tar.gz"');
    header('X-Content-Type-Options: nosniff');

    // Disable output buffering
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Stream tar output directly (from public_html directory, creates uploads/ in archive)
    $cmd = sprintf(
        "tar -czf - -C %s uploads 2>/dev/null",
        escapeshellarg($site_root . '/public_html')
    );

    passthru($cmd);
    exit;
}

/**
 * Handle themes export - streams tar.gz archive of themes directory
 */
function handle_themes_export($settings, $client_ip) {
    log_clone_request('themes', $client_ip);

    $site_root = dirname(dirname(__DIR__));
    $themes_dir = $site_root . '/public_html/theme';

    if (!is_dir($themes_dir)) {
        http_response_code(404);
        header('Content-Type: application/json');
        die(json_encode(['status' => 'error', 'message' => 'Themes directory not found']));
    }

    // Set headers for streaming download
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="themes.tar.gz"');
    header('X-Content-Type-Options: nosniff');

    // Disable output buffering
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Stream tar output directly (from public_html directory, creates theme/ in archive)
    $cmd = sprintf(
        "tar -czf - -C %s theme 2>/dev/null",
        escapeshellarg($site_root . '/public_html')
    );

    passthru($cmd);
    exit;
}

/**
 * Handle plugins export - streams tar.gz archive of plugins directory
 */
function handle_plugins_export($settings, $client_ip) {
    log_clone_request('plugins', $client_ip);

    $site_root = dirname(dirname(__DIR__));
    $plugins_dir = $site_root . '/public_html/plugins';

    if (!is_dir($plugins_dir)) {
        http_response_code(404);
        header('Content-Type: application/json');
        die(json_encode(['status' => 'error', 'message' => 'Plugins directory not found']));
    }

    // Set headers for streaming download
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="plugins.tar.gz"');
    header('X-Content-Type-Options: nosniff');

    // Disable output buffering
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Stream tar output directly (from public_html directory, creates plugins/ in archive)
    $cmd = sprintf(
        "tar -czf - -C %s plugins 2>/dev/null",
        escapeshellarg($site_root . '/public_html')
    );

    passthru($cmd);
    exit;
}

/**
 * Handle static_files export - streams tar.gz archive of static_files directory
 */
function handle_static_files_export($settings, $client_ip) {
    log_clone_request('static_files', $client_ip);

    $site_root = dirname(dirname(__DIR__));
    $static_files_dir = $site_root . '/static_files';

    // Check if static_files directory exists
    if (!is_dir($static_files_dir)) {
        // Return empty success response - no static_files to transfer
        header('Content-Type: application/json');
        die(json_encode(['status' => 'ok', 'message' => 'No static_files directory', 'count' => 0]));
    }

    // Count files that would actually be transferred (excluding upgrade packages and themes)
    // Use find to count, excluding the patterns we'll exclude from tar
    $count_cmd = sprintf(
        "find %s -type f ! -name '*.upg.zip' ! -name '*.upg.zip.*' ! -path '*/themes/*' 2>/dev/null | wc -l",
        escapeshellarg($static_files_dir)
    );
    $file_count = (int) trim(shell_exec($count_cmd));

    if ($file_count === 0) {
        // Return empty success response - no files to transfer after exclusions
        header('Content-Type: application/json');
        die(json_encode(['status' => 'ok', 'message' => 'No static files to transfer (only upgrade packages/themes present)', 'count' => 0]));
    }

    // Set headers for streaming download
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="static_files.tar.gz"');
    header('X-Content-Type-Options: nosniff');

    // Disable output buffering
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Stream tar output directly (from site root, creates static_files/ in archive)
    // Exclude upgrade packages and theme archives (these are for the upgrade system, not user data)
    $cmd = sprintf(
        "tar -czf - -C %s --exclude='*.upg.zip' --exclude='*.upg.zip.*' --exclude='static_files/themes' static_files 2>/dev/null",
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
    $site_root = dirname(dirname(__DIR__));
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
