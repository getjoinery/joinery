<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

/**
 * Logic for the relay fleet console (operator side).
 *
 * The control panel for running a shared relay service other deployments
 * enroll in: service on/off + MX zone, shard registration/provisioning, and
 * the DNS the fleet zone needs. Operator infrastructure, so it lives off the
 * Server Manager dashboard — tenant relay surfaces (Setup/Settings tabs)
 * never show it. Machinery is shared from includes/relay_admin.php.
 *
 * @version 1.0
 */
function admin_mailbox_fleet_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/relay_admin.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	// Running a fleet needs server_manager (shards are managed nodes and
	// provisioning runs as its jobs); without it there is no console.
	if (!PluginHelper::isPluginActive('server_manager')) {
		return LogicResult::redirect('/admin/server_manager');
	}

	$self_url = '/plugins/mailbox/admin/admin_mailbox_fleet';
	$redirect = admin_mailbox_relay_operator_actions($input, $session, $self_url);
	if ($redirect !== null) {
		return $redirect;
	}

	$vars = admin_mailbox_relay_operator_vars();
	$vars['session'] = $session;
	return LogicResult::render($vars);
}
?>
