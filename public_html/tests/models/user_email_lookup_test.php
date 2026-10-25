<?php
/** @joinery-test
 * name: user_email_lookup
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * Finding and changing an account by its email address.
 *
 *   - B117: a deleted account and a live one can share an address, and the
 *     lookup took whichever row came first, so the live member signed in
 *     against the deleted account's password. The live one answers.
 *   - B121: an address only a deleted account holds is refused for a new
 *     account, never handed back as that closed account.
 *   - B119: the email-change link's code stores the address lowercased, and is
 *     refused (and spent) when another account took the address meanwhile.
 *
 * Run: php tests/run.php test-db --filter=user_email_lookup
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

$db = DbConnector::get_instance()->get_db_link();

function uel_make(string $label): User {
	$user = User::CreateNew(array(
		'usr_first_name' => 'Lookup',
		'usr_email'      => harness_fixture_email($label),
		'send_emails'    => false,
	));
	harness_register_user($user);
	return new User($user->key, TRUE);
}

section('A blank address finds nobody');

check(User::GetByEmail('') === NULL, "GetByEmail('') is NULL");
check(User::GetByEmail(NULL) === NULL, 'GetByEmail(NULL) is NULL');
check(User::GetByEmail('   ') === NULL, 'GetByEmail of spaces is NULL');

section('B117: the live account answers to a shared address');

$closed = uel_make('lookup_closed');
$shared = (string)$closed->get('usr_email');
SystemBase::server_initiated_write(function () use ($closed) { $closed->soft_delete(); });
$live = uel_make('lookup_live');
// Only a raw write can give a live account a deleted one's address now; old
// rows made before these rules hold such pairs (dev: 4669 and 4671).
$q = $db->prepare('UPDATE usr_users SET usr_email = ? WHERE usr_user_id = ?');
$q->execute(array($shared, $live->key));

$found = User::GetByEmail($shared);
check($found && (int)$found->key === (int)$live->key, 'GetByEmail returns the live account, not the deleted one',
	'got ' . ($found ? $found->key : 'NULL') . ', live ' . $live->key . ', deleted ' . $closed->key);
$found = User::GetByEmail(strtoupper($shared));
check($found && (int)$found->key === (int)$live->key, 'and ignores capitals');

$restore = '';
try {
	SystemBase::server_initiated_write(function () use ($closed) { (new User($closed->key, TRUE))->undelete(); });
} catch (DisplayableUserException $e) {
	$restore = $e->getMessage();
}
check(strpos($restore, 'cannot be restored') !== false,
	'restoring the deleted twin is refused in words, not by the database', $restore);

section('B121: an address only a deleted account holds is refused');

$lone = uel_make('lookup_lone');
$lone_email = (string)$lone->get('usr_email');
SystemBase::server_initiated_write(function () use ($lone) { $lone->soft_delete(); });

$error = '';
try {
	User::CreateNew(array('usr_first_name' => 'Again', 'usr_email' => $lone_email, 'send_emails' => false));
} catch (DisplayableUserException $e) {
	$error = $e->getMessage();
}
check($error === User::CLOSED_ACCOUNT_MESSAGE, 'CreateNew refuses it with the closed-account message', $error);

$error = '';
try {
	User::CreateCompleteNew(array('usr_first_name' => 'Again', 'usr_email' => $lone_email, 'password' => ''), false, false, false);
} catch (DisplayableUserException $e) {
	$error = $e->getMessage();
}
check($error === User::CLOSED_ACCOUNT_MESSAGE, 'CreateCompleteNew refuses it too', $error);

section('B119: the email-change code stores the address lowercased');

$mover = uel_make('lookup_mover');
$target = harness_fixture_email('lookup_target');
Activation::email_change_send($mover->key, strtoupper($target));
$q = $db->prepare('SELECT act_code, act_usr_email FROM act_activation_codes
	WHERE act_usr_user_id = ? AND act_purpose = ? AND act_delete_time IS NULL ORDER BY act_activation_code_id DESC LIMIT 1');
$q->execute(array($mover->key, Activation::EMAIL_CHANGE));
$code = $q->fetch(PDO::FETCH_ASSOC);
check($code && $code['act_usr_email'] === strtolower($target), 'the code holds the address lowercased',
	$code ? $code['act_usr_email'] : 'no code');

$changed = $code ? Activation::ChangeEmailUser($code['act_code']) : false;
check($changed && (int)$changed->key === (int)$mover->key, 'spending the code changes the account');
check((new User($mover->key, TRUE))->get('usr_email') === strtolower($target), 'to the new address, lowercased');
check($code && Activation::ChangeEmailUser($code['act_code']) === FALSE, 'and the code cannot be spent twice');

section('B119: a code for an address another account took meanwhile is refused');

$asker = uel_make('lookup_asker');
$taken = harness_fixture_email('lookup_taken');
Activation::email_change_send($asker->key, $taken);
$q->execute(array($asker->key, Activation::EMAIL_CHANGE));
$code = $q->fetch(PDO::FETCH_ASSOC);
$taker = User::CreateNew(array('usr_first_name' => 'Taker', 'usr_email' => $taken, 'send_emails' => false));
harness_register_user($taker);

$error = '';
try {
	Activation::ChangeEmailUser($code['act_code']);
} catch (DisplayableUserException $e) {
	$error = $e->getMessage();
}
check(strpos($error, 'now belongs to another account') !== false, 'the change is refused, saying why', $error);
check((new User($asker->key, TRUE))->get('usr_email') !== $taken, 'the asker keeps their address');
check(!Activation::checkTempCode($code['act_code'], Activation::EMAIL_CHANGE), 'and the code is spent');

harness_finish();
