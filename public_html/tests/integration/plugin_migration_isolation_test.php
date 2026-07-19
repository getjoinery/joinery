<?php
/** @joinery-test
 * name: plugin_migration_isolation
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Plugin migration failure isolation.
 *
 * PluginManager::install() wraps the whole install in one transaction. In
 * Postgres, one failed statement aborts the transaction: without isolation a
 * failing migration (a) masks its own error — every later statement, including
 * the plm_plugin_migrations bookkeeping INSERT, reports only "current
 * transaction is aborted" — and (b) poisons the rest of the install. The
 * runner holds a SAVEPOINT per migration so a failure is contained to itself,
 * the REAL error message is captured and recorded, and later migrations plus
 * the wrapping transaction keep working.
 *
 * Proven here for both runners (migrations.php closures and .sql files) by
 * running a fail-in-the-middle set inside an explicit transaction, then
 * rolling the wrapper back — which also erases every row the test recorded,
 * so the test leaves no residue.
 *
 * Run: php tests/integration/plugin_migration_isolation_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/PluginManager.php'));

$PLUGIN = 'harness_migration_probe'; // never a real plugin; rows roll back anyway
$dblink = DbConnector::get_instance()->get_db_link();

$scratch = sys_get_temp_dir() . '/plugin_migration_isolation_' . bin2hex(random_bytes(4));
mkdir($scratch, 0777, true);

$manager = new PluginManager();
$run_php = new ReflectionMethod('PluginManager', 'runPhpMigrations');
$run_php->setAccessible(true);
$run_sql = new ReflectionMethod('PluginManager', 'runSqlMigration');
$run_sql->setAccessible(true);

try {
	section('A. Failing php migration: real error surfaces, transaction survives');

	$migrations_file = $scratch . '/migrations.php';
	file_put_contents($migrations_file, <<<'PHP'
<?php
return [
	[
		'id' => 'probe_001_ok',
		'version' => '1.0.0',
		'up' => function($dbconnector) {
			$dbconnector->get_db_link()->exec("SELECT 1");
		},
	],
	[
		'id' => 'probe_002_boom',
		'version' => '1.0.0',
		'up' => function($dbconnector) {
			$dbconnector->get_db_link()->exec(
				"CREATE INDEX probe_boom_idx ON zzz_no_such_table_probe (nope)");
		},
	],
	[
		'id' => 'probe_003_after_failure',
		'version' => '1.0.0',
		'up' => function($dbconnector) {
			$dbconnector->get_db_link()->exec("SELECT 1");
		},
	],
];
PHP
	);

	$dblink->beginTransaction();

	$results = $run_php->invoke($manager, $PLUGIN, $migrations_file);
	$by_id = array();
	foreach ($results as $r) {
		$by_id[$r['id']] = $r;
	}

	check(count($results) === 3,
		'three migrations ran');
	check(!empty($by_id['probe_001_ok']['success']),
		'probe_001 succeeded');
	check(empty($by_id['probe_002_boom']['success']),
		'probe_002 failed');
	check(isset($by_id['probe_002_boom']['error'])
		&& stripos($by_id['probe_002_boom']['error'], 'zzz_no_such_table_probe') !== false
		&& stripos($by_id['probe_002_boom']['error'], 'current transaction is aborted') === false,
		'probe_002 error is the REAL error, not the aborted-transaction echo');
	check(!empty($by_id['probe_003_after_failure']['success']),
		'probe_003 still ran and succeeded after the failure');

	check($dblink->inTransaction(),
		'wrapping transaction is still open');
	$alive = $dblink->query("SELECT 42")->fetchColumn();
	check(intval($alive) === 42,
		'wrapping transaction is still usable');

	section('B. Bookkeeping recorded inside the surviving transaction');

	$stmt = $dblink->prepare(
		"SELECT plm_migration_id, plm_status, plm_error_message
		 FROM plm_plugin_migrations WHERE plm_plugin_name = ? ORDER BY plm_migration_id");
	$stmt->execute(array($PLUGIN));
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
	$status = array();
	foreach ($rows as $row) {
		$status[$row['plm_migration_id']] = $row;
	}

	check(count($rows) === 3,
		'three bookkeeping rows recorded');
	check(($status['probe_001_ok']['plm_status'] ?? '') === 'applied'
		&& ($status['probe_003_after_failure']['plm_status'] ?? '') === 'applied',
		'success rows marked applied');
	check(($status['probe_002_boom']['plm_status'] ?? '') === 'error'
		&& stripos((string)($status['probe_002_boom']['plm_error_message'] ?? ''), 'zzz_no_such_table_probe') !== false,
		'failure row marked error with the real message');

	$dblink->rollBack(); // erases the probe rows — no residue

	section('C. Failing .sql migration: same isolation');

	$sql_file = $scratch . '/001_boom.sql';
	file_put_contents($sql_file, "CREATE INDEX probe_sql_boom_idx ON zzz_no_such_table_probe (nope);");

	$dblink->beginTransaction();

	$sql_result = $run_sql->invoke($manager, $PLUGIN, $sql_file);

	check(empty($sql_result['success']),
		'sql migration reported failure');
	check(isset($sql_result['error'])
		&& stripos($sql_result['error'], 'zzz_no_such_table_probe') !== false
		&& stripos($sql_result['error'], 'current transaction is aborted') === false,
		'sql migration error is the REAL error');
	check($dblink->inTransaction() && intval($dblink->query("SELECT 7")->fetchColumn()) === 7,
		'transaction survived the sql failure');

	$dblink->rollBack();
} finally {
	if ($dblink->inTransaction()) {
		$dblink->rollBack();
	}
	@unlink($scratch . '/migrations.php');
	@unlink($scratch . '/001_boom.sql');
	@rmdir($scratch);
}

harness_finish();
