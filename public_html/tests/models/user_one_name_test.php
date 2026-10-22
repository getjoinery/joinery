<?php
/** @joinery-test
 * name: user_one_name
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * A person can be created with only one name.
 *
 * The compact newsletter signup creates its member from the address alone
 * (User::guessNameFromEmail), and a one-word address (bob@example.com) gives a
 * first name and no last name. User::CreateNew refused that ("Missing last
 * name"), so such a visitor could not subscribe. A last name is optional; the
 * name shown and the name search work without one.
 *
 * Run: php tests/run.php test-db --filter=user_one_name
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

section('A one-word address gives a first name only');

$guess = User::guessNameFromEmail('bob@example.com');
check($guess['first_name'] === 'Bob' && $guess['last_name'] === '', 'bob@example.com guesses Bob and no last name', json_encode($guess));

section('CreateNew takes a person with one name');

$email = harness_fixture_email('onename');
$user = null;
$error = '';
try {
	$user = User::CreateNew(array(
		'usr_first_name' => 'Bob' . substr(md5($email), 0, 6),
		'usr_last_name'  => $guess['last_name'],
		'usr_email'      => $email,
		'send_emails'    => false,
	));
} catch (\Throwable $e) {
	$error = $e->getMessage();
}
check($user instanceof User && $user->key, 'the account is created', $error);
if ($user instanceof User && $user->key) {
	harness_register_user($user);
	$user = new User($user->key, TRUE);
	$first = (string)$user->get('usr_first_name');
	check((string)$user->get('usr_last_name') === '', 'with no last name stored', var_export($user->get('usr_last_name'), true));
	check($user->display_name() === $first, 'its display name is the first name, with no trailing space', var_export($user->display_name(), true));

	$found = new MultiUser(array('user_name_fulltext' => $first));
	$ids = array();
	foreach ($found as $u) { $ids[] = (int)$u->key; }
	check(in_array((int)$user->key, $ids, true), 'and the name search finds it by its first name');
}

$no_key = '';
try {
	User::CreateNew(array('usr_first_name' => 'X', 'usr_email' => harness_fixture_email('nolastkey'), 'send_emails' => false));
} catch (\Throwable $e) {
	$no_key = $e->getMessage();
}
check(stripos($no_key, 'last name') === false, 'no usr_last_name key at all is not an error either', $no_key);
$made = User::GetByEmail(harness_fixture_email('nolastkey'));
if ($made) {
	harness_register_user($made);
}

harness_finish();
