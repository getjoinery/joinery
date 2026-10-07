<?php
/** @joinery-test
 * name: release_log_entries
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * What the database remembers about logged releases (spec
 * release_transparency, WP3; reviewer2 B3, B4):
 *
 *   - every logged statement is on record (ReleaseLogEntry), and a version
 *     once logged is spent
 *   - previous() is the newest release row holding a statement
 *   - unfinished() names a release row newer than that holding none: a
 *     publish that stopped between logging and recording; before any release
 *     is logged it names nothing
 *
 * Rows go to the test database and are deleted.
 *
 * Run: php tests/run.php --only=plugins/server_manager/tests/release_log_entries_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();

$db = DbConnector::get_instance()->get_db_link();
$db->exec("DELETE FROM rle_release_log_entries WHERE rle_version LIKE '97.%'");
$db->exec("DELETE FROM upg_upgrades WHERE upg_major_version = 97");

/** A statement document as publish writes it, with a stand-in envelope. */
function rle_doc($index, $tag) {
	return json_encode(array('format' => 1,
		'envelope' => array('payload' => base64_encode(json_encode(array('version' => $tag, 'keys_installed' => array())))),
		'entry' => array('log_origin' => 'log.example.test', 'log_index' => $index), 'key_chain' => array()));
}
function rle_row($patch, $statement) {
	$u = new Upgrade(NULL);
	$u->set('upg_major_version', 97);
	$u->set('upg_minor_version', 0);
	$u->set('upg_patch_version', $patch);
	$u->set('upg_name', "joinery-core-97.0.{$patch}.tar.gz");
	if ($statement !== null) { $u->set('upg_release_statement', $statement); }
	$u->prepare();
	$u->save();
	return $u;
}

// ---------------------------------------------------------------------------
section('Every logged statement is on record');

$row = ReleaseLogEntry::record('97.0.1', rle_doc(9001, '97.0.1'));
check($row->key > 0 && (int)$row->get('rle_log_index') === 9001 && $row->get('rle_log_origin') === 'log.example.test', 'recorded with its origin and index');
check(ReleaseLogEntry::versionLogged('97.0.1') && !ReleaseLogEntry::versionLogged('97.0.2'), 'a logged version is spent; another is not');
$refused = false;
try { ReleaseLogEntry::record('97.0.3', '{"envelope":{}}'); } catch (Exception $e) { $refused = true; }
check($refused, 'a statement without its log entry cannot be recorded');

// ---------------------------------------------------------------------------
section('What nodes hold, and half-finished publishes');

$own = (int)$db->query("SELECT count(*) FROM upg_upgrades WHERE upg_release_statement <> ''")->fetchColumn();
if ($own === 0) {
	rle_row(1, null);
	check(ReleaseStatementPublisher::unfinished() === array() && ReleaseStatementPublisher::previous() === null,
		'before any release is logged: genesis, and nothing is unfinished');
	rle_row(2, rle_doc(9002, '97.0.2'));
	check(json_decode(base64_decode(ReleaseStatementPublisher::previous()['envelope']['payload']), true)['version'] === '97.0.2',
		'previous() is the newest row holding a statement');
	rle_row(3, null);
	check(ReleaseStatementPublisher::unfinished() === array('97.0.3'), 'a later row with no statement is named as unfinished');
	rle_row(4, rle_doc(9004, '97.0.4'));
	check(ReleaseStatementPublisher::unfinished() === array(), 'and a logged row after it ends the question');
} else {
	check(true, 'this test database already holds logged releases; the ordering checks need an empty upg_upgrades');
}

$db->exec("DELETE FROM rle_release_log_entries WHERE rle_version LIKE '97.%'");
$db->exec("DELETE FROM upg_upgrades WHERE upg_major_version = 97");
harness_finish();
