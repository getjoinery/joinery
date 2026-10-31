<?php
/** @joinery-test
 * name: xwing_vector
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 *
 * X-Wing and the v2 seal, held to known answers, and the rules around them
 * (specs/post_quantum_sealing.md R1, R2, R9).
 *
 * tests/vault/fixtures/xwing_vector.json carries the X-Wing draft's Appendix C
 * vectors and a Joinery v2 seal vector; every runtime reproduces the same file
 * (WP2-WP6 add theirs). Here PHP must reproduce the draft's keys, ciphertexts
 * and shared secrets byte for byte, reproduce the seal blobs from their fixed
 * inputs, and open them.
 *
 * The rules: nothing seals to a 32-byte (v1) key, and the refusal names the
 * rotation owed; a box sealed for one scope never opens as another (a restore
 * approval's answer cannot open a decommission box); a v1 blob still opens, so
 * a holder can rotate off it; and a blob of one version handed to a key of the
 * other is refused naming both versions. A half-open hands over the dek-scope
 * key, which opens no other scope's box.
 */
require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/vault_fixtures.php');
harness_boot();

$why = SealedBox::pqReadyReason();
check($why === null, 'this process can seal with post-quantum keys', (string)$why);
if ($why !== null) {
	harness_finish();
}

$v = json_decode((string)file_get_contents(__DIR__ . '/fixtures/xwing_vector.json'), true);
check(is_array($v) && count($v['xwing']) >= 3 && count($v['seal']) >= 2, 'the vector file loads (' . ($v['draft'] ?? '?') . ')');
$xw = new XWing();
$box = new SealedBox();

/** Expect a RuntimeException from $fn; returns its message, or null when none was thrown. */
function xv_throws(callable $fn): ?string {
	try { $fn(); } catch (RuntimeException $e) { return $e->getMessage(); }
	return null;
}

section('X-Wing: the draft\'s Appendix C');

$bad = array();
foreach ($v['xwing'] as $i => $t) {
	$seed = hex2bin($t['seed']);
	$pk = $xw->publicKey($seed);
	[$ct, $ss] = $xw->encapsulate($pk, hex2bin($t['eseed']));
	$ss_dec = $xw->decapsulate($seed, hex2bin($t['ct']));
	if (bin2hex($pk) !== $t['pk']) { $bad[] = "#$i public key"; }
	if (bin2hex($ct) !== $t['ct']) { $bad[] = "#$i ciphertext"; }
	if (bin2hex($ss) !== $t['ss']) { $bad[] = "#$i encapsulated secret"; }
	if (bin2hex($ss_dec) !== $t['ss']) { $bad[] = "#$i decapsulated secret"; }
}
check($bad === array(), 'every vector: key generation, encapsulation with its eseed, and decapsulation give the draft\'s bytes',
	implode(', ', $bad));

$seed = random_bytes(XWing::SEED_BYTES);
$pk = $xw->publicKey($seed);
[$ct, $ss] = $xw->encapsulate($pk);
check(strlen($pk) === 1216 && strlen($ct) === 1120 && strlen($ss) === 32, 'sizes: public key 1216, ciphertext 1120, secret 32');
check($xw->decapsulate($seed, $ct) === $ss, 'a fresh encapsulation decapsulates');
$tampered = $ct;
$tampered[1100] = chr(ord($tampered[1100]) ^ 1);   // inside ct_X
check($xw->decapsulate($seed, $tampered) !== $ss, 'a modified X25519 half gives another secret');
check(xv_throws(function () use ($xw) { $xw->publicKey(random_bytes(31)); }) !== null, 'a seed that is not 32 bytes is refused');
check(xv_throws(function () use ($xw) { $xw->encapsulate(random_bytes(32)); }) !== null, 'a 32-byte key is refused by X-Wing');

section('the v2 seal: the Joinery vector');

