<?php
/** @joinery-test
 * name: node_console_gate
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The node console's gate — who may run a command on a managed node.
 *
 * The console runs whatever an operator types, as the node's SSH identity, on
 * a live server. Nothing about the command itself is inspected (no allowlist
 * could be honest), so the gate in front of it is the entire safety story and
 * the only thing worth testing hard. Four conditions must each be sufficient
 * on their own to refuse:
 *
 *   - the node has not opted in (mgn_allow_console off)
 *   - the operator is not a superadmin
 *   - the operator owes a second-factor confirmation
 *   - the command is empty, or the timeout is not one the form offers
 *
 * The refusals are also asserted to create no job. A gate that refuses in the
 * UI but leaves a queued job behind would be no gate at all — the agent would
 * pick it up regardless of what the browser was told.
 *
 * The accept path additionally asserts the audit record: a run_command job
 * carrying the command, the operator, and its source. That row IS the audit
 * trail, so a run that does not produce one is the failure this feature exists
 * to prevent.
 *
 * Throwaway node, users and job rows are permanently removed in cleanup.
 *
 * Run: php plugins/server_manager/tests/node_console_gate_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SmAdminCsrf.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/logic/node_detail_actions_logic.php'));

$db = DbConnector::get_instance()->get_db_link();

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$session    = SessionControl::get_instance();
$page_regex = '/\/admin\/server_manager/';
$token      = SmAdminCsrf::token();

/** Non-deleted jobs for a node — any created mutation shows up here. */
function console_job_count($db, $node_id) {
	$q = $db->prepare('SELECT count(*) FROM mjb_management_jobs WHERE mjb_mgn_node_id = ? AND mjb_delete_time IS NULL');
	$q->execute([(int)$node_id]);
	return (int)$q->fetchColumn();
}

/** Act as $user at $permission for the rest of the test. */
function console_act_as($user, $permission) {
	$_SESSION['usr_user_id'] = (int)$user->key;
	$_SESSION['loggedin']    = true;
	$_SESSION['permission']  = $permission;
}

$node_id = null;

