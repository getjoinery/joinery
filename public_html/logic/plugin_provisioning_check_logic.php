<?php
/**
 * plugin_provisioning_check — run every active plugin's provisioning checks.
 *
 * Read-only, staff-only (floor 5). Returns {plugins: {...}} — the admin Plugins
 * page fires this after render so a slow check never blocks the page.
 *
 * @version 1.0.0
 */

function plugin_provisioning_check_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/PluginProvisioning.php'));

	try {
		return LogicResult::render(['plugins' => PluginProvisioning::runChecks()]);
	} catch (\Throwable $e) {
		return LogicResult::error($e->getMessage());
	}
}

function plugin_provisioning_check_logic_descriptor(): array {
	return [
		'description' => 'Run every active plugin\'s provisioning checks and return the results.',
		'mutates'     => false,
		'auth'        => [
			'capability'          => 'read',
			'min_user_permission' => 5,
		],
		'input'       => [],
	];
}
?>
