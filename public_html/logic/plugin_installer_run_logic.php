<?php
/**
 * plugin_installer_run — the Plugins page's Run setup again: queue one active
 * plugin's host installer to run now, as root.
 *
 * It queues a run_plugin_installer root request and returns its id, which the
 * page shows in the root-request panel. Root reads only the plugin's name from
 * the request and runs nothing the daily pass would not
 * (plugin_installer_from_the_browser.md D1, D4). A second press while one
 * is queued or running returns that one's id.
 *
 * @version 1.0
 */
function plugin_installer_run_logic(array $input): LogicResult {
	$name = (string)($input['plugin'] ?? '');
	if (preg_match('/^[a-z][a-z0-9_]{1,49}$/', $name) !== 1) {
		return LogicResult::error('That is not a plugin name.');
	}
	$plugin = Plugin::get_by_plugin_name($name);
	if (!$plugin || !$plugin->is_active()) {
		return LogicResult::error("The plugin {$name} is not active here.");
	}
	if (!PluginProvisioning::hasHostInstaller($name)) {
		return LogicResult::error("The plugin {$name} has no setup to run on this server.");
	}

	$session = SessionControl::get_instance();
	try {
		$id = RootRequest::submit('run_plugin_installer', array('plugin' => $name), (int)$session->get_user_id());
	} catch (\Throwable $e) {
		return LogicResult::error('Could not queue the setup run: ' . $e->getMessage());
	}

	return LogicResult::render(array(
		'request_id'    => $id,
		'actor_warning' => RootRequest::actorWarning(),
	));
}

function plugin_installer_run_logic_descriptor(): array {
	return [
		'description' => 'Run an active plugin\'s server setup (its host installer) again now, as a root request.',
		'mutates'     => true,
		'requires_session' => true,
		'auth'        => [
			'requires_browser_session' => true,
			'min_user_permission'      => 10,
		],
		'input'       => [
			'plugin' => ['type' => 'string', 'required' => true, 'label' => 'Plugin name'],
		],
	];
}
