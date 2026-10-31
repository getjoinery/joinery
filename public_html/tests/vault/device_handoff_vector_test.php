<?php
/** @joinery-test
 * name: device_handoff_vector_php
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 *
 * The server speaks the device-handoff vector's bytes.
 *
 * tests/vault/fixtures/device_handoff_vector.json is built by the browser's
 * vault-crypto.js (device_handoff_vector_gate.sh holds it to that) and is what
 * the phone apps test their crypto against (specs/fortress_mobile_apps.md WP1).
 * Every key is X-Wing and every key seal the v2 format
 * (specs/post_quantum_sealing.md R2, WP2): here XWing derives each public key
 * from its seed, SealedBox reproduces both seals byte for byte from the
 * recorded encapsulation seeds and IVs and opens them, and each field (a
 * format v2 did not change) is reproduced exactly from its recorded IV.
 *
 * @version 2.0 - X-Wing: seeds, v2 seals reproduced and opened
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$v = json_decode((string)file_get_contents(__DIR__ . '/fixtures/device_handoff_vector.json'), true);
check(is_array($v), 'the vector file parses');
if (!is_array($v)) {
	harness_finish();
}
$box = new SealedBox();

/** Call a private SealedBox helper that takes fixed randomness (the vector's only use for it). */
function dhv_private(string $method, array $args) {
	return (new ReflectionMethod('SealedBox', $method))->invokeArgs(null, $args);
}

section('Key pairs');
$device_seed = hex2bin($v['device']['seed_hex']);
$vault_seed = hex2bin($v['vault']['seed_hex']);
$xwing = new XWing();
check(base64_encode($xwing->publicKey($device_seed)) === $v['device']['public_b64'],
	'the device public key is the device seed\'s X-Wing public key');
check(base64_encode($xwing->publicKey($vault_seed)) === $v['vault']['public_b64'],
	'the vault public key is the vault seed\'s X-Wing public key');

section('The handoff');
$handoff = dhv_private('sealV2With', array($vault_seed, base64_decode($v['device']['public_b64']), SealedBox::SCOPE_DEK,
	hex2bin($v['handoff']['eseed_hex']), hex2bin($v['handoff']['iv_hex'])));
check(base64_encode($handoff) === $v['handoff']['blob'], 'the server seals exactly the browser\'s handoff bytes');
check($box->openEdge($v['handoff']['blob'], SealedBox::b64url($device_seed), $v['device']['public_b64']) === $vault_seed,
	'and opens the browser\'s blob with the device seed to the vault seed');

section('The row');
$row = $v['row'];
$dek = hex2bin($row['dek_hex']);
check(VaultCrypto::parseEdgeScope($row['sealed_dek']) === 'mail' && strpos($row['sealed_dek'], 'v2.edgeseal.mail.') === 0,
	'the sealed DEK is framed v2 and names the mail scope');
$sealed_dek = dhv_private('sealV2With', array($dek, base64_decode($v['vault']['public_b64']), SealedBox::SCOPE_DEK,
	hex2bin($row['dek_eseed_hex']), hex2bin($row['dek_iv_hex'])));
check('v2.edgeseal.mail.' . base64_encode($sealed_dek) === $row['sealed_dek'], 'the server seals exactly the browser\'s row key');
check($box->openEdge(substr($row['sealed_dek'], strlen('v2.edgeseal.mail.')), SealedBox::b64url($vault_seed), $v['vault']['public_b64']) === $dek,
	'and opens with the mail seed');
check(InboundEmailMessage::sealedAdPrefix() === $row['ad_prefix'], 'the AD prefix is the one the server hands a reader (sealedAdPrefix)');
check(InboundEmailMessage::attachmentAd($row['id'], $row['part']['mime_part']) === $row['part']['ad'], 'the part AD is attachmentAd()');

$crypto = new VaultCrypto();
foreach (array_merge($row['fields'], array($row['search_text'])) as $f) {
	$plain = $f['plaintext'] ?? $f['packed'];
	$blob = dhv_private('aeadEncryptGcmWith', array($plain, $dek, $f['ad'], hex2bin($f['iv_hex'])));
	check('v1.edge.' . $blob === $f['value'], $f['column'] . ': the server seals exactly the browser\'s bytes');
	check($crypto->openField($f['value'], $dek, $f['ad']) === $plain, $f['column'] . ': openField opens it');
}
$packed = $row['search_text']['packed'];
check(strpos($packed, 'gz:') === 0 && gzdecode(base64_decode(substr($packed, 3))) === $row['search_text']['text'],
	'the gz: search text inflates as the server\'s own searchTextFor() output does');

$part = $row['part'];
$stored = substr($part['stored'], strlen('v1.edge.'));
check($box->aeadDecryptGcm($stored, $dek, $part['ad']) === base64_decode($part['bytes_b64']),
	'the stored part opens to its bytes under the part AD');

harness_finish();
