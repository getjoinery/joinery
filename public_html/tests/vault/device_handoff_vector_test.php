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
 * Here SealedBox reproduces every blob in it exactly from the recorded
 * ephemeral keys and IVs, and opens each one: so browser, server and phones
 * agree on one set of bytes.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}

$v = json_decode((string)file_get_contents(__DIR__ . '/fixtures/device_handoff_vector.json'), true);
check(is_array($v), 'the vector file parses');
if (!is_array($v)) {
	harness_finish();
}
$box = new SealedBox();

/** Call a private SealedBox helper that takes fixed randomness (the vector's only use for it). */
function dhv_private(string $method, array $args) {
	$m = new ReflectionMethod('SealedBox', $method);
	$m->setAccessible(true);
	return $m->invokeArgs(null, $args);
}

section('Key pairs');
$device_secret = hex2bin($v['device']['secret_hex']);
$vault_secret = hex2bin($v['vault']['secret_hex']);
check(base64_encode(sodium_crypto_box_publickey_from_secretkey($device_secret)) === $v['device']['public_b64'],
	'the device public key is the device secret\'s X25519 public key');
check(base64_encode(sodium_crypto_box_publickey_from_secretkey($vault_secret)) === $v['vault']['public_b64'],
	'the vault public key is the vault secret\'s X25519 public key');
check($v['vault']['pkcs8_hex'] === '302e020100300506032b656e04220420' . $v['vault']['secret_hex'],
	'the handed-over secret is PKCS#8: the fixed 16-byte prefix and the raw scalar');

section('The handoff');
$pkcs8 = hex2bin($v['vault']['pkcs8_hex']);
$handoff = dhv_private('sealEdgeWith', array($pkcs8, $v['device']['public_b64'],
	hex2bin($v['handoff']['eph_secret_hex']), hex2bin($v['handoff']['iv_hex'])));
check($handoff === $v['handoff']['blob'], 'SealedBox seals the PKCS#8 to the device key into exactly the browser\'s bytes');
check($box->openEdge($v['handoff']['blob'], SealedBox::b64url($device_secret), $v['device']['public_b64']) === $pkcs8,
	'and opens the browser\'s blob with the device secret');

section('The row');
$row = $v['row'];
$dek = hex2bin($row['dek_hex']);
$sealed = 'v1.edgeseal.mail.' . dhv_private('sealEdgeWith', array($dek, $v['vault']['public_b64'],
	hex2bin($row['dek_eph_secret_hex']), hex2bin($row['dek_iv_hex'])));
check($sealed === $row['sealed_dek'], 'the row DEK seals to the vault key into exactly the browser\'s bytes');
check(VaultCrypto::parseEdgeScope($row['sealed_dek']) === 'mail', 'the sealed DEK names the mail scope');
check($box->openEdge(substr($row['sealed_dek'], strlen('v1.edgeseal.mail.')), SealedBox::b64url($vault_secret), $v['vault']['public_b64']) === $dek,
	'and opens with the mail secret');
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
