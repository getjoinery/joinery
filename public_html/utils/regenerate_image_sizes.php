<?php
/**
 * Regenerate Image Sizes Utility
 *
 * Iterates all image files and generates resized versions for all
 * registered sizes from ImageSizeRegistry. Safe to run multiple times.
 *
 * Usage: php utils/regenerate_image_sizes.php
 * Or via browser: https://yoursite.com/admin/regenerate_image_sizes
 *
 * @version 1.0.0
 */

// Bootstrap the application
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
$settings = Globalvars::get_instance();

require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));

// Check if running from CLI or web
$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
	// Web mode - require admin login
	$session = SessionControl::get_instance();
	if (!$session->is_logged_in() || $session->get_permission() < 5) {
		http_response_code(403);
		echo "Permission denied. Admin access required.";
		exit;
	}
	echo "<pre>";
}

function output($message, $is_cli = false) {
	echo $message . ($is_cli ? "\n" : "<br>\n");
	if (!$is_cli) {
		ob_flush();
		flush();
	}
}

output("=== Image Size Regeneration Utility ===", $is_cli);
output("", $is_cli);

// Show registered sizes
$sizes = ImageSizeRegistry::get_sizes();
output("Registered sizes:", $is_cli);
foreach ($sizes as $key => $config) {
	$crop_text = $config['crop'] ? 'crop' : 'fit';
	output("  - {$key}: {$config['width']}x{$config['height']} ({$crop_text}, quality: {$config['quality']})", $is_cli);
}
output("", $is_cli);

// Ensure directories exist
$upload_dir = $settings->get_setting('upload_dir');
foreach ($sizes as $key => $config) {
	$dir_path = $upload_dir . '/' . $key;
	if (!is_dir($dir_path)) {
		if (mkdir($dir_path, 0777, true)) {
			chmod($dir_path, 0777);
			output("Created directory: {$key}/", $is_cli);
		} else {
			output("ERROR: Failed to create directory: {$dir_path}", $is_cli);
		}
	}
}

// Get all image files
$files = new MultiFile(
	['picture' => true, 'deleted' => false],
	['fil_file_id' => 'ASC']
);
$total = $files->count_all();
output("Found {$total} image files to process.", $is_cli);
output("", $is_cli);

if ($total == 0) {
	output("No images to process. Done.", $is_cli);
	exit;
}

$processed = 0;
$errors = 0;

// Process in batches to avoid memory issues
$batch_size = 50;
$offset = 0;

while ($offset < $total) {
	$batch = new MultiFile(
		['picture' => true, 'deleted' => false],
		['fil_file_id' => 'ASC'],
		$batch_size,
		$offset
	);
	$batch->load();

	foreach ($batch as $file) {
		$processed++;
		$file_name = $file->get('fil_name');
		$source_path = $upload_dir . '/' . $file_name;

		if (!file_exists($source_path)) {
			output("[{$processed}/{$total}] SKIP - File not on disk: {$file_name}", $is_cli);
			$errors++;
			continue;
		}

		output("[{$processed}/{$total}] Processing: {$file_name}", $is_cli);

		try {
			$file->resize('all');
		} catch (Exception $e) {
			output("  ERROR: " . $e->getMessage(), $is_cli);
			$errors++;
		}
	}

	$offset += $batch_size;
}

output("", $is_cli);
output("=== Complete ===", $is_cli);
output("Processed: {$processed} files", $is_cli);
output("Errors: {$errors}", $is_cli);

if (!$is_cli) {
	echo "</pre>";
}
