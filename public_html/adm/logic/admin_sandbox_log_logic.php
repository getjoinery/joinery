<?php
/**
 * Logic for a sandboxed plugin's call log (plugin_tiers.md WP8): what
 * the plugin was told and asked, what it called through each door, and what
 * was refused and why, newest first, from SandboxLog.
 *
 * @version 1.0
 */

function admin_sandbox_log_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->check_permission(SandboxPluginManager::ADMIN_PERMISSION);
	$session->set_return();

	$plugin = (string)($input['plugin'] ?? '');
	if (!preg_match(SandboxManifest::NAME_PATTERN, $plugin) || !Plugin::get_by_plugin_name($plugin)) {
		return LogicResult::error('That is not a sandboxed plugin on this site.');
	}
	$title = $plugin;
	try {
		$title = SandboxPlugins::manifest($plugin)->title();
	} catch (SandboxManifestException $e) {
	}

	$filters = array('plugin' => $plugin);
	$kinds = array(SandboxLog::KIND_UI, SandboxLog::KIND_SIGNAL, SandboxLog::KIND_SCHEDULE, SandboxLog::KIND_TEST);
	$statuses = array(SandboxLog::STATUS_OK, SandboxLog::STATUS_ERROR, SandboxLog::STATUS_TIMEOUT, SandboxLog::STATUS_UNAVAILABLE, SandboxLog::STATUS_BUSY);
	if (in_array($input['kind'] ?? '', $kinds, true)) {
		$filters['kind'] = $input['kind'];
	}
	if (in_array($input['status'] ?? '', $statuses, true)) {
		$filters['status'] = $input['status'];
	}
	$page = max(0, (int)($input['page'] ?? 0));
	$per_page = 50;
	$logs = new MultiSandboxLog($filters, array('sbl_sandbox_log_id' => 'DESC'), $per_page, $page * $per_page);
	$total = (new MultiSandboxLog($filters))->count_all();

	return LogicResult::render(array(
		'session'  => $session,
		'plugin'   => $plugin,
		'title'    => $title,
		'logs'     => $logs,
		'total'    => $total,
		'page'     => $page,
		'per_page' => $per_page,
		'kinds'    => $kinds,
		'statuses' => $statuses,
		'filters'  => $filters,
		'here'     => '/admin/admin_sandbox_log?plugin=' . rawurlencode($plugin),
	));
}
