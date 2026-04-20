<?php
/**
 * GET /api/v1/management/version
 *
 * Detailed version info — system version, schema version, plugin versions.
 */

function version_handler_api() {
	return [
		'method'      => 'GET',
		'description' => 'Joinery system version, schema version, and per-plugin versions.',
	];
}

function version_handler($request) {
	$result = [
		'system_version'  => LibraryFunctions::get_joinery_version(),
		'schema_version'  => null,
		'plugin_versions' => [],
	];

	try {
		$settings = Globalvars::get_instance();
		$sv = trim((string)($settings->get_setting('schema_version', true, true) ?? ''));
		if ($sv !== '') {
			$result['schema_version'] = $sv;
		}
	} catch (Throwable $e) {}

	// Plugin versions — version comes from each plugin's plugin.json via get_version().
	try {
		require_once(PathHelper::getIncludePath('data/plugins_class.php'));
		$plugins = new MultiPlugin();
		$plugins->load();
		foreach ($plugins as $p) {
			$name = $p->get('plg_name');
			if ($name) {
				$result['plugin_versions'][$name] = $p->get_version();
			}
		}
	} catch (Throwable $e) {
		// Table may not exist on older nodes — leave empty.
	}

	return $result;
}
?>
