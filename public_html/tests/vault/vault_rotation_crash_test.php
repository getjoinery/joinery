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
VaultUnlock::onReseal(function (int $uid, string $old_secret, int $old_gen, string $new_pub, int $new_gen) use ($consumer, $crypto) {
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
$open_all_items = function (string $secret) use ($consumer, $crypto): int {
	$readable = 0;
	foreach ($consumer->items as $item) {
		try {
			$dek = $crypto->openItemDek($item['sealed_key'], $secret);
			if ($crypto->openField($item['blob'], $dek, $item['ad']) === $item['plain']) { $readable++; }
		} catch (Exception $e) { /* unreadable */ }
	}
	return $readable;
};
$secret_for_generation = function (int $gen) use ($box, $vault_id, $credential_id, $kek): ?string {
	foreach (vault_live_wrappings($vault_id) as $w) {
		if ($w->get('uew_unlocker_type') !== UserEncryptionWrapping::TYPE_PASSKEY) { continue; }
		if ((int)$w->get('uew_pkc_credential_id') !== $credential_id) { continue; }
		if ((int)$w->get('uew_key_generation') !== $gen) { continue; }
		return $box->unwrapKey($w->get('uew_wrapped_secret_key'), $kek, UserEncryptionWrapping::adFor($vault_id, (int)$w->key));
	}
	return null;
};

$seal_item('first message', 'item:1');
$seal_item('second message', 'item:2');
$seal_item('third message', 'item:3');

// ---- R1: happy rotation -------------------------------------------------
section('R1 happy rotation');
$r1 = $ceremonies->rotate($user, new UserEncryptionVault($vault_id, TRUE), $credential_id, 'label', $kek, '', false);
check($r1['completed_pending'] === false, 'normal mode');
check($r1['key_generation'] === 2, 'vault moved to generation 2');
check(count($consumer->calls) === 1 && $consumer->calls[0]['old_gen'] === 1 && $consumer->calls[0]['new_gen'] === 2, 'consumer drained generation 1 toward 2');
$v = new UserEncryptionVault($vault_id, TRUE);
check($consumer->calls[0]['new_pub'] === (string)$v->get('uev_public_key'), 'consumer sealed toward the advertised public key');
check(UserEncryptionWrapping::liveGenerations($vault_id) === [2], 'generation 1 wrappings retired');
$gen2_secret = $secret_for_generation(2);
check($gen2_secret !== null, 'the presented credential unwraps the new secret');
check($open_all_items($gen2_secret) === 3, 'every item opens under the new secret');
check(count($r1['recovery_codes']) === 10, 'fresh codes were minted and returned');
$threw = false;
try { $ceremonies->unlockWithRecoveryCode($user, $v, $fx['recovery_codes'][0], false); } catch (VaultCeremonyException $e) { $threw = true; }
check($threw, 'the drained generation\'s codes are dead');
$ok = $ceremonies->unlockWithRecoveryCode($user, $v, $r1['recovery_codes'][0], false);
check(is_array($ok), 'the new generation\'s codes unlock');

// ---- R3: re-seal failure leaves the two-generation state ----------------
section('R3 re-seal failure');
$consumer->armed = true;
$threw = '';
try { $ceremonies->rotate($user, new UserEncryptionVault($vault_id, TRUE), $credential_id, 'label', $kek, '', false); } catch (VaultCeremonyException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'nothing was retired') !== false, 'the ceremony reports the failure honestly');
$v = new UserEncryptionVault($vault_id, TRUE);
check((int)$v->get('uev_key_generation') === 3, 'the vault row already advertises generation 3 (persisted before the drain)');
$gens = UserEncryptionWrapping::liveGenerations($vault_id);
sort($gens);
check($gens === [2, 3], 'both generations\' wrappings are live', json_encode($gens));
check($open_all_items($secret_for_generation(2)) === 3, 'every item still opens under the generation-2 secret');
// The tour's #9 regression: gen-2 recovery codes derive from the gen-2 salt,
// which the failed attempt replaced on the vault row.
$ok = $ceremonies->unlockWithRecoveryCode($user, $v, $r1['recovery_codes'][1], false);
check(is_array($ok), 'a generation-2 recovery code still unlocks via its per-wrapping salt');

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
$r2 = $ceremonies->rotate($user, new UserEncryptionVault($vault_id, TRUE), $credential_id, 'label', $kek, '', false);
check($r2['completed_pending'] === true, 'the retry COMPLETES the pending rotation');
check($r2['key_generation'] === 3, 'no new generation was minted');
check($r2['recovery_codes'] === [], 'no codes to display (generation 3\'s were minted by the interrupted attempt)');
check($r2['regenerate_recommended'] === true, 'and the user is told to regenerate them');
check(UserEncryptionWrapping::liveGenerations($vault_id) === [3], 'exactly one generation remains live');
$last = $consumer->calls[count($consumer->calls) - 1];
check($last['old_gen'] === 2 && $last['new_gen'] === 3, 'the drain ran from generation 2 to 3');
$gen3_secret = $secret_for_generation(3);
check($open_all_items($gen3_secret) === 4, 'ALL content - including the mid-brokenness item - opens under one secret');
$threw = false;
try { $ceremonies->unlockWithRecoveryCode($user, new UserEncryptionVault($vault_id, TRUE), $r1['recovery_codes'][2], false); } catch (VaultCeremonyException $e) { $threw = true; }
check($threw, 'the drained generation-2 codes are dead after completion');

// ---- R4: orphan-generation cleanup --------------------------------------
section('R4 orphan cleanup');
// Fabricate the mirror-image crash artifact: a wrapping tagged newer than
// the vault row (its keypair was never advertised).
$orphan = UserEncryptionWrapping::createWrapped($vault_id, UserEncryptionWrapping::TYPE_PASSKEY,
	$box->generateKeypair()['secret'], random_bytes(32), $credential_id, 'orphan', 9);
$r3 = $ceremonies->rotate($user, new UserEncryptionVault($vault_id, TRUE), $credential_id, 'label', $kek, '', false);
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
try { $ceremonies->rotate($user, new UserEncryptionVault($vault_id, TRUE), $credential_id, "bad\xC3\x28label", $kek, '', false); } catch (VaultCeremonyException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'nothing was changed') !== false, 'the ceremony reports a clean abort');
$v_after = new UserEncryptionVault($vault_id, TRUE);
check((string)$v_after->get('uev_public_key') === (string)$v_before->get('uev_public_key'), 'public key untouched');
check((string)$v_after->get('uev_salt') === (string)$v_before->get('uev_salt'), 'salt untouched');
check((int)$v_after->get('uev_key_generation') === 4, 'generation untouched');
check(count(vault_live_wrappings($vault_id)) === $wrappings_before, 'no wrapping appeared or disappeared');
check($open_all_items($secret_for_generation(4)) === 4, 'every item still opens');

harness_finish();
?>
