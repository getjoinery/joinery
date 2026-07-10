<?php
/** @joinery-test
 * name: vault_crypto_envelope
 * tier: safe
 * env: any
 * needs: []
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable', 'SealedBox hard-requires ext-sodium');
	harness_finish();
}

$box = new SealedBox();
$crypto = new VaultCrypto();
$kp = $box->generateKeypair();

section('Envelope dance');
$dek = $crypto->newItemDek();
check(strlen($dek) === 32, 'item DEK is 32 bytes');
$sealed_key = $crypto->sealItemDek($dek, $kp['public']);
check($crypto->openItemDek($sealed_key, $kp['secret']) === $dek, 'DEK seal/open round trip');
$blob = $crypto->sealField('the plaintext body', $dek, 'mail:42:body');
check($crypto->openField($blob, $dek, 'mail:42:body') === 'the plaintext body', 'field seal/open round trip');

section('Splice defenses');
// Two rows, each with its own DEK and AD.
$dek_a = $crypto->newItemDek();
$dek_b = $crypto->newItemDek();
$sealed_a = $crypto->sealItemDek($dek_a, $kp['public']);
$sealed_b = $crypto->sealItemDek($dek_b, $kp['public']);
$blob_a = $crypto->sealField('message A', $dek_a, 'mail:1:body');
$blob_b = $crypto->sealField('message B', $dek_b, 'mail:2:body');

$threw = false;
try { $crypto->openField($blob_a, $dek_a, 'mail:2:body'); } catch (Exception $e) { $threw = true; }
check($threw, 'row A ciphertext with row B AD throws');

// Swap the sealed DEKs between rows: opening row A's blob with row B's DEK
// (what a spliced sealed_key column would yield) must fail even with the
// correct AD.
$dek_swapped = $crypto->openItemDek($sealed_b, $kp['secret']);
$threw = false;
try { $crypto->openField($blob_a, $dek_swapped, 'mail:1:body'); } catch (Exception $e) { $threw = true; }
check($threw, 'row A ciphertext with row B DEK throws (sealed-key splice)');

$threw = false;
try { $crypto->openItemDek($sealed_a, $box->generateKeypair()['secret']); } catch (Exception $e) { $threw = true; }
check($threw, 'a sealed DEK never opens under a different vault key');

harness_finish();
?>
