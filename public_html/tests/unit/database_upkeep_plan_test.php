<?php
/** @joinery-test
 * name: database_upkeep_plan
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * DatabaseUpkeep::plan() — which tables the nightly maintenance vacuums or
 * analyzes, decided from statistics rows alone.
 *
 *   - a large table with too few all-visible pages, or with dead rows past the
 *     churn threshold, is vacuumed (which analyzes it too — never both)
 *   - a small table is never vacuumed, however stale
 *   - a table never analyzed, or changed past the threshold since, is analyzed
 *   - reltuples of -1 (never vacuumed or analyzed) counts as no rows
 *   - vacuums come first, most unmarked pages first; then analyzes, largest first
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

function dup_t(string $name, array $over = array()): array {
	return array_merge(array(
		'name' => $name, 'relpages' => 1000, 'relallvisible' => 1000, 'reltuples' => 100000.0,
		'n_dead_tup' => 0, 'n_mod_since_analyze' => 0, 'analyzed' => true,
	), $over);
}
function dup_actions(array $plan): array {
	$out = array();
	foreach ($plan as $s) { $out[$s['table']] = $s['action']; }
	return $out;
}

section('a current table is left alone');
check(DatabaseUpkeep::plan(array(dup_t('public.fresh'))) === array(), 'all-visible, analyzed, no churn: nothing to do');
check(DatabaseUpkeep::plan(array()) === array(), 'no tables, no plan');

section('vacuum');
$p = dup_actions(DatabaseUpkeep::plan(array(dup_t('public.unmarked', array('relallvisible' => 560)))));
check(($p['public.unmarked'] ?? null) === 'vacuum', '56% of pages all-visible (the mailbox on 09-28) is vacuumed');
$p = dup_actions(DatabaseUpkeep::plan(array(dup_t('public.edge', array('relallvisible' => 900)))));
check(!isset($p['public.edge']), 'exactly at the 90% floor is current');
$p = dup_actions(DatabaseUpkeep::plan(array(dup_t('public.dead', array('n_dead_tup' => 3001)))));
check(($p['public.dead'] ?? null) === 'vacuum', 'dead rows past 1000 + 2% of 100k rows is vacuumed');
$p = dup_actions(DatabaseUpkeep::plan(array(dup_t('public.fewdead', array('n_dead_tup' => 3000)))));
check(!isset($p['public.fewdead']), 'dead rows at the threshold are not');
$p = DatabaseUpkeep::plan(array(dup_t('public.both', array('relallvisible' => 0, 'analyzed' => false, 'n_mod_since_analyze' => 99999))));
check(count($p) === 1 && $p[0]['action'] === 'vacuum', 'a vacuumed table is not analyzed separately (VACUUM (ANALYZE) does both)');
check(strpos($p[0]['reason'], '100% of pages') !== false, 'the reason says what was stale', $p[0]['reason']);

section('small tables are never vacuumed');
$p = dup_actions(DatabaseUpkeep::plan(array(dup_t('public.tiny', array('relpages' => 127, 'relallvisible' => 0, 'n_dead_tup' => 50000)))));
check(!isset($p['public.tiny']), 'under 128 pages: no vacuum, whatever its state');
$p = dup_actions(DatabaseUpkeep::plan(array(dup_t('public.tiny2', array('relpages' => 1, 'relallvisible' => 0, 'analyzed' => false)))));
check(($p['public.tiny2'] ?? null) === 'analyze', 'but a small table never analyzed is analyzed');

section('analyze');
$p = dup_actions(DatabaseUpkeep::plan(array(dup_t('public.never', array('analyzed' => false)))));
check(($p['public.never'] ?? null) === 'analyze', 'never analyzed (statistics lost on an unclean restart, or a restore) is analyzed');
$p = dup_actions(DatabaseUpkeep::plan(array(dup_t('public.changed', array('n_mod_since_analyze' => 3001)))));
check(($p['public.changed'] ?? null) === 'analyze', 'changed past the threshold since analyzed is analyzed');
$p = dup_actions(DatabaseUpkeep::plan(array(dup_t('public.unknown', array('reltuples' => -1.0, 'relpages' => 0, 'relallvisible' => 0, 'n_mod_since_analyze' => 1001)))));
check(($p['public.unknown'] ?? null) === 'analyze', 'reltuples -1 counts as no rows: 1001 changes pass the 1000 base');

section('order');
$p = DatabaseUpkeep::plan(array(
	dup_t('public.a_analyze_small', array('relpages' => 10, 'analyzed' => false)),
	dup_t('public.b_vacuum_some', array('relallvisible' => 800)),
	dup_t('public.c_analyze_big', array('relpages' => 5000, 'relallvisible' => 5000, 'analyzed' => false)),
	dup_t('public.d_vacuum_most', array('relallvisible' => 100)),
));
check(array_column($p, 'table') === array('public.d_vacuum_most', 'public.b_vacuum_some', 'public.c_analyze_big', 'public.a_analyze_small'),
	'vacuums first by unmarked pages, then analyzes by size', json_encode(array_column($p, 'table')));
check(!array_key_exists('_order', $p[0]), 'the plan carries no sort key');

harness_finish();
