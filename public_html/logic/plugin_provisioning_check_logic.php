<?php
/**
 * plugin_provisioning_check — run every active plugin's provisioning checks.
 *
 * Read-only, staff-only (floor 5). Returns {plugins: {...}} — the admin Plugins
 * page fires this after render so a slow check never blocks the page — plus,
 * for each active plugin with a host installer, what its last install run did
 * (installer_status: PluginInstallerStatus::forPlugin, null when it has not
 * run) and the machine's root actor (actor_state).
 *
 * @version 1.1.0 - installer_status and actor_state (plugin_installer_from_the_browser.md D4)
 * @version 1.0.0
 */

function plugin_provisioning_check_logic(array $input): LogicResult {
	try {
		$installer_status = [];
		foreach (array_keys(PluginHelper::getActivePlugins()) as $name) {
			if (PluginProvisioning::hasHostInstaller($name)) {
				$installer_status[$name] = PluginInstallerStatus::forPlugin($name);
			}
		}
		return LogicResult::render([
			'plugins'          => PluginProvisioning::runChecks(),
			'installer_status' => $installer_status,
			'actor_state'      => RootRequest::actorState(),
		]);
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
			'min_user_permission' => 8,
		],
		'input'       => [],
	];
}
?>
