<?php
/** @joinery-test
 * name: notification_preferences_signal_name
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * migrations/notification_preferences_signal_name.php — preference rows written
 * before ntp_hook_point became ntp_signal_name take their signal name back.
 *
 * Builds the shape a site that never ran column cleanup still has: the old
 * column beside an empty new one. The old name is copied across; an old row
 * whose user has saved a newer row for the same signal goes, and the newer row
 * stays; a row naming no signal at all goes; a current row is untouched; the
 * old column is dropped; a second run changes nothing and the registry's test
 * query then skips it.
 *
 * Run: php tests/run.php test-db --filter=notification_preferences_signal_name
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

require_once(PathHelper::getIncludePath('migrations/notification_preferences_signal_name.php'));

$db = DbConnector::get_instance()->get_db_link();

$make_user = function ($label) {
	$u = new User(NULL);
	$u->set('usr_first_name', 'HarnessTest');
	$u->set('usr_last_name', 'NtpRename');
	$u->set('usr_email', harness_fixture_email('ntpr_' . $label . '_' . LibraryFunctions::random_string(4)));
	$u->set('usr_password', User::GeneratePassword('TestPassword_ntpr'));
	$u->set('usr_permission', 5);
	$u->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
	$u->save();
	$u->load();
	harness_register_user($u);
	return (int)$u->key;
};

// Names the old column only when given a value for it, so it also works once that column is gone.
$insert = function ($user_id, $hook_point, $signal_name, $subscribed, $email) use ($db) {
	$cols = array('ntp_usr_user_id' => $user_id, 'ntp_signal_name' => $signal_name,
		'ntp_subscribed' => $subscribed ? 't' : 'f', 'ntp_email_enabled' => $email ? 't' : 'f');
	if ($hook_point !== null) {
		$cols['ntp_hook_point'] = $hook_point;
	}
	$q = $db->prepare('INSERT INTO ntp_notification_preferences (' . implode(', ', array_keys($cols)) . ')
		VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ') RETURNING ntp_notification_preference_id');
	$q->execute(array_values($cols));
	return (int)$q->fetchColumn();
};

$row = function ($id) use ($db) {
	$q = $db->prepare('SELECT ntp_signal_name, ntp_subscribed, ntp_email_enabled FROM ntp_notification_preferences WHERE ntp_notification_preference_id = ?');
	$q->execute(array($id));
	return $q->fetch(PDO::FETCH_ASSOC) ?: null;
};

$has_hook_point = function () use ($db) {
	return (bool)$db->query("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public'
		AND table_name = 'ntp_notification_preferences' AND column_name = 'ntp_hook_point'")->fetchColumn();
};

$registry_test = function () use ($db) {
	$migrations = array();
	require(PathHelper::getIncludePath('migrations/migrations.php'));
	foreach ($migrations as $m) {
		if (($m['migration_file'] ?? '') === 'notification_preferences_signal_name.php') {
			return (int)$db->query($m['test'])->fetchColumn();
		}
	}
	return null;
};

// The old column is put back for this suite only, and never outlives it.
harness_defer(function () use ($db) {
	$db->exec('ALTER TABLE ntp_notification_preferences DROP COLUMN IF EXISTS ntp_hook_point');
});

try {
	section('A site that still has the old column');

	$db->exec('ALTER TABLE ntp_notification_preferences ADD COLUMN IF NOT EXISTS ntp_hook_point varchar(100)');
	$a = $make_user('a');
	$b = $make_user('b');

	$old_only   = $insert($a, 'comment.posted', null, true, true);
	$old_stale  = $insert($a, 'purchase.completed', null, true, true);
	$newer      = $insert($a, null, 'purchase.completed', false, false);
	$no_name    = $insert($b, null, null, true, true);
	$current    = $insert($b, null, 'account.signup', true, false);

	check($registry_test() === 0, 'the registry test runs the migration while the old column is there');

	ob_start();
	notification_preferences_signal_name();
	$out = ob_get_clean();

	check($row($old_only) === array('ntp_signal_name' => 'comment.posted', 'ntp_subscribed' => true, 'ntp_email_enabled' => true),
		'an old row takes its signal name from ntp_hook_point, settings kept', json_encode($row($old_only)));
	check($row($old_stale) === null && $row($newer)['ntp_signal_name'] === 'purchase.completed' && $row($newer)['ntp_subscribed'] === false,
		'an old row superseded by a newer save for the same signal goes; the newer row stays');
	check($row($no_name) === null, 'a row naming no signal in either column goes');
	check($row($current) === array('ntp_signal_name' => 'account.signup', 'ntp_subscribed' => true, 'ntp_email_enabled' => false),
		'a current row is untouched');
	check(!$has_hook_point(), 'the old column is dropped');
	check(strpos($out, '1 signal names copied') !== false && strpos($out, '1 superseded') !== false, 'it says what it did', trim($out));

	section('Run again');

	ob_start();
	notification_preferences_signal_name();
	$out = ob_get_clean();
	check($row($old_only)['ntp_signal_name'] === 'comment.posted' && $row($newer) !== null && $row($current) !== null,
		'a second run changes nothing', trim($out));
	check($registry_test() === 1, 'and the registry test then skips it');

	section('A site where the old column was already dropped');

	$orphan = $insert($b, null, null, true, false);
	check($registry_test() === 0, 'a nameless row alone is enough to run it');
	ob_start();
	notification_preferences_signal_name();
	ob_end_clean();
	check($row($orphan) === null && $row($current) !== null, 'the nameless row goes; the named one stays');
} catch (\Throwable $e) {
	check(false, 'suite ran without an exception', get_class($e) . ': ' . $e->getMessage()
		. ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
