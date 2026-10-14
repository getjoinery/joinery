<?php
/** @joinery-test
 * name: notification_preferences
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * The preferences pages show what a person actually gets, and one save turns
 * an alert off.
 *
 * Some alerts reach a person with no saved preference: a signal declared with
 * an `audience` (the incident alerts go to every superadmin) and a signal sent
 * only to the people it is about. The admin page once drew every box from saved
 * rows alone, so a superadmin receiving every incident email saw the incident
 * boxes unticked, and unticking saved nothing. What is pinned here:
 *
 *  - every declared audience is one Notify knows, and the superadmin alerts
 *    declare theirs;
 *  - with no saved row, a superadmin is shown on for an audience signal
 *    (email per default_email), an admin outside the audience is shown off,
 *    a pure opt-in signal is off, a signal sent only to the people it is about
 *    is on;
 *  - one save with the box unticked writes a muted row, and Notify honours it;
 *    a choice equal to the default writes no row, so an admin promoted into an
 *    audience later still gets its alerts;
 *  - a superadmin with no row is reached through the declared audience;
 *  - push follows the alert: on wherever the alert is on, until unticked.
 *
 * Run: php tests/run.php test-db --filter=notification_preferences
 *
 * @version 1.1 - each state carries push
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

// Notify queues its emails against these; the test database copy may carry none.
harness_set_setting_mem('defaultemail', 'notify-prefs-test@dev.getjoinery.com');
harness_set_setting_mem('defaultemailname', 'Notification preferences test');

$db = DbConnector::get_instance()->get_db_link();

$make_user = function ($label, $permission) {
	$u = new User(NULL);
	$u->set('usr_first_name', 'HarnessTest');
	$u->set('usr_last_name', 'NotifyPrefs');
	$u->set('usr_email', harness_fixture_email('np_' . $label . '_' . LibraryFunctions::random_string(4)));
	$u->set('usr_password', User::GeneratePassword('TestPassword_notify'));
	$u->set('usr_permission', $permission);
	$u->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
	$u->save();
	$u->load();
	harness_register_user($u);
	return $u;
};

try {
	$signals = Notify::notifiable_signals();

	// ------------------------------------------------------------------
	section('Declared audiences');

	$unknown = array();
	foreach ($signals as $name => $meta) {
		if (isset($meta['notify']['audience']) && Notify::audience_ids($meta['notify']['audience']) === null) {
			$unknown[] = $name . ' => ' . $meta['notify']['audience'];
		}
	}
	check(!$unknown, 'every declared audience is one Notify knows', json_encode($unknown));

	$superadmin_signals = array('secret.unreadable', 'site.outbound_transfer_high', 'incident.opened', 'incident.opened_critical');
	$present = array_values(array_intersect($superadmin_signals, array_keys($signals)));
	$undeclared = array_values(array_filter($present, function ($name) use ($signals) {
		return ($signals[$name]['notify']['audience'] ?? '') !== 'superadmins';
	}));
	check(in_array('secret.unreadable', $present, true) && !$undeclared,
		'the superadmin alerts declare audience superadmins', 'present: ' . json_encode($present) . ' undeclared: ' . json_encode($undeclared));

	// An audience signal that emails by default, and one that does not.
	$emailing = 'secret.unreadable';
	$quiet    = null;
	foreach ($signals as $name => $meta) {
		if (isset($meta['notify']['audience']) && empty($meta['notify']['default_email'])) { $quiet = $name; break; }
	}
	$opt_in = null;
	$about  = null;
	foreach ($signals as $name => $meta) {
		$n = $meta['notify'];
		if ($opt_in === null && !empty($n['supports_topic']) && !isset($n['audience'])) $opt_in = $name;
		if ($about === null && empty($n['supports_topic'])) $about = $name;
	}

	$super   = $make_user('super', 10);
	$super2  = $make_user('super2', 10);
	$admin   = $make_user('admin', 5);

	// ------------------------------------------------------------------
	section('With no saved row, the page shows what delivery does');

	$s = Notify::preference_states((int)$super->key, $signals);
	$a = Notify::preference_states((int)$admin->key, $signals);

	check($s[$emailing] === array('subscribed' => true, 'email' => true, 'push' => true),
		'a superadmin is shown on, with email, for ' . $emailing, json_encode($s[$emailing]));
	if ($quiet !== null) {
		check($s[$quiet] === array('subscribed' => true, 'email' => false, 'push' => true),
			'and on, without email, for ' . $quiet . ' (no default_email)', json_encode($s[$quiet]));
	} else {
		harness_skip('an audience signal without default_email', 'none declared here');
	}
	check($a[$emailing] === array('subscribed' => false, 'email' => false, 'push' => false),
		'a permission-5 admin is outside the audience and shown off', json_encode($a[$emailing]));
	if ($opt_in !== null) {
		check($s[$opt_in] === array('subscribed' => false, 'email' => false, 'push' => false),
			'a pure opt-in signal (' . $opt_in . ') is off', json_encode($s[$opt_in]));
	} else {
		harness_skip('a pure opt-in signal', 'none declared here');
	}
	if ($about !== null) {
		$expect = array('subscribed' => true, 'email' => !empty($signals[$about]['notify']['default_email']), 'push' => true);
		check($a[$about] === $expect,
			'a signal sent only to the people it is about (' . $about . ') is on, email per default_email', json_encode($a[$about]));
	} else {
		harness_skip('a signal sent only to the people it is about', 'none active here');
	}

	// ------------------------------------------------------------------
	section('One save turns an alert off; a default choice writes nothing');

	// The superadmin submits the form with every box unticked.
	Notify::save_preferences((int)$super->key, $signals, array(), array());
	$row = NotificationPreference::get_for((int)$super->key, $emailing);
	check($row && !$row->get('ntp_subscribed') && !$row->get('ntp_email_enabled'),
		'unticking ' . $emailing . ' once writes a muted row');
	if ($opt_in !== null) {
		check(NotificationPreference::get_for((int)$super->key, $opt_in) === null,
			'an opt-in signal left off writes no row');
	}
	$s = Notify::preference_states((int)$super->key, $signals);
	check($s[$emailing]['subscribed'] === false, 'and the page then shows it off');

	// The permission-5 admin saves the same: off is already their default.
	Notify::save_preferences((int)$admin->key, $signals, array(), array());
	check(NotificationPreference::get_for((int)$admin->key, $emailing) === null,
		'an admin outside the audience who leaves it off writes no row, so a later promotion still reaches them');

	// The second superadmin ticks what they already get: nothing to write.
	$s2 = Notify::preference_states((int)$super2->key, $signals);
	$ticked = array_keys(array_filter($s2, function ($st) { return $st['subscribed']; }));
	$emails = array_keys(array_filter($s2, function ($st) { return $st['email']; }));
	$pushes = array_keys(array_filter($s2, function ($st) { return $st['push']; }));
	Notify::save_preferences((int)$super2->key, $signals, $ticked, $emails, $pushes);
	$rows = (int)$db->query('SELECT count(*) FROM ntp_notification_preferences WHERE ntp_usr_user_id = ' . (int)$super2->key)->fetchColumn();
	check($rows === 0, 'saving the page unchanged writes no rows', $rows . ' rows');

	// ------------------------------------------------------------------
	section('Delivery reads the same declaration');

	$marker  = 'HarnessTest notify prefs ' . LibraryFunctions::random_string(6);
	$payload = array();
	foreach (array_keys($signals[$emailing]['payload'] ?? array()) as $field) {
		$payload[$field] = $marker;
	}
	$like = '%' . $marker . '%';
	SignalBus::dispatch($emailing, $payload);

	$ntf = $db->prepare('SELECT ntf_usr_user_id FROM ntf_notifications WHERE ntf_title LIKE ?');
	$ntf->execute(array($like));
	$ntf_users = array_map('intval', $ntf->fetchAll(PDO::FETCH_COLUMN));
	$equ = $db->prepare('SELECT equ_queued_email_id, equ_subject FROM equ_queued_emails WHERE equ_subject LIKE ?');
	$equ->execute(array($like));
	$equ_rows = $equ->fetchAll(PDO::FETCH_ASSOC);
	$db->prepare('DELETE FROM ntf_notifications WHERE ntf_title LIKE ?')->execute(array($like));
	foreach ($equ_rows as $r) { harness_register_model('QueuedEmail', (int)$r['equ_queued_email_id']); }

	$emailed = function (User $u) use ($equ_rows) {
		foreach ($equ_rows as $r) {
			if (stripos($r['equ_subject'], (string)$u->get('usr_email')) !== false) return true;
		}
		return false;
	};
	check(in_array((int)$super2->key, $ntf_users, true) && $emailed($super2),
		'a superadmin with no row gets the bell and the email, by the declared audience', json_encode($ntf_users));
	check(!in_array((int)$super->key, $ntf_users, true) && !$emailed($super),
		'the superadmin who unticked it once gets nothing');
	check(!in_array((int)$admin->key, $ntf_users, true) && !$emailed($admin),
		'an admin outside the audience gets nothing');
} catch (\Throwable $e) {
	check(false, 'suite ran without an exception', get_class($e) . ': ' . $e->getMessage()
		. ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
