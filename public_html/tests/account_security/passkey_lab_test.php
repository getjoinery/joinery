<?php
/** @joinery-test
 * name: passkey_lab
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Passkey lab — the superadmin diagnostic page that reproduces a failing
 * WebAuthn ceremony one variable at a time (adm/admin_passkey_lab.php).
 *
 * The lab is only useful if each variant's request shape is exactly what its
 * button claims, and only safe if completing a diagnostic ceremony grants
 * nothing and no one below superadmin can reach it. So: assert the minted
 * options for every knob (user verification, PRF extension, credential
 * subset), the single-use challenge stash, and the API permission gate.
 *
 * Run: php tests/account_security/passkey_lab_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/passkeys_class.php'));
require_once(PathHelper::getIncludePath('data/passkey_ceremonies_class.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
// PasskeyService declares classes against WebAuthn library interfaces at file
// level, so the composer autoloader must be in place before the include.
require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('logic/passkey_lab_options_logic.php'));

if (session_id() === '') @session_start();
if (session_id() === '') {
	harness_skip('passkey lab suite', 'no session could be started on the CLI');
	harness_finish();
}

harness_set_setting_mem('passkeys_enabled', '1');

$sid = session_id();
harness_defer(function () use ($sid) {
	try {
		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare("DELETE FROM pks_passkey_ceremonies WHERE pks_session_id = ?")->execute(array($sid));
	} catch (\Throwable $e) {
		echo "  WARNING: could not clean ceremony rows for $sid: " . $e->getMessage() . "\n";
	}
});

$super = make_user('PkLabSuper', 10);
$plain = make_user('PkLabPlain', 0);

harness_defer(function () use ($super, $plain) {
	try {
		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare("DELETE FROM rql_request_logs WHERE rql_feature = 'passkey_lab' AND rql_usr_user_id IN (?, ?)")
			->execute(array((int)$super->key, (int)$plain->key));
	} catch (\Throwable $e) {
		echo "  WARNING: could not clean lab log rows: " . $e->getMessage() . "\n";
	}
});

function lab_b64url($bytes) {
	return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function lab_make_passkey($user_id, $label, $transports, $prf) {
	$pk = new Passkey(NULL);
	$pk->set('pkc_usr_user_id', intval($user_id));
	$pk->set('pkc_credential_id', lab_b64url(random_bytes(16)));
	$pk->set('pkc_source_json', '{}');
	$pk->set('pkc_transports', json_encode($transports));
	$pk->set('pkc_prf_capable', $prf);
	$pk->set('pkc_label', $label);
	$pk->save();
	$pk->load();
	harness_register_row('pkc_passkey_credentials', 'pkc_passkey_credential_id', intval($pk->key));
	return $pk;
}

$platform_key = lab_make_passkey($super->key, 'Lab platform key', array('internal', 'hybrid'), true);
$usb_key = lab_make_passkey($super->key, 'Lab security key', array('usb', 'nfc'), false);

$service = new PasskeyService();

function lab_challenge_rows($sid, $purpose) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare("SELECT COUNT(*) AS cnt FROM pks_passkey_ceremonies
		WHERE pks_session_id = ? AND pks_kind = 'challenge' AND pks_purpose = ?");
	$q->execute(array($sid, $purpose));
	return (int)$q->fetch(PDO::FETCH_ASSOC)['cnt'];
}

// ---------------------------------------------------------------------------
section('Options shapes');

$opt = $service->getDiagnosticOptions($super);
check(($opt['userVerification'] ?? '') === 'required',
	'the default variant demands user verification');
check((int)($opt['timeout'] ?? 0) === 120000,
	'the default variant carries the 120-second timeout');
check(count($opt['allowCredentials'] ?? array()) === 2,
	'the default variant lists every live credential',
	'allowCredentials: ' . var_export($opt['allowCredentials'] ?? null, true));
check(empty($opt['extensions']['prf']),
	'the default variant carries no PRF extension');
check(!empty($opt['challenge']) && is_string($opt['challenge']),
	'the options carry a challenge');

$opt = $service->getDiagnosticOptions($super, array('prf' => true));
check(!empty($opt['extensions']['prf']['eval']['first']) && is_string($opt['extensions']['prf']['eval']['first']),
	'the PRF variant carries a throwaway eval input',
	'extensions: ' . var_export($opt['extensions'] ?? null, true));

$opt = $service->getDiagnosticOptions($super, array('credential_ids' => array($usb_key->get('pkc_credential_id'))));
check(count($opt['allowCredentials'] ?? array()) === 1
	&& ($opt['allowCredentials'][0]['id'] ?? '') === $usb_key->get('pkc_credential_id'),
	'a credential whitelist narrows the allow list to exactly that key',
	'allowCredentials: ' . var_export($opt['allowCredentials'] ?? null, true));

$opt = $service->getDiagnosticOptions($super, array('uv' => 'preferred'));
check(($opt['userVerification'] ?? '') === 'preferred',
	'the preferred-verification variant passes through');

$opt = $service->getDiagnosticOptions($super, array('uv' => 'discouraged'));
check(($opt['userVerification'] ?? '') === 'required',
	'an unrecognized verification value falls back to required');

$threw = false;
try {
	$service->getDiagnosticOptions($super, array('credential_ids' => array('no-such-credential')));
} catch (PasskeyException $e) {
	$threw = true;
}
check($threw, 'a whitelist matching no credential refuses rather than minting an empty allow list');

// ---------------------------------------------------------------------------
section('Challenge stash');

$purpose = 'lab:' . $super->key;
$service->getDiagnosticOptions($super);
check(lab_challenge_rows($sid, $purpose) === 1,
	'minting options stashes one lab challenge for this session');

$service->getDiagnosticOptions($super);
check(lab_challenge_rows($sid, $purpose) === 1,
	'minting again replaces the pending challenge rather than stacking a second');

// ---------------------------------------------------------------------------
section('Verify is fenced');

// A malformed assertion is rejected - and the attempt burns the challenge, so
// the stash is single-use even for garbage input.
$threw = false;
try {
	$service->verifyDiagnostic('{"id":"bogus"}', $super);
} catch (\Throwable $e) {
	$threw = true;
}
check($threw, 'a malformed assertion is rejected');

$threw = false;
$message = '';
try {
	$service->verifyDiagnostic('{"id":"bogus"}', $super);
} catch (\Throwable $e) {
	$threw = true;
	$message = $e->getMessage();
}
check($threw && stripos($message, 'expired') !== false,
	'the diagnostic challenge is single-use - a second verify finds it gone',
	'message: ' . $message);

// ---------------------------------------------------------------------------
section('The API gate');

$_SESSION['usr_user_id'] = $plain->key;
$_SESSION['loggedin'] = true;
$_SESSION['permission'] = 0;
$res = passkey_lab_options_logic(array('variant' => 'gate-test'));
check($res->error === 'Not authorized.',
	'a non-superadmin is refused lab options',
	'error: ' . var_export($res->error, true));

$_SESSION['usr_user_id'] = $super->key;
$_SESSION['permission'] = 10;
$res = passkey_lab_options_logic(array('variant' => 'gate-test', 'uv' => 'preferred', 'prf' => true));
check($res->error === null && !empty($res->data['options']['challenge']),
	'a superadmin receives ceremony options',
	'error: ' . var_export($res->error, true));
check(($res->data['options']['userVerification'] ?? '') === 'preferred'
	&& !empty($res->data['options']['extensions']['prf']['eval']['first']),
	'the requested variant shape flows through the action');

$_SESSION = array();
harness_finish();
