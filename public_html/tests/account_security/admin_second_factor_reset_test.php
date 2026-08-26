<?php
/** @joinery-test
 * name: admin_second_factor_reset
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The administrative factor reset — a superadmin's lever for a user who lost
 * their phone, their security key, or both.
 *
 * Two refusals guard passkey removal, and the whole design turns on the fact
 * that they are not the same kind of refusal. The stranding floor protects data
 * that cannot be recreated: strip the last unlocker from an encrypted vault and
 * the bytes are gone, so no authority overrides it and this path must not
 * pretend otherwise. The possession-factor invariant protects a posture: a
 * vault holder should not be reachable with a phished password alone. Kept
 * strictly, that second refusal deadlocks the exact user this feature exists to
 * rescue — TOTP cannot be turned off without a passkey, and the last passkey
 * cannot be revoked with TOTP off — so the admin path is allowed to accept it
 * knowingly, because a re-enrollment gate closes the exposure at the user's
 * next page load.
 *
 * That makes the context argument threaded from PasskeyService::adminRevoke()
 * through to the veto callbacks the load-bearing piece, and the checks below
 * exercise both directions of it on the same fixture: the floor still fires
 * with the admin flag set, the invariant no longer does, and the self-service
 * order on identical state is still refused.
 *
 * The re-enrollment gates are asserted directly rather than over HTTP: both are
 * pure functions of session state and stored factors, and what actually matters
 * about them is that the factor half is a live read — a gate that only noticed
 * a missing factor at the next sign-in would leave the window it exists to
 * close standing open.
 *
 * Sections: callback plumbing; the stranding floor; the invariant bypass;
 * disable_totp's blast radius; the vault gate; the Fortress gate; and the
 * acting-admin gate the handler cannot delegate to the step-up.
 *
 * Run: php tests/account_security/admin_second_factor_reset_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('tests/lib/vault_fixtures.php'));

if (session_id() === '') @session_start();
if (session_id() === '') {
	harness_skip('admin second-factor reset suite', 'no session could be started on the CLI');
	harness_finish();
}

harness_set_setting_mem('passkeys_enabled', '1');
harness_set_setting_mem('email_dry_run', '1');

$session = SessionControl::get_instance();

// The probe callbacks record what the registries were handed. Registered once,
// before anything else registers, so every revocation in this file passes
// through them — including the ones the vault also vetoes.
$GLOBALS['a2fa_pre']  = array();
$GLOBALS['a2fa_post'] = array();
PasskeyService::onPreRevoke(function ($user_id, $credential_id, $context = 'MISSING') {
	$GLOBALS['a2fa_pre'][] = array($user_id, $credential_id, $context);
});
PasskeyService::onPostRevoke(function ($user_id, $credential_id) {
	$GLOBALS['a2fa_post'][] = array($user_id, $credential_id);
});

/** Re-read a credential straight from the database. */
function a2fa_reload_passkey($id) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare('SELECT pkc_passkey_credential_id, pkc_delete_time
		FROM pkc_passkey_credentials WHERE pkc_passkey_credential_id = ?');
	$q->execute(array((int)$id));
	return $q->fetch(PDO::FETCH_ASSOC);
}

/** Soft-delete every recovery wrapping past the first $keep of a vault. */
function a2fa_trim_recovery_codes($vault_id, $keep) {
	$wrappings = new MultiUserEncryptionWrapping(array(
		'vault_id'      => (int)$vault_id,
		'unlocker_type' => UserEncryptionWrapping::TYPE_RECOVERY,
	));
	$wrappings->load();
	$seen = 0;
	foreach ($wrappings as $wrapping) {
		$seen++;
		if ($seen > $keep) {
			$wrapping->soft_delete();
		}
	}
}

$admin = make_user('A2faAdmin', 10);
$admin->enable_totp('JBSWY3DPEHPK3PXP');
$admin->load();

$service = new PasskeyService();

// ---------------------------------------------------------------------------
section('The revocation registries are driven with the admin context');

