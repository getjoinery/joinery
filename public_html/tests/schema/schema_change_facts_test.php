<?php
/** @joinery-test
 * name: schema_change_facts
 * tier: safe
 * env: dev-only
 * needs: []
 * covers: [public_html/utils/update_database.php, public_html/utils/upgrade.php]
 */
/**
 * What an upgrade reports as a schema change is a change.
 *
 * DatabaseUpdater keeps two lists: `messages`, what it changed, and `notes`,
 * what it looked at and left as it was (a table that already exists, a primary
 * key's nullability it never touches, how many classes it loaded).
 * update_database records each message in its UPDATE_DATABASE_RESULT line as a
 * schema change, and the agent's apply result counts them. When the notes were
 * messages, every upgrade reported about 230 schema changes with none made.
 *
 * And upgrade.php runs update_database without --verbose: that mode is
 * class-loading debug output (about 250 KB a run) that pushed the rest of the
 * upgrade's transcript past the agent's output cap (B81, 2026-10-17).
 *
 * A new NOT NULL column is one change: that its NOT NULL is set later is a
 * note (0.8.525's apply counted two schema changes for one column).
 *
 * The same holds for the primary-key, unique-constraint, index and foreign-key
 * steps: a key added to a table that lacked one is a change, and it reaches the
 * apply result (a site that gained its grm_group_members key on 0.8.521 showed
 * no schema change at all); 'already exists' and 'Checking' are notes.
 *
 * Runs inside a transaction that is rolled back, so it persists nothing.
 *
 * CLI:  php tests/schema/schema_change_facts_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/DatabaseUpdater.php'));

class SchemaFactsStub {
	public static $tablename = 'zz_schema_facts_test';
	public static $pkey_column = 'zsf_id';
	public static $field_specifications = array(
		'zsf_id'   => array('type' => 'int8', 'serial' => true),
		'zsf_name' => array('type' => 'varchar(64)', 'is_nullable' => false),
	);
}

class SchemaFactsKeyStub {
	public static $tablename = 'zz_schema_facts_keys';
	public static $pkey_column = 'zsk_id';
	public static $field_specifications = array(
		'zsk_id'   => array('type' => 'int8', 'serial' => true),
		'zsk_code' => array('type' => 'varchar(32)', 'unique' => true),
	);
}

$dblink = DbConnector::get_instance()->get_db_link();
$dblink->beginTransaction();

try {
	$dblink->exec("DROP TABLE IF EXISTS zz_schema_facts_test CASCADE");
	$dblink->exec("CREATE TABLE zz_schema_facts_test (zsf_id int8 PRIMARY KEY, zsf_name varchar(64))");

	$updater = new DatabaseUpdater(true /* verbose */, true /* upgrade */, false);

	section('A table that already exists is a note, not a change');
	$create = new ReflectionMethod('DatabaseUpdater', 'createTableIfMissing');
	$create->setAccessible(true);
	$r = $create->invoke($updater, 'SchemaFactsStub', LibraryFunctions::get_tables_and_columns(), $dblink);
	check(in_array('Table zz_schema_facts_test already exists', $r['notes'] ?? array(), true),
		'a verbose run notes that the table already exists', json_encode($r));
	check(empty($r['messages']), 'and records no change for it', json_encode($r['messages'] ?? null));

	section('Column operations: what was left alone is a note, what changed is a message');
	$r = $updater->processAdvancedColumnOperations(array('SchemaFactsStub'), false);
	$notes = implode("\n", $r['notes'] ?? array());
	$messages = implode("\n", $r['messages'] ?? array());
	check(strpos($notes, 'Skipping nullable constraint change for primary key column: zz_schema_facts_test.zsf_id') !== false,
		'the primary key\'s nullability, never touched, is a note', $notes);
	check(strpos($messages, 'primary key column') === false, 'and not a message', $messages);
	check(strpos($messages, 'Modified nullable constraint: zz_schema_facts_test.zsf_name') !== false,
		'a NOT NULL the spec asks for and the table lacks is a change, recorded as one', $messages);
	check(strpos($notes, 'Modified nullable') === false, 'and is not filed as a note', $notes);

	$quiet = new DatabaseUpdater(false, true, false);
	$r = $create->invoke($quiet, 'SchemaFactsStub', LibraryFunctions::get_tables_and_columns(), $dblink);
	check(empty($r['notes']) && empty($r['messages']), 'a run that is not verbose has nothing to note');

	section('A column added is a message; that its NOT NULL comes later is a note');
	$dblink->exec("ALTER TABLE zz_schema_facts_test DROP COLUMN zsf_name");
	$add = new ReflectionMethod('DatabaseUpdater', 'addMissingColumns');
	$add->setAccessible(true);
	$r = $add->invoke($updater, 'SchemaFactsStub', LibraryFunctions::get_tables_and_columns(), $dblink);
	$messages = implode("\n", $r['messages'] ?? array());
	check(strpos($messages, 'Added column: zz_schema_facts_test.zsf_name') !== false, 'the column added is a message', $messages);
	check(strpos($messages, 'will be set to NOT NULL') === false
		&& strpos(implode("\n", $r['notes'] ?? array()), 'zz_schema_facts_test.zsf_name will be set to NOT NULL') !== false,
		'and its NOT NULL to come is a note, not a second change', json_encode($r));

	section('A primary key or unique constraint added is a message; one already there is a note');
	$dblink->exec("DROP TABLE IF EXISTS zz_schema_facts_keys CASCADE");
	$dblink->exec("CREATE TABLE zz_schema_facts_keys (zsk_id int8 NOT NULL, zsk_code varchar(32))");
	$dblink->exec("INSERT INTO zz_schema_facts_keys VALUES (1, 'a'), (2, 'b')");
	ob_start();
	$r = $updater->fixPrimaryKeys(array('SchemaFactsKeyStub'));
	ob_end_clean();
	$messages = implode("\n", $r['messages']);
	$notes = implode("\n", $r['notes']);
	check(strpos($messages, 'FIXED: Added primary key constraint to zz_schema_facts_keys.zsk_id') !== false,
		'a primary key added to a table that had none is a message', $messages);
	check(strpos($notes, 'Checking primary key for SchemaFactsKeyStub') !== false && strpos($messages, 'Checking') === false,
		'looking at the table is a note', $notes);
	$r = $updater->fixPrimaryKeys(array('SchemaFactsKeyStub'));
	check(empty($r['messages']) && strpos(implode("\n", $r['notes']), 'already has primary key constraint') !== false,
		'a second run changes nothing and says so only as a note', json_encode($r));
	$r = $updater->manageUniqueConstraints(array('SchemaFactsKeyStub'));
	check(strpos(implode("\n", $r['messages']), 'Added unique constraint') !== false,
		'a unique constraint added is a message', json_encode($r['messages']));
	$r = $updater->manageUniqueConstraints(array('SchemaFactsKeyStub'));
	check(empty($r['messages']) && strpos(implode("\n", $r['notes']), 'Unique constraint already exists') !== false,
		'one already there is a note', json_encode($r));
} finally {
	$dblink->rollBack();
}

