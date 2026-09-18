<?php
/** @joinery-test
 * name: agent_log_access
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The node side of specs/agent_log_access.md: the owner's switch and the
 * one-time notice.
 *
 * The agent reads agent_log_access with the same reader it reads agent_enabled
 * with, so the two settings must be declared the same way; the switch is on by
 * default by the owner's decision (2026-09-17); and the notice is due under
 * exactly three stored facts and silent otherwise. The notice's acknowledgement
 * is a POST: nothing here writes on a page view.
 *
 * Runs offline, no DB.
 * Run: php tests/unit/agent_log_access_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('adm/logic/admin_management_node_logic.php'));

// ---------------------------------------------------------------------------
section('A. The switch and its notice flag are declared managed, on by default');

$declared = json_decode(file_get_contents(PathHelper::getIncludePath('settings.json')), true);
$by_name = array();
foreach ($declared['settings'] as $s) { $by_name[$s['name']] = $s; }

check(isset($by_name['agent_log_access']), 'agent_log_access is declared in settings.json');
check(($by_name['agent_log_access']['default'] ?? null) === '1', 'agent_log_access defaults to on (the owner decided on by default, because the node redacts)');
check(!empty($by_name['agent_log_access']['managed']), 'agent_log_access is a managed setting, set from the Management Node page like agent_enabled');
check(isset($by_name['agent_log_access_notice_seen']), 'agent_log_access_notice_seen is declared');
check(($by_name['agent_log_access_notice_seen']['default'] ?? null) === '', 'the notice flag defaults to empty: due until acknowledged');
check(!empty($by_name['agent_enabled']['managed']) && !empty($by_name['agent_log_access']['managed']),
	'both switches are declared the same way, because the agent reads both with one reader');

// ---------------------------------------------------------------------------
section('B. The page reads the switch with the agent switch\'s spellings');

foreach (array('1', 'true', 'yes', 'on', ' On ') as $v) {
	check(admin_management_node_agent_switch_on($v), "'$v' reads as on");
}
foreach (array('', '0', 'false', 'no', 'off', 'maybe') as $v) {
	check(!admin_management_node_agent_switch_on($v), "'$v' reads as off");
}
check(function_exists('admin_management_node_log_access'), 'the page has a log-access reader beside the agent-enabled reader');

$logic_src = file_get_contents(PathHelper::getIncludePath('adm/logic/admin_management_node_logic.php'));
foreach (array('log_access_on', 'log_access_off', 'log_access_notice_seen') as $action) {
	check(strpos($logic_src, "\$input['action'] === '$action'") !== false, "the page handles the $action action");
}
check(preg_match('/Setting::put\(\'agent_log_access_notice_seen\'/', $logic_src) === 1
	&& preg_match('/LogicResult::render\(/', $logic_src) === 1
	&& strpos($logic_src, "notice_seen', '1');\n\t\treturn LogicResult::redirect") !== false,
	'the notice flag is written only inside an action that redirects, never on the render path');

// ---------------------------------------------------------------------------
section('C. The one-time notice is due under three stored facts, and silent otherwise');

$connected = json_encode(array('status' => 'connected', 'url' => 'https://plane.example', 'fingerprint' => 'abcd'));
$pending   = json_encode(array('status' => 'pending', 'url' => 'https://plane.example'));

check(AgentLogAccessNotice::due($connected, '1', ''), 'connected + on + unseen: due');
check(!AgentLogAccessNotice::due($connected, '1', '1'), 'acknowledged: silent');
check(!AgentLogAccessNotice::due($connected, '', ''), 'switch off: silent (there is nothing to tell)');
check(!AgentLogAccessNotice::due($pending, '1', ''), 'a join still pending is not a connection: silent');
check(!AgentLogAccessNotice::due('', '1', ''), 'never connected: silent');
check(!AgentLogAccessNotice::due('not json', '1', ''), 'an unreadable join state is not a connection');
check(AgentLogAccessNotice::due($connected, 'on', 'no'), 'the spellings are the switch\'s: on / no');

$notices_src = file_get_contents(PathHelper::getIncludePath('includes/AdminNotices.php'));
check(strpos($notices_src, "'agent_log_access' => array('AgentLogAccessNotice', 'render')") !== false,
	'the notice is a core notice, registered in AdminNotices');

$notice_src = file_get_contents(PathHelper::getIncludePath('includes/AgentLogAccessNotice.php'));
check(strpos($notice_src, 'method="POST"') !== false && strpos($notice_src, 'log_access_notice_seen') !== false,
	'the notice acknowledges itself with a POST to the Management Node page');
check(strpos($notice_src, 'Setting::put') === false, 'the notice itself writes nothing');

harness_finish();
