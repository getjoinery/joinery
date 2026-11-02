<?php
/** @joinery-test
 * name: seal_entry_points
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 * covers: [plugins/mailbox/provisioning/relay-sealer/seal.go]
 *
 * Every seal the platform writes is v2 (post_quantum_sealing.md,
 * Acceptance for the whole): each public PHP entry point that seals to a public
 * key is called, and the frame and size of what it returns are inspected,
 * then opened with the seed. A new keypair is X-Wing. The refusal to seal to
 * a key that is not 1216 bytes is tests/vault/xwing_vector_test.php's.
 *
 * @version 1.1 - the source scan for X25519 sealing is gone with the X25519 code
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$why = SealedBox::pqReadyReason();
if ($why !== null) {
	check(false, 'this process can seal with post-quantum keys', $why);
	harness_finish();
}

$box = new SealedBox();
$crypto = new VaultCrypto();
$v2_raw_len = function (int $plain) { return XWing::CIPHERTEXT_BYTES + 12 + $plain + 16; };

section('A new keypair is X-Wing');
$kp = $box->generateKeypair();
$pub_raw = SealedBox::b64url_decode($kp['public']);
check($kp['version'] === SealedBox::V2 && strlen($pub_raw) === XWing::PUBLIC_KEY_BYTES,
	'generateKeypair(): version 2, a 1216-byte public key', 'got ' . strlen($pub_raw) . ' bytes');
check(strlen(SealedBox::b64url_decode($kp['secret'])) === XWing::SEED_BYTES, 'its secret is the 32-byte seed');
check(SealedBox::publicKeyFor($kp['secret'], SealedBox::V2) === $kp['public'], 'the seed generates its public key');

$dek = random_bytes(32);

section('Each PHP sealer writes v2');
$sealed = $box->sealDek($dek, $kp['public']);
check(strncmp($sealed, 'v2.seal.', 8) === 0, 'SealedBox::sealDek() frames v2.seal.');
check(strlen(SealedBox::b64url_decode(substr($sealed, 8))) === $v2_raw_len(32), 'sealDek(): the v2 raw size');
check($box->openDek($sealed, $kp['secret']) === $dek, 'sealDek(): opens with the seed');

$bin = $box->sealBinary($dek, $kp['public']);
check(strlen($bin) === $v2_raw_len(32) && $box->openBinary($bin, $kp['secret'], SealedBox::V2) === $dek,
	'SealedBox::sealBinary(): raw v2, opens as v2');
check(strlen($bin) === DirectProtocol::sealedSizeCeiling(32), 'a Direct part\'s size ceiling is the v2 size');

$edge = $box->sealEdge($dek, $kp['public']);
check(strlen(base64_decode($edge, true)) === $v2_raw_len(32) && $box->openEdge($edge, $kp['secret'], $kp['public']) === $dek,
	'SealedBox::sealEdge(): base64 of the v2 raw form, opens with the seed');

$raw = SealedBox::sealV2($dek, $pub_raw, 'joinery-restore-approval:');
check(strlen($raw) === $v2_raw_len(32) && SealedBox::openV2($raw, SealedBox::b64url_decode($kp['secret']), 'joinery-restore-approval:') === $dek,
	'SealedBox::sealV2() with a challenge scope opens under that scope');

$item = $crypto->sealItemDek($dek, $kp['public']);
check(strncmp($item, 'v2.seal.', 8) === 0, 'VaultCrypto::sealItemDek() frames v2.seal.');
$to_browser = $crypto->sealItemDekToBrowserKey($dek, $kp['public'], 'drive');
check(strncmp($to_browser, 'v2.edgeseal.drive.', 18) === 0 && VaultCrypto::parseEdgeScope($to_browser) === 'drive',
	'VaultCrypto::sealItemDekToBrowserKey() frames v2.edgeseal.{scope}.');
check($box->openEdge(substr($to_browser, 18), $kp['secret'], $kp['public']) === $dek, 'the browser-format key opens with the seed');

section('A backup envelope is version 2, every recipient X-Wing');
$site = $box->generateKeypair();
$env = BackupEnvelope::build($dek, 'seal-entry-points.tar.gz.enc', array(
	array('kind' => 'recovery', 'pub' => $pub_raw),
	array('kind' => 'site', 'pub' => SealedBox::b64url_decode($site['public'])),
));
check($env['version'] === 2, 'BackupEnvelope::build(): version 2');
$algs = array_unique(array_column($env['recipients'], 'alg'));
check($algs === array(BackupEnvelope::ALG_XWING), 'every recipient says alg xwing', implode(',', $algs));
$sizes = array_unique(array_map(function ($r) { return strlen(base64_decode($r['sealed'], true)); }, $env['recipients']));
check($sizes === array($v2_raw_len(32)), 'every recipient box is the v2 raw size');
check(BackupEnvelope::open($env, base64_encode(SealedBox::b64url_decode($kp['secret']))) === $dek, 'the recovery seed opens it');

harness_finish();
