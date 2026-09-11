<?php
/** @joinery-test
 * name: queued_email_test_mode
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * Mail that is queued cannot escape email test mode, and nobody is notified who
 * is not a person.
 *
 * The queue is drained by a different process (the SendQueuedEmails task) under
 * the site's real settings. A guard that lives only in the process that queued
 * the row is therefore no guard for the row: it was carried out of test mode
 * with its real recipient and delivered through the paid provider — 229 times in
 * thirty days from one db-tier suite, every one to the system user's placeholder
 * address. Two invariants close that:
 *
 *  - a QueuedEmail written while test mode is on is addressed to the trap AS IT
 *    IS WRITTEN, with the real recipient carried in the subject; a row already
 *    at the trap is not prefixed twice; test mode with no trap writes the row
 *    DELETED; test mode off leaves the row alone
 *  - Notify never delivers to the system or deleted user, on either channel
 *
 * Run: php tests/run.php test-db --filter=queued_email_test_mode
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

$db   = DbConnector::get_instance()->get_db_link();
$trap = 'joineryemailtests@' . HARNESS_FIXTURE_DOMAIN;

$make_row = function ($to, $subject) {
	$q = new QueuedEmail(NULL);
	$q->set('equ_from', 'sender@' . HARNESS_FIXTURE_DOMAIN);
	$q->set('equ_from_name', 'Harness');
	$q->set('equ_to', $to);
	$q->set('equ_to_name', 'Somebody');
	$q->set('equ_subject', $subject);
	$q->set('equ_body', '<p>body</p>');
	$q->set('equ_status', QueuedEmail::READY_TO_SEND);
	$q->save();
	$q->load();
	harness_register_model('QueuedEmail', $q->key);
	return $q;
};

try {
	// ------------------------------------------------------------------
	section('A row queued under test mode is redirected as it is written');

	harness_set_setting_mem('email_test_mode', '1');
	harness_set_setting_mem('email_test_recipient', $trap);

	$row = $make_row('person@example.test', 'Your import is ready');
	check($row->get('equ_to') === $trap, 'recipient is the trap', $row->get('equ_to'));
	check($row->get('equ_subject') === '[for person@example.test] Your import is ready',
		'the real recipient rides the subject', $row->get('equ_subject'));
	check((int)$row->get('equ_status') === QueuedEmail::READY_TO_SEND,
		'the row is still sendable', (string)$row->get('equ_status'));

	$already = $make_row($trap, '[for a@example.test] Already redirected');
	check($already->get('equ_subject') === '[for a@example.test] Already redirected',
		'a row already at the trap is not prefixed twice', $already->get('equ_subject'));

	$row->set('equ_subject', 'edited later');
	$row->save();
	$row->load();
	check($row->get('equ_subject') === 'edited later' && $row->get('equ_to') === $trap,
		'an update to an existing row is not re-redirected', $row->get('equ_subject'));

	// ------------------------------------------------------------------
	section('Test mode with no trap address never leaves a sendable row');

	// A blank in-memory setting falls through to the stored row (Globalvars
	// treats blank as unset), so the blank has to be in the test database's
	// own row for the duration. Restored at teardown.
	$stored = $db->query("SELECT stg_value FROM stg_settings WHERE stg_name = 'email_test_recipient'")->fetchColumn();
	$db->exec("UPDATE stg_settings SET stg_value = '' WHERE stg_name = 'email_test_recipient'");
	harness_defer(function () use ($db, $stored) {
		if ($stored === false) return;
		$db->prepare("UPDATE stg_settings SET stg_value = ? WHERE stg_name = 'email_test_recipient'")
			->execute(array($stored));
	});
	harness_set_setting_mem('email_test_recipient', '');
	$dropped = $make_row('person@example.test', 'Nowhere to go');
	check((int)$dropped->get('equ_status') === QueuedEmail::DELETED,
		'the row is written DELETED', (string)$dropped->get('equ_status'));
	check($dropped->get('equ_to') === 'person@example.test',
		'the recipient is left as it was (nothing to redirect to)', $dropped->get('equ_to'));

	// ------------------------------------------------------------------
	section('Test mode off leaves the row alone');

	harness_set_setting_mem('email_test_mode', '0');
	harness_set_setting_mem('email_test_recipient', $trap);
	$plain = $make_row('person@example.test', 'Real send');
	check($plain->get('equ_to') === 'person@example.test', 'recipient untouched', $plain->get('equ_to'));
	check($plain->get('equ_subject') === 'Real send', 'subject untouched', $plain->get('equ_subject'));
	harness_set_setting_mem('email_test_mode', '1');

	// ------------------------------------------------------------------
	section('Notify never delivers to a placeholder user');

	check(User::is_placeholder(User::USER_SYSTEM) && User::is_placeholder(User::USER_DELETED),
		'the system and deleted users are placeholders');
	check(!User::is_placeholder(1) && !User::is_placeholder(0),
		'other ids are not');

	// Any declared signal that emails its targeted recipients by default.
	$signal = null;
	foreach (SignalBus::signals() as $name => $decl) {
		if (!empty($decl['notify']['default_email']) && !empty($decl['notify']['title_template'])) {
			$signal = $name;
			break;
		}
	}
	if ($signal === null) {
		harness_skip('placeholder users get nothing', 'no signal with default_email declared here');
	} else {
		$person = new User(NULL);
		$person->set('usr_first_name', 'HarnessTest');
		$person->set('usr_last_name', 'NotifyTarget');
		$person->set('usr_email', harness_fixture_email('notify_' . LibraryFunctions::random_string(6)));
		$person->set('usr_password', User::GeneratePassword('TestPassword_notify'));
		$person->set('usr_permission', 0);
		$person->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
		$person->save();
		$person->load();
		harness_register_user($person);

		$title_marker = 'HarnessTest notify ' . LibraryFunctions::random_string(6);
		$decl    = SignalBus::signals()[$signal];
		$payload = array('recipients' => array(User::USER_SYSTEM, User::USER_DELETED, (int)$person->key));
		// Fill every declared payload field with the marker so the rendered
		// title carries it whichever field the template uses.
		foreach (array_keys($decl['payload'] ?? array()) as $field) {
			if ($field !== 'recipients') $payload[$field] = $title_marker;
		}
		$before_ntf = (int)$db->query('SELECT coalesce(max(ntf_notification_id),0) FROM ntf_notifications')->fetchColumn();
		$before_equ = (int)$db->query('SELECT coalesce(max(equ_queued_email_id),0) FROM equ_queued_emails')->fetchColumn();

		SignalBus::dispatch($signal, $payload);

		$ntf = $db->prepare('SELECT ntf_usr_user_id FROM ntf_notifications WHERE ntf_notification_id > ?');
		$ntf->execute(array($before_ntf));
		$ntf_users = array_map('intval', $ntf->fetchAll(PDO::FETCH_COLUMN));
		$equ = $db->prepare('SELECT equ_to, equ_queued_email_id FROM equ_queued_emails WHERE equ_queued_email_id > ?');
		$equ->execute(array($before_equ));
		$equ_rows = $equ->fetchAll(PDO::FETCH_ASSOC);

		$db->prepare('DELETE FROM ntf_notifications WHERE ntf_notification_id > ?')->execute(array($before_ntf));
		foreach ($equ_rows as $r) { harness_register_model('QueuedEmail', (int)$r['equ_queued_email_id']); }

		check(in_array((int)$person->key, $ntf_users, true),
			'the person got an in-app notification (the signal was live)', json_encode($ntf_users));
		check(!in_array(User::USER_SYSTEM, $ntf_users, true) && !in_array(User::USER_DELETED, $ntf_users, true),
			'placeholder users got no in-app notification', json_encode($ntf_users));
		check(count($equ_rows) === 1, 'exactly one email was queued — the person\'s', json_encode($equ_rows));
		// And, being queued under test mode, it is already at the trap.
		check(count($equ_rows) === 1 && strcasecmp($equ_rows[0]['equ_to'], $trap) === 0,
			'that queued row is addressed to the trap, not the person', json_encode($equ_rows));
	}
} catch (\Throwable $e) {
	check(false, 'suite ran without an exception', get_class($e) . ': ' . $e->getMessage()
		. ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
