<?php
/** @joinery-test
 * name: session_formfields
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * A form kept in the session to refill it after a refusal keeps no secret.
 *
 * SessionControl::save_formfields() stores the posted fields so a refused
 * form (registration, say) comes back filled in. A password, card detail or
 * token typed into it must never be among them: the session outlives the
 * request and is written to disk.
 *
 * Run: php tests/unit/session_formfields_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

section('Secrets are dropped, the rest is kept');

$saved_post = $_POST;
$_POST = array(
	'usr_first_name' => 'Jane',
	'usr_email' => 'jane@example.com',
	'password' => 'hunter2',
	'password2' => 'hunter2',
	'usr_password' => 'hunter2',
	'card_number' => '4242424242424242',
	'cvc' => '123',
	'_csrf_token' => 'abc',
	'api_secret' => 's',
);
SessionControl::get_instance()->save_formfields('register');
$kept = SessionControl::get_instance()->get_formfields_array('register');
$_POST = $saved_post;

check(is_array($kept) && ($kept['usr_first_name'] ?? null) === 'Jane' && ($kept['usr_email'] ?? null) === 'jane@example.com',
	'ordinary fields are kept', json_encode($kept));
$leaked = array_intersect(array_keys((array)$kept), array('password', 'password2', 'usr_password', 'card_number', 'cvc', '_csrf_token', 'api_secret'));
check(empty($leaked), 'no password, card, token or secret field is kept', implode(', ', $leaked));
check(strpos((string)($_SESSION['formfields'] ?? ''), 'hunter2') === false, 'the password text is nowhere in the session');

harness_finish();