$plain = make_user('A2faPlain');
$plain_passkey = vault_fixture_passkey((int)$plain->key, 'Admin reset probe');

$GLOBALS['a2fa_pre'] = array();
$GLOBALS['a2fa_post'] = array();
$service->adminRevoke((int)$plain_passkey->key, $plain, $admin);

check(count($GLOBALS['a2fa_pre']) === 1,
	'adminRevoke() runs the pre-revoke registry',
	'calls: ' . count($GLOBALS['a2fa_pre']));
check(count($GLOBALS['a2fa_post']) === 1,
	'adminRevoke() runs the post-revoke registry',
	'calls: ' . count($GLOBALS['a2fa_post']));
check(!empty($GLOBALS['a2fa_pre'][0]) && (int)$GLOBALS['a2fa_pre'][0][0] === (int)$plain->key,
	'the pre-revoke callback is told the TARGET user, not the acting admin',
	'got user_id: ' . var_export($GLOBALS['a2fa_pre'][0][0] ?? null, true) . ', target: ' . $plain->key);
check(!empty($GLOBALS['a2fa_pre'][0]) && $GLOBALS['a2fa_pre'][0][2] === array('admin_reset' => true),
	'the pre-revoke callback receives [\'admin_reset\' => true] as its third argument',
	'context: ' . var_export($GLOBALS['a2fa_pre'][0][2] ?? null, true));

$row = a2fa_reload_passkey($plain_passkey->key);
check($row !== false, 'the credential row still exists — it was soft-deleted, not erased');
check($row !== false && $row['pkc_delete_time'] !== null,
	'the credential is soft-deleted',
	'pkc_delete_time: ' . var_export($row['pkc_delete_time'] ?? null, true));

// A self-service revoke() hands the same registry an EMPTY context, so a
// callback cannot mistake an ordinary revocation for an administrative one.
$plain_second = vault_fixture_passkey((int)$plain->key, 'Self-service probe');
$GLOBALS['a2fa_pre'] = array();
$service->revoke((int)$plain_second->key, $plain);
check(!empty($GLOBALS['a2fa_pre'][0]) && $GLOBALS['a2fa_pre'][0][2] === array(),
	'a self-service revoke() passes an empty context',
	'context: ' . var_export($GLOBALS['a2fa_pre'][0][2] ?? null, true));

// ---------------------------------------------------------------------------
section('The stranding floor is absolute — the admin context does not lift it');

VaultUnlock::registerRevocationHooks();

$stranded = vault_fixture_vault('A2faStrand', '', 10);
// One live passkey wrapping and two unused recovery codes: revoking the passkey
// leaves zero unlockers and fewer than the three codes the floor demands.
a2fa_trim_recovery_codes((int)$stranded['vault']->key, 2);

$vetoed = null;
try {
	$service->adminRevoke((int)$stranded['passkey']->key, $stranded['user'], $admin);
}
catch (PasskeyRevocationVetoException $e) {
	$vetoed = $e;
}
check($vetoed !== null,
	'adminRevoke() is refused when it would strand an encrypted vault',
	$vetoed === null ? 'no exception was thrown' : '');
check($vetoed !== null && stripos($vetoed->getMessage(), 'vault') !== false,
	'the refusal names the vault',
	$vetoed ? $vetoed->getMessage() : '');

$row = a2fa_reload_passkey($stranded['passkey']->key);
check($row !== false && $row['pkc_delete_time'] === null,
	'the vetoed credential is still live — the refusal happened before any mutation');

// ---------------------------------------------------------------------------
section('The possession-factor invariant yields to the admin context');

// Same shape as the stranded fixture but with the floor satisfied: ten unused
// recovery codes, so the ONLY thing standing between this passkey and removal
// is the invariant (vault present, TOTP off, no other passkey).
$bypass = vault_fixture_vault('A2faBypass', '', 10);
$bypass_user = $bypass['user'];
check(!$bypass_user->has_totp_enabled(), 'the fixture user has no authenticator app enrolled');

