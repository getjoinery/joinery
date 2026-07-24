<?php
/** @joinery-test
 * name: node_detail_actions_csrf
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * NodeDetailActions — CSRF is enforced on every node-detail POST action.
 *
 * The property under test: the dispatch validates the SmAdminCsrf token once,
 * before any handler runs, so a POST without a valid token is rejected for all
 * 18 actions (redirected back with no side effect), and a POST with the token
 * is accepted (the handler runs). This is the 1.0 hardening acceptance "CSRF
 * enforced on every plugin POST", now covered at the single dispatch point.
 *
 * Throwaway node + any job rows are created and permanently removed in cleanup.
 *
 * Run: php plugins/server_manager/tests/node_detail_actions_csrf_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SmAdminCsrf.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/logic/node_detail_actions_logic.php'));

$db = DbConnector::get_instance()->get_db_link();

// Every action the node-detail page dispatches. CSRF is a single gate ahead of
// all of them, so the reject path is asserted for the whole set.
$ALL_ACTIONS = [
	'check_status', 'backup_database', 'backup_project', 'escrow_backup_key',
	'copy_database', 'copy_database_local', 'restore_database', 'restore_project',
	'apply_update', 'apply_update_all_on_host', 'retry_install', 'provision_ssl',
	'run_plugin_installers', 'set_reverse_dns', 'save_api_credential',
	'clear_api_credential', 'save_node', 'delete_node',
];

/** Count non-deleted jobs for a node (any created mutation shows up here). */
function job_count($db, $node_id) {
	$q = $db->prepare('SELECT count(*) FROM mjb_management_jobs WHERE mjb_mgn_node_id = ? AND mjb_delete_time IS NULL');
	$q->execute([(int)$node_id]);
	return (int)$q->fetchColumn();
}

$node_id = null;

try {
	// A node with full SSH config so the accept path's builder can succeed.
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'zz-csrf-test-node');
	$n->set('mgn_slug', 'zz-csrf-test-' . getmypid());
	$n->set('mgn_host', '203.0.113.20');
	$n->set('mgn_ssh_user', 'root');
	$n->set('mgn_ssh_key_path', '/home/user1/.ssh/id_ed25519_claude');
	$n->save();
	$n->load();
	$node_id = (int)$n->key;

	$session = SessionControl::get_instance();
	$base_url = '/admin/server_manager/node_detail?mgn_id=' . $n->key;
	$page_regex = '/\/admin\/server_manager/';

	// Establish a known session token (mints it if absent).
	if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
	$token = SmAdminCsrf::token();

	// -----------------------------------------------------------------------
	section('every action is rejected without a valid CSRF token');
	// -----------------------------------------------------------------------

	$before = job_count($db, $node_id);
	$all_rejected = true;
	foreach ($ALL_ACTIONS as $action) {
		$_POST = ['action' => $action]; // no _sm_csrf field
		$r = NodeDetailActions::dispatch($n, $session, $base_url, $page_regex);
		// The CSRF reject redirects to the plain base URL, ahead of any handler.
		if ($r !== $base_url) {
			$all_rejected = false;
			check(false, "action '{$action}' rejected without a token (got: " . var_export($r, true) . ')');
		}
	}
	if ($all_rejected) {
		check(true, 'all 18 actions returned the reject redirect without a token');
	}
	check(job_count($db, $node_id) === $before, 'no job was created by any tokenless action');

	// -----------------------------------------------------------------------
	section('a wrong token is rejected');
	// -----------------------------------------------------------------------

	$_POST = ['action' => 'check_status', SmAdminCsrf::FIELD => 'not-the-real-token'];
	$r = NodeDetailActions::dispatch($n, $session, $base_url, $page_regex);
	check($r === $base_url, 'a mismatched token is rejected (redirect to base)');
	check(job_count($db, $node_id) === $before, 'no job created on a mismatched token');

	// -----------------------------------------------------------------------
	section('a valid token is accepted — the handler runs');
	// -----------------------------------------------------------------------

	// A validation-bounce action proves the gate passed without touching a
	// builder: copy_database with no source returns the database-tab URL, a
	// destination the reject path never produces.
	$_POST = ['action' => 'copy_database', SmAdminCsrf::FIELD => $token, 'source_node_id' => '0'];
	$r = NodeDetailActions::dispatch($n, $session, $base_url, $page_regex);
	check($r === $base_url . '&tab=database', 'valid token lets the handler run (database-tab redirect)');

	// A real mutation with a valid token creates a job.
	$_POST = ['action' => 'check_status', SmAdminCsrf::FIELD => $token];
	$r = NodeDetailActions::dispatch($n, $session, $base_url, $page_regex);
	check(strpos((string)$r, '/admin/server_manager/job_detail?job_id=') === 0,
		'valid token on check_status redirects to the created job');
	check(job_count($db, $node_id) === $before + 1, 'exactly one job created on the accepted mutation');

} finally {
	$_POST = [];
	if ($node_id) {
		$db->prepare('DELETE FROM mjb_management_jobs WHERE mjb_mgn_node_id = ?')->execute([$node_id]);
		$db->prepare('DELETE FROM mgn_managed_nodes WHERE mgn_id = ?')->execute([$node_id]);
	}
}

harness_finish();
