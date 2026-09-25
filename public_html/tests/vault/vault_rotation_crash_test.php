<?php
/** @joinery-test
 * name: vault_rotation_crash
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

$box = new SealedBox();
$crypto = new VaultCrypto();
$ceremonies = new VaultCeremonies();

// ---- Fixture: a vault plus a synthetic consumer -------------------------
// The consumer holds sealed items in memory, mirrors the contract exactly
// (re-seal items on $old_generation, attempt all, throw on failure), and can
// be armed to fail - the crash lever every scenario below pulls.
$fx = vault_fixture_vault('Rot', '', 5);
$user = $fx['user'];
$vault = $fx['vault'];
$vault_id = (int)$vault->key;
$kek = $fx['kek'];
$credential_id = (int)$fx['passkey']->key;

$consumer = new stdClass();
$consumer->items = [];   // each: ['sealed_key','gen','blob','ad','plain']
$consumer->calls = [];
$consumer->armed = false;
VaultUnlock::onReseal(function (int $uid, VaultKey $old_secret, int $old_gen, string $new_pub, int $new_gen) use ($consumer, $crypto) {
	$consumer->calls[] = ['old_gen' => $old_gen, 'new_gen' => $new_gen, 'new_pub' => $new_pub];
	if ($consumer->armed) {
		throw new RuntimeException('synthetic consumer failure');
	}
	foreach ($consumer->items as &$item) {
		if ($item['gen'] !== $old_gen) { continue; }
		$dek = $crypto->openItemDek($item['sealed_key'], $old_secret);
		$item['sealed_key'] = $crypto->sealItemDek($dek, $new_pub);
		$item['gen'] = $new_gen;
	}
	unset($item);
});

$seal_item = function (string $plain, string $ad) use ($consumer, $crypto, $vault_id) {
	$v = new UserEncryptionVault($vault_id, TRUE);
	$dek = $crypto->newItemDek();
	$consumer->items[] = [
		'sealed_key' => $crypto->sealItemDek($dek, (string)$v->get('uev_public_key')),
		'gen'        => (int)$v->get('uev_key_generation'),
		'blob'       => $crypto->sealField($plain, $dek, $ad),
		'ad'         => $ad,
		'plain'      => $plain,
	];
};
$open_all_items = function (VaultKey $secret) use ($consumer, $crypto): int {
	$readable = 0;
	foreach ($consumer->items as $item) {
		try {
			$dek = $crypto->openItemDek($item['sealed_key'], $secret);
			if ($crypto->openField($item['blob'], $dek, $item['ad']) === $item['plain']) { $readable++; }
		} catch (Exception $e) { /* unreadable */ }
	}
	return $readable;
};
$secret_for_generation = function (int $gen) use ($box, $vault_id, $credential_id, $kek): ?VaultKey {
	foreach (vault_live_wrappings($vault_id) as $w) {
		if ($w->get('uew_unlocker_type') !== UserEncryptionWrapping::TYPE_PASSKEY) { continue; }
		if ((int)$w->get('uew_pkc_passkey_credential_id') !== $credential_id) { continue; }
		if ((int)$w->get('uew_key_generation') !== $gen) { continue; }
		return VaultUnlock::openKey(0, $w->unlocker($kek))['key'];
	}
	return null;
};

// A rotation takes a browser-made code set and the root vault's twins of it
// (specs/one_vault_experience.md § R6). Returns [result, codes].
$rotate = function (string $label = 'label') use ($ceremonies, $user, $vault_id, $credential_id, $kek, $fx): array {
	// Each rotation is its own request, so it starts cold: this process opened
	// sealed items between rotations (the suite's own checks), which a real
	// rotation request never has when it writes the root's code twins.
	SealedEgressGuard::reset();
	$set = vault_fixture_code_set($fx['root_salt'], 10);
	$twins = vault_fixture_root_recovery($fx['root_salt'], $set, $fx['root_secret']);
	$r = $ceremonies->rotate($user, new UserEncryptionVault($vault_id, TRUE), $credential_id, $label, $kek, '',
		$set['set'], ['recovery' => $twins], false);
	return [$r, array_values($set['codes'])];
};
$code_kek = function (string $code) use ($fx): string {
	return VaultUnlockerKdf::codeKekAccount($code, $fx['root_salt']);
};

$seal_item('first message', 'item:1');
$seal_item('second message', 'item:2');
$seal_item('third message', 'item:3');

// ---- R1: happy rotation -------------------------------------------------
section('R1 happy rotation');
[$r1, $r1_codes] = $rotate();
check($r1['completed_pending'] === false, 'normal mode');
check($r1['key_generation'] === 2, 'vault moved to generation 2');
check(count($consumer->calls) === 1 && $consumer->calls[0]['old_gen'] === 1 && $consumer->calls[0]['new_gen'] === 2, 'consumer drained generation 1 toward 2');
$v = new UserEncryptionVault($vault_id, TRUE);
check($consumer->calls[0]['new_pub'] === (string)$v->get('uev_public_key'), 'consumer sealed toward the advertised public key');
check(UserEncryptionWrapping::liveGenerations($vault_id) === [2], 'generation 1 wrappings retired');
$gen2_secret = $secret_for_generation(2);
check($gen2_secret !== null, 'the presented credential unwraps the new secret');
check($open_all_items($gen2_secret) === 3, 'every item opens under the new secret');
check(count($r1_codes) === 10, 'the new generation carries the browser\'s ten codes');
$threw = false;
try { $ceremonies->unlockWithRecoveryKek($user, $v, $code_kek($fx['recovery_codes'][0]), false); } catch (VaultCeremonyException $e) { $threw = true; }
check($threw, 'the drained generation\'s codes are dead');
$ok = $ceremonies->unlockWithRecoveryKek($user, $v, $code_kek($r1_codes[0]), false);
check(is_array($ok) && is_array($ok['root_wrapping']), 'the new generation\'s codes unlock, and open the root with it');