$self_veto = null;
try {
	$service->revoke((int)$bypass['passkey']->key, $bypass_user);
}
catch (PasskeyRevocationVetoException $e) {
	$self_veto = $e;
}
check($self_veto !== null,
	'the owner\'s own revoke() is refused on this fixture — the invariant holds for self-service',
	$self_veto === null ? 'no exception was thrown' : '');
check($self_veto !== null && stripos($self_veto->getMessage(), 'last passkey') !== false,
	'the self-service refusal is the invariant, not the floor',
	$self_veto ? $self_veto->getMessage() : '');

$row = a2fa_reload_passkey($bypass['passkey']->key);
check($row !== false && $row['pkc_delete_time'] === null,
	'the self-service refusal left the credential live');

$admin_error = null;
try {
	$service->adminRevoke((int)$bypass['passkey']->key, $bypass_user, $admin);
}
catch (\Throwable $e) {
	$admin_error = $e;
}
check($admin_error === null,
	'adminRevoke() succeeds on the same state the owner was refused',
	$admin_error ? get_class($admin_error) . ': ' . $admin_error->getMessage() : '');

$row = a2fa_reload_passkey($bypass['passkey']->key);
check($row !== false && $row['pkc_delete_time'] !== null,
	'the credential is soft-deleted after the administrative removal');

// ---------------------------------------------------------------------------
section('Disabling TOTP clears the factor and ends device trust');

$totp_user = make_user('A2faTotp');
$totp_user->enable_totp('JBSWY3DPEHPK3PXP');
$totp_user->set('usr_totp_backup_codes', json_encode(array('aaaa-bbbb', 'cccc-dddd')));
$totp_user->set('usr_totp_last_used_step', 12345);
$totp_user->set('usr_second_factor_hmac_key', str_repeat('a', 128));
$totp_user->save();
$totp_user->load();

$hmac_before = $totp_user->get('usr_second_factor_hmac_key');
check($totp_user->has_totp_enabled(), 'the fixture starts with TOTP enabled');

$totp_user->disable_totp();
$reloaded = new User($totp_user->key, TRUE);

check(!$reloaded->has_totp_enabled(), 'TOTP reads as disabled afterwards');
check($reloaded->get('usr_totp_secret') === null, 'the shared secret is cleared');
check($reloaded->get('usr_totp_enabled_time') === null, 'the enabled timestamp is cleared');
check($reloaded->get('usr_totp_backup_codes') === null, 'the backup codes are cleared');
check($reloaded->get('usr_totp_last_used_step') === null, 'the replay counter is cleared');
check($reloaded->get('usr_second_factor_hmac_key') !== $hmac_before,
	'the trusted-device HMAC key is rotated, signing out every remembered browser');

// ---------------------------------------------------------------------------
section('The vault re-enrollment gate');

// A vault holder with nothing enrolled — the state only an administrative reset
// can produce, and the one the gate exists to undo.
$gated = make_user('A2faGate');
vault_fixture_client_vault((int)$gated->key, base64_encode(random_bytes(32)), 'drive');

$saved_session = $_SESSION;
$_SESSION['usr_user_id'] = $gated->key;
$_SESSION['loggedin'] = true;
unset($_SESSION['has_encryption_vault']);

check($session->must_enroll_2fa_for_vault(),
	'a vault holder with no second factor is gated');

// The factor half is a LIVE read: enrolling clears the gate on the very next
// call, without the session cache being touched.
$gated->enable_totp('JBSWY3DPEHPK3PXP');
$gated->load();
check(!$session->must_enroll_2fa_for_vault(),
	'enrolling a factor clears the gate immediately, with the vault cache still warm',
	'has_encryption_vault: ' . var_export($_SESSION['has_encryption_vault'] ?? null, true));
check(!empty($_SESSION['has_encryption_vault']),
	'vault existence is what got cached — the expensive half, not the factor check');

