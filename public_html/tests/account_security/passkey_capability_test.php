<?php
/** @joinery-test
 * name: passkey_capability
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Vault capability detection — which enrolled passkeys can ever unlock a Sealed
 * Vault, and which ceremonies are allowed to act on that answer.
 *
 * Nothing here can drive a real ceremony: the WebAuthn virtual authenticator
 * does not implement PRF, so every claim about what a physical authenticator
 * does needs hardware (see the Live verification section of
 * specs/passkey_vault_capability_detection.md). What IS assertable, and is the
 * whole of this suite, is the classification rule over stored signals and the
 * shape of the options the server mints from it — which is exactly where the two
 * bugs this work fixes lived.
 *
 * Tier is `db` rather than `safe`: the offer rule and the scoping regression
 * guard both need real credential and wrapping rows to intersect.
 *
 * Run: php tests/account_security/passkey_capability_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');

require_once(PathHelper::getIncludePath('data/passkeys_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
// PasskeyService declares classes against WebAuthn library interfaces at file
// level, so the composer autoloader must be in place before the include.
require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));

harness_set_setting_mem('passkeys_enabled', '1');

// Every get*Options() call stashes its challenge against the browser session id,
// so the ceremony sections need a session even on the CLI.
if (session_id() === '') @session_start();
if (session_id() === '') {
	harness_skip('passkey capability suite', 'no session could be started on the CLI');
	harness_finish();
}
$sid = session_id();
harness_defer(function () use ($sid) {
	try {
		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare("DELETE FROM pks_passkey_ceremonies WHERE pks_session_id = ?")->execute(array($sid));
	} catch (\Throwable $e) {
		echo "  WARNING: could not clean ceremony rows for $sid: " . $e->getMessage() . "\n";
	}
});

/**
 * A credential row shaped by its stored signals. $source_uv is what
 * pkc_source_json's uvInitialized should say: true, false, or null for "never
 * recorded", which is the shape of a row written before the flag existed.
 */