section('Only changes become schema-change facts, and upgrades run the updater quietly');
$udb = (string)file_get_contents(PathHelper::getIncludePath('utils/update_database.php'));
preg_match_all('/update_database_schema_facts\(([^;]*)\);/', $udb, $calls);
$bad = array_filter($calls[1], function ($a) { return strpos($a, 'notes') !== false; });
check(count($calls[1]) >= 3 && !$bad, 'update_database records messages as facts and never notes', json_encode($calls[1]));
$recorded = implode(' ', $calls[1]);
$steps = array('primary_key_result', 'constraint_result', 'index_result', 'fk_result');
$missing = array_filter($steps, function ($v) use ($recorded) { return strpos($recorded, '$' . $v . "['messages']") === false; });
check(!$missing, 'the key, constraint, index and foreign-key steps record their changes too', json_encode(array_values($missing)));
$up = (string)file_get_contents(PathHelper::getIncludePath('utils/upgrade.php'));
$at = strpos($up, '$update_cmd = ');
$line = $at === false ? '' : substr($up, $at, strpos($up, "\n", $at) - $at);
$after = $at === false ? '' : substr($up, $at, 400);
check($line !== '' && strpos($after, "--verbose") === false,
	'upgrade.php runs update_database without --verbose', $line);

harness_finish();
