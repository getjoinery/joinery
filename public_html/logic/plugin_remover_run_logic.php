<?php
/**
 * plugin_remover_run — the Plugins page's Run removal again: queue an
 * uninstalled plugin's host remover to run now, as root, and its files to go
 * after it.
 *
 * It queues a run_plugin_remover root request and returns its id, which the
 * page shows in the root-request panel. Root reads only the plugin's name from
 * the request, asks the same checks remove_plugin asks before anything runs,
 * and runs the declared remover under the installer's trust and signature
 * checks (specs/plugin_host_removal.md D6, D7). A second press while one is
 * queued or running returns that one's id. The remover is idempotent, so a
 * retry after the operator fixed the cause finishes the job.
 *
 * @version 1.0
 */
function plugin_remover_run_logic(array $input): LogicResult {
	$name = (string)($input['plugin'] ?? '');
	if (preg_match('/^[a-z][a-z0-9_]{1,49}$/', $name) !== 1) {
		return LogicResult::error('That is not a plugin name.');
	}
	$plugin = Plugin::get_by_plugin_name($name);
	if (!$plugin || !$plugin->is_uninstalled()) {
		return LogicResult::error("The plugin {$name} is not uninstalled here; its removal runs at its uninstall.");
	}
	$plugins_dir = PathHelper::getAbsolutePath('plugins');
	if (!is_dir($plugins_dir . '/' . $name)) {
		return LogicResult::error("The plugin {$name} has no files left here; there is nothing to remove.");
	}
	if (PluginRemoval::declared_remover($name, $plugins_dir) === '') {
		return LogicResult::error("The plugin {$name} declares no host remover; its files go with the remove_plugin request its uninstall queued.");
	}

	$session = SessionControl::get_instance();
	try {
		$id = RootRequest::submit('run_plugin_remover', array('plugin' => $name), (int)$session->get_user_id());
	} catch (\Throwable $e) {
		return LogicResult::error('Could not queue the removal: ' . $e->getMessage());
	}
	// The row names the request the page shows, whichever kind it is.
	PluginManager::getInstance()->recordRemoveRequest($plugin, $id);

	return LogicResult::render(array(
		'request_id'    => $id,
		'actor_warning' => RootRequest::actorWarning(),
	));
}

function plugin_remover_run_logic_descriptor(): array {
	return [
		'description' => 'Run an uninstalled plugin\'s host remover again now, as a root request, and remove its files after it.',
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