$m = new ReflectionMethod('SealedBox', 'sealV2With');
foreach ($v['seal'] as $t) {
	$pub = base64_decode($t['public_b64'], true);
	check(bin2hex($xw->publicKey(hex2bin($t['seed_hex']))) === bin2hex($pub), 'scope ' . $t['scope'] . ': the seed generates the vector\'s public key');
	$blob = $m->invoke(null, hex2bin($t['plaintext_hex']), $pub, $t['scope'], hex2bin($t['eseed_hex']), hex2bin($t['iv_hex']));
	check(base64_encode($blob) === $t['sealed_b64'], 'scope ' . $t['scope'] . ': sealing with the fixed randomness gives the vector\'s blob');
	check(SealedBox::openV2(base64_decode($t['sealed_b64']), hex2bin($t['seed_hex']), $t['scope']) === hex2bin($t['plaintext_hex']),
		'scope ' . $t['scope'] . ': the vector blob opens');
	check(strlen($blob) === SealedBox::V2_OVERHEAD + strlen(hex2bin($t['plaintext_hex'])), 'the seal adds exactly ' . SealedBox::V2_OVERHEAD . ' bytes');
}

section('one scope\'s box never opens as another\'s');

$secret = random_bytes(32);
$restore = SealedBox::sealV2('job 7 approved', $pk, 'joinery-restore-approval:');
check(SealedBox::openV2($restore, $seed, 'joinery-restore-approval:') === 'job 7 approved', 'a restore-approval box opens under its own scope');
check(xv_throws(function () use ($restore, $seed) { SealedBox::openV2($restore, $seed, 'joinery-decommission-approval:'); }) !== null,
	'the same box does not open as a decommission approval');
check(xv_throws(function () use ($restore, $seed) { SealedBox::openV2($restore, $seed); }) !== null,
	'nor as a key seal (the dek scope)');
check(xv_throws(function () use ($pk) { SealedBox::sealV2('x', $pk, ''); }) !== null, 'a v2 seal must name its scope');

// A holder that decapsulates elsewhere hands over the dek-scope key, never the
// shared secret: a stager who swaps in an approval challenge's ciphertext gets
// a key that opens only dek boxes (reviewer2 WP1 B1).
$dek_box = SealedBox::sealV2('the data key', $pk);
$ss_restore = $xw->decapsulate($seed, substr($restore, 0, XWing::CIPHERTEXT_BYTES));
$ss_dek = $xw->decapsulate($seed, substr($dek_box, 0, XWing::CIPHERTEXT_BYTES));
check(SealedBox::openV2WithKey($dek_box, SealedBox::v2Key($ss_dek)) === 'the data key', 'the dek-scope key opens its dek box');
check(xv_throws(function () use ($restore, $ss_restore) { SealedBox::openV2WithKey($restore, SealedBox::v2Key($ss_restore)); }) !== null,
	'the dek-scope key worked out from a challenge\'s ciphertext does not open that challenge');

section('nothing seals to a 32-byte key');

$old = sodium_crypto_box_keypair();
$old_pub = SealedBox::b64url(sodium_crypto_box_publickey($old));
$old_secret = SealedBox::b64url(sodium_crypto_box_secretkey($old));
foreach (array(
	'sealDek'   => function () use ($box, $old_pub) { $box->sealDek(random_bytes(32), $old_pub); },
	'sealBinary' => function () use ($box, $old_pub) { $box->sealBinary(random_bytes(32), $old_pub); },
	'sealEdge'  => function () use ($box, $old_pub) { $box->sealEdge(random_bytes(32), $old_pub); },
	'sealV2'    => function () use ($old) { SealedBox::sealV2(random_bytes(32), sodium_crypto_box_publickey($old)); },
	'VaultCrypto::sealItemDek' => function () use ($old_pub) { (new VaultCrypto())->sealItemDek(random_bytes(32), $old_pub); },
	'BackupEnvelope::build' => function () use ($old) {
		BackupEnvelope::build('k', 'a', array(array('kind' => 'recovery', 'pub' => sodium_crypto_box_publickey($old))));
	},
) as $what => $fn) {
	$msg = null;
	try { $fn(); } catch (Throwable $e) { $msg = $e->getMessage(); }
	check($msg !== null && stripos($msg, 'X25519') !== false && (stripos($msg, 'rotat') !== false || stripos($msg, 'new recovery key') !== false),
		$what . ' refuses a 32-byte key and names the rotation owed', (string)$msg);
}

