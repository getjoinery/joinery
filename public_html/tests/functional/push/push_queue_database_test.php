<?php
/** @joinery-test
 * name: push_queue_database
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * A queued push is sent only from the database that queued it.
 *
 * PushSender::queue() holds a message for the end of the request, sent by a
 * shutdown function. A test-db suite closes test mode in harness_finish(),
 * before shutdown, so a message a test queued (anything that raises a
 * notification queues one) was sent with the test database's member id
 * against the LIVE database's push destinations: a real push to whoever holds
 * that id there, and live rows touched. Each queued message now records which
 * database queued it; flush() sends only those for the database open now and
 * holds the rest, the harness drops what test mode queued before it closes
 * test mode, and the shutdown send drops (and logs) what is left.
 *
 * The member id queued here is a sentinel no member has on either database,
 * and no destination names it, so nothing is sent even where a send is run.
 *
 * Run: php tests/functional/push/push_queue_database_test.php
 */

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();
harness_test_mode();

$db = DbConnector::get_instance();
$sentinel = 2147480321;
$message = array('title' => 'push_queue_database', 'body' => 'never delivered', 'tag' => 'test');
$destinations = function () use ($db, $sentinel) {
	return (int)$db->get_db_link()->query('SELECT COUNT(*) FROM psh_push_destinations WHERE psh_usr_user_id = ' . $sentinel)->fetchColumn();
};

section('the sentinel holds no push destination on either database');
$on_test = $destinations();
$db->close_test_mode();
$on_live = $destinations();
$db->set_test_mode();
check($on_test === 0 && $on_live === 0, 'no destination names the sentinel member', "test=$on_test live=$on_live");

section('a push queued in test mode waits for the test database');
PushSender::clear_queue();
PushSender::queue($sentinel, $message);
$db->close_test_mode();
PushSender::flush();
check(count(PushSender::queued()) === 1, 'flushed on the live database, it is held, not sent');
$db->set_test_mode();
PushSender::flush();
check(count(PushSender::queued()) === 0, 'back on the test database, it is taken (and finds nothing to send)');

section('at shutdown, one queued on a database no longer open is dropped');
PushSender::queue($sentinel, $message);
$db->close_test_mode();
PushSender::send_after_response();
check(count(PushSender::queued()) === 0, 'nothing is left queued, and nothing was sent on the live database');
$db->set_test_mode();

section('the harness drops what test mode queued before it leaves');
PushSender::queue($sentinel, $message);
check(count(PushSender::queued()) === 1, 'one push queued in test mode');
register_shutdown_function(function () {
	if (PushSender::queued() !== array()) {
		fwrite(STDERR, "push_queue_database: a push was still queued after test mode closed\n");
		exit(1);
	}
});

harness_finish();
