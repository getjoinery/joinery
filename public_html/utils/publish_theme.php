<?php
/**
 * Theme and Plugin Distribution Endpoint
 *
 * Handles:
 *   ?list=themes       - List available stock themes with metadata
 *   ?list=plugins      - List available stock plugins with metadata
 *   ?download=name     - Download a theme archive
 *   ?download=name&type=plugin - Download a plugin archive
 *   ?core              - Redirect to core archive download
 *
 * Version: 1.1.0
 */

// Standalone script - load minimal requirements
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$settings = Globalvars::get_instance();
$baseDir = $settings->get_setting('baseDir');
$site_template = $settings->get_setting('site_template');
$full_site_dir = $baseDir . $site_template;

// Check if upgrade server functionality is enabled
if (!$settings->get_setting('upgrade_server_active')) {
    http_response_code(403);
    echo json_encode(['error' => 'Upgrade server is not active']);
    exit;
}

// Handle ?list=themes
if (isset($_GET['list']) && $_GET['list'] === 'themes') {
    header('Content-Type: application/json');

    $themes = [];
    $theme_dir = $full_site_dir . '/public_html/theme';

    foreach (glob($theme_dir . '/*/theme.json') as $json_file) {
        $theme_data = json_decode(file_get_contents($json_file), true);
        if ($theme_data) {
            // Only include stock, non-deprecated themes
            $is_stock = $theme_data['is_stock'] ?? true;
            $is_deprecated = !empty($theme_data['deprecated']);
            if ($is_stock && !$is_deprecated) {
                $themes[] = [
                    'name' => $theme_data['name'] ?? basename(dirname($json_file)),
                    'directory_name' => basename(dirname($json_file)),
                    'display_name' => $theme_data['display_name'] ?? $theme_data['displayName'] ?? $theme_data['name'] ?? basename(dirname($json_file)),
                    'version' => $theme_data['version'] ?? '1.0.0',
                    'description' => $theme_data['description'] ?? '',
                    'author' => $theme_data['author'] ?? '',
                    'is_system' => $theme_data['is_system'] ?? $theme_data['system'] ?? false,
                    'is_stock' => true,
                ];
            }
        }
    }

    echo json_encode(['success' => true, 'themes' => $themes]);
    exit;
}

// Handle ?list=plugins
if (isset($_GET['list']) && $_GET['list'] === 'plugins') {
    header('Content-Type: application/json');

    $plugins = [];
    $plugin_dir = $full_site_dir . '/public_html/plugins';

    foreach (glob($plugin_dir . '/*/plugin.json') as $json_file) {
        $plugin_data = json_decode(file_get_contents($json_file), true);
        if ($plugin_data) {
            // Only include stock, non-deprecated plugins
            $is_stock = $plugin_data['is_stock'] ?? true;
            $is_deprecated = !empty($plugin_data['deprecated']);
            if ($is_stock && !$is_deprecated) {
                $plugins[] = [
                    'name' => basename(dirname($json_file)),
                    'directory_name' => basename(dirname($json_file)),
                    'display_name' => $plugin_data['name'] ?? basename(dirname($json_file)),
                    'version' => $plugin_data['version'] ?? '1.0.0',
                    'description' => $plugin_data['description'] ?? '',
                    'author' => $plugin_data['author'] ?? '',
                    'is_system' => $plugin_data['is_system'] ?? $plugin_data['system'] ?? false,
                    'is_stock' => true,
                ];
            }
        }
    }

    echo json_encode(['success' => true, 'plugins' => $plugins]);
    exit;
}

