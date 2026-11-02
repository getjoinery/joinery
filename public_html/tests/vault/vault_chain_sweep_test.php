<?php
/** @joinery-test
 * name: vault_chain_sweep
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 * covers: [includes/VaultChainSweep.php, includes/VaultPreviousKeys.php]
 */
/**
 * Content left on a retired vault key moves to the current key in its owner's
 * window (VaultChainSweep).
 *
 * A rotation re-seals only what its consumers' callbacks find, and until
 * joinery_ai 0.26.14 that missed every recipe run: on jeremytunnell 27,092 runs
 * stayed on the owner's retired X25519 key, readable only through the vault's
 * chain of retired keys. The sweep moves them, records its place when a slice
 * runs out of time, and marks the generation swept so later unlocks skip it.
 *
 * Run: php tests/run.php db --filter=vault_chain_sweep
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');

if (!extension_loaded('sodium') || !class_exists('RecipeRun')) {
	harness_skip('sodium or the joinery_ai plugin is unavailable');
	harness_finish();
}

$db = DbConnector::get_instance()->get_db_link();
$crypto = new VaultCrypto();
$ceremonies = new VaultCeremonies();

$fx = vault_fixture_vault('ChainSweep', '', 5);
$user = $fx['user'];
$uid = (int)$user->key;
$vault_id = (int)$fx['vault']->key;
$kek = $fx['kek'];
$credential_id = (int)$fx['passkey']->key;

$secret_for_generation = function (int $gen, bool $retired = false) use ($vault_id, $credential_id, $kek): ?VaultKey {
	$opts = array('vault_id' => $vault_id);
	if ($retired) { $opts['deleted'] = true; }
	foreach (new MultiUserEncryptionWrapping($opts) as $w) {
		if ($w->get('uew_unlocker_type') !== UserEncryptionWrapping::TYPE_PASSKEY) { continue; }
		if ((int)$w->get('uew_pkc_passkey_credential_id') !== $credential_id) { continue; }
		if ((int)$w->get('uew_key_generation') !== $gen) { continue; }
		return VaultUnlock::openKey(0, $w->unlocker($kek))['key'];
	}
	return null;
};

$v1 = new UserEncryptionVault($vault_id, TRUE);
$gen1 = (int)$v1->get('uev_key_generation');
$old_public = (string)$v1->get('uev_public_key');

SealedEgressGuard::reset();
$set = vault_fixture_code_set($fx['root_salt'], 5);
$twins = vault_fixture_root_recovery($fx['root_salt'], $set, $fx['root_secret']);
$r = $ceremonies->rotate($user, new UserEncryptionVault($vault_id, TRUE), $credential_id, 'label', $kek, '',
	$set['set'], array('recovery' => $twins), false);
$gen2 = (int)$r['key_generation'];
$current = $secret_for_generation($gen2);
$vault = new UserEncryptionVault($vault_id, TRUE);

// A recipe run left on the retired key: sealed as a run is, then its DEK
// sealed again to the old public key at the old generation, as a rotation
// that missed the model left it.
$recipe = new Recipe(NULL);
$recipe->set('rcp_name', 'chain sweep ' . bin2hex(random_bytes(3)));
$recipe->set('rcp_owner_user_id', $uid);
$recipe->set('rcp_allowed_tools', json_encode(array()));
$recipe->set('rcp_allowed_models', json_encode(array()));
$recipe->set('rcp_max_iterations', 1);
$recipe->set('rcp_max_tokens', 100);
$recipe->prepare();
$recipe->save();
harness_register_row('rcp_recipes', 'rcp_recipe_id', (int)$recipe->key);

$left_behind = function () use ($db, $crypto, $vault, $old_public, $gen1, $uid, $recipe): array {
	$run = new RecipeRun(NULL);
	$run->set('rcr_rcp_recipe_id', (int)$recipe->key);
	$run->set('rcr_status', RecipeRun::STATUS_RUNNING);
	$run->save();
	$run->load();
	harness_register_row('rcr_recipe_runs', 'rcr_recipe_run_id', (int)$run->key);
	$dek = RecipeRun::sealColumns((int)$run->key, $vault, array());
	$db->prepare('UPDATE rcr_recipe_runs SET rcr_content_sealed = true, rcr_sealed_key = ?, rcr_key_generation = ?,
		rcr_sealed_owner_user_id = ? WHERE rcr_recipe_run_id = ?')
		->execute(array($crypto->sealItemDek($dek, $old_public), $gen1, $uid, (int)$run->key));
	return array((int)$run->key, $dek);
};
$row = function (int $id) use ($db): array {
	$q = $db->prepare('SELECT rcr_sealed_key, rcr_key_generation FROM rcr_recipe_runs WHERE rcr_recipe_run_id = ?');
	$q->execute(array($id));
	return $q->fetch(PDO::FETCH_ASSOC);
};
$opens_with = function (int $id, VaultKey $key) use ($crypto, $row): ?string {
	try { return $crypto->openItemDek((string)$row($id)['rcr_sealed_key'], $key); } catch (Throwable $e) { return null; }
};
$entry = function () use ($vault_id, $gen1): array {
	foreach (VaultPreviousKeys::entries(new UserEncryptionVault($vault_id, TRUE)) as $e) {
		if ($e['generation'] === $gen1) { return $e; }
	}
	return array();
};

$runs = array($left_behind(), $left_behind(), $left_behind());

section('Batches: resealRows takes a limit and says where the next batch starts');
$retired = VaultPreviousKeys::open(new UserEncryptionVault($vault_id, TRUE), $current);
check(isset($retired[$gen1]), 'the chain holds the retired generation, opened through the current key');
$b = RecipeRun::resealRows($uid, $retired[$gen1], $gen1, (string)$vault->get('uev_public_key'), $gen2, 1, 0);
check($b['attempted'] === 1 && $b['last_id'] === $runs[0][0], 'a batch of one moves the first row and names it', json_encode($b));
check((int)$row($runs[0][0])['rcr_key_generation'] === $gen2 && $opens_with($runs[0][0], $current) === $runs[0][1],
	'that row is on the current key with the same DEK');
// Put it back, so the sweep below has all three to move.
$db->prepare('UPDATE rcr_recipe_runs SET rcr_sealed_key = ?, rcr_key_generation = ? WHERE rcr_recipe_run_id = ?')
	->execute(array($crypto->sealItemDek($runs[0][1], $old_public), $gen1, $runs[0][0]));

section('A slice out of time records its place and moves nothing');
check(VaultChainSweep::hasWork($uid), 'a chain entry not yet swept is work');
$done = VaultChainSweep::drain($uid, $current, microtime(true) - 1);
$e = $entry();
check($done === 0 && empty($e['swept']) && isset($e['sweep_at']['step']), 'the entry records where the sweep stopped', json_encode($e['sweep_at'] ?? null));
check((int)$row($runs[0][0])['rcr_key_generation'] === $gen1, 'and the rows are where they were');

section('The sweep moves the leftovers and marks the generation swept');
$done = VaultChainSweep::drain($uid, $current, microtime(true) + 60);
$moved = 0;
foreach ($runs as $run) {
	$moved += ((int)$row($run[0])['rcr_key_generation'] === $gen2 && $opens_with($run[0], $current) === $run[1]) ? 1 : 0;
}
check($moved === 3, 'every left-behind run opens with the current key, its DEK unchanged', (string)$moved);
check($opens_with($runs[0][0], $retired[$gen1]) === null, 'and no longer with the retired one');
$e = $entry();
check(!empty($e['swept']) && !isset($e['sweep_at']), 'the generation is marked swept', json_encode($e));
check(!VaultChainSweep::hasWork($uid), 'so the next unlock has nothing to do');
check(VaultChainSweep::drain($uid, $current, microtime(true) + 60) === 0, 'and a drain anyway moves nothing');

section('A rotation keeps the swept mark on what it carries over');
SealedEgressGuard::reset();
$set = vault_fixture_code_set($fx['root_salt'], 5);
$twins = vault_fixture_root_recovery($fx['root_salt'], $set, $fx['root_secret']);
$r = $ceremonies->rotate($user, new UserEncryptionVault($vault_id, TRUE), $credential_id, 'label', $kek, '',
	$set['set'], array('recovery' => $twins), false);
$gen3 = (int)$r['key_generation'];
$by_gen = array();
foreach (VaultPreviousKeys::entries(new UserEncryptionVault($vault_id, TRUE)) as $x) {
	$by_gen[$x['generation']] = !empty($x['swept']);
}
check(($by_gen[$gen1] ?? null) === true && ($by_gen[$gen2] ?? null) === false,
	'the old generation stays swept; the one just retired is not', json_encode($by_gen));
check((int)$row($runs[0][0])['rcr_key_generation'] === $gen3, 'the rotation itself moved the recipe runs (joinery_ai re-seals them)');
check(VaultChainSweep::drain($uid, $secret_for_generation($gen3), microtime(true) + 60) >= 0 && !VaultChainSweep::hasWork($uid),
	'one sweep after the rotation marks the new entry swept too');

section('A window on a key that is not the vault\'s current one sweeps nothing');
$v = new UserEncryptionVault($vault_id, TRUE);
$db->prepare('UPDATE uev_user_encryption_vaults SET uev_previous_keys = ? WHERE uev_user_encryption_vault_id = ?')
	->execute(array(str_replace('"swept":true', '"swept":false', (string)$v->get('uev_previous_keys')), $vault_id));
check(VaultChainSweep::hasWork($uid), 'with the marks cleared there is work again');
check(VaultChainSweep::drain($uid, $current, microtime(true) + 60) === 0 && VaultChainSweep::hasWork($uid),
	'a stale key (generation ' . $gen2 . ') moves nothing and marks nothing');

harness_finish();
