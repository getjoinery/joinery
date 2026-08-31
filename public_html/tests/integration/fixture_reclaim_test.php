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
 * reclaims fixture rows older than an hour. What this suite pins:
 *
 *  - stale debris IS reclaimed (a user and a named-family row, backdated);
 *  - fresh fixtures are NEVER touched — the floor is what makes the sweep
 *    safe beside concurrent live runs;
 *  - a tier that promises no side effects (safe) never sweeps.
 *
 * The fixtures here are made stale by backdating the very columns the sweep
 * ages by, exactly what a real killed run's leftovers look like an hour on.
 *
 * Run: php tests/integration/fixture_reclaim_test.php
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$db = DbConnector::get_instance()->get_db_link();
$two_hours_ago = gmdate('Y-m-d H:i:s', time() - 7200);

/** A fixture user shaped like make_user_row()'s, deliberately UNREGISTERED —
 *  the sweep under test is its teardown. The finally below is the backstop. */
$make_stranded_user = function () use ($two_hours_ago) {
	$u = new User(NULL);
	$u->set('usr_first_name', 'HarnessTest');
	$u->set('usr_last_name', 'UserReclaim');
	$u->set('usr_email', harness_fixture_email('reclaim_' . LibraryFunctions::random_string(6)));
	$u->set('usr_password', User::GeneratePassword('TestPassword_reclaim'));
	$u->set('usr_permission', 0);
	$u->set('usr_terms_accepted_time', $two_hours_ago);
	$u->save();
	$u->load();
	return $u;
};
$user_exists = function ($id) use ($db) {
	$q = $db->prepare('SELECT count(*) FROM usr_users WHERE usr_user_id = ?');
	$q->execute(array($id));
	return (int)$q->fetchColumn() === 1;
};

$stranded = null;
$fresh = null;
$event = null;
try {
	// ------------------------------------------------------------------
	section('Stale debris is reclaimed, through the model');

	$stranded = $make_stranded_user();
	check($user_exists($stranded->key), 'the stranded fixture exists before the sweep');
	harness_cleanup_stale_fixtures();
	check(!$user_exists($stranded->key),
		'a fixture user older than the floor is reclaimed', 'user ' . $stranded->key . ' survived');

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
	section('Fresh fixtures are never touched');

	$fresh = $make_stranded_user();
	$q = $db->prepare('UPDATE usr_users SET usr_terms_accepted_time = ? WHERE usr_user_id = ?');
	$q->execute(array(gmdate('Y-m-d H:i:s'), $fresh->key));
	harness_register_user($fresh); // real teardown owns this one
	harness_cleanup_stale_fixtures();
	check($user_exists($fresh->key),
		'a fixture younger than the floor is left alone — it may belong to a live run');

	// ------------------------------------------------------------------
	section('A no-side-effects tier never sweeps');

	$stranded2 = $make_stranded_user();
	$h = &$GLOBALS['__harness'];
	$real_tier = $h['meta']['tier'];
	$h['meta']['tier'] = 'safe';
	harness_cleanup_stale_fixtures();
	$h['meta']['tier'] = $real_tier;
	check($user_exists($stranded2->key),
		'under tier safe the sweep declines — that tier promises a pure read');
	harness_cleanup_stale_fixtures();
	check(!$user_exists($stranded2->key), 'and reclaims the same row once the tier can write');
	if ($user_exists($stranded2->key)) { harness_register_user($stranded2); }
} finally {
	// Backstop only: if a check above failed, don't strand the props.
	foreach (array($stranded, $event) as $leftover) {
		if ($leftover && $leftover->key) {
			try { $leftover->permanent_delete(); } catch (\Throwable $e) { /* already gone */ }
		}
	}
}

harness_finish();
