<?php
/** @joinery-test
 * name: deferred_reclaim_database
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * A deferred blob reclaim runs only on the database that queued it.
 *
 * FileBlob::release() inside a caller's transaction defers the at-zero reclaim
 * to a shutdown function. A test-db suite closes test mode in harness_finish(),
 * before shutdown, so a reclaim it queued ran against the LIVE database with the
 * test database's blob id, guarded only by that id's refcount there: a live
 * blob at refcount 0 with a matching id would have lost its bytes and its row.
 * Each queued reclaim now records which database queued it; a flush runs only
 * those for the database open now and holds the rest, the harness flushes
 * before it closes test mode, and the shutdown flush drops (and logs) what
 * belongs to a database that is no longer open.
 *
 * The id queued here is a sentinel no blob has on either database, so a
 * reclaim run on the wrong side would do nothing but still be counted.
 *
 * Run: php tests/functional/files/deferred_reclaim_database_test.php
 */

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();
harness_test_mode();

$db = DbConnector::get_instance();
$sentinel = 2147480123;
$queue = new ReflectionMethod('FileBlob', '_defer_reclaim');
$queue->setAccessible(true);
$pending = new ReflectionProperty('FileBlob', 'deferred_reclaims');
$pending->setAccessible(true);

section('the sentinel names no blob on either database');
$on_test = (int)$db->get_db_link()->query('SELECT COUNT(*) FROM fbb_file_blobs WHERE fbb_file_blob_id = ' . $sentinel)->fetchColumn();
$db->close_test_mode();
$on_live = (int)$db->get_db_link()->query('SELECT COUNT(*) FROM fbb_file_blobs WHERE fbb_file_blob_id = ' . $sentinel)->fetchColumn();
$db->set_test_mode();
check($on_test === 0 && $on_live === 0, 'no blob row has the sentinel id', "test=$on_test live=$on_live");

section('a reclaim queued in test mode waits for the test database');
FileBlob::flushDeferredReclaims();
$queue->invoke(null, $sentinel);
$db->close_test_mode();
$live = FileBlob::flushDeferredReclaims();
check($live['reclaimed'] === 0 && $live['held'] === 1, 'flushed on the live database, it is held, not run', json_encode($live));
$db->set_test_mode();
$back = FileBlob::flushDeferredReclaims();
check($back['reclaimed'] === 1 && $back['held'] === 0, 'back on the test database, it runs there', json_encode($back));

section('at shutdown, one queued on a database no longer open is dropped');
$queue->invoke(null, $sentinel);
$db->close_test_mode();
$done = FileBlob::flushDeferredReclaimsAtShutdown();
check($done['reclaimed'] === 0, 'nothing is reclaimed on the live database', json_encode($done));
check($pending->getValue() === array(), 'nothing is left queued');
$db->set_test_mode();

section('the harness flushes what test mode queued before it leaves');
$queue->invoke(null, $sentinel);
check(count($pending->getValue()) === 1, 'one reclaim queued in test mode');
// harness_finish() runs the deferred close; the queue must be empty after it,
// which the shutdown function then confirms by having nothing to drop.
register_shutdown_function(function () use ($pending) {
	if ($pending->getValue() !== array()) {
		fwrite(STDERR, "deferred_reclaim_database: a reclaim was still queued after test mode closed\n");
		exit(1);
	}
});

harness_finish();
