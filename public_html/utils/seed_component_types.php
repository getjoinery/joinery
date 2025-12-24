<?php
/**
 * Component Type Seeder
 *
 * Seeds the com_components table from definition files.
 * Run this script after installing the system or adding new component types.
 *
 * Usage:
 *   php /var/www/html/joinerytest/public_html/utils/seed_component_types.php
 *
 * @see /specs/page_component_system.md
 */

// Bootstrap the application
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('data/components_class.php'));

echo "Component Type Seeder\n";
echo "=====================\n\n";

// Directories to scan for component definitions
$definition_dirs = [
	PathHelper::getIncludePath('views/components/definitions'),
];

// Also scan theme-specific definitions
$settings = Globalvars::get_instance();
$theme = $settings->get_setting('theme') ?: 'falcon';
$theme_def_dir = PathHelper::getIncludePath("theme/{$theme}/views/components/definitions");
if (is_dir($theme_def_dir)) {
	$definition_dirs[] = $theme_def_dir;
}

// Track stats
$created = 0;
$updated = 0;
$skipped = 0;
$errors = 0;

foreach ($definition_dirs as $dir) {
	if (!is_dir($dir)) {
		echo "Skipping non-existent directory: {$dir}\n";
		continue;
	}

	echo "Scanning: {$dir}\n";

	$files = glob($dir . '/*.php');
	foreach ($files as $file) {
		$filename = basename($file, '.php');
		echo "  Processing: {$filename}... ";

		try {
			// Load the definition
			$definition = require($file);

			if (!is_array($definition) || empty($definition['type_key'])) {
				echo "SKIPPED (invalid definition)\n";
				$skipped++;
				continue;
			}

			$type_key = $definition['type_key'];

			// Check if component type already exists
			$existing = Component::get_by_type_key($type_key);

			if ($existing) {
				// Update existing
				$component = $existing;
				$action = 'UPDATED';
				$updated++;
			} else {
				// Create new
				$component = new Component(NULL);
				$action = 'CREATED';
				$created++;
			}

			// Set all fields from definition
			$component->set('com_type_key', $definition['type_key']);
			$component->set('com_title', $definition['title'] ?? $definition['type_key']);
			$component->set('com_description', $definition['description'] ?? null);
			$component->set('com_category', $definition['category'] ?? null);
			$component->set('com_icon', $definition['icon'] ?? null);
			$component->set('com_template_file', $definition['template_file'] ?? null);

			// JSON encode the config schema if it's an array
			$config_schema = $definition['config_schema'] ?? null;
			if (is_array($config_schema)) {
				$config_schema = json_encode($config_schema);
			}
			$component->set('com_config_schema', $config_schema);

			$component->set('com_logic_function', $definition['logic_function'] ?? null);
			$component->set('com_requires_plugin', $definition['requires_plugin'] ?? null);
			$component->set('com_is_active', true);

			$component->prepare();
			$component->save();

			echo "{$action}\n";

		} catch (Exception $e) {
			echo "ERROR: " . $e->getMessage() . "\n";
			$errors++;
		}
	}
}

echo "\n";
echo "Summary:\n";
echo "  Created: {$created}\n";
echo "  Updated: {$updated}\n";
echo "  Skipped: {$skipped}\n";
echo "  Errors:  {$errors}\n";
echo "\nDone.\n";
