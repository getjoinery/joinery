<?php
/** @joinery-test
 * name: tables_without_model
 * tier: safe
 * env: dev-only
 * needs: []
 */
/**
 * Every table the platform holds is declared by a model, or is on the list
 * of tables that are not, with the reason (read-only).
 *
 * A table no model declares lives outside update_database, the deletion
 * engine, the validator and the naming scheme — and looks exactly like a
 * retired feature's leftover, which is how the 2026-09 naming sweep found
 * seventeen of them, two still live: log_logins (written on every sign-in
 * by a hand-rolled class with its own CREATE TABLE) and a dead one that
 * looked alive. A new one is a decision, not an accident: give it a model,
 * or put it on the list below with the reason it stays outside.
 *
 * The list is the record. Nothing here drops anything.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

// Tables that are not models, each with the reason.
$without_model = array(
	// Reference data the installer and the test-db copy seed; read without a
	// model (users_addrs_class.php reads zone, LibraryFunctions the rest).
	'cco_country_codes'      => 'reference data: ISO country codes',
	'country'                => 'reference data: country names',
	'timezone'               => 'reference data: IANA transitions (no code reads it; see TestDatabaseHelper::referenceTables)',
	'zone'                   => 'reference data: IANA zone names',
	// Kept on the record of two implemented specs: the sealed node keys for
	// pre-envelope archives still in buckets, and the agent-signing-key
	// backup. Nothing writes it; it has no model and never will.
	'bke_backup_key_escrow'  => 'recovery artifact (specs/implemented/backups_core_and_incremental.md, server_manager_permanent_node_delete.md: keep)',
);

$dblink = DbConnector::get_instance()->get_db_link();

$classes = LibraryFunctions::discover_model_classes(array(
	'require_tablename' => true,
	'require_field_specifications' => true,
	'include_plugins' => true,
));
$declared = array();
foreach ($classes as $class) {
	$declared[$class::$tablename] = $class;
}

$live = $dblink->query(
	"SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);

section('Every live table is a model, or on the list with its reason');

$unlisted = array();
foreach ($live as $table) {
	if (isset($declared[$table]) || isset($without_model[$table])) continue;
	$unlisted[] = $table;
}
check(empty($unlisted),
	'no live table is undeclared and unlisted (' . count($live) . ' tables, ' . count($declared) . ' models)',
	$unlisted
		? 'no model declares: ' . implode(', ', $unlisted)
		  . ' - give it a model (docs/example_class.php), or add it to $without_model in this test with the reason it stays outside'
		: '');

section('The list carries no table that has since gained a model or gone');

$stale = array();
foreach ($without_model as $table => $reason) {
	if (isset($declared[$table])) {
		$stale[] = "$table (now declared by {$declared[$table]})";
	} elseif (!in_array($table, $live, true)) {
		$stale[] = "$table (no longer exists)";
	}
}
check(empty($stale), 'every listed table is still live and still modelless', implode(', ', $stale));

harness_finish();