try {
	$superadmin = make_user('console_super', 10);
	$plain      = make_user('console_plain', 5);

	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'zz-console-test-node');
	$n->set('mgn_slug', 'zz-console-test-' . getmypid());
	$n->set('mgn_host', '203.0.113.30');
	$n->set('mgn_ssh_user', 'root');
	$n->set('mgn_ssh_key_path', '/home/user1/.ssh/id_ed25519_claude');
	$n->set('mgn_allow_console', false);
	$n->save();
	$n->load();
	$node_id = (int)$n->key;
	harness_register_row('mgn_managed_nodes', 'mgn_id', $node_id);

	$base_url = '/admin/server_manager/node_detail?mgn_id=' . $node_id;
	$good_post = [
		'action'          => 'run_command',
		SmAdminCsrf::FIELD => $token,
		'console_command' => 'uptime',
		'console_timeout' => (string)JobCommandBuilder::CONSOLE_TIMEOUT_DEFAULT,
	];

	// A harness user has no TOTP and no passkeys, so no second factor is owed —
	// the platform rule is that the gate binds a factor the account HAS. That
	// makes this the account under which the other conditions are isolated.
	console_act_as($superadmin, 10);
	check(!NodeDetailActions::step_up_required($session),
		'an account with no second factor owes no confirmation');

	// -----------------------------------------------------------------------
	section('a node that has not opted in refuses');
	// -----------------------------------------------------------------------

	$before = console_job_count($db, $node_id);
	$_POST = $good_post;
	$r = NodeDetailActions::dispatch($n, $session, $base_url, $page_regex);
	check($r === null, 'the refusal re-renders in place rather than redirecting away',
		'got: ' . var_export($r, true));
	check(console_job_count($db, $node_id) === $before,
		'no job is created for a node with the console off');

	// -----------------------------------------------------------------------
	section('a non-superadmin refuses, even on an opted-in node');
	// -----------------------------------------------------------------------

	$n->set('mgn_allow_console', true);
	$n->save();
	$n->load();

	console_act_as($plain, 5);
	$_POST = $good_post;
	$r = NodeDetailActions::dispatch($n, $session, $base_url, $page_regex);
	check($r === null, 'permission 5 is refused');
	check(console_job_count($db, $node_id) === $before, 'no job is created for a non-superadmin');

	// -----------------------------------------------------------------------
	section('an owed second-factor confirmation refuses');
	// -----------------------------------------------------------------------

	// TOTP enabled and never confirmed this session: the account holds a factor
	// it has not used, which is exactly the state the step-up gate exists for.
	$superadmin->set('usr_totp_secret', 'HARNESSTOTPSECRET');
	$superadmin->set('usr_totp_enabled_time', gmdate('Y-m-d H:i:s'));
	$superadmin->save();
	$superadmin->load();

	console_act_as($superadmin, 10);
	check(NodeDetailActions::step_up_required($session),
		'an account with TOTP and no recent confirmation owes one');

	$_POST = $good_post;
	$r = NodeDetailActions::dispatch($n, $session, $base_url, $page_regex);
	check($r === null, 'a superadmin who owes a confirmation is refused');
	check(console_job_count($db, $node_id) === $before,
		'no job is created while a confirmation is owed');

	// Back to no factor for the remaining sections.
	$superadmin->set('usr_totp_secret', null);
	$superadmin->set('usr_totp_enabled_time', null);
	$superadmin->save();
	$superadmin->load();
	console_act_as($superadmin, 10);

	// -----------------------------------------------------------------------
	section('malformed input refuses without discarding what was typed');
	// -----------------------------------------------------------------------

	$_POST = array_merge($good_post, ['console_command' => '   ']);
	$r = NodeDetailActions::dispatch($n, $session, $base_url, $page_regex);
	check($r === null, 'an empty command is refused in place');

	$_POST = array_merge($good_post, ['console_timeout' => '86400']);
	$r = NodeDetailActions::dispatch($n, $session, $base_url, $page_regex);
	check($r === null, 'a timeout outside the offered set is refused in place');
	check(console_job_count($db, $node_id) === $before, 'neither malformed post created a job');

	// -----------------------------------------------------------------------
	section('the accept path runs — and leaves the audit record');
	// -----------------------------------------------------------------------

	$_POST = array_merge($good_post, ['console_command' => "df -h / && echo 'done'"]);
	$r = NodeDetailActions::dispatch($n, $session, $base_url, $page_regex);
	check(strpos((string)$r, '/admin/server_manager/job_detail?job_id=') === 0,
		'an allowed run redirects to its job', 'got: ' . var_export($r, true));
	check(console_job_count($db, $node_id) === $before + 1, 'exactly one job was created');

	$job_id = (int)substr((string)$r, strrpos((string)$r, '=') + 1);
	$job = new ManagementJob($job_id, TRUE);
	check($job->get('mjb_job_type') === 'run_command',
		'the job is recorded under run_command', (string)$job->get('mjb_job_type'));
	check((int)$job->get('mjb_created_by') === (int)$superadmin->key,
		'the job names the operator who ran it');

	$params = json_decode($job->get('mjb_parameters') ?: '{}', true);
	check(($params['command'] ?? '') === "df -h / && echo 'done'",
		'the command is recorded verbatim for the audit trail');
	check(($params['source'] ?? '') === 'ui', 'the run is marked as coming from the UI');

	$steps = json_decode($job->get('mjb_commands') ?: '{}', true);
	check(count($steps['steps'] ?? []) === 1, 'the job carries one step');
	check(($steps['steps'][0]['cmd'] ?? '') === "df -h / && echo 'done'",
		'the step the agent will run is the command as typed');

} finally {
	$_POST = [];
	unset($_SESSION['usr_user_id'], $_SESSION['loggedin'], $_SESSION['permission']);
	if ($node_id) {
		$db->prepare('DELETE FROM mjb_management_jobs WHERE mjb_mgn_node_id = ?')->execute([$node_id]);
	}
}

harness_finish();
