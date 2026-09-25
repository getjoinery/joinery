<?php
/** @joinery-test
 * name: vault_ceremonies
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}

// Session must start before the harness emits any output (CLI sessions
// cannot start once headers/output are sent) — capture window availability
// now for the kill-switch section at the bottom.
$apcu = vault_apcu_usable() && vault_ensure_session();

$box = new SealedBox();
$ceremonies = new VaultCeremonies();

/** Count a vault's live wrappings by type. */
function cer_counts(int $vault_id): array {
	$counts = ['passkey' => 0, 'recovery' => 0, 'passphrase' => 0, 'root' => 0];
	foreach (vault_live_wrappings($vault_id) as $w) {
		$counts[$w->get('uew_unlocker_type')]++;
	}
	return $counts;
}

/** The root vault of a user, or null. */
function cer_root(int $user_id): ?UserEncryptionVault {
	return UserEncryptionVault::loadForUser($user_id, VaultScopes::ROOT_SCOPE);
}

section('Setup: the account vault and the root vault, one set of codes');
$fx = vault_fixture_vault('CerA', '', 7);
$vault = $fx['vault'];
check((int)$vault->get('uev_key_generation') === 1, 'a fresh vault is generation 1');
check(cer_counts((int)$vault->key) === ['passkey' => 1, 'recovery' => 7, 'passphrase' => 0, 'root' => 0],
	'the account vault: one passkey and 7 codes', json_encode(cer_counts((int)$vault->key)));
$root = cer_root((int)$fx['user']->key);
check($root !== null && (string)$root->get('uev_custody') === UserEncryptionVault::CUSTODY_CLIENT,
	'the root vault was created with it, browser-held');
check($root !== null && cer_counts((int)$root->key) === ['passkey' => 1, 'recovery' => 7, 'passphrase' => 0, 'root' => 0],
	'the root: the same passkey and the same 7 codes');
$pairs_ok = true;
foreach ([(int)$vault->key, $root ? (int)$root->key : 0] as $vid) {
	$indices = [];
	foreach (vault_live_wrappings($vid) as $w) {
		if ($w->get('uew_unlocker_type') !== UserEncryptionWrapping::TYPE_RECOVERY) { continue; }
		if ((string)$w->get('uew_code_set') !== $fx['code_set']['post']['id']) { $pairs_ok = false; }
		$indices[] = (int)$w->get('uew_code_index');
	}
	sort($indices);
	if ($indices !== range(0, 6)) { $pairs_ok = false; }
}
check($pairs_ok, 'every code wrapping on both vaults carries the set id and its index, 0 to 6');
check(UserEncryptionWrapping::hasCodeSet((int)$vault->key), 'the account vault reads as holding a browser-made set');

section('Key file reconstructibility');
// The backup payload alone + one known recovery code must reconstruct the
// secret: the file names the salt the code's KEK is derived with.
$kf = $fx['key_file'];
check(($kf['code_kdf']['code_salt'] ?? null) === $fx['root_salt'], 'the key file names the code salt (the root vault\'s)');
$code = $fx['recovery_codes'][0];
$recovered = null;
$kek0 = VaultUnlockerKdf::codeKekAccount($code, $kf['code_kdf']['code_salt']);
foreach ($kf['wrappings'] as $row) {
	if ($row['unlocker_type'] !== 'recovery') { continue; }
	try {
		$recovered = $box->unwrapKey($row['wrapped_secret'], $kek0, UserEncryptionWrapping::adFor($kf['vault_id'], $row['id']));
		break;
	} catch (Exception $e) { continue; }
}
check($recovered !== null, 'a recovery code + the key file reconstruct the secret key');
check($recovered !== null && SealedBox::b64url(sodium_crypto_box_publickey_from_secretkey(SealedBox::b64url_decode($recovered))) === $kf['public_key'],
	'the reconstructed secret matches the advertised public key');

