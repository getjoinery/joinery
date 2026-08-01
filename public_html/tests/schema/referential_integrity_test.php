<?php
/** @joinery-test
 * name: referential_integrity
 * tier: safe
 * env: dev-only
 * needs: []
 */
/**
 * Standing referential-integrity gate (read-only).
 *
 * Turns "no residue, no drift" into a red/green signal in every gate run:
 *
 *   1. Every 'foreign_key' field-spec declaration is materialized in the DB
 *      with the declared referenced column and ON DELETE action.
 *   2. Zero orphan child rows under every declared foreign key.
 *   3. No serial sequence is behind MAX(pkey) — a behind sequence means the
 *      next INSERT reuses a primary key, re-attaching whatever orphaned
 *      references the old ID left behind (the vault-suite flakiness root
 *      cause; see specs/test_gate_flakiness.md).
 *   4. No stray harness fixtures survive (harnesstest_% users, vault-test-%
 *      passkey credentials, and the 'HarnessTest ...' named families) — a leak
 *      here names its leaker's table instead of surfacing later as
 *      unexplainable flakiness in an unrelated suite, or as phantom rows
 *      someone eventually notices in an admin screen.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$dblink = DbConnector::get_instance()->get_db_link();

$classes = LibraryFunctions::discover_model_classes(array(
	'require_tablename' => true,
	'require_field_specifications' => true,
	'include_plugins' => true,
));
$existing_tables = LibraryFunctions::get_tables_and_columns();

section('Declared foreign keys are materialized');
$fk_count = 0;
foreach ($classes as $class) {
	$table = $class::$tablename;
	foreach ($class::$field_specifications as $field => $spec) {
		if (empty($spec['foreign_key']['table']) || empty($spec['foreign_key']['column'])) {
			continue;
		}
		$fk = $spec['foreign_key'];
		$expected_delete = strtoupper(trim($fk['on_delete'] ?? 'RESTRICT'));
		if (!isset($existing_tables[$table]) || !isset($existing_tables[$fk['table']])) {
			harness_skip("$table.$field → {$fk['table']}", 'table not present on this install');
			continue;
		}
		$fk_count++;

		$q = $dblink->prepare(
			"SELECT ref.relname AS ref_table, ratt.attname AS ref_column,
			        CASE c.confdeltype WHEN 'c' THEN 'CASCADE' WHEN 'n' THEN 'SET NULL'
			             WHEN 'r' THEN 'RESTRICT' WHEN 'd' THEN 'SET DEFAULT' ELSE 'NO ACTION' END AS on_delete
			 FROM pg_constraint c
			 JOIN pg_class t ON t.oid = c.conrelid
			 JOIN pg_attribute att ON att.attrelid = t.oid AND att.attnum = c.conkey[1]
			 JOIN pg_class ref ON ref.oid = c.confrelid
			 JOIN pg_attribute ratt ON ratt.attrelid = ref.oid AND ratt.attnum = c.confkey[1]
			 WHERE c.contype = 'f' AND array_length(c.conkey, 1) = 1
			   AND t.relname = ? AND att.attname = ?");
		$q->execute(array($table, $field));
		$row = $q->fetch(PDO::FETCH_ASSOC);

		$ok = $row && $row['ref_table'] === $fk['table']
			&& $row['ref_column'] === $fk['column']
			&& $row['on_delete'] === $expected_delete;
		check($ok, "$table.$field → {$fk['table']}.{$fk['column']} ON DELETE $expected_delete",
			$row ? "found → {$row['ref_table']}.{$row['ref_column']} ON DELETE {$row['on_delete']}"
				: 'constraint missing — run update_database');

		// Zero orphans (the constraint enforces this going forward; the check
		// still runs so a dropped constraint + orphan shows both failures).
		$q = $dblink->prepare(
			"SELECT count(*) FROM {$table} c WHERE c.{$field} IS NOT NULL
			   AND NOT EXISTS (SELECT 1 FROM {$fk['table']} p WHERE p.{$fk['column']} = c.{$field})");
		$q->execute();
		$orphans = (int)$q->fetchColumn();
		check($orphans === 0, "$table.$field has no orphan rows", "$orphans orphan(s)");
	}
}
check($fk_count > 0, 'at least one declared foreign key was checked', 'declaration discovery is broken');

section('Serial sequences are never behind MAX(pkey)');
$behind = array();
$checked = 0;
foreach ($classes as $class) {
	$table = $class::$tablename;
	$pkey = $class::$pkey_column;
	if (!isset($existing_tables[$table])) continue;
	if (empty($class::$field_specifications[$pkey]['serial'])) continue;
	$seq = $table . '_' . $pkey . '_seq';
	try {
		$q = $dblink->prepare("SELECT last_value, is_called FROM {$seq}");
		$q->execute();
		$s = $q->fetch(PDO::FETCH_ASSOC);
		$q = $dblink->prepare("SELECT COALESCE(MAX({$pkey}), 0) FROM {$table}");
		$q->execute();
		$max = (int)$q->fetchColumn();
	} catch (PDOException $e) {
		continue; // sequence absent on this install — creation is update_database's job
	}
	$checked++;
	$current = (int)$s['last_value'];
	$is_called = ($s['is_called'] === true || $s['is_called'] === 't' || $s['is_called'] === 1);
	$is_behind = $is_called ? ($max > $current) : ($max >= $current && $max > 0);
	if ($is_behind) $behind[] = "$seq (last_value=$current, max=$max)";
}
check(count($behind) === 0, "no sequence behind its table's MAX ($checked checked)", implode('; ', $behind));

section('No stray harness fixtures');
// Domain-agnostic on purpose: a harnesstest_ user is a leak wherever it lives,
// and fixtures have used more than one domain over time. Matching the current
// domain only would quietly stop detecting the older strays.
$q = $dblink->prepare("SELECT count(*) FROM usr_users WHERE usr_email LIKE 'harnesstest\\_%'");
$q->execute();
$stray_users = (int)$q->fetchColumn();
check($stray_users === 0, 'no leftover harnesstest_% users', "$stray_users row(s)");

if (isset($existing_tables['pkc_passkey_credentials'])) {
	$q = $dblink->prepare("SELECT count(*) FROM pkc_passkey_credentials WHERE pkc_credential_id LIKE 'vault-test-%'");
	$q->execute();
	$stray_pkc = (int)$q->fetchColumn();
	check($stray_pkc === 0, 'no leftover vault-test-% passkey credentials', "$stray_pkc row(s)");
}

// The fixture families that name themselves 'HarnessTest ...'. Naming the table
// and the surviving row turns a leak into a one-line fix instead of an
// investigation — the alternative is noticing phantom rows in an admin screen
// weeks later. Tables absent on this install are simply skipped.
$named_fixtures = array(
	'evt_events'        => 'evt_name',
	'svy_surveys'       => 'svy_name',
	'grp_groups'        => 'grp_name',
	'pro_products'      => 'pro_name',
	'bkt_booking_types' => 'bkt_name',
	'mgn_managed_nodes' => 'mgn_name',
	'qst_questions'     => 'qst_question',
);
foreach ($named_fixtures as $table => $column) {
	if (!isset($existing_tables[$table])) {
		harness_skip("no leftover HarnessTest rows in $table", 'table not present on this install');
		continue;
	}
	$q = $dblink->prepare("SELECT count(*) FROM {$table} WHERE {$column} LIKE 'HarnessTest %'");
	$q->execute();
	$stray = (int)$q->fetchColumn();
	$detail = '';
	if ($stray > 0) {
		$q = $dblink->prepare("SELECT {$column} FROM {$table} WHERE {$column} LIKE 'HarnessTest %' ORDER BY 1 LIMIT 5");
		$q->execute();
		$detail = "$stray row(s): " . implode(', ', $q->fetchAll(PDO::FETCH_COLUMN));
	}
	check($stray === 0, "no leftover HarnessTest rows in $table", $detail);
}

harness_finish();
?>
