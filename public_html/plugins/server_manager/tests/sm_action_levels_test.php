<?php
/** @joinery-test
 * name: sm_action_levels
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Server Manager's levels (spec declared_permissions WP6): the dashboard,
 * node detail and the job pages open at 7, the rest at 8 or above; every
 * node detail action is placed in SmActionLevels, the looks at 7 (Q1) and the
 * destructive and key-changing ones at 10 (Q8); the dashboard's read actions
 * are 7 and backup_list holds the backup reads.
 *
 * Run: php tests/run.php --filter=sm_action_levels
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

section('pages');
foreach (array('index', 'node_detail', 'jobs', 'job_detail') as $p) {
	check(AdminPageAccess::headerLevel("plugins/server_manager/views/admin/$p.php") === 7, "$p opens at 7 (Q1, Q10, Q11)");
}
foreach (AccessDeclarations::pages() as $url => $page) {
	if ($page['plugin'] !== 'server_manager' || in_array(basename($page['file'], '.php'), array('index', 'node_detail', 'jobs', 'job_detail'), true)) continue;
	check(AdminPageAccess::levelForFile($page['file']) >= 8, "$url needs 8 or more");
}

section('node detail actions');
require_once(PathHelper::getIncludePath('plugins/server_manager/logic/node_detail_actions_logic.php'));   // NodeDetailActions lives in its logic file
$tabs = (new ReflectionClass('NodeDetailActions'))->getStaticPropertyValue('error_tab');
$unplaced = array_diff(array_keys($tabs), array_keys(SmActionLevels::PAGES['node_detail']));
check($unplaced === array(), 'every node detail action has a level', implode(', ', $unplaced));
$stale = array_diff(array_keys(SmActionLevels::PAGES['node_detail']), array_keys($tabs));
check($stale === array(), 'and every level names an action that exists', implode(', ', $stale));
foreach (array('check_status', 'site_log', 'log_table_tail', 'unit_journal', 'site_converger', 'file_head', 'page_probe', 'disk_usage', 'schema_probe') as $look) {
	check(SmActionLevels::level('node_detail', $look) === 7, "$look is a look: 7 (Q1)");
}
foreach (array('restore_database', 'restore_chain', 'delete_node', 'purge_node', 'approve_join', 'save_api_credential',
		'data_root_rekey', 'copy_switch', 'reboot_machine', 'disable_root_login') as $big) {
	check(SmActionLevels::level('node_detail', $big) === 10, "$big is destructive or key-changing: 10 (Q8)");
}
check(SmActionLevels::level('node_detail', 'no_such_action') === 10, 'an action not placed needs 10');

section('every page action is placed');
foreach (array('index', 'job_detail', 'publish_upgrade', 'incident', 'incidents', 'rollouts', 'fleet_security') as $page) {
	$src = (string)file_get_contents(PathHelper::getIncludePath("plugins/server_manager/views/admin/$page.php"));
	preg_match_all("/(?:action'\\] \\?\\? ''\\) === |post_action === |\\\$action === )'([a-z_]+)'/", $src, $m);
	if (preg_match("/in_array\\(\\\$_POST\\['action'\\] \\?\\? '', \\[([^\\]]*)\\]/", $src, $in)) {
		preg_match_all("/'([a-z_]+)'/", $in[1], $mm);
		$m[1] = array_merge($m[1], $mm[1]);
	}
	$missing = array_diff(array_unique($m[1]), array_keys(SmActionLevels::PAGES[$page] ?? array()));
	check(isset(SmActionLevels::PAGES[$page]) && $missing === array(), "$page: every action it handles has a level", implode(', ', $missing));
	check(strpos($src, "SmActionLevels::refuse_post('$page'") !== false, "$page asks before its handlers run");
}

section('actions');
$actions = AccessDeclarations::actions();
foreach (array('job_status', 'refresh_node_status', 'probe_api', 'moved_site_check', 'backup_list') as $read) {
	check(($actions[$read]['level'] ?? null) === 7, "$read is the dashboard's own: 7");
}
check(($actions['backup_actions']['level'] ?? null) === 8, 'backup_actions acts at 8 (its delete and restore need 10 inside)');
check(($actions['transfer_operator']['level'] ?? null) === 10, 'moving a site to its customer is 10');
check(($actions['transfer_customer']['level'] ?? null) === 0, 'the buyer\'s own transfer page: 0, the logic\'s rule');

harness_finish();