section('Setup refusals');
$root_salt = vault_fixture_root_salt();
$codes = vault_fixture_code_set($root_salt, 5);
$threw = '';
try {
	$ceremonies->setup($fx['user'], (int)$fx['passkey']->key, 'x', random_bytes(32), '', $codes['set'],
		vault_fixture_root($root_salt, $codes, $fx['passkey'])['payload'], false);
} catch (VaultCeremonyException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'already set up') !== false, 'a second setup is refused');

$user_b = make_user('VaultCerB');
$pk_b = vault_fixture_passkey((int)$user_b->key);
[$pp_account, $pp_root] = vault_fixture_passphrase('a sufficiently long passphrase', $root_salt);
$threw = '';
try {
	$ceremonies->setup($user_b, (int)$pk_b->key, 'x', random_bytes(32), $pp_account, $codes['set'],
		vault_fixture_root($root_salt, $codes, $pk_b, null, $pp_root)['payload'], false);
} catch (VaultCeremonyException $e) { $threw = $e->getMessage(); }
check($threw !== '', 'a passphrase beside a passkey that can hold the key is refused (R8)', $threw);

// A root whose codes are not the account's set: refused, nothing kept.
$other = vault_fixture_code_set($root_salt, 5);
$threw = '';
try {
	$ceremonies->setup($user_b, (int)$pk_b->key, 'x', random_bytes(32), '', $codes['set'],
		vault_fixture_root($root_salt, $other, $pk_b)['payload'], false);
} catch (VaultCeremonyException $e) { $threw = $e->getMessage(); }
check($threw !== '', 'a root vault made with a different set of codes is refused', $threw);
check((new MultiUserEncryptionVault(['user_id' => $user_b->key]))->count_all() === 0,
	'and neither vault exists: the two are made together or not at all');

$threw = '';
try {
	$ceremonies->setup($user_b, (int)$pk_b->key, 'x', random_bytes(32), '', $codes['set'],
		vault_fixture_root($root_salt, $codes)['payload'], false);
} catch (VaultCeremonyException $e) { $threw = $e->getMessage(); }
check($threw !== '', 'a root vault with no passkey or phrase to open it is refused', $threw);
check((new MultiUserEncryptionVault(['user_id' => $user_b->key]))->count_all() === 0, 'and nothing was kept');

section('Setup atomicity');
// A 16-byte KEK passes no validation until the FIRST wrapping is sealed -
// by then the vault row is saved inside the transaction. The failure must
// roll everything back: no vault, no wrappings, and setup can run again.
$threw = false;
try {
	$ceremonies->setup($user_b, (int)$pk_b->key, 'x', random_bytes(16), '', $codes['set'],
		vault_fixture_root($root_salt, $codes, $pk_b)['payload'], false);
} catch (VaultCeremonyException $e) { $threw = true; }
check($threw, 'a mid-ceremony failure surfaces as an error');
check((new MultiUserEncryptionVault(['user_id' => $user_b->key]))->count_all() === 0,
	'no vault row survives the rollback - never a vault with zero unlockers');
$retry = $ceremonies->setup($user_b, (int)$pk_b->key, 'x', random_bytes(32), '', $codes['set'],
	vault_fixture_root($root_salt, $codes, $pk_b)['payload'], false);
vault_fixture_register_vaults((int)$user_b->key);
check((int)$retry['vault']->key > 0, 'setup runs cleanly after the rolled-back attempt');
check(cer_counts((int)$retry['vault']->key)['recovery'] === 5, 'with its five codes');

