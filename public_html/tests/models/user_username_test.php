<?php
/** @joinery-test
 * name: user_username
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * An account has an email, a username, or both (spec optional_username).
 *
 *   - A username has the allowed form, and is unique regardless of capitals;
 *     a deleted account's username stays taken.
 *   - Every save keeps the email-or-username rule, including a plain save()
 *     that never runs prepare().
 *   - A blank identifier is stored as NULL, never ''.
 *   - CreateNew makes a username-only account, activated and sent nothing.
 *   - GetByLogin finds a live account by either identifier, never a deleted one.
 *   - An API update never changes either identifier.
 *
 * Run: php tests/run.php test-db --filter=user_username
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

$db = DbConnector::get_instance()->get_db_link();

function uu_handle(string $label): string {
	return 'uu' . $label . bin2hex(random_bytes(4));
}

function uu_refusal(callable $fn): string {
	try {
		$fn();
	} catch (DisplayableUserException $e) {
		return $e->getMessage();
	} catch (\Throwable $e) {
		return get_class($e) . ': ' . $e->getMessage();
	}
	return '';
}

section('The form of a username');

foreach (array('abc', 'Jo.Smith', 'a_b-c.d', str_repeat('x', 32), '9lives') as $ok) {
	check(User::IsValidUsername($ok), "'$ok' is a username");
}
foreach (array('ab', str_repeat('x', 33), 'jo@smith', '.dot', '-dash', 'has space', '') as $bad) {
	check(!User::IsValidUsername($bad), "'$bad' is not");
}

section('CreateNew makes a username-only account');

$handle = uu_handle('only');
$only = User::CreateNew(array('usr_first_name' => 'Only', 'usr_username' => $handle));
harness_register_user($only);
$only = new User($only->key, TRUE);
check($only->get('usr_email') === NULL, 'with no email (NULL)', var_export($only->get('usr_email'), true));
check($only->get('usr_username') === $handle, 'and the username as typed');
check((bool)$only->get('usr_is_activated'), 'activated: there is no address to prove');
check($only->login_label() === '@' . $handle, 'its label is @username', $only->login_label());

$missing = uu_refusal(function () {
	User::CreateNew(array('usr_first_name' => 'Nobody', 'send_emails' => false));
});
check(stripos($missing, 'email or username') !== false, 'with neither, CreateNew refuses', $missing);

section('A username is unique regardless of capitals, deleted accounts included');

$taken = uu_refusal(function () use ($handle) {
	$u = new User(NULL);
	$u->set('usr_first_name', 'Copy');
	$u->set('usr_username', strtoupper($handle));
	$u->save();
	harness_register_user($u);
});
check(strpos($taken, 'is taken') !== false, 'the same username in other capitals is refused', $taken);

$closed_handle = uu_handle('closed');
$closed = User::CreateNew(array('usr_first_name' => 'Closed', 'usr_username' => $closed_handle));
harness_register_user($closed);
SystemBase::server_initiated_write(function () use ($closed) { $closed->soft_delete(); });
$reuse = uu_refusal(function () use ($closed_handle) {
	$u = User::CreateNew(array('usr_first_name' => 'Reuse', 'usr_username' => $closed_handle));
	harness_register_user($u);
});
check(strpos($reuse, 'is taken') !== false, 'a deleted account\'s username stays taken', $reuse);

$badform = uu_refusal(function () {
	$u = User::CreateNew(array('usr_first_name' => 'Bad', 'usr_username' => 'x@y'));
	harness_register_user($u);
});
check(strpos($badform, '3 to 32') !== false, 'a username with an @ is refused', $badform);

section('Every save keeps the email-or-username rule');

$both = User::CreateNew(array('usr_first_name' => 'Both',
	'usr_email' => harness_fixture_email('uu_both'), 'usr_username' => uu_handle('both'), 'send_emails' => false));
harness_register_user($both);
$both = new User($both->key, TRUE);
$both->set('usr_email', '');
$both->save();
$both = new User($both->key, TRUE);
check($both->get('usr_email') === NULL, 'clearing the email of an account with a username is kept, as NULL',
	var_export($both->get('usr_email'), true));

$both->set('usr_username', '   ');
$neither = uu_refusal(function () use ($both) { $both->save(); });
check(stripos($neither, 'email address or a username') !== false,
	'clearing the last identifier is refused by a plain save(), no prepare()', $neither);
check((new User($both->key, TRUE))->get('usr_username') !== NULL, 'and nothing changed');

section('GetByLogin');

$by_name = User::GetByLogin(strtoupper($handle));
check($by_name && (int)$by_name->key === (int)$only->key, 'finds an account by its username, in any capitals');
check(User::GetByLogin($closed_handle) === NULL, 'never a deleted account');
check(User::GetByLogin('') === NULL && User::GetByLogin('  ') === NULL, 'nothing for blank text');
$mail_user = User::CreateNew(array('usr_first_name' => 'Mail', 'usr_email' => harness_fixture_email('uu_mail'), 'send_emails' => false));
harness_register_user($mail_user);
$by_mail = User::GetByLogin(strtoupper($mail_user->get('usr_email')));
check($by_mail && (int)$by_mail->key === (int)$mail_user->key, 'finds an account by its email');

section('An API update never changes a sign-in identifier');

check(in_array('usr_email', User::$api_create_only_fields, true)
	&& in_array('usr_username', User::$api_create_only_fields, true),
	'both are create-only on the API');

harness_finish();
