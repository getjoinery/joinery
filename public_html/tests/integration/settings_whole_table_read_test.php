<?php
/** @joinery-test
 * name: settings_whole_table_read
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A web request reads the settings table once (specs/request_path_speedups.md WP5).
 *
 * Globalvars::get_setting() in a web request loads every stg_settings row
 * with one statement and answers each setting from that copy. This runs on
 * the command line, so it switches the web behaviour on with
 * Globalvars::read_whole_table() and pins:
 *  - any number of settings, present, blank or missing, cost one statement;
 *  - a setting written by raw SQL (a prepared statement, exec(), query())
 *    is read at its new value straight after, with no call to forget it,
 *    because the database layer drops the copy when stg_settings is written;
 *  - forget_setting() drops the copy too;
 *  - a rollback drops the copy, so a value read inside an undone
 *    transaction is not kept;
 *  - a sealed secret is held sealed in the copy and never handed out sealed;
 *  - the command line, left alone, still asks one statement per setting.
 *
 * Its rows are two scratch settings named for this run, removed at the end.
 *
 * Run: php tests/run.php --only=tests/integration/settings_whole_table_read_test.php
 *
 * @version 1.0
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$db = DbConnector::get_instance()->get_db_link();
$settings = Globalvars::get_instance();
$token = substr(md5(uniqid('', true)), 0, 10);
$name_a = 'zz_whole_table_a_' . $token;
$name_b = 'zz_whole_table_b_' . $token;

harness_defer(function () use ($db, $name_a, $name_b) {
	Globalvars::read_whole_table(false);
	if ($db->inTransaction()) { $db->rollBack(); }
	$db->prepare('DELETE FROM stg_settings WHERE stg_name IN (?, ?)')->execute(array($name_a, $name_b));
});

$statements = function (callable $work) {
	$before = GuardedPdo::$statements;
	$work();
	return GuardedPdo::$statements - $before;
};
$read = function (string $name) use ($settings) {
	return $settings->get_setting($name, false, true);
};

// ── One statement for any number of settings ────────────────────────────────
section('The table is read once');

// A missing setting is looked up among the declared settings, and the first
// such lookup in a process reads which plugins are active. Done here, so the
// counts below are of settings statements alone.
SettingsDeclarations::get('site_name');

Globalvars::read_whole_table(true);
check(!Globalvars::holds_stored_settings(), 'no copy of the table is held before a setting is asked for');
$cost = $statements(function () use ($read, $name_a) {
	$read('site_name');
	$read('theme_template');
	$read($name_a);
	$read('zz_no_such_setting_at_all');
	$read($name_a);
});
check($cost === 1, 'five reads, of settings present and missing, run one statement', 'statements: ' . $cost);
check(Globalvars::holds_stored_settings(), 'a copy of the table is held after the first read');
$cost = $statements(function () use ($read, $name_a, $name_b) {
	$read($name_a);
	$read($name_b);
	$read('zz_no_such_setting_at_all');
});
check($cost === 0, 'later reads of blank and missing settings run no statement', 'statements: ' . $cost);

// ── A raw write is seen at once ─────────────────────────────────────────────
section('A setting written by raw SQL is read at its new value');

check($read($name_a) === '', 'the scratch setting does not exist yet');
$db->prepare('INSERT INTO stg_settings (stg_name, stg_value) VALUES (?, ?)')->execute(array($name_a, ''));
check(!Globalvars::holds_stored_settings(), 'a prepared INSERT into stg_settings drops the copy');
check($read($name_a) === null, 'the inserted blank setting reads as blank (null), not as missing');

$db->exec("UPDATE stg_settings SET stg_value = '' WHERE stg_name = " . $db->quote($name_a));
check(!Globalvars::holds_stored_settings(), 'an exec() UPDATE of stg_settings drops the copy');
$read($name_b);
check(Globalvars::holds_stored_settings(), 'and the next read takes a new copy');

$db->query("UPDATE stg_settings SET stg_value = 'first' WHERE stg_name = " . $db->quote($name_a) . ' RETURNING stg_name')->fetchAll();
check($read($name_a) === 'first', 'a value written by query() is read straight after');

$read($name_b);
$cost = $statements(function () use ($db, $name_b) {
	$db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?')->execute(array($name_b));
});
check($cost === 1 && Globalvars::holds_stored_settings(), 'a statement that only reads stg_settings leaves the copy alone');

$db->prepare('SELECT count(*) FROM usr_users WHERE usr_user_id = ?')->execute(array(0));
check(Globalvars::holds_stored_settings(), 'and so does a statement about another table');

// ── forget_setting ──────────────────────────────────────────────────────────
section('forget_setting() drops the copy');

$read($name_b);
check(Globalvars::holds_stored_settings(), 'a copy is held');
$settings->forget_setting($name_a);
check(!Globalvars::holds_stored_settings(), 'forget_setting() drops it');
check($read($name_a) === 'first', 'and the forgotten setting is read from the table again');

// ── Rollback ────────────────────────────────────────────────────────────────
section('A rollback drops the copy');

$db->beginTransaction();
$db->prepare('INSERT INTO stg_settings (stg_name, stg_value) VALUES (?, ?)')->execute(array($name_b, 'undone'));
$copy = (new ReflectionProperty('Globalvars', 'stored'));
$read('zz_no_such_setting_at_all');
check(($copy->getValue()[$name_b] ?? null) === 'undone', 'a copy taken inside the transaction holds the uncommitted row');
$db->rollBack();
check(!Globalvars::holds_stored_settings(), 'the rollback drops that copy');
check($read($name_b) === '', 'and the undone setting reads as missing');

// ── Sealed values stay sealed in the copy ───────────────────────────────────
section('A sealed secret is held sealed and never handed out sealed');

$sealed_name = null;
foreach ($db->query("SELECT stg_name, stg_value FROM stg_settings WHERE stg_value LIKE 'v1.%'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
	if (SecretBox::looksEncrypted($row['stg_value']) && SettingsDeclarations::isSecret($row['stg_name'])) {
		$sealed_name = $row['stg_name'];
		break;
	}
}
if ($sealed_name === null) {
	harness_skip('this site stores no sealed secret setting');
} else {
	$settings->forget_setting($sealed_name);
	$opened = $read($sealed_name);
	$held = $copy->getValue()[$sealed_name] ?? null;
	check(is_string($held) && SecretBox::looksEncrypted($held), 'the copy holds the secret as stored, sealed');
	check($opened === null || !(is_string($opened) && SecretBox::looksEncrypted($opened)),
		'get_setting() hands out what it holds, or no value when it cannot be opened, never the sealed text');
	$settings->forget_setting($sealed_name);
}

// ── The command line is unchanged ───────────────────────────────────────────
section('The command line asks one statement per setting');

Globalvars::read_whole_table(false);
check(!Globalvars::holds_stored_settings(), 'switching back drops the copy');
$cost = $statements(function () use ($read, $name_b) {
	$read($name_b);
	$read($name_b);
});
check($cost === 2 && !Globalvars::holds_stored_settings(), 'two reads of a missing setting run two statements and keep no copy',
	'statements: ' . $cost);

harness_finish();
