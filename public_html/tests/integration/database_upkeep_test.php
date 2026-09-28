<?php
/** @joinery-test
 * name: database_upkeep
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * DatabaseUpkeep against the real catalog, and the Database maintenance task.
 *
 *   - tableStats() reads every user table, each name already quoted
 *   - a run with no time left starts nothing, defers every step, and leaves the
 *     shared connection's vacuum throttle as it found it
 *   - the task's dry run describes the plan and changes nothing
 *
 * The full run is not driven here: it vacuums the whole dev database.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('tasks/DatabaseMaintenance.php'));

$db = DbConnector::get_instance()->get_db_link();

section('tableStats');
$stats = DatabaseUpkeep::tableStats();
$names = array_column($stats, 'name');
check(count($stats) > 50, 'every user table is read', count($stats) . ' tables');
check(in_array('public.stg_settings', $names, true), 'names come quoted as schema.table');
$row = $stats[array_search('public.stg_settings', $names, true)];
check(is_int($row['relpages']) && is_bool($row['analyzed']) && is_float($row['reltuples']), 'figures are typed for plan()');

section('the budget');
$before = (string)$db->query('SHOW vacuum_cost_delay')->fetchColumn();
$planned = count(DatabaseUpkeep::plan($stats));
$r = DatabaseUpkeep::run(0.0);
check($r['done'] === array() && $r['failed'] === array(), 'with no time left, nothing is started');
check($r['deferred'] >= $planned - 1 && $r['deferred'] <= $planned + 1, 'every planned step is deferred to the next run',
	$r['deferred'] . ' deferred, ' . $planned . ' planned');
check((string)$db->query('SHOW vacuum_cost_delay')->fetchColumn() === $before,
	'the shared connection\'s vacuum throttle is put back', $before);

section('the task');
$task = new DatabaseMaintenance();
$dry = $task->dryRun(array());
check($dry['status'] === 'success' && (strpos($dry['message'], 'Would vacuum') === 0 || strpos($dry['message'], 'Every table') === 0),
	'the dry run describes the plan', $dry['message']);
$json = json_decode((string)file_get_contents(PathHelper::getIncludePath('tasks/DatabaseMaintenance.json')), true);
check(($json['activate_on_install'] ?? null) === true && ($json['default_time'] ?? '') === '03:30:00',
	'it activates on install and runs at 03:30, after the 03:00 retention sweep');

harness_finish();
