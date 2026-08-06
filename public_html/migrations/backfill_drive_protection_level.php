<?php
/**
 * Drive's two encryption booleans become one protection level.
 *
 * fol_encrypted / fil_encrypted only ever answered "is this client-custody
 * end-to-end encrypted?" — a yes/no that cannot express the middle rung, where
 * the server holds a key wrapping and opens the bytes inside the owner's unlock
 * window. The level column carries the whole ladder (standard / private /
 * fortress), and every row that was encrypted is a Fortress row.
 *
 * Runs before the deferred column-drop step in update_database, so the booleans
 * are still there to read. Idempotent, and a no-op once they are gone.
 */
function backfill_drive_protection_level() {
	$dblink = DbConnector::get_instance()->get_db_link();

	$pairs = array(
		array('table' => 'fol_folders', 'bool' => 'fol_encrypted', 'level' => 'fol_protection_level'),
		array('table' => 'fil_files',   'bool' => 'fil_encrypted', 'level' => 'fil_protection_level'),
	);

	foreach ($pairs as $p) {
		$has_bool = (int)$dblink->query(
			"SELECT COUNT(*) FROM information_schema.columns
			 WHERE table_schema = 'public' AND table_name = '" . $p['table'] . "'
			   AND column_name = '" . $p['bool'] . "'")->fetchColumn();
		if (!$has_bool) {
			echo $p['table'] . ": " . $p['bool'] . " already dropped, nothing to carry over.\n";
			continue;
		}

		$stmt = $dblink->prepare(
			"UPDATE " . $p['table'] . " SET " . $p['level'] . " = 'fortress'
			 WHERE " . $p['bool'] . " = true AND " . $p['level'] . " <> 'fortress'");
		$stmt->execute();
		echo $p['table'] . ": " . $stmt->rowCount() . " encrypted row(s) recorded as fortress.\n";
	}

	return true;
}