section('Code sets are 5 to 20 codes, well formed');
$threw = false;
try { VaultCeremonies::codeSet(vault_fixture_code_set($root_salt, 5)['post']); } catch (VaultCeremonyException $e) { $threw = true; }
check(!$threw, 'five codes are a set');
foreach ([4 => 'four codes', 21 => 'twenty-one codes'] as $n => $label) {
	$post = ['id' => bin2hex(random_bytes(16)), 'entries' => []];
	for ($i = 0; $i < $n; $i++) { $post['entries'][] = ['index' => $i, 'kek' => SealedBox::b64url(random_bytes(32))]; }
	$threw = false;
	try { VaultCeremonies::codeSet($post); } catch (VaultCeremonyException $e) { $threw = true; }
	check($threw, $label . ' are refused');
}
$post = $codes['post'];
$post['entries'][1]['index'] = $post['entries'][0]['index'];
$threw = false;
try { VaultCeremonies::codeSet($post); } catch (VaultCeremonyException $e) { $threw = true; }
check($threw, 'a repeated index is refused');
$post = $codes['post'];
$post['entries'][0]['kek'] = SealedBox::b64url(random_bytes(16));
$threw = false;
try { VaultCeremonies::codeSet($post); } catch (VaultCeremonyException $e) { $threw = true; }
check($threw, 'a KEK that is not 32 bytes is refused');

section('The passphrase fallback: one phrase, both halves');
$fp = vault_fixture_vault('CerPhrase', 'a sufficiently long passphrase', 5);
check(cer_counts((int)$fp['vault']->key) === ['passkey' => 0, 'recovery' => 5, 'passphrase' => 1, 'root' => 0],
	'a passkeyless account vault: 5 codes and the phrase');
$fp_root = cer_root((int)$fp['user']->key);
check($fp_root !== null && cer_counts((int)$fp_root->key)['passphrase'] === 1, 'the root holds the phrase\'s other half');
$pp_key = $ceremonies->unlockWithPassphrase($fp['user'], $fp['vault'], $fp['passphrase_kek']);
check($pp_key instanceof VaultKey && $pp_key->publicKey() === (string)$fp['vault']->get('uev_public_key'),
	'the phrase\'s account half opens the account key');
[, $fp_root_kek] = vault_fixture_passphrase('a sufficiently long passphrase', $fp['root_salt']);
$root_phrase_blob = null;
foreach (vault_live_wrappings((int)$fp_root->key) as $w) {
	if ($w->get('uew_unlocker_type') === UserEncryptionWrapping::TYPE_PASSPHRASE) { $root_phrase_blob = (string)$w->get('uew_wrapped_secret_key'); }
}
check($root_phrase_blob !== null && vault_fixture_unwrap($root_phrase_blob, $fp_root_kek, 'vault:root:passphrase') === $fp['root_secret'],
	'and the same phrase\'s root half opens the root vault (in the browser)');
[$wrong_account] = vault_fixture_passphrase('the wrong passphrase entirely', $fp['root_salt']);
$threw = false;
try { $ceremonies->unlockWithPassphrase($fp['user'], $fp['vault'], $wrong_account); } catch (VaultCeremonyException $e) { $threw = true; }
check($threw, 'a wrong passphrase is refused');
$threw = false;
try { $ceremonies->unlockWithPassphrase($fx['user'], $fx['vault'], $fp['passphrase_kek']); } catch (VaultCeremonyException $e) { $threw = true; }
check($threw, 'a vault with no passphrase enrolled refuses');

section('Recovery unlock: one code, both halves, spent together');
$root_twin_used = function (int $root_id, int $index) {
	foreach (vault_live_wrappings($root_id) as $w) {
		if ($w->get('uew_unlocker_type') === UserEncryptionWrapping::TYPE_RECOVERY && (int)$w->get('uew_code_index') === $index) {
			return (bool)$w->get('uew_is_used');
		}
	}
	return null;
};
$res = $ceremonies->unlockWithRecoveryKek($fx['user'], $fx['vault'], VaultUnlockerKdf::codeKekAccount($fx['recovery_codes'][1], $fx['root_salt']), $apcu);
check($res['regenerate_recommended'] === false, 'plenty of codes left: no regenerate nag');
check(is_array($res['root_wrapping']), 'the code\'s root twin comes back for the browser');
check(is_array($res['root_wrapping']) && vault_fixture_unwrap($res['root_wrapping']['wrapped_secret_key'],
	VaultUnlockerKdf::codeKekRoot($fx['recovery_codes'][1], $fx['root_salt']), 'vault:root:recovery') === $fx['root_secret'],
	'and the same code\'s root half opens the root vault');
