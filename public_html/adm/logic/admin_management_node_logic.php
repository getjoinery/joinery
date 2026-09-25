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
 * @version 1.5 - admin_management_node_cli_join_request(): a CLI join naming the URL of a request not yet
 *                answered keeps that request, so the agent keeps its staged key; the page's Connect
 *                stays a fresh ask
 * @version 1.4 - disconnecting also removes the manager backup profile's object-store marker, so
 *                offloaded files stop waiting for a management node's backup that will not come
 * @version 1.3 - the log-access switch (agent_log_access, specs/agent_log_access.md): whether a connected
 *                management node may read this site's redacted log excerpts; on by default, and the
 *                one-time notice about it is acknowledged here (a POST, never a write on a page view)
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

	// Whether a connected management node may read this site's logs. The
	// agent reads the same setting before every log word and refuses when it
	// is off; the switch is the owner's, not the plane's. Either way it is
	// flipped counts as having seen the notice about it.
	if (isset($input['action']) && $input['action'] === 'log_access_on') {
		Setting::put('agent_log_access', '1');
		Setting::put('agent_log_access_notice_seen', '1');
		return LogicResult::redirect('/admin/admin_management_node?logs=on');
	}

	if (isset($input['action']) && $input['action'] === 'log_access_off') {
		Setting::put('agent_log_access', '');
		Setting::put('agent_log_access_notice_seen', '1');
		return LogicResult::redirect('/admin/admin_management_node?logs=off');
	}

	// The one-time notice's acknowledgement: a POST from the notice itself or
	// from this page, never a write made because somebody viewed a page.
	if (isset($input['action']) && $input['action'] === 'log_access_notice_seen') {
		Setting::put('agent_log_access_notice_seen', '1');
		return LogicResult::redirect('/admin/admin_management_node');
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
		// The management node's backups stop with the connection, so its
		// profile must stop holding this site's offloaded files on disk.
		try {
			BackupObjects::clear_enabled(BackupRunner::output_dir());
		} catch (\Throwable $e) {
			error_log('admin_management_node: could not clear the manager object-store marker: ' . $e->getMessage());
		}
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
		'log_access'      => admin_management_node_log_access($settings),
		'log_access_switched' => isset($input['logs']) ? (string)$input['logs'] : '',
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
 * May a connected management node read this site's logs? Same spellings as
 * the agent switch, because the agent reads this value with the same reader
 * it reads agent_enabled with (quiet.go switchOn), and one setting read two
 * ways is how a page and a machine come to disagree.
 */
function admin_management_node_log_access($settings): bool {
	return admin_management_node_agent_switch_on((string)$settings->get_setting('agent_log_access'));
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
/**
 * The join request `utils/agent_control.php --join` records, or null to keep
 * the one already there.
 *
 * The agent treats a newer requested_time as a new ask: it withdraws, drops
 * the keypair it staged and asks again with a new one, orphaning the request
 * waiting on the management node. `install.sh site` passes --join on every run,
 * so rebuilding a site before its join was approved changed its key. A request
 * for the same URL that is still recorded has not been answered (the agent
 * clears it on approval and on rejection), and the same ask again is that
 * request. A different URL, or no request, is a fresh ask.
 *
 * The page's own Connect does not use this: an operator asking again means it.
 */
function admin_management_node_cli_join_request(string $current, string $url, string $now): ?string {
	$url = rtrim(trim($url), '/');
	$existing = json_decode($current, true);
	if (is_array($existing)
		&& rtrim(trim((string)($existing['url'] ?? '')), '/') === $url
		&& trim((string)($existing['requested_time'] ?? '')) !== '') {
		return null;
	}
	return json_encode([
		'url'            => $url,
		'requested_time' => $now,
	]);
}

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