// ---- R3: re-seal failure leaves the two-generation state ----------------
section('R3 re-seal failure');
$consumer->armed = true;
$threw = '';
try { $rotate(); } catch (VaultCeremonyException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'nothing was retired') !== false, 'the ceremony reports the failure honestly');
$v = new UserEncryptionVault($vault_id, TRUE);
check((int)$v->get('uev_key_generation') === 3, 'the vault row already advertises generation 3 (persisted before the drain)');
$gens = UserEncryptionWrapping::liveGenerations($vault_id);
sort($gens);
check($gens === [2, 3], 'both generations\' wrappings are live', json_encode($gens));
check($open_all_items($secret_for_generation(2)) === 3, 'every item still opens under the generation-2 secret');
// The tour's #9 regression: the failed attempt replaced the vault row's salt;
// a generation-2 code's KEK never depended on it.
$ok = $ceremonies->unlockWithRecoveryKek($user, $v, $code_kek($r1_codes[1]), false);
check(is_array($ok), 'a generation-2 recovery code still unlocks the account vault');

// ---- R5: content sealed during the broken state survives ----------------
section('R5 mid-brokenness seal survives');
// New mail arriving now seals to the CURRENT (generation 3) public key -
// under the pre-fix ordering bug this key's secret would not exist anywhere
// durable, and this item would be lost forever.
$seal_item('sealed while broken', 'item:4');
check($consumer->items[3]['gen'] === 3, 'the new item is stamped with the orphan-risk generation');
check($secret_for_generation(3) !== null, 'the generation-3 secret is recoverable from a durable wrapping (the fix)');

// ---- Completion: the retry converges instead of splitting forever -------
section('Completion mode convergence');
$consumer->armed = false;
[$r2] = $rotate();
check($r2['completed_pending'] === true, 'the retry COMPLETES the pending rotation');
check($r2['key_generation'] === 3, 'no new generation was minted');
check($r2['regenerate_recommended'] === true, 'and the user is told to regenerate them');
check(UserEncryptionWrapping::liveGenerations($vault_id) === [3], 'exactly one generation remains live');
$last = $consumer->calls[count($consumer->calls) - 1];
check($last['old_gen'] === 2 && $last['new_gen'] === 3, 'the drain ran from generation 2 to 3');
$gen3_secret = $secret_for_generation(3);
check($open_all_items($gen3_secret) === 4, 'ALL content - including the mid-brokenness item - opens under one secret');
$threw = false;
try { $ceremonies->unlockWithRecoveryKek($user, new UserEncryptionVault($vault_id, TRUE), $code_kek($r1_codes[2]), false); } catch (VaultCeremonyException $e) { $threw = true; }
check($threw, 'the drained generation-2 codes are dead after completion');

// ---- R4: orphan-generation cleanup --------------------------------------
section('R4 orphan cleanup');
// Fabricate the mirror-image crash artifact: a wrapping tagged newer than
// the vault row (its keypair was never advertised).
$orphan = UserEncryptionWrapping::reserve($vault_id, UserEncryptionWrapping::TYPE_PASSKEY, $credential_id, 'orphan', 9);
$orphan->storeWrapped(VaultUnlock::openKey(0, null, [$orphan->wrapEntry(random_bytes(32))])['wrappings'][0]);
[$r3] = $rotate();
check($r3['completed_pending'] === false && $r3['key_generation'] === 4, 'rotation proceeded normally past the orphan');
$orphan_after = new UserEncryptionWrapping((int)$orphan->key, TRUE);
check($orphan_after->get('uew_delete_time') !== null, 'the orphan wrapping was retired, not authorized from');
check(UserEncryptionWrapping::liveGenerations($vault_id) === [4], 'a single clean generation remains');

// ---- R2: persist-phase failure leaves no trace --------------------------
section('R2 persist failure aborts clean');
$v_before = new UserEncryptionVault($vault_id, TRUE);
$wrappings_before = count(vault_live_wrappings($vault_id));
$threw = '';
// An invalid-UTF8 label is rejected by Postgres at the first INSERT of the
// persist phase; the transaction must leave the vault untouched.
try { $rotate("bad\xC3\x28label"); } catch (VaultCeremonyException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'nothing was changed') !== false, 'the ceremony reports a clean abort');
$v_after = new UserEncryptionVault($vault_id, TRUE);
check((string)$v_after->get('uev_public_key') === (string)$v_before->get('uev_public_key'), 'public key untouched');
check((string)$v_after->get('uev_salt') === (string)$v_before->get('uev_salt'), 'salt untouched');
check((int)$v_after->get('uev_key_generation') === 4, 'generation untouched');
check(count(vault_live_wrappings($vault_id)) === $wrappings_before, 'no wrapping appeared or disappeared');
check($open_all_items($secret_for_generation(4)) === 4, 'every item still opens');

harness_finish();
?>
