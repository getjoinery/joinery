<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

/**
 * Management Node page logic — connect this machine's agent to a management
 * node (specs/agent_on_node_architecture.md Phase 1.5, decision A6).
 *
 * The web tier's entire role here is a handoff: it records WHICH management
 * node the admin asked to join (a URL — not a secret) in the managed setting
 * agent_join_request. The root agent on this machine notices that request,
 * generates its own keypair, sends the join, and reports progress back in
 * agent_join_state, which this page renders. No credential ever exists in the
 * web tier, and nothing this page stores could enroll anyone.
 *
 * @version 1.2 - the agent's own on/off switch (agent_enabled) lives here too: nothing on this page
 *                can happen on a machine that runs no agent, so it is the first thing decided
 * @version 1.1 - disconnect action: the node ends the connection from its own side by recording a
 *                leave request the agent honours (one signed goodbye, then it deletes its identity)
 * @version 1.0
 */
function admin_management_node_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/settings_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);
	$session->set_return();

	$settings = Globalvars::get_instance();

	// Whether this machine runs an agent at all. Recorded here; acted on by the
	// installer at the next root moment, because a web request has no root and
	// cannot install a service. The page says so rather than implying the
	// switch took effect the moment it was flipped.
	if (isset($input['action']) && $input['action'] === 'enable_agent') {
		Setting::put('agent_enabled', '1');
		return LogicResult::redirect('/admin/admin_management_node?agent=on');
	}

	if (isset($input['action']) && $input['action'] === 'disable_agent') {
		Setting::put('agent_enabled', '');
		return LogicResult::redirect('/admin/admin_management_node?agent=off');
	}

	if (isset($input['action']) && $input['action'] === 'connect') {
		$url = trim((string)($input['management_node_url'] ?? ''));
		$refusal = admin_management_node_url_refusal($url);
		if ($refusal !== null) {
			return LogicResult::redirect('/admin/admin_management_node?error=' . urlencode($refusal));
		}
		$url = rtrim($url, '/');
		Setting::put('agent_join_request', json_encode([
			'url'            => $url,
			'requested_time' => gmdate('Y-m-d H:i:s'),
		]));
		// A fresh ask supersedes whatever an earlier attempt reported.
		Setting::put('agent_join_state', '');
		return LogicResult::redirect('/admin/admin_management_node?requested=1');
	}

	if (isset($input['action']) && $input['action'] === 'cancel') {
		Setting::put('agent_join_request', '');
		Setting::put('agent_join_state', '');
		return LogicResult::redirect('/admin/admin_management_node?cancelled=1');
	}

	// Ending the connection is the same credential-free handoff as starting
	// it: the web tier records only that the admin asked. The agent finishes
	// any job it is running, sends one signed goodbye so the management node
	// forgets this machine's key immediately, then deletes its own identity —
	// and does all of that whether or not the management node is reachable.
	if (isset($input['action']) && $input['action'] === 'disconnect') {
		Setting::put('agent_leave_request', json_encode([
			'requested_time' => gmdate('Y-m-d H:i:s'),
		]));
		return LogicResult::redirect('/admin/admin_management_node');
	}

	if (isset($input['action']) && $input['action'] === 'cancel_disconnect') {
		Setting::put('agent_leave_request', '');
		return LogicResult::redirect('/admin/admin_management_node');
	}

	$request = json_decode((string)$settings->get_setting('agent_join_request'), true);
	$state   = json_decode((string)$settings->get_setting('agent_join_state'), true);
	$leave   = json_decode((string)$settings->get_setting('agent_leave_request'), true);

	return LogicResult::render([
		'session'         => $session,
		'request'         => is_array($request) ? $request : null,
		'state'           => is_array($state) ? $state : null,
		'leave_request'   => is_array($leave) ? $leave : null,
		'agent_enabled'   => admin_management_node_agent_enabled($settings),
		'agent_installed' => file_exists(ADMIN_MANAGEMENT_NODE_AGENT_BINARY),
		'installer_hint'  => admin_management_node_installer_hint(),
		'error'           => isset($input['error']) ? (string)$input['error'] : '',
		'requested'       => !empty($input['requested']),
		'cancelled'       => !empty($input['cancelled']),
		'agent_switched'  => isset($input['agent']) ? (string)$input['agent'] : '',
	]);
}

/**
 * Where the agent lands on every platform that runs one. The page reports
 * whether the switch has actually been acted on, and this file is the only
 * thing that knows the path.
 */
define('ADMIN_MANAGEMENT_NODE_AGENT_BINARY', '/usr/local/bin/joinery-agent');

/**
 * Is the agent switched on for this machine?
 */
function admin_management_node_agent_enabled($settings): bool {
	return admin_management_node_agent_switch_on((string)$settings->get_setting('agent_enabled'));
}

/**
 * Does a stored agent_enabled value mean on? Split from the reader above so a
 * caller holding the raw string — the CLI, which writes and then reports, and
 * cannot use a settings instance cached before its own write — asks the same
 * question the page does.
 *
 * The accepted spellings match install_agent.sh's, so a value written by
 * either side is read the same by both.
 */
function admin_management_node_agent_switch_on(string $value): bool {
	return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * The command that installs the agent on this machine, for an admin who does
 * not want to wait for the next container start or upgrade to reach it.
 *
 * A web request cannot run it — that is the whole reason the installer is a
 * root-moment script — so the page prints it rather than offering a button
 * that could not work.
 */
function admin_management_node_installer_hint(): string {
	$public_html = rtrim(PathHelper::getIncludePath(''), '/');
	$site_root   = dirname($public_html);
	$sitename    = basename($site_root);

	return 'sudo bash ' . $site_root . '/maintenance_scripts/install_tools/install_agent.sh ' . $sitename;
}

/**
 * Why a management-node URL is unacceptable, or null when it is fine.
 * Pure so the boundary is testable without a session.
 */
function admin_management_node_url_refusal(string $url): ?string {
	if ($url === '') {
		return 'Enter the management node\'s URL.';
	}
	if (strlen($url) > 255) {
		return 'That URL is longer than the 255-character limit.';
	}
	$parts = parse_url($url);
	if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
		return 'That is not a full URL. Enter it as https://example.com';
	}
	if (!in_array(strtolower($parts['scheme']), ['https', 'http'], true)) {
		return 'The URL must start with https:// (or http:// for a private test network).';
	}
	if (!empty($parts['path']) && rtrim($parts['path'], '/') !== '') {
		return 'Enter just the management node\'s address, without a path — the agent knows where to knock.';
	}
	if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])) {
		return 'Enter just the management node\'s address — no query, fragment, or credentials.';
	}
	return null;
}