check($root_twin_used((int)$root->key, 1) === true, 'the twin is spent with the code');
check($root_twin_used((int)$root->key, 2) === false, 'and no other twin is');
$threw = false;
try { $ceremonies->unlockWithRecoveryKek($fx['user'], $fx['vault'], VaultUnlockerKdf::codeKekAccount($fx['recovery_codes'][1], $fx['root_salt']), false); }
catch (VaultCeremonyException $e) { $threw = true; }
check($threw, 'a consumed code never unlocks again');
$typo = strtr($fx['recovery_codes'][2], ['0' => 'O', '1' => 'l']);
$res = $ceremonies->unlockWithRecoveryKek($fx['user'], $fx['vault'], VaultUnlockerKdf::codeKekAccount($typo, $fx['root_salt']), false);
check(is_array($res), 'a mistranscribed code (O for 0, l for 1) derives the same KEK and unlocks');
$threw = false;
try { $ceremonies->unlockWithRecoveryKek($fx['user'], $fx['vault'], VaultUnlockerKdf::codeKekAccount('AAAAA-AAAAA-AAAAA-AAAAA-AAAAA-A', $fx['root_salt']), false); }
catch (VaultCeremonyException $e) { $threw = true; }
check($threw, 'a wrong code is refused');
check(UserEncryptionVault::lastRecoveryTime((int)$fx['user']->key) !== null, 'a recovery use is stamped for the resume check');

// Burn down to fewer than 3 unused: the nag flips on. Of 7 codes, 1 and 2
// are already consumed; burning 3, 4, and 6 leaves only 0 and 5 unused.
foreach ([3, 4] as $i) {
	$ceremonies->unlockWithRecoveryKek($fx['user'], $fx['vault'], VaultUnlockerKdf::codeKekAccount($fx['recovery_codes'][$i], $fx['root_salt']), false);
}
$res = $ceremonies->unlockWithRecoveryKek($fx['user'], $fx['vault'], VaultUnlockerKdf::codeKekAccount($fx['recovery_codes'][6], $fx['root_salt']), false);
check($res['regenerate_recommended'] === true, 'fewer than 3 unused codes recommends regeneration');

section('Recovery kill-switch');
if (!$apcu) {
	harness_skip('APCu unavailable', 'kill-switch ordering needs a live window store; run with -d apc.enable_cli=1');
} else {
	$uid = (int)$fx['user']->key;
	// A pre-existing window on another session (the thief's, say).
	apcu_store('vault:stolen-session:' . $uid . ':user', 'stolen-secret', 3600);
	$ceremonies->unlockWithRecoveryKek($fx['user'], $fx['vault'], VaultUnlockerKdf::codeKekAccount($fx['recovery_codes'][5], $fx['root_salt']), true);
	check(apcu_fetch('vault:stolen-session:' . $uid . ':user') === false, 'every pre-existing window died first');
	check(VaultUnlock::isOpen($uid), 'and a fresh window opened for the recovering session only');
	VaultUnlock::lockAll($uid);
}

section('Cross-user ownership guard');
// A (user, vault) pair from different users must be refused at the ceremony
// boundary — before any passphrase/code check — so a mismatched pair can never
// open one user's window with another user's vault secret.
$threw = false;
try { $ceremonies->unlockWithPassphrase($fx['user'], $fp['vault'], $fp['passphrase_kek']); }
catch (VaultCeremonyException $e) { $threw = ($e->getMessage() === 'Vault does not belong to this user.'); }
check($threw, 'passphrase unlock refuses a foreign vault (ownership)');
$threw = false;
try { $ceremonies->unlockWithRecoveryKek($fx['user'], $fp['vault'], VaultUnlockerKdf::codeKekAccount($fp['recovery_codes'][0], $fp['root_salt']), false); }
catch (VaultCeremonyException $e) { $threw = ($e->getMessage() === 'Vault does not belong to this user.'); }
check($threw, 'recovery unlock refuses a foreign vault (ownership)');

