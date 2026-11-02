<?php
/** @joinery-test
 * name: root_codes_current_key
 * tier: test-db
 * env: any
 * needs: [test-db]
 *
 * New root recovery codes made under a key that is not the root's current one
 * are refused, and the root's working codes stay (reviewer2 B3): a tab holding
 * an older root cannot replace them.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');
harness_test_mode();

// ── Root recovery codes ─────────────────────────────────────────────────
section('New codes made under a root key that is not the root\'s current one are refused (reviewer2 B3)');

VaultUnlock::loadConsumerBootstraps();
$fx = vault_fixture_vault('OklRoot');
$fuid = (int)$fx['user']->key;
$root_public = (string)VaultClientCustody::loadVault($fuid, 'root')->get('uev_public_key');
$other_public = base64_encode((new XWing())->publicKey(random_bytes(32)));
$regen = vault_fixture_code_set($fx['root_salt'], 5);
$recovery_rows = function () use ($fuid) {
	$ids = array();
	foreach (new MultiUserEncryptionWrapping(array('vault_id' => VaultClientCustody::loadVault($fuid, 'root')->key,
			'unlocker_type' => 'recovery', 'deleted' => false)) as $w) { $ids[] = (int)$w->key; }
	sort($ids);
	return $ids;
};
$rows_before = $recovery_rows();
$stale = vault_fixture_root_recovery($fx['root_salt'], $regen, $fx['root_secret']);
foreach ($stale as $i => $w) { $stale[$i]['public_key'] = $other_public; }
$threw = '';
try { VaultClientCustody::replaceRootRecovery($fuid, $regen['set'], $stale); } catch (VaultClientCustodyException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'reload the page') !== false, 'codes that name another root key are refused', $threw);
check($recovery_rows() === $rows_before, 'and the root\'s working codes are left as they were');
$good = vault_fixture_root_recovery($fx['root_salt'], $regen, $fx['root_secret']);
foreach ($good as $i => $w) { $good[$i]['public_key'] = $root_public; }
$threw = '';
try { VaultClientCustody::replaceRootRecovery($fuid, $regen['set'], $good); } catch (VaultClientCustodyException $e) { $threw = $e->getMessage(); }
check($threw === '' && $recovery_rows() !== $rows_before, 'made under the current root key, they replace the old ones', $threw);

harness_finish();
