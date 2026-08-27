<?php
/** @joinery-test
 * name: arbitrary_command_retirement
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * A control plane holds no way to run an instruction it composed on a node.
 *
 * This replaces the node console's gate test, and the replacement is the point.
 * That test asked whether the gate in front of arbitrary command execution was
 * tight enough — four conditions, each sufficient to refuse. It was a good test
 * of a feature that should not exist: no gate in front of "run this string as
 * root on that server" is worth more than the string, because a compromised
 * plane passes its own gate. Decision A1 removed the feature rather than
 * hardening it further, and this asserts the removal held.
 *
 * Deleting code does not keep it deleted. A run_command builder is three lines
 * and solves a real problem at 2am; a Console tab is a small convenience that
 * reads as harmless in isolation. Each would arrive as an improvement. This is
 * the check that makes their return deliberate rather than incidental.
 *
 * The same for A3's copy_database: node-to-node copying made one node trust
 * another and moved a dump between them under commands this plane composed. It
 * is expressed now as backup-on-source then restore-on-target — the restore
 * human-present, because restores destroy data.
 *
 * Run: php plugins/server_manager/tests/arbitrary_command_retirement_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));

$plugin_dir = PathHelper::getIncludePath('plugins/server_manager');
$site_root  = dirname(rtrim(PathHelper::getIncludePath(''), '/'));

section('The files are gone');

foreach ([
	$plugin_dir . '/node_exec.php'
		=> 'the diagnostic CLI that ran any command on any node',
	$plugin_dir . '/includes/node_detail_tabs/console.php'
		=> 'the Console tab',
	$plugin_dir . '/tests/node_console_gate_test.php'
		=> 'the console gate test, whose subject no longer exists',
] as $path => $what) {
	check(!file_exists($path), 'removed: ' . $what, $path);
}

section('The builders are gone');

// A builder is what turns an operator's intent into something a node executes.
// These three composed text: a typed command, and the shell that moved a
// database dump between two nodes.
foreach ([
	'build_run_command'            => 'one ad-hoc command, typed by an operator (A1)',
	'build_copy_database'          => 'node-to-node database copy (A3)',
	'build_copy_database_by_name'  => 'same-node database copy (A3)',
] as $method => $what) {
	check(!method_exists('JobCommandBuilder', $method),
		'no builder for ' . $what,
		"JobCommandBuilder::{$method}() is back");
}

// The console's own bounds went with it; a constant left behind is the seed of
// a reintroduction that looks like it was always supported.
check(!defined('JobCommandBuilder::CONSOLE_TIMEOUTS')
	&& !defined('JobCommandBuilder::CONSOLE_TIMEOUT_DEFAULT'),
	'the console timeout constants are gone too');

section('The actions cannot be dispatched');

// The dispatcher refuses an action it does not know, so an action absent from
// its table cannot be posted back into existence by a stale form or a crafted
// request.
$actions_src = file_get_contents($plugin_dir . '/logic/node_detail_actions_logic.php');
foreach (['run_command', 'copy_database', 'copy_database_local'] as $action) {
	check(strpos($actions_src, "case '{$action}':") === false,
		"no dispatch case for {$action}",
		'a handler is back in node_detail_actions_logic');
	check(strpos($actions_src, "'{$action}'  ") === false
		&& !preg_match("/'" . preg_quote($action, '/') . "'\s*=>\s*'/", $actions_src),
		"{$action} is not in the action table");
}

section('The tab is gone from the page');

$detail_src = file_get_contents($plugin_dir . '/views/admin/node_detail.php');
check(strpos($detail_src, "'console'") === false,
	'console is not a valid tab', 'node_detail.php still lists it');
check(stripos($detail_src, 'tab=console') === false,
	'and nothing links to it');

section('Retired job types are not offered as new work');

// Historical rows keep their type strings and must still render — the audit of
// what was run does not disappear because the ability to run it did. What must
// not appear is a retired type offered as something to filter for, or produce.
$types = ManagementJob::filterTypes(true);
foreach (['run_command', 'copy_database', 'copy_database_local'] as $retired) {
	check(!in_array($retired, $types, true),
		"{$retired} is not an offered job type",
		implode(', ', $types));
}
check(!in_array('copy_database', ManagementJob::databaseOpTypes(), true),
	'and the database-operations list no longer expects copies');

section('The per-node console opt-in is gone');

// A flag that gates a feature which no longer exists is worse than useless: it
// reads as a capability the node still has.
check(!array_key_exists('mgn_allow_console', ManagedNode::$field_specifications),
	'mgn_allow_console is not a declared field',
	'the physical column may linger; nothing should read it');

$overview_src = file_get_contents($plugin_dir . '/includes/node_detail_tabs/overview.php');
check(strpos($overview_src, 'mgn_allow_console') === false,
	'and the node edit form does not offer it');

harness_finish();