section('v1 blobs still open, for the rotation off them');

$dek = random_bytes(32);
$v1_blob = 'v1.seal.' . SealedBox::b64url(sodium_crypto_box_seal($dek, sodium_crypto_box_publickey($old)));
check($box->openDek($v1_blob, $old_secret) === $dek, 'a v1.seal. blob opens with its X25519 secret (the frame names the version)');
check($box->openBinary(sodium_crypto_box_seal($dek, sodium_crypto_box_publickey($old)), $old_secret, SealedBox::V1) === $dek,
	'a raw v1 box opens when the caller names v1');
$edge = json_decode((string)file_get_contents(__DIR__ . '/fixtures/edge_vector.json'), true);
check($box->openEdge($edge['sealed_dek_b64'], SealedBox::b64url(hex2bin($edge['recipient_secret_hex'])), $edge['recipient_public_b64'])
	=== hex2bin($edge['dek_hex']), 'the browser\'s v1 ECIES vector opens (the 32-byte public key names v1)');
$v1_key = vault_fixture_key($old_secret, SealedBox::V1);
check($v1_key->version() === SealedBox::V1 && $v1_key->publicKey() === $old_pub, 'a v1 secret in a wrapping opens as a v1 vault key with its X25519 public key');
check((new VaultCrypto())->openItemDek($v1_blob, $v1_key) === $dek, 'a v1 vault key opens a v1.seal. DEK');

section('a blob of one version handed to a key of the other is refused, naming both');

$kp = $box->generateKeypair();
check($kp['version'] === SealedBox::V2 && strlen(SealedBox::b64url_decode($kp['public'])) === 1216
	&& strlen(SealedBox::b64url_decode($kp['secret'])) === 32, 'generateKeypair() mints a 32-byte seed and a 1216-byte public key');
$v2_key = vault_fixture_key($kp['secret']);
check($v2_key->version() === SealedBox::V2 && $v2_key->publicKey() === $kp['public'], 'a v2 secret in a wrapping opens as a v2 vault key');
$v2_blob = $box->sealDek($dek, $kp['public']);
check(strpos($v2_blob, 'v2.seal.') === 0, 'sealDek() writes v2.seal.');
check((new VaultCrypto())->openItemDek($v2_blob, $v2_key) === $dek, 'the v2 key opens it');
$msg = xv_throws(function () use ($v2_blob, $v1_key) { (new VaultCrypto())->openItemDek($v2_blob, $v1_key); });
check($msg !== null && strpos($msg, 'v2') !== false && strpos($msg, 'v1') !== false, 'a v2 blob under a v1 key is refused naming both versions', (string)$msg);
$msg = xv_throws(function () use ($v1_blob, $v2_key) { (new VaultCrypto())->openItemDek($v1_blob, $v2_key); });
check($msg !== null && strpos($msg, 'v1') !== false && strpos($msg, 'v2') !== false, 'and a v1 blob under a v2 key', (string)$msg);
$edge_v2 = (new VaultCrypto())->sealItemDekToBrowserKey($dek, $kp['public'], 'drive');
check(strpos($edge_v2, 'v2.edgeseal.drive.') === 0, 'the browser-format key is framed v2.edgeseal.{scope}.');
$msg = xv_throws(function () use ($edge_v2, $v1_key) { (new VaultCrypto())->openItemDek($edge_v2, $v1_key); });
check($msg !== null && strpos($msg, 'v2') !== false && strpos($msg, 'v1') !== false, 'a v2.edgeseal. key under a v1 vault key is refused naming both', (string)$msg);
check((new VaultCrypto())->openItemDek($edge_v2, $v2_key) === $dek, 'and opens under the v2 key');

harness_finish();
