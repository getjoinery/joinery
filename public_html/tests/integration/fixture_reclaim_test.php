<?php
/** @joinery-test
 * name: fixture_reclaim
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The stale-fixture reclaim sweep — the thing that ended the recurring chore
 * of hand-deleting rows a killed run stranded.
 *
 * A SIGKILL skips teardown, so a killed suite's fixture rows outlive it and
 * red the referential_integrity gate until someone deletes them by hand.
 * harness_cleanup_stale_fixtures() (run at every db-tier suite's boot)
 * reclaims them. Several agents run suites on this box at once, so a fixture
 * user is judged by whether the run that made it is alive: every run holds a
 * lock on cache/tests/live/<run_token>, and the token is in the address.
 * What this suite pins:
 *
 *  - a dead run's user (lock file there, nobody holding it) goes at once,
 *    whatever its age;
 *  - a live run's user (lock held) is NEVER touched, however old;
 *  - a user whose token has no lock file goes only past the one-hour floor,
 *    and so does a HarnessTest-named family row, which carries no token;
 *  - a tier that promises no side effects (safe) never sweeps.
 *
 * The other runs are faked: a lock file this suite holds stands in for a live
 * run, one it leaves unheld for a dead run, and no file for an unknown one.
 * Stale is made by backdating the very columns the sweep ages by.
 *
 * Run: php tests/integration/fixture_reclaim_test.php
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$db = DbConnector::get_instance()->get_db_link();
$two_hours_ago = gmdate('Y-m-d H:i:s', time() - 7200);
$live_dir = harness_scratch_dir('live');

/** A token no real run holds. */
$other_token = function () { return bin2hex(random_bytes(4)); };

/** A fixture user shaped like make_user_row()'s but minted under another
 *  run's token, deliberately UNREGISTERED — the sweep under test is its
 *  teardown. The finally below is the backstop. */
$stranded_users = array();
$make_stranded_user = function ($token, $birth) use (&$stranded_users) {
	$u = new User(NULL);
	$u->set('usr_first_name', 'HarnessTest');
	$u->set('usr_last_name', 'UserReclaim');
	$u->set('usr_email', 'harnesstest_reclaim_' . strtolower(LibraryFunctions::random_string(6))
		. '_' . $token . '@' . HARNESS_FIXTURE_DOMAIN);
	$u->set('usr_password', User::GeneratePassword('TestPassword_reclaim'));
	$u->set('usr_permission', 0);
	$u->set('usr_terms_accepted_time', $birth);
	$u->save();
	$u->load();
	$stranded_users[] = $u;
	return $u;
};
$user_exists = function ($id) use ($db) {
	$q = $db->prepare('SELECT count(*) FROM usr_users WHERE usr_user_id = ?');
	$q->execute(array($id));
	return (int)$q->fetchColumn() === 1;
};

$lock_files = array();
$held = array();
$event = null;
try {
	// ------------------------------------------------------------------
	section('A dead run\'s fixtures go at once');

	$dead = $other_token();
	$lock_files[] = $live_dir . '/' . $dead;
	touch($live_dir . '/' . $dead);   // the file a run leaves, with nobody holding it
	check(harness_run_token_state($dead) === 'dead', 'an unheld lock file reads as a dead run');
	$u = $make_stranded_user($dead, gmdate('Y-m-d H:i:s'));
	harness_cleanup_stale_fixtures();
	check(!$user_exists($u->key),
		'a dead run\'s user is reclaimed, young as it is', 'user ' . $u->key . ' survived');

	// ------------------------------------------------------------------
	section('A live run\'s fixtures are never touched');

	$live = $other_token();
	$lock_files[] = $live_dir . '/' . $live;
	$fh = fopen($live_dir . '/' . $live, 'c');
	check($fh && flock($fh, LOCK_EX | LOCK_NB), 'this suite holds a lock standing in for another live run');
	$held[] = $fh;
	check(harness_run_token_state($live) === 'live', 'a held lock reads as a live run');
	$u = $make_stranded_user($live, $two_hours_ago);
	harness_cleanup_stale_fixtures();
	check($user_exists($u->key), 'a live run\'s user is left alone, old as it is');
	flock($fh, LOCK_UN);
	check(harness_run_token_state($live) === 'dead', 'and reads as dead once the lock is let go');
	harness_cleanup_stale_fixtures();
	check(!$user_exists($u->key), 'then the next sweep takes it', 'user ' . $u->key . ' survived');

	// ------------------------------------------------------------------
	section('With no lock file, the one-hour floor decides');

	$unknown = $other_token();
	check(harness_run_token_state($unknown) === 'unknown', 'a token with no lock file reads as unknown');
	$old = $make_stranded_user($unknown, $two_hours_ago);
	$young = $make_stranded_user($unknown, gmdate('Y-m-d H:i:s'));
	harness_cleanup_stale_fixtures();
	check(!$user_exists($old->key),
		'an unknown run\'s user older than the floor is reclaimed', 'user ' . $old->key . ' survived');
	check($user_exists($young->key),
		'an unknown run\'s user younger than the floor is left alone — it may belong to a live run');

	if (class_exists('Event')) {
		$event = new Event(NULL);
		$event->set('evt_name', 'HarnessTest ReclaimProbe');
		$event->save();
		$event->load();
		// Backdate the birth column the sweep ages by.
		$q = $db->prepare('UPDATE evt_events SET evt_create_time = ? WHERE evt_event_id = ?');
		$q->execute(array($two_hours_ago, $event->key));
		harness_cleanup_stale_fixtures();
		$q = $db->prepare('SELECT count(*) FROM evt_events WHERE evt_event_id = ?');
		$q->execute(array($event->key));
		check((int)$q->fetchColumn() === 0,
			'a stale HarnessTest-named family row is reclaimed too', 'event ' . $event->key . ' survived');
	} else {
		harness_skip('a stale HarnessTest-named family row is reclaimed too', 'event_manager not active here');
	}

	// ------------------------------------------------------------------
	section('A no-side-effects tier never sweeps');

	$u = $make_stranded_user($other_token(), $two_hours_ago);
	$h = &$GLOBALS['__harness'];
	$real_tier = $h['meta']['tier'];
	$h['meta']['tier'] = 'safe';
	harness_cleanup_stale_fixtures();
	$h['meta']['tier'] = $real_tier;
	check($user_exists($u->key),
		'under tier safe the sweep declines — that tier promises a pure read');
	harness_cleanup_stale_fixtures();
	check(!$user_exists($u->key), 'and reclaims the same row once the tier can write');
} finally {
	// Backstop only: if a check above failed, don't strand the props.
	foreach ($held as $fh) { if ($fh) { fclose($fh); } }
	foreach ($lock_files as $file) { @unlink($file); }
	foreach (array_merge($stranded_users, array($event)) as $leftover) {
		if ($leftover && $leftover->key) {
			try { $leftover->permanent_delete(); } catch (\Throwable $e) { /* already gone */ }
		}
	}
}

harness_finish();
