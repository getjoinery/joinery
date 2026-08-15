<?php
/** @joinery-test
 * name: vault_passphrase_fallback
 * tier: db
 * env: any
 * needs: [db]
 */
/**
 * The bypass-phrase compatibility fallback (docs/sealed_vault.md § When a
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
require_once(PathHelper::getIncludePath('data/passkeys_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));

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

section('A working passkey route blocks the fallback');
$capable_user = make_user('VaultFbCapable');
fallback_make_passkey((int)$capable_user->key, true);
check(Passkey::userHasVaultCapableOption((int)$capable_user->key) === true,
	'a PRF-capable credential counts as a usable route');
check(Passkey::userNeedsPassphraseFallback((int)$capable_user->key) === false,
	'so the account is not eligible for the phrase fallback');

$refused = null;
try {
	$ceremonies->setup($capable_user, 0, null, '', $phrase, 10, false);
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

$short = null;
try {
	$ceremonies->setup($blocked_user, 0, null, '', 'short', 10, false);
} catch (VaultCeremonyException $e) {
	$short = $e->getMessage();
}
check($short !== null, 'a too-short phrase is still refused');

$result = $ceremonies->setup($blocked_user, 0, null, '', $phrase, 10, false);
fallback_register_vault_cleanup((int)$blocked_user->key);
check(!empty($result['vault']->key), 'the vault is created');
check(count($result['recovery_codes']) === 10, 'with its recovery codes');

$wrappings = new MultiUserEncryptionWrapping(array('vault_id' => (int)$result['vault']->key));
$types = array();
foreach ($wrappings as $wrapping) {
	$types[] = $wrapping->get('uew_unlocker_type');
}
check(!in_array(UserEncryptionWrapping::TYPE_PASSKEY, $types, true),
	'and NO passkey wrapping — there was no passkey able to make one');
check(in_array(UserEncryptionWrapping::TYPE_PASSPHRASE, $types, true),
	'the bypass phrase is a live unlocker');
check(count(array_filter($types, function ($t) { return $t === UserEncryptionWrapping::TYPE_RECOVERY; })) === 10,
	'alongside the recovery codes, so the vault is never left with one way in');

section('A phrase is mandatory when there is no passkey');
$blocked_two = make_user('VaultFbNoPhrase');
fallback_make_passkey((int)$blocked_two->key, false, true);
$no_phrase = null;
try {
	$ceremonies->setup($blocked_two, 0, null, '', '', 10, false);
} catch (VaultCeremonyException $e) {
	$no_phrase = $e->getMessage();
}
check($no_phrase !== null, 'a passkeyless vault with no phrase is refused',
	'that would leave recovery codes as the only unlocker');

harness_finish();
