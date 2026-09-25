<?php
/** @joinery-test
 * name: vault_passphrase_fallback
 * tier: db
 * env: any
 * needs: [db]
 */
/**
 * The passphrase compatibility fallback (docs/sealed_vault.md § When a
 * passkey cannot hold the key). A vault with no passkey wrapping is weaker than
 * one with, so the whole point of these checks is that it can ONLY be reached
 * by an account whose every credential is provably incapable —
 * and that the refusal lives in the ceremony, not in the page that hides the
 * button.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/VaultCeremonies.php'));
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('data/passkey_credentials_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));
require_once(__DIR__ . '/../lib/vault_fixtures.php');

/** A credential row good enough for capability questions. */
function fallback_make_passkey(int $user_id, bool $prf_capable, bool $prf_failed = false) {
	$passkey = new Passkey(NULL);
	$passkey->set('pkc_usr_user_id', $user_id);
	$passkey->set('pkc_credential_id', 'cred_' . bin2hex(random_bytes(8)));
	$passkey->set('pkc_source_json', json_encode(array('uvInitialized' => true)));
	$passkey->set('pkc_prf_capable', $prf_capable);
	if ($prf_failed) {
		$passkey->set('pkc_prf_failed_time', gmdate('Y-m-d H:i:s'));
	}
	$passkey->set('pkc_transports', json_encode(array('internal')));
	$passkey->save();
	harness_register_row('pkc_passkey_credentials', 'pkc_passkey_credential_id', $passkey->key);
	return $passkey;
}

function fallback_register_vault_cleanup(int $user_id) {
	$vaults = new MultiUserEncryptionVault(array('user_id' => $user_id));
	foreach ($vaults as $vault) {
		harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', $vault->key);
	}
}

$ceremonies = new VaultCeremonies();
$phrase = 'correct horse battery staple';

/**
 * What a browser posts for a phrase-only setup: the account half of the
 * phrase's KEK, a code set, and the root vault holding the phrase's other half
 * (specs/one_vault_experience.md § R6, R7). The phrase never leaves the browser.
 */
function fallback_setup_parts(string $phrase): array {
	$salt = vault_fixture_root_salt();
	$codes = vault_fixture_code_set($salt, 10);
	[$account, $root_kek] = vault_fixture_passphrase($phrase, $salt);
	return array($account, $codes['set'], vault_fixture_root($salt, $codes, null, null, $root_kek)['payload']);
}

section('A working passkey route blocks the fallback');
$capable_user = make_user('VaultFbCapable');
fallback_make_passkey((int)$capable_user->key, true);
check(Passkey::userHasVaultCapableOption((int)$capable_user->key) === true,
	'a PRF-capable credential counts as a usable route');
check(Passkey::userNeedsPassphraseFallback((int)$capable_user->key) === false,
	'so the account is not eligible for the phrase fallback');

$refused = null;
try {
	[$pk, $set, $root] = fallback_setup_parts($phrase);
	$ceremonies->setup($capable_user, 0, null, '', $pk, $set, $root, false);
} catch (VaultCeremonyException $e) {
	$refused = $e->getMessage();
}
check($refused !== null, 'the CEREMONY refuses a passkeyless vault for that account',
	'the gate must not live only in the page that hides the button');
check((new MultiUserEncryptionVault(array('user_id' => (int)$capable_user->key)))->count_all() === 0,
	'and nothing was created');

section('An untested account is not eligible either');
$fresh_user = make_user('VaultFbFresh');
check(Passkey::userNeedsPassphraseFallback((int)$fresh_user->key) === false,
	'an account with NO passkeys is sent to enrol one, not handed the weaker unlocker',
	'otherwise deleting your passkeys would be a way to opt into a phrase');

section('A proven-incapable account gets the fallback');
$blocked_user = make_user('VaultFbBlocked');
$blocked_passkey = fallback_make_passkey((int)$blocked_user->key, false, true);
check($blocked_passkey->vault_capability() === Passkey::VAULT_INCAPABLE,
	'a verified failed derivation makes the credential incapable');
check(Passkey::userNeedsPassphraseFallback((int)$blocked_user->key) === true,
	'and the account becomes eligible');

// The length rule is the browser's (the phrase never arrives); what the
// server can refuse is a phrase whose root half is missing.
[$pk, $set, $root] = fallback_setup_parts($phrase);
$root['wrappings'] = array_values(array_filter($root['wrappings'], function ($w) { return $w['unlocker_type'] !== 'passphrase'; }));
$no_root_half = null;
try {
	$ceremonies->setup($blocked_user, 0, null, '', $pk, $set, $root, false);
} catch (VaultCeremonyException $e) {
	$no_root_half = $e->getMessage();
}
check($no_root_half !== null, 'a phrase that does not also open the root vault is refused');
check((new MultiUserEncryptionVault(array('user_id' => (int)$blocked_user->key)))->count_all() === 0, 'and nothing was created');

[$pk, $set, $root] = fallback_setup_parts($phrase);
$result = $ceremonies->setup($blocked_user, 0, null, '', $pk, $set, $root, false);
fallback_register_vault_cleanup((int)$blocked_user->key);
check(!empty($result['vault']->key), 'the vault is created');
$root_vault = UserEncryptionVault::loadForUser((int)$blocked_user->key, VaultScopes::ROOT_SCOPE);
check($root_vault !== null, 'with the root vault beside it');

$wrappings = new MultiUserEncryptionWrapping(array('vault_id' => (int)$result['vault']->key));
$types = array();
foreach ($wrappings as $wrapping) {
	$types[] = $wrapping->get('uew_unlocker_type');
}
check(!in_array(UserEncryptionWrapping::TYPE_PASSKEY, $types, true),
	'and NO passkey wrapping — there was no passkey able to make one');
check(in_array(UserEncryptionWrapping::TYPE_PASSPHRASE, $types, true),
	'the passphrase is a live unlocker');
check(count(array_filter($types, function ($t) { return $t === UserEncryptionWrapping::TYPE_RECOVERY; })) === 10,
	'alongside the recovery codes, so the vault is never left with one way in');

section('A phrase is mandatory when there is no passkey');
$blocked_two = make_user('VaultFbNoPhrase');
fallback_make_passkey((int)$blocked_two->key, false, true);
$no_phrase = null;
try {
	[, $set, $root] = fallback_setup_parts($phrase);
	$ceremonies->setup($blocked_two, 0, null, '', '', $set, $root, false);
} catch (VaultCeremonyException $e) {
	$no_phrase = $e->getMessage();
}
check($no_phrase !== null, 'a passkeyless vault with no phrase is refused',
	'that would leave recovery codes as the only unlocker');

harness_finish();
