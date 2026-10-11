<?php
/** @joinery-test
 * name: app_access
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * App access (specs/mail_apps_plugin.md § Core: app access): the decisions
 * core makes about a credential limited to one app purpose.
 *
 * Covers:
 *  - AppAccess: purposes come from active plugins' `appAccess`; a bad name,
 *    and a purpose two plugins claim, are refused; borrowsUnlock and labels
 *  - ApiAuth::authorize(): a purpose credential reaches only an action that
 *    declares its purpose, and such an action admits nothing else (browser,
 *    session key, machine key, a credential of another purpose)
 *  - requires_person_credential and requires_browser_session refuse it
 *  - refuseAppCredentialOutsideActions(): every other route family refuses it
 *  - ApiKey: the app password's shape and its split, forgiving spaces and case;
 *    kind_label()
 *  - appClientIp(): the gateway's client address is believed from loopback only
 *
 * Drives the decisions directly with unsaved keys: no database.
 *
 * Run: php tests/unit/app_access_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

if (!function_exists('api_error')) {
	function api_error($message, $error_type = 'TransactionError', $status_code = 400) {
		throw new RuntimeException($status_code . '|' . $error_type . '|' . $message);
	}
}

require_once(PathHelper::getIncludePath('includes/ApiAuth.php'));

AppAccess::setDeclarationsForTests(array(
	'mail_apps' => array('mail' => array('label' => 'Mail apps', 'borrowsUnlock' => true)),
	'calendar'  => array('caldav' => array('label' => 'Calendar apps'), 'Bad Name' => array()),
	'one'       => array('shared' => array()),
	'two'       => array('shared' => array()),
	'switched'  => array('gated' => array('label' => 'Gated apps', 'borrowsUnlock' => true,
		'requiresSetting' => 'app_access_test_switch')),
));

function credential($purpose, $type = ApiKey::TYPE_APP_PASSWORD) {
	$key = new ApiKey(NULL);
	$key->set('apk_type', $type);
	$key->set('apk_purpose', $purpose);
	$key->set('apk_permission', 4);
	return $key;
}

function decide(array $auth, $api_entry, $endpoint = 'mail_apps/mail_sync', $user_permission = 0) {
	try {
		ApiAuth::authorize($auth, $api_entry, $user_permission, 'Action', $endpoint);
		return array('allowed' => true);
	} catch (RuntimeException $e) {
		list($status, , $message) = explode('|', $e->getMessage(), 3);
		return array('allowed' => false, 'status' => (int)$status, 'message' => $message);
	}
}

function outside($api_entry, $family) {
	try {
		ApiAuth::refuseAppCredentialOutsideActions($api_entry, $family);
		return true;
	} catch (RuntimeException $e) {
		return false;
	}
}

$MAIL = array('capability' => ApiAuth::CAP_WRITE, 'app_purpose' => 'mail');
$PLAIN = array('capability' => ApiAuth::CAP_WRITE, 'app_purpose' => null);

section('Declared purposes');
check(AppAccess::isDeclared('mail'), 'A plugin\'s declared purpose is known');
check(AppAccess::borrowsUnlock('mail'), 'borrowsUnlock is read from the declaration');
check(AppAccess::isDeclared('caldav') && !AppAccess::borrowsUnlock('caldav'), 'A purpose without borrowsUnlock never borrows');
check(AppAccess::label('mail') === 'Mail apps', 'The label is the declared one');
check(!AppAccess::isDeclared('Bad Name'), 'A name outside the pattern is refused');
check(!AppAccess::isDeclared('shared'), 'A purpose two plugins claim is refused for both');
check(!AppAccess::isDeclared('nonexistent') && !AppAccess::isDeclared(null) && !AppAccess::isDeclared(''),
	'An undeclared purpose is not declared');
check(AppAccess::borrowingPurposes() === array('mail'), 'Only the borrowing purposes are listed as borrowing');

section('A purpose behind a switch');
harness_set_setting_mem('app_access_test_switch', '0');
check(AppAccess::isDeclared('gated') && !AppAccess::isAvailable('gated'), 'Switched off, it is declared but not available');
check(!AppAccess::borrowsUnlock('gated') && !in_array('gated', AppAccess::borrowingPurposes(), true), 'and borrows nothing');
harness_set_setting_mem('app_access_test_switch', '1');
check(AppAccess::isAvailable('gated') && AppAccess::borrowsUnlock('gated'), 'Switched on, it is available and borrows');
check(AppAccess::isAvailable('mail'), 'A purpose with no switch is available whenever it is declared');

section('A purpose credential reaches only its purpose');
$mail = credential('mail');
check($mail->is_app_credential() && $mail->purpose() === 'mail', 'The credential reports its purpose');
check(decide($MAIL, $mail)['allowed'], 'It calls an action declaring its purpose');
$r = decide($PLAIN, $mail, 'notification_unread_count');
check(!$r['allowed'] && $r['status'] === 403, 'It is refused 403 on an action declaring no purpose');
check(!$r['allowed'] && strpos($r['message'], 'Mail apps') !== false, 'The refusal names the purpose', $r['message'] ?? '');
$r = decide(array('capability' => ApiAuth::CAP_READ), $mail, null);
check(!$r['allowed'] && $r['status'] === 403, 'It is refused on a surface with no name (CRUD, forms, management)');
$r = decide(array('capability' => ApiAuth::CAP_WRITE, 'app_purpose' => 'caldav'), $mail);
check(!$r['allowed'] && $r['status'] === 403, 'It is refused on an action of another purpose');

section('An action declaring a purpose admits nothing else');
$r = decide($MAIL, null, 'mail_apps/mail_sync', 5);
check(!$r['allowed'] && $r['status'] === 403, 'A browser session is refused');
$r = decide($MAIL, credential(null, ApiKey::TYPE_SESSION));
check(!$r['allowed'] && $r['status'] === 403, 'An app\'s session key is refused');
$r = decide($MAIL, credential(null, ApiKey::TYPE_MACHINE));
check(!$r['allowed'] && $r['status'] === 403, 'A machine key is refused');
$r = decide($MAIL, credential('caldav'));
check(!$r['allowed'] && $r['status'] === 403, 'A credential of another purpose is refused');

section('Person-only and browser-only actions refuse it');
$r = decide(array('capability' => ApiAuth::CAP_WRITE, 'requires_person_credential' => true), $mail, 'vault_unlock_passkey');
check(!$r['allowed'] && $r['status'] === 403, 'requires_person_credential refuses an app credential');
$r = decide(array('capability' => ApiAuth::CAP_WRITE, 'requires_browser_session' => true), $mail, 'vault_lock');
check(!$r['allowed'] && $r['status'] === 403, 'requires_browser_session refuses an app credential');

section('Every other route family refuses it');
foreach (array('auth', 'app', 'form', 'management', 'drive_upload', 'actions', 'Users') as $family) {
	check(!outside($mail, $family), "Refused on $family");
}
check(outside(credential(null, ApiKey::TYPE_SESSION), 'auth'), 'A session key is not confined');
check(outside(null, 'auth'), 'A browser session is not confined');

section('The app password\'s shape');
$split = ApiKey::SplitAppPassword('jm-abcdefghijklmnop-' . str_repeat('a', 52));
check($split !== null && $split['public_key'] === 'jmabcdefghijklmnop' && strlen($split['secret_key']) === 52,
	'A well-formed password splits into its public and secret halves');
$spaced = 'JM - ABCD EFGH IJKL MNOP - ' . trim(chunk_split(str_repeat('a', 52), 4, ' '));
check(ApiKey::SplitAppPassword($spaced) === $split, 'Spaces and case are forgiven');
check(ApiKey::SplitAppPassword('jm-short-secret') === null, 'A short password is not one');
check(ApiKey::SplitAppPassword('jm-abcdefghijklmnop-' . str_repeat('1', 52)) === null, 'Characters outside base32 are refused');
check(ApiKey::SplitAppPassword('') === null, 'An empty password is not one');
check($mail->kind_label() === 'app password', 'An app password says what kind it is');
check(credential(null, ApiKey::TYPE_SESSION)->kind_label() === null, 'A session key has no app kind');

section('The gateway\'s client address');
$saved = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
check(ApiAuth::appClientIp(array('x_joinery_client_ip' => '203.0.113.9')) === '203.0.113.9', 'Believed from IPv4 loopback');
$_SERVER['REMOTE_ADDR'] = '::1';
check(ApiAuth::appClientIp(array('x_joinery_client_ip' => '2001:db8::0:1')) === '2001:db8::1', 'Believed from IPv6 loopback, as an address');
$_SERVER['REMOTE_ADDR'] = '::ffff:127.0.0.1';
check(ApiAuth::appClientIp(array('x_joinery_client_ip' => '203.0.113.9')) === '203.0.113.9', 'Believed from IPv4-mapped loopback');
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
check(ApiAuth::appClientIp(array('x_joinery_client_ip' => '203.0.113.9')) === '198.51.100.7', 'Ignored from anywhere else');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
check(ApiAuth::appClientIp(array('x_joinery_client_ip' => 'not an address')) === '127.0.0.1', 'A malformed claim is ignored');
if ($saved === null) { unset($_SERVER['REMOTE_ADDR']); } else { $_SERVER['REMOTE_ADDR'] = $saved; }

AppAccess::setDeclarationsForTests(null);
harness_finish();