// Handle ?download=name (theme download)
if (isset($_GET['download'])) {
    $item_name = basename($_GET['download']); // Sanitize
    $type = $_GET['type'] ?? 'theme';

    if ($type === 'plugin') {
        $archive_dir = $full_site_dir . '/static_files/plugins';
        $source_dir = $full_site_dir . '/public_html/plugins/' . $item_name;
        $manifest_file = $source_dir . '/plugin.json';
    } else {
        $archive_dir = $full_site_dir . '/static_files/themes';
        $source_dir = $full_site_dir . '/public_html/theme/' . $item_name;
        $manifest_file = $source_dir . '/theme.json';
    }

    // Verify item exists
    if (!is_dir($source_dir) || !file_exists($manifest_file)) {
        http_response_code(404);
        echo json_encode(['error' => ucfirst($type) . ' not found: ' . $item_name]);
        exit;
    }

    // Get version from manifest
    $manifest = json_decode(file_get_contents($manifest_file), true);
    $version = $manifest['version'] ?? '1.0.0';

    // Archive filename
    $archive_filename = $item_name . '-' . $version . '.tar.gz';
    $archive_path = $archive_dir . '/' . $archive_filename;

    // Check if archive exists and is newer than source
    $need_regenerate = false;
    if (!file_exists($archive_path)) {
        $need_regenerate = true;
    } else {
        // Check if source is newer than archive
        $archive_time = filemtime($archive_path);
        $source_time = get_newest_file_time($source_dir);
        if ($source_time > $archive_time) {
            $need_regenerate = true;
        }
    }

    // Generate archive if needed
    if ($need_regenerate) {
        // Ensure archive directory exists
        if (!is_dir($archive_dir)) {
            mkdir($archive_dir, 0755, true);
        }

        // Create tar.gz with just the item directory
        $parent_dir = dirname($source_dir);
        $tar_cmd = sprintf(
            'tar -czf %s -C %s %s 2>&1',
            escapeshellarg($archive_path),
            escapeshellarg($parent_dir),
            escapeshellarg($item_name)
        );

        $output = [];
        $exit_code = 0;
        exec($tar_cmd, $output, $exit_code);

        if ($exit_code !== 0 || !file_exists($archive_path)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create archive', 'details' => implode("\n", $output)]);
            exit;
        }
    }

    // Serve the archive
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $archive_filename . '"');
    header('Content-Length: ' . filesize($archive_path));
    header('Cache-Control: no-cache, must-revalidate');

    while (ob_get_level()) {
        ob_end_clean();
    }
    readfile($archive_path);
    exit;
}

// Handle ?core - redirect to core archive
if (isset($_GET['core'])) {
    require_once(PathHelper::getIncludePath('data/upgrades_class.php'));

    // Get latest upgrade
    $upgrades = new MultiUpgrade([], ['upgrade_id' => 'DESC'], 1);
    $upgrades->load();

    if ($upgrades->count() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'No core archive available']);
        exit;
    }

    $upgrade = $upgrades->get(0);
    $version = $upgrade->get('upg_major_version') . '.' . $upgrade->get('upg_minor_version');

    // Check for core-only archive first
    $core_filename = 'joinery-core-' . $version . '.tar.gz';
    $core_path = $full_site_dir . '/static_files/' . $core_filename;

    if (file_exists($core_path)) {
        $location = LibraryFunctions::get_absolute_url('/static_files/' . $core_filename);
    } else {
        // Fall back to full archive if core-only doesn't exist
        $full_filename = $upgrade->get('upg_name');
        $location = LibraryFunctions::get_absolute_url('/static_files/' . $full_filename);
    }

    header('Location: ' . $location);
    exit;
}

// Invalid request
http_response_code(400);
echo json_encode([
    'error' => 'Invalid request',
    'usage' => [
        '?list=themes' => 'List available themes',
        '?list=plugins' => 'List available plugins',
        '?download=name' => 'Download a theme archive',
        '?download=name&type=plugin' => 'Download a plugin archive',
        '?core' => 'Redirect to core archive',
    ]
]);

/**
 * Get the newest modification time of any file in a directory (recursive)
 */
function get_newest_file_time($dir) {
    $newest = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $mtime = $file->getMTime();
            if ($mtime > $newest) {
                $newest = $mtime;
            }
        }
    }

    return $newest;
}
