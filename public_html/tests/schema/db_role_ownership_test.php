<?php
/** @joinery-test
 * name: db_role_ownership
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * The ownership block hands a database to a role, for real
 * (mail_access_hardening WP4, _db_ownership_sql.sh).
 *
 * Inside one transaction on the test database, rolled back at the end: a
 * throwaway role is created (CREATE ROLE is transactional), the public schema
 * is owned by name, a table with a serial column, a stand-alone sequence, a
 * view, a function and an enum are made, the block runs, and the schema, the
 * table, its owned sequence (which must follow the table, not be altered on
 * its own), the stand-alone sequence, the view, the function and the type come
 * out owned by the throwaway role; a second run changes nothing. Skipped when
 * the connection is not a superuser: after the role split lands on dev the
 * test-box rehearsal is this check.
 *
 * Run: php tests/run.php test-db --filter=db_role_ownership
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

$db = DbConnector::get_instance()->get_db_link();
$is_super = $db->query("SELECT rolsuper FROM pg_roles WHERE rolname = current_user")->fetchColumn();
if (!($is_super === true || $is_super === 't' || $is_super === 1 || $is_super === '1')) {
	harness_skip('db_role_ownership', 'the test connection is not a superuser; the live rehearsal covers this');
	harness_finish();
}

$tools = dirname(PathHelper::getBasePath()) . '/maintenance_scripts/install_tools';
$role = 'joinery_own_' . bin2hex(random_bytes(3));
$sql = shell_exec('bash ' . escapeshellarg($tools . '/_db_ownership_sql.sh') . ' ' . escapeshellarg($role) . ' 2>&1');
check(is_string($sql) && strpos($sql, 'DO $joinery_ownership$') === 0, 'the block prints', substr((string)$sql, 0, 80));

$owner_of = function (string $sql) use ($db): string {
	return (string)$db->query($sql)->fetchColumn();
};
$suffix = substr($role, -6);
$tbl = 'own_t_' . $suffix;
$seq = 'own_s_' . $suffix;
$view = 'own_v_' . $suffix;
$fn = 'own_f_' . $suffix;
$type = 'own_e_' . $suffix;

$db->beginTransaction();
try {
	$db->exec("CREATE ROLE \"$role\" NOLOGIN");
	$db->exec("ALTER SCHEMA public OWNER TO " . $db->query('SELECT current_user')->fetchColumn());
	$db->exec("CREATE TABLE $tbl (id serial PRIMARY KEY, n int)");
	$db->exec("CREATE SEQUENCE $seq");
	$db->exec("CREATE VIEW $view AS SELECT n FROM $tbl");
	$db->exec("CREATE FUNCTION $fn() RETURNS int LANGUAGE sql AS 'SELECT 1'");
	$db->exec("CREATE TYPE $type AS ENUM ('a', 'b')");

	section('before: everything is the connection\'s');
	$me = (string)$db->query('SELECT current_user')->fetchColumn();
	check($owner_of("SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid = '$tbl'::regclass") === $me, 'the table');
	check($owner_of("SELECT pg_get_userbyid(nspowner) FROM pg_namespace WHERE nspname = 'public'") === $me, 'the schema, by name');

	section('the block hands everything to the role');
	$db->exec($sql);
	check($owner_of("SELECT pg_get_userbyid(nspowner) FROM pg_namespace WHERE nspname = 'public'") === $role, 'the public schema (B1)');
	check($owner_of("SELECT has_schema_privilege('$role', 'public', 'USAGE')::text") === 'true'
			&& $owner_of("SELECT has_schema_privilege('$role', 'public', 'CREATE')::text") === 'true', 'with USAGE and CREATE on it');
	check($owner_of("SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid = '$tbl'::regclass") === $role, 'the table');
	check($owner_of("SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid = pg_get_serial_sequence('$tbl', 'id')::regclass") === $role,
		'the serial column\'s sequence, following its table (B2)');
	check($owner_of("SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid = '$seq'::regclass") === $role, 'the stand-alone sequence');
	check($owner_of("SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid = '$view'::regclass") === $role, 'the view');
	check($owner_of("SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE oid = '$fn()'::regprocedure") === $role, 'the function');
	check($owner_of("SELECT pg_get_userbyid(typowner) FROM pg_type WHERE oid = '$type'::regtype") === $role, 'the enum');
	$left = (int)$db->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
		WHERE n.nspname = 'public' AND c.relkind IN ('r','p','S','v','m','f') AND pg_get_userbyid(c.relowner) <> '$role'")->fetchColumn();
	check($left === 0, 'nothing in public is left with another owner', (string)$left);

	section('a second run changes nothing');
	$db->exec($sql);
	check($owner_of("SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid = '$tbl'::regclass") === $role, 'still the role\'s');

	$db->rollBack();
	check(!$db->inTransaction(), 'and the whole thing is rolled back: the test database is as it was');
} catch (Throwable $e) {
	if ($db->inTransaction()) { $db->rollBack(); }
	check(false, 'the block ran without error', $e->getMessage());
}
check((string)$db->query("SELECT count(*) FROM pg_roles WHERE rolname = '$role'")->fetchColumn() === '0', 'the throwaway role is gone');

harness_finish();