// A user with no vault is never gated, whatever their factors.
$novault = make_user('A2faNoVault');
$_SESSION['usr_user_id'] = $novault->key;
unset($_SESSION['has_encryption_vault']);
check(!$session->must_enroll_2fa_for_vault(),
	'an account with no vault is not gated');

// The cache belongs to a user, not to a session. One session outlives one
// user - "log in as user" swaps it, and so does a second sign-in without a
// logout - so a vault holder's cached yes must not follow the switch onto a
// factorless account that owns nothing to protect.
$_SESSION['usr_user_id'] = $gated->key;
unset($_SESSION['has_encryption_vault']);
$session->must_enroll_2fa_for_vault();
check(!empty($_SESSION['has_encryption_vault']), 'the vault holder\'s answer is cached');
$_SESSION['usr_user_id'] = $novault->key;
check(!$session->must_enroll_2fa_for_vault(),
	'switching the session to a vault-less user recomputes instead of reusing that yes');

// And the switch itself drops every cached posture answer, so the arriving user
// is never judged on the departing user's vault, Fortress level or password
// flag. This is the path "log in as user" takes.
$_SESSION['usr_user_id'] = $gated->key;
unset($_SESSION['has_encryption_vault']);
$session->must_enroll_2fa_for_vault();
$_SESSION['max_security_level'] = 'fortress';
$_SESSION['force_password_change'] = true;
$session->store_session_variables($novault);
check(!isset($_SESSION['has_encryption_vault']) && !isset($_SESSION['has_encryption_vault_uid']),
	'switching identity drops the cached vault answer');
check(!isset($_SESSION['max_security_level']),
	'switching identity drops the cached Fortress posture');
check(!isset($_SESSION['force_password_change']),
	'switching identity drops the cached forced-password-change flag');

unset($_SESSION['usr_user_id']);
unset($_SESSION['has_encryption_vault']);
check(!$session->must_enroll_2fa_for_vault(),
	'an anonymous visitor is not gated');

// ---------------------------------------------------------------------------
section('The Fortress gate still notices a factor removed by an admin');

// Fortress demands a factor INDEPENDENT of any single passkey: TOTP, or two
// passkeys. Two passkeys and no TOTP satisfies it; taking one away does not.
$fortress = make_user('A2faFortress');
$fortress_a = vault_fixture_passkey((int)$fortress->key, 'Fortress key A');
$fortress_b = vault_fixture_passkey((int)$fortress->key, 'Fortress key B');

$_SESSION['usr_user_id'] = $fortress->key;
$_SESSION['loggedin'] = true;
// The posture lookup is the cached half; a Fortress domain fixture would need
// the mailbox plugin, and the gate reads this value either way.
$_SESSION['max_security_level'] = 'fortress';

check(!$session->must_enroll_2fa_for_fortress(),
	'two passkeys satisfy the Fortress independent-factor requirement');

$service->adminRevoke((int)$fortress_b->key, $fortress, $admin);

check($session->must_enroll_2fa_for_fortress(),
	'removing one of them through the admin path flips the gate on, on the next call');

unset($_SESSION['max_security_level']);

// ---------------------------------------------------------------------------
section('The acting-admin gate the step-up cannot provide');

// require_recent_second_factor() stands aside for an account with no factor —
// deliberately, so an optional-2FA account is not locked out of its own
// settings. That is exactly why the handler refuses a factorless acting admin
// itself: relying on the step-up alone would let an admin who enrolled nothing
// strip everyone else's factors with a stolen session cookie.
$factorless_admin = make_user('A2faWeakAdmin', 10);
$_SESSION['usr_user_id'] = $factorless_admin->key;

check($session->require_recent_second_factor('/admin/admin_user?usr_user_id=1') === null,
	'the step-up gate is a no-op for a factorless acting admin');
check(!$session->user_has_second_factor($factorless_admin),
	'so the handler\'s own-factor refusal is the only thing standing there');
check($session->user_has_second_factor($admin),
	'an admin who enrolled a factor passes that refusal');

$_SESSION = $saved_session;

harness_finish();