section('The server never takes a code or a phrase');
foreach ([['code' => $fp['recovery_codes'][0]], ['passphrase' => 'a sufficiently long passphrase']] as $raw) {
	$threw = '';
	try { $ceremonies->openWithUnlocker($fp['user'], $fp['vault'], $raw, []); } catch (VaultCeremonyException $e) { $threw = $e->getMessage(); }
	check(strpos($threw, 'out of date') !== false, 'openWithUnlocker refuses a raw ' . key($raw) . ' without a look');
}
$threw = '';
try { VaultCeremonies::assertNoSecondPrfOutput(['clientExtensionResults' => ['prf' => ['results' => ['first' => 'a', 'second' => 'b']]]]); }
catch (VaultCeremonyException $e) { $threw = $e->getMessage(); }
check($threw !== '', 'a passkey assertion still carrying the root vault\'s output is refused');
$threw = '';
try { VaultCeremonies::assertNoSecondPrfOutput(['clientExtensionResults' => ['prf' => ['results' => ['first' => 'a']]]]); }
catch (VaultCeremonyException $e) { $threw = $e->getMessage(); }
check($threw === '', 'one with the first output only passes');

section('Enrolment presents a fresh unlocker (openWithUnlocker, spec B1)');
// A wrapping is produced only in the request that presented a real unlocker;
// the window that results is the session's.
if (!$apcu) {
	harness_skip('APCu unavailable', 'openWithUnlocker arms a window; run with -d apc.enable_cli=1');
} else {
	$ex = vault_fixture_vault('CerEnrol', 'the enrolment phrase, long enough', 5);
	$euser = $ex['user'];
	$evault = $ex['vault'];
	$euid = (int)$euser->key;
	$code_kek = fn($i) => vault_fixture_code_kek($ex['recovery_codes'][$i], $ex['root_salt']);

	// 1. A new phrase, confirming with recovery code 0.
	[$new_account] = vault_fixture_passphrase('a brand new passphrase', $ex['root_salt']);
	$phrase_row = UserEncryptionWrapping::reserve((int)$evault->key, UserEncryptionWrapping::TYPE_PASSPHRASE, null, null, 1);
	$opened = $ceremonies->openWithUnlocker($euser, $evault, ['code_kek' => $code_kek(0)], [$phrase_row->wrapEntry($new_account)]);
	$phrase_row->storeWrapped($opened['wrappings'][0]);
	check($opened['key'] instanceof VaultKey && $opened['key']->publicKey() === (string)$evault->get('uev_public_key'),
		'the open under a recovery code yields the vault key');
	check(VaultUnlock::isOpen($euid), 'and the window that results is this session\'s');
	$consumed = 0;
	foreach (vault_live_wrappings((int)$evault->key) as $w) {
		if ($w->get('uew_unlocker_type') === UserEncryptionWrapping::TYPE_RECOVERY && $w->get('uew_is_used')) { $consumed++; }
	}
	check($consumed === 1, 'the code that confirmed the enrolment is used up');
	check($root_twin_used((int)cer_root($euid)->key, 0) === true, 'with its root twin');
	$via_phrase = $ceremonies->unlockWithPassphrase($euser, new UserEncryptionVault((int)$evault->key, TRUE), $new_account);
	check($via_phrase->id() === $opened['key']->id(), 'the enrolled phrase opens the same key');

	// 2. The same code again is refused, and so is a wrong phrase.
	$threw = false;
	try { $ceremonies->openWithUnlocker($euser, $evault, ['code_kek' => $code_kek(0)], []); } catch (VaultCeremonyException $e) { $threw = true; }
	check($threw, 'a used recovery code cannot confirm an enrolment');
	[$not_it] = vault_fixture_passphrase('not the phrase at all', $ex['root_salt']);
	$threw = false;
	try { $ceremonies->openWithUnlocker($euser, $evault, ['passphrase_kek' => SealedBox::b64url($not_it)], []); } catch (VaultCeremonyException $e) { $threw = true; }
	check($threw, 'a wrong passphrase cannot confirm an enrolment');
	$threw = false;
	try { $ceremonies->openWithUnlocker($euser, $evault, null, []); } catch (VaultCeremonyException $e) { $threw = true; }
	check($threw, 'no unlocker at all is refused');
	$threw = false;
	try { $ceremonies->openWithUnlocker($euser, $fx['vault'], ['passphrase_kek' => SealedBox::b64url($new_account)], []); }
	catch (VaultCeremonyException $e) { $threw = ($e->getMessage() === 'Vault does not belong to this user.'); }
	check($threw, 'a foreign vault is refused before any unlocker is tried');

	// 3. A new set under the phrase, with the root's twins in the same transaction.
	$fresh = vault_fixture_code_set($ex['root_salt'], 5);
	UserEncryptionWrapping::adoptCodeSet($evault, $fresh['set'], function (array $wrap_under) use ($ceremonies, $euser, $evault, $new_account) {
		return $ceremonies->openWithUnlocker($euser, $evault, ['passphrase_kek' => SealedBox::b64url($new_account)], $wrap_under);
	}, function () use ($ceremonies, $euser, $fresh, $ex) {
		$ceremonies->replaceRootTwins($euser, $fresh['set'], ['recovery' => vault_fixture_root_recovery($ex['root_salt'], $fresh, $ex['root_secret'])]);
	});
	$res = $ceremonies->unlockWithRecoveryKek($euser, new UserEncryptionVault((int)$evault->key, TRUE),
		VaultUnlockerKdf::codeKekAccount($fresh['codes'][1], $ex['root_salt']), false);
	check(is_array($res) && is_array($res['root_wrapping']), 'a code from the new set opens both halves');
	$threw = false;
	try { $ceremonies->unlockWithRecoveryKek($euser, new UserEncryptionVault((int)$evault->key, TRUE), VaultUnlockerKdf::codeKekAccount($ex['recovery_codes'][2], $ex['root_salt']), false); }
	catch (VaultCeremonyException $e) { $threw = true; }
	check($threw, 'and the old set is gone');
	check(cer_counts((int)cer_root($euid)->key)['recovery'] === 5, 'the root holds exactly the new set\'s five twins');

	// 4. A root twin set that does not match the account's set changes nothing.
	$bad = vault_fixture_code_set($ex['root_salt'], 5);
	$mismatch = vault_fixture_code_set($ex['root_salt'], 5);
	$threw = false;
	try {
		UserEncryptionWrapping::adoptCodeSet($evault, $bad['set'], function (array $wrap_under) use ($ceremonies, $euser, $evault, $new_account) {
			return $ceremonies->openWithUnlocker($euser, $evault, ['passphrase_kek' => SealedBox::b64url($new_account)], $wrap_under);
		}, function () use ($ceremonies, $euser, $bad, $mismatch, $ex) {
			$ceremonies->replaceRootTwins($euser, $bad['set'], ['recovery' => vault_fixture_root_recovery($ex['root_salt'], $mismatch, $ex['root_secret'])]);
		});
	} catch (Throwable $e) { $threw = true; }
	check($threw, 'root twins of a different set are refused');
	$res = $ceremonies->unlockWithRecoveryKek($euser, new UserEncryptionVault((int)$evault->key, TRUE),
		VaultUnlockerKdf::codeKekAccount($fresh['codes'][2], $ex['root_salt']), false);
	check(is_array($res), 'and the set in place still works: a failure mid-change spends and replaces nothing');
	VaultUnlock::lockAll($euid);
}

harness_finish();
?>
