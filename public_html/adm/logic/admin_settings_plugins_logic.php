<?php
/**
 * Plugin Settings tab.
 *
 * Each plugin section on the page is an independent form, so a save carries
 * exactly one plugin's fields and names that plugin in `plugin_settings_target`.
 * The write scope is that one plugin's declared settings — nothing else in the
 * POST is written, so a crafted post cannot reach a core or sibling-plugin row.
 *
 * @version 2.1
 * @changelog 2.1 - One plugin per view: ?plugin= selects a subtab and a save returns to the plugin it wrote
 * @changelog 2.0 - Saves through SettingsWriter, so scope, validation, credential handling and the vault gate are the same rules every other settings page enforces
 */
function admin_settings_plugins_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/settings_class.php'));
	require_once(PathHelper::getIncludePath('includes/SettingsDeclarations.php'));
	require_once(PathHelper::getIncludePath('includes/SettingsWriter.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	// A plugin contributes a section because it declares settings. A
	// deactivated plugin's stored rows persist untouched but are neither shown
	// nor writable here.
	$plugin_sources = SettingsDeclarations::renderableSources();

	// One plugin shows at a time, chosen by its subtab (?plugin=). An unknown
	// or absent name falls back to the first plugin rather than erroring, so a
	// stale link still lands somewhere useful.
	$selected = isset($input['plugin']) ? trim((string)$input['plugin']) : '';
	if (!in_array($selected, $plugin_sources, true)) {
		$selected = $plugin_sources[0] ?? null;
	}

	// Only run the save handler on an actual form POST — $input is never empty
	// on a GET. See LibraryFunctions::isFormSubmission().
	if (!LibraryFunctions::isFormSubmission()) {
		return LogicResult::render(array(
			'plugin_sources'  => $plugin_sources,
			'selected_plugin' => $selected,
		));
	}

	$target = isset($input['plugin_settings_target'])
		? trim((string)$input['plugin_settings_target'])
		: '';
	if ($target === '' || !in_array($target, $plugin_sources, true)) {
		return LogicResult::error(
			'That save named a plugin with no settings on this site.',
			array('plugin_sources' => $plugin_sources, 'selected_plugin' => $selected)
		);
	}

	// One save path for every settings page. The scope is narrowed to the
	// submitting plugin, so a crafted post still cannot reach a core or
	// sibling-plugin row. See includes/SettingsWriter.php.
	$write = SettingsWriter::write($input, array(
		'page'   => 'admin_settings_plugins:' . $target,
		'source' => $target,
	));
	SettingsWriter::reportTo($write, '~/admin/admin_settings_plugins~');

	if (!empty($write['errors'])) {
		$lines = array();
		foreach ($write['errors'] as $name => $messages) {
			$lines[] = $name . ': ' . implode(' ', (array)$messages);
		}
		return LogicResult::error(
			'Nothing was saved: ' . implode(' | ', $lines),
			array('plugin_sources' => $plugin_sources, 'selected_plugin' => $target)
		);
	}

	return LogicResult::redirect('/admin/admin_settings_plugins?plugin=' . urlencode($target));
}

?>
