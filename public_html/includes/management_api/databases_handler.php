<?php
/**
 * GET /api/v1/management/databases
 *
 * Lists PostgreSQL databases accessible to the site's DB user. Used by the
 * server_manager "Internal Copy" dropdown — source selection for a within-
 * instance database copy.
 */

function databases_handler_api() {
	return [
		'method'      => 'GET',
		'description' => 'List of PostgreSQL databases accessible to the site.',
	];
}

function databases_handler($request) {
	$dblink = DbConnector::get_instance()->get_db_link();

	$q = $dblink->query("SELECT current_database() AS db");
	$current = ($q && ($row = $q->fetch(PDO::FETCH_ASSOC))) ? $row['db'] : null;

	$q = $dblink->query(
		"SELECT datname FROM pg_database "
		. "WHERE datistemplate = false AND datname NOT IN ('postgres') "
		. "ORDER BY datname"
	);
	$databases = [];
	if ($q) {
		while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
			$databases[] = $row['datname'];
		}
	}

	return [
		'current_db' => $current,
		'databases'  => $databases,
	];
}
?>
