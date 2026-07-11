<?php
	/**
	 * Theme + plugin sync runner — exec'd by utils/upgrade.php after deployment.
	 *
	 * Runs in its OWN process for the same reason update_database.php does:
	 * the upgrade process pre-loads model classes from the PRE-swap tree
	 * (SessionControl → cart → data classes). When a class file moves between
	 * core and a plugin across versions, the in-process sync would load the
	 * same class from its new path and fatal with "Cannot declare class".
	 * A clean process sees only the post-swap file layout.
	 *
	 * Usage (CLI only):
	 *   php utils/sync_extensions.php [--themes=a,b,c] [--plugins=x,y,z]
	 *
	 * --themes / --plugins carry the source's published-archive manifest names
	 * for stale reconciliation (see specs/upgrade_pipeline_rename_gap.md);
	 * omit them to sync without stale marking.
	 *
	 * Output: human-readable progress lines, then a final line of JSON:
	 *   SYNC_RESULT: {"themes": {...}, "plugins": {...}}
	 * Exit code 0 on success, 1 on failure.
	 *
	 * @version 1.0.0
	 */
	set_time_limit(1800);

	if (php_sapi_name() !== 'cli') {
		http_response_code(403);
		echo 'CLI only.';
		exit(1);
	}

	require_once(__DIR__ . '/../includes/PathHelper.php');
	require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
	require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/ThemeManager.php'));
	require_once(PathHelper::getIncludePath('includes/PluginManager.php'));

	$options = getopt('', ['themes::', 'plugins::']);
	$parse_list = function ($value) {
		if (!is_string($value) || trim($value) === '') {
			return null;
		}
		return array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
	};
	$theme_manifest = $parse_list($options['themes'] ?? null);
	$plugin_manifest = $parse_list($options['plugins'] ?? null);

	try {
		$theme_manager = ThemeManager::getInstance();
		$theme_result = $theme_manager->sync(
			$theme_manifest !== null ? ['source_manifest' => $theme_manifest] : []
		);

		$plugin_manager = PluginManager::getInstance();
		$plugin_result = $plugin_manager->sync(
			$plugin_manifest !== null ? ['source_manifest' => $plugin_manifest] : []
		);

		echo 'SYNC_RESULT: ' . json_encode([
			'themes' => [
				'added' => count($theme_result['added'] ?? []),
				'updated' => count($theme_result['updated'] ?? []),
				'stale_marked' => (int)($theme_result['stale_marked'] ?? 0),
			],
			'plugins' => [
				'added' => count($plugin_result['added'] ?? []),
				'updated' => count($plugin_result['updated'] ?? []),
				'stale_marked' => (int)($plugin_result['stale_marked'] ?? 0),
				'table_messages' => array_values($plugin_result['table_messages'] ?? []),
				'migration_messages' => array_values($plugin_result['migration_messages'] ?? []),
			],
		]) . "\n";
		exit(0);
	} catch (Throwable $e) {
		echo 'SYNC_ERROR: ' . $e->getMessage() . "\n";
		exit(1);
	}
?>
