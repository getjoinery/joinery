<?php
/**
 * Migration: delete rows that violate declared foreign keys, so the FOREIGN
 * KEYS step of update_database can materialize the declared constraints.
 *
 * Two sources of residue are removed:
 *
 * 1. Stray test-fixture users (harnesstest_% / vaulttest_% emails) left behind
 *    by killed test runs, along with nothing else — their dependent rows fall
 *    out through pass 2.
 * 2. Orphaned child rows under every 'foreign_key' field-spec declaration
 *    (e.g. uew_user_encryption_wrappings rows whose uev vault row is gone).
 *    Swept in passes so chains resolve (deleting a stray user orphans its
 *    vault, whose deletion orphans its wrappings, ...).
 *
 * Idempotent: deletes only rows that are already orphaned; a clean database
 * deletes nothing.
 */
function cleanup_orphaned_fk_rows() {
	$dblink = DbConnector::get_instance()->get_db_link();

	// 1. Stray test-fixture users. Harness emails are @getjoinery.com and the
	// prefixes are reserved for fixtures; real accounts never carry them.
	$q = $dblink->prepare(
		"DELETE FROM usr_users
		 WHERE usr_email LIKE 'harnesstest\\_%@getjoinery.com'
		    OR usr_email LIKE 'vaulttest\\_%@getjoinery.com'");
	$q->execute();
	echo "Removed " . $q->rowCount() . " stray test-fixture user(s)\n";

	// 2. Orphan sweep over every declared foreign key, in passes until stable.
	$classes = LibraryFunctions::discover_model_classes(array(
		'require_tablename' => true,
		'require_field_specifications' => true,
		'include_plugins' => true,
	));

	$relations = array();
	foreach ($classes as $class) {
		foreach ($class::$field_specifications as $field => $spec) {
			if (empty($spec['foreign_key']['table']) || empty($spec['foreign_key']['column'])) {
				continue;
			}
			$relations[] = array(
				'table' => $class::$tablename,
				'field' => $field,
				'ref_table' => $spec['foreign_key']['table'],
				'ref_column' => $spec['foreign_key']['column'],
			);
		}
	}

	// Model classes are discovered from code on disk, which includes plugins
	// that are not installed on this site — their tables were never created.
	// Sweep only relations whose tables exist in THIS database.
	$table_exists_cache = array();
	$exists_stmt = $dblink->prepare("SELECT to_regclass(?)");
	$table_exists = function($table) use ($exists_stmt, &$table_exists_cache) {
		if (!array_key_exists($table, $table_exists_cache)) {
			$exists_stmt->execute(array('public.' . $table));
			$val = $exists_stmt->fetchColumn();
			$table_exists_cache[$table] = !empty($val);
		}
		return $table_exists_cache[$table];
	};
	$relations = array_values(array_filter($relations, function($r) use ($table_exists) {
		return $table_exists($r['table']) && $table_exists($r['ref_table']);
	}));

	$total = 0;
	for ($pass = 1; $pass <= 6; $pass++) {
		$deleted_this_pass = 0;
		foreach ($relations as $r) {
			$q = $dblink->prepare(
				"DELETE FROM {$r['table']} c
				 WHERE c.{$r['field']} IS NOT NULL
				   AND NOT EXISTS (SELECT 1 FROM {$r['ref_table']} p
				                   WHERE p.{$r['ref_column']} = c.{$r['field']})");
			$q->execute();
			$n = $q->rowCount();
			if ($n > 0) {
				echo "Pass {$pass}: removed {$n} orphan row(s) from {$r['table']} ({$r['field']} → {$r['ref_table']})\n";
				$deleted_this_pass += $n;
			}
		}
		$total += $deleted_this_pass;
		if ($deleted_this_pass === 0) {
			break;
		}
	}

	echo "Orphan cleanup complete: {$total} row(s) removed across " . count($relations) . " declared relation(s)\n";
	return true;
}
?>