function cap_passkey(int $user_id, string $label, array $spec): Passkey {
	$p = new Passkey(NULL);
	$p->set('pkc_usr_user_id', $user_id);
	// Must be real base64url: the ceremonies decode this back to raw bytes.
	$p->set('pkc_credential_id', rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='));
	$source = array('counter' => 0);
	if (array_key_exists('uv', $spec)) {
		$source['uvInitialized'] = $spec['uv'];
	}
	$p->set('pkc_source_json', json_encode($source));
	$p->set('pkc_transports', json_encode($spec['transports'] ?? array()));
	$p->set('pkc_prf_capable', !empty($spec['prf']));
	if (array_key_exists('discoverable', $spec)) {
		$p->set('pkc_discoverable', $spec['discoverable']);
	}
	if (array_key_exists('attachment', $spec)) {
		$p->set('pkc_attachment', $spec['attachment']);
	}
	$p->set('pkc_label', $label);
	$p->save();
	$p->load();
	harness_register_row('pkc_passkey_credentials', 'pkc_passkey_credential_id', (int)$p->key);
	return $p;
}

/** The base64url credential ids an options payload actually offers. */
function cap_allowed(array $options): array {
	$ids = array();
	foreach ($options['allowCredentials'] ?? array() as $descriptor) {
		$ids[] = $descriptor['id'] ?? '';
	}
	sort($ids);
	return $ids;
}

// ---------------------------------------------------------------------------
section('The classification rule');

$user = make_user('PkCapA');

// The signals registration records now. All three must be known and negative.
$u2f = cap_passkey($user->key, 'U2F only', array(
	'prf' => false, 'discoverable' => false, 'attachment' => 'cross-platform',
	'transports' => array('usb'), 'uv' => false));
check($u2f->vault_capability() === Passkey::VAULT_INCAPABLE,
	'no PRF + non-discoverable + cross-platform is incapable',
	'got: ' . $u2f->vault_capability());

$pinless = cap_passkey($user->key, 'FIDO2 no PIN', array(
	'prf' => true, 'discoverable' => true, 'attachment' => 'cross-platform',
	'transports' => array('usb'), 'uv' => false));
check($pinless->vault_capability() === Passkey::VAULT_CAPABLE,
	'a key that reported PRF is capable even though it has never verified a user — '
	. 'this is the whole premise: hmac-secret is a property of the key, not of the PIN',
	'got: ' . $pinless->vault_capability());

// Each of the three negative signals withdrawn in turn. A null is the absence
// of a signal, never a negative one.
$partial = cap_passkey($user->key, 'No attachment reported', array(
	'prf' => false, 'discoverable' => false,
	'transports' => array('usb'), 'uv' => true));
check($partial->vault_capability() === Passkey::VAULT_UNKNOWN,
	'a missing attachment leaves the credential unknown, not incapable',
	'got: ' . $partial->vault_capability());

$partial = cap_passkey($user->key, 'No credProps reported', array(
	'prf' => false, 'attachment' => 'cross-platform',
	'transports' => array('usb'), 'uv' => true));
check($partial->vault_capability() === Passkey::VAULT_UNKNOWN,
	'a missing discoverable flag leaves the credential unknown',
	'got: ' . $partial->vault_capability());

$discoverable = cap_passkey($user->key, 'Discoverable security key', array(
	'prf' => false, 'discoverable' => true, 'attachment' => 'cross-platform',
	'transports' => array('usb'), 'uv' => true));
check($discoverable->vault_capability() === Passkey::VAULT_UNKNOWN,
	'a discoverable credential is never incapable — CTAP1 cannot make one',
	'got: ' . $discoverable->vault_capability());

// Evidence set two: what rows enrolled before those columns existed carry.
$legacy_u2f = cap_passkey($user->key, 'Legacy U2F', array(
	'prf' => false, 'transports' => array('usb', 'nfc'), 'uv' => false));
check($legacy_u2f->vault_capability() === Passkey::VAULT_INCAPABLE,
	'a pre-columns row is classified from uvInitialized + transports',
	'got: ' . $legacy_u2f->vault_capability());

$hello = cap_passkey($user->key, 'Windows Hello', array(
	'prf' => false, 'transports' => array('internal'), 'uv' => false));
check($hello->vault_capability() === Passkey::VAULT_UNKNOWN,
	'a platform authenticator is never incapable — Windows Hello reports no PRF '
	. 'at creation and evaluates it fine at assertion',
	'got: ' . $hello->vault_capability());

$no_flag = cap_passkey($user->key, 'No uvInitialized', array(
	'prf' => false, 'transports' => array('usb')));
check($no_flag->vault_capability() === Passkey::VAULT_UNKNOWN,
	'an unrecorded uvInitialized is not evidence of anything',
	'got: ' . $no_flag->vault_capability());

$no_transports = cap_passkey($user->key, 'No transports', array(
	'prf' => false, 'transports' => array(), 'uv' => false));
check($no_transports->vault_capability() === Passkey::VAULT_UNKNOWN,
	'an empty transport list is not a claim to be a security key',
	'got: ' . $no_transports->vault_capability());

// Evidence beats reporting, in the one direction that is provable.
$upgraded = cap_passkey($user->key, 'Proven by use', array(
	'prf' => true, 'discoverable' => false, 'attachment' => 'cross-platform',
	'transports' => array('usb'), 'uv' => false));
check($upgraded->vault_capability() === Passkey::VAULT_CAPABLE,
	'a credential that has evaluated PRF is capable whatever the other signals say',
	'got: ' . $upgraded->vault_capability());

check(($u2f->export_for_api()['vault_capability'] ?? null) === Passkey::VAULT_INCAPABLE,
	'the API export carries the derived capability, so every reader agrees');

// ---------------------------------------------------------------------------
section('Scoping a derivation ceremony');

// The regression guard for the misrouting bug: "Activate for vault" on one row
// used to accept any enrolled passkey, so tapping a different authenticator
// activated THAT one while the row clicked still read Not activated. Assertable
// without hardware because it only inspects the options the server mints.
$scope_user = make_user('PkCapB');
$first = cap_passkey($scope_user->key, 'First key', array('prf' => true, 'transports' => array('usb'), 'uv' => true));
$second = cap_passkey($scope_user->key, 'Second key', array('prf' => true, 'transports' => array('internal'), 'uv' => true));
$service = new PasskeyService();

$options = $service->getDerivationOptions($scope_user, 'vault-kek');
check(count($options['allowCredentials'] ?? array()) === 2,
	'an unscoped ceremony still offers every credential');

$options = $service->getDerivationOptions($scope_user, 'vault-kek', array((int)$first->key));
check(cap_allowed($options) === array($first->get('pkc_credential_id')),
	'a scoped ceremony offers exactly the named credential',
	'offered: ' . json_encode(cap_allowed($options)));

$options = $service->getDerivationOptions($scope_user, 'vault-kek', array());
check(count($options['allowCredentials'] ?? array()) === 2,
	'an empty filter means no opinion, not an empty allow list — minting an empty '
	. 'allowCredentials on the unlock path would be a vault lockout');

$threw = false;
try {
	$service->getDerivationOptions($scope_user, 'vault-kek', array(-1));
} catch (PasskeyException $e) {
	$threw = true;
}
check($threw, 'a filter naming no owned credential refuses rather than offering everything');

// ---------------------------------------------------------------------------
section('Which passkeys a vault prompt offers');

if (!extension_loaded('sodium')) {
	harness_skip('offer rule', 'sodium extension unavailable — no vault can be built');
} else {
	// vault_fixture_vault() runs the real setup ceremony, so its user has one
	// passkey holding one wrapping. A second, unwrapped passkey is what the rule
	// has to exclude.
	$fx = vault_fixture_vault('CapOffer');
	$owner = $fx['user'];
	$wrapped = $fx['passkey'];
	$unwrapped = cap_passkey($owner->key, 'Not an unlocker', array('prf' => true, 'transports' => array('usb'), 'uv' => true));

	$offered = VaultUnlock::offerableCredentialIds((int)$owner->key, UserEncryptionVault::SCOPE_USER);
	check($offered === array((int)$wrapped->key),
		'with wrappings present, only the credentials holding one are offered',
		'offered: ' . json_encode($offered) . ' wrapped: ' . (int)$wrapped->key);

	$options = $service->getDerivationOptions($owner, 'vault-kek', $offered);
	check(cap_allowed($options) === array($wrapped->get('pkc_credential_id')),
		'the unlock ceremony carries that answer through to allowCredentials');

	// A vault that does not exist yet: the rule falls through to capability,
	// which is the only place classification is allowed to decide anything.
	$fresh = make_user('PkCapC');
	$fresh_ok = cap_passkey($fresh->key, 'Untested key', array('prf' => false, 'transports' => array('usb'), 'uv' => true));
	$fresh_u2f = cap_passkey($fresh->key, 'U2F key', array(
		'prf' => false, 'discoverable' => false, 'attachment' => 'cross-platform',
		'transports' => array('usb'), 'uv' => false));
	$offered = VaultUnlock::offerableCredentialIds((int)$fresh->key, UserEncryptionVault::SCOPE_USER);
	check($offered === array((int)$fresh_ok->key),
		'with no vault, setup offers the unknown credential and drops the incapable one',
		'offered: ' . json_encode($offered));

	// The fallback that must never be reachable by the rule, and must hold
	// anyway if a future caller reorders the branches.
	$none = make_user('PkCapD');
	$only_u2f = cap_passkey($none->key, 'Only a U2F key', array(
		'prf' => false, 'discoverable' => false, 'attachment' => 'cross-platform',
		'transports' => array('usb'), 'uv' => false));
	check(VaultUnlock::offerableCredentialIds((int)$none->key, UserEncryptionVault::SCOPE_USER) === array(),
		'an all-incapable account computes an empty set');
	$options = $service->getDerivationOptions($none, 'vault-kek', array());
	check(cap_allowed($options) === array($only_u2f->get('pkc_credential_id')),
		'and the empty set falls back to offering everything rather than nothing');
}

// ---------------------------------------------------------------------------
section('Backfill');

// The migration's job is to mark U2F-only keys and leave everything else alone,
// so the negative cases are the ones that matter.
require_once(PathHelper::getIncludePath('migrations/backfill_passkey_vault_capability.php'));

$bf = make_user('PkCapE');
$bf_u2f      = cap_passkey($bf->key, 'BF u2f', array('prf' => false, 'transports' => array('usb'), 'uv' => false));
$bf_pinless  = cap_passkey($bf->key, 'BF pinless fido2', array('prf' => true, 'transports' => array('usb'), 'uv' => false));
$bf_platform = cap_passkey($bf->key, 'BF platform', array('prf' => false, 'transports' => array('internal'), 'uv' => false));
$bf_no_flag  = cap_passkey($bf->key, 'BF no flag', array('prf' => false, 'transports' => array('usb')));
$bf_verified = cap_passkey($bf->key, 'BF verified', array('prf' => false, 'transports' => array('usb'), 'uv' => true));

ob_start();
backfill_passkey_vault_capability();
$log = ob_get_clean();

$marked = function (Passkey $p) {
	$fresh = new Passkey((int)$p->key, TRUE);
	return $fresh->get('pkc_discoverable') === false && $fresh->get('pkc_attachment') === 'cross-platform';
};

check($marked($bf_u2f), 'a U2F-shaped row is marked');
check(!$marked($bf_pinless), 'a PIN-less FIDO2 row is left alone — it reported PRF');
check(!$marked($bf_platform), 'a platform row is left alone');
check(!$marked($bf_no_flag), 'a row with no uvInitialized is left alone');
check(!$marked($bf_verified), 'a row that has verified a user is left alone');
check(strpos($log, (string)$bf_u2f->key) !== false,
	'the migration names the credential it marked',
	'log: ' . trim($log));

$before = $log;
ob_start();
backfill_passkey_vault_capability();
$again = ob_get_clean();
check(strpos($again, (string)$bf_u2f->key) === false,
	'a second run skips the already-stamped row rather than re-reporting it',
	'log: ' . trim($again));

harness_finish();
