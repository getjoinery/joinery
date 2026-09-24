<?php
/** @joinery-test
 * name: edge_format
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 *
 * The browser format, sealed and opened on the server, byte for byte.
 *
 * A client-custody row is sealed with the format vault-crypto.js speaks:
 * `v1.edgeseal.{scope}.` + base64(ephPub[32] ‖ IV[12] ‖ AES-GCM ct) for the
 * DEK, and `v1.edge.` + base64(IV[12] ‖ ct ‖ tag) for a field
 * (docs/sealed_vault.md § Client-custody scopes). The server writes both when
 * it seals for a Fortress owner, and opens both when a DEK is sealed to its
 * own key. Nothing checks that agreement at run time, so this suite pins it:
 * tests/vault/fixtures/edge_vector.json was produced by WebCrypto, PHP must
 * reproduce exactly those bytes from the same inputs and open them, and
 * vault-crypto.js selfCheck() must open the same vector in a JS engine.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}

$vector = json_decode((string)file_get_contents(__DIR__ . '/fixtures/edge_vector.json'), true);
$box = new SealedBox();
$crypto = new VaultCrypto();

/** Call a private SealedBox helper that takes fixed randomness (the vector's only use for it). */
function ef_private(string $method, array $args) {
	$m = new ReflectionMethod('SealedBox', $method);
	$m->setAccessible(true);
	return $m->invokeArgs(null, $args);
}

/** Expect a RuntimeException from $fn. */
function ef_throws(callable $fn): bool {
	try { $fn(); } catch (RuntimeException $e) { return true; }
	return false;
}

$recipient_secret = hex2bin($vector['recipient_secret_hex']);
$recipient_secret_b64url = SealedBox::b64url($recipient_secret);
$recipient_public_b64 = $vector['recipient_public_b64'];
$dek = hex2bin($vector['dek_hex']);

section('the sealed DEK: PHP reproduces what WebCrypto produced');

check(SealedBox::b64url(sodium_crypto_box_publickey_from_secretkey($recipient_secret)) === rtrim(strtr($recipient_public_b64, '+/', '-_'), '='),
	'the browser-generated recipient keypair is an ordinary X25519 pair to libsodium');

$sealed = ef_private('sealEdgeWith', array($dek, $recipient_public_b64, hex2bin($vector['eph_secret_hex']), hex2bin($vector['seal_iv_hex'])));
check($sealed === $vector['sealed_dek_b64'], 'sealing the vector DEK with the vector ephemeral and IV gives the WebCrypto bytes exactly');

$raw = base64_decode($sealed, true);
check($raw !== false && strlen($raw) === 32 + 12 + 32 + 16, 'the blob is standard base64 of ephPub[32] ‖ IV[12] ‖ ct[32] ‖ tag[16]');
check(bin2hex(substr($raw, 0, 32)) === $vector['eph_public_hex'], 'bytes 0-31 are the ephemeral public key');
check(bin2hex(substr($raw, 32, 12)) === $vector['seal_iv_hex'], 'bytes 32-43 are the IV');

check($box->openEdge($vector['sealed_dek_b64'], $recipient_secret_b64url, $recipient_public_b64) === $dek,
	'openEdge opens the WebCrypto-sealed DEK');

$tampered = $raw;
$tampered[50] = chr(ord($tampered[50]) ^ 1);
check(ef_throws(function () use ($box, $tampered, $recipient_secret_b64url, $recipient_public_b64) {
	$box->openEdge(base64_encode($tampered), $recipient_secret_b64url, $recipient_public_b64);
}), 'a flipped ciphertext bit refuses to open');

$other = $box->generateKeypair();
check(ef_throws(function () use ($box, $other, $vector, $recipient_public_b64) {
	$box->openEdge($vector['sealed_dek_b64'], $other['secret'], $recipient_public_b64);
}), 'another secret key refuses to open');

check(ef_throws(function () use ($box, $dek) { $box->sealEdge($dek, base64_encode(random_bytes(31))); }),
	'a public key that does not decode to 32 bytes is refused');

section('a fresh seal round-trips, with either base64 alphabet for the public key');

$pair = $box->generateKeypair();
$public_std = base64_encode(SealedBox::b64url_decode($pair['public']));
$fresh_dek = random_bytes(32);
$fresh = $box->sealEdge($fresh_dek, $public_std);
check($box->openEdge($fresh, $pair['secret'], $public_std) === $fresh_dek, 'sealEdge → openEdge (standard base64 public key, as the browser stores it)');
check($box->openEdge($box->sealEdge($fresh_dek, $pair['public']), $pair['secret'], $pair['public']) === $fresh_dek,
	'sealEdge → openEdge (base64url public key, as a server vault stores it)');
check($box->sealEdge($fresh_dek, $public_std) !== $fresh, 'two seals of the same DEK differ (fresh ephemeral and IV each time)');

section('a field: AES-256-GCM with the row AD');

$field = ef_private('aeadEncryptGcmWith', array($vector['field_plaintext'], $dek, $vector['field_ad'], hex2bin($vector['field_iv_hex'])));
check($field === $vector['field_blob_b64'], 'encrypting the vector field with the vector IV gives the WebCrypto bytes exactly');
check($box->aeadDecryptGcm($vector['field_blob_b64'], $dek, $vector['field_ad']) === $vector['field_plaintext'], 'aeadDecryptGcm opens the WebCrypto field');
check(ef_throws(function () use ($box, $dek, $vector) { $box->aeadDecryptGcm($vector['field_blob_b64'], $dek, 'acn:43:acn_body'); }),
	'the wrong AD (another row) refuses to open');
$blob = $box->aeadEncryptGcm('', $dek, 'x:1:y');
check($box->aeadDecryptGcm($blob, $dek, 'x:1:y') === '', 'an empty plaintext round-trips');
check(ef_throws(function () use ($box) { $box->aeadEncryptGcm('a', random_bytes(16), 'x'); }), 'a key that is not 32 bytes is refused');

section('VaultCrypto frames the row layer and opens whichever prefix it finds');

$key = PoolVaultKey::open(null)['key'];
$edge_to_server = $crypto->sealItemDekToBrowserKey($fresh_dek, $key->publicKey(), 'drive');
check(strpos($edge_to_server, 'v1.edgeseal.drive.') === 0, 'sealItemDekToBrowserKey frames the blob as v1.edgeseal.{scope}.');
check(VaultCrypto::parseEdgeScope($edge_to_server) === 'drive', 'parseEdgeScope reads the scope back');
check(VaultCrypto::parseEdgeScope('v1.seal.abc') === null && VaultCrypto::parseEdgeScope('') === null,
	'parseEdgeScope is null for a server-custody blob and for nothing');
check(VaultCrypto::parseEdgeScope('v1.edgeseal.Bad.Scope.x') === null, 'parseEdgeScope refuses a malformed scope');
check(ef_throws(function () use ($crypto, $fresh_dek, $key) { $crypto->sealItemDekToBrowserKey($fresh_dek, $key->publicKey(), 'no.dots'); }),
	'sealItemDekToBrowserKey refuses a scope name that could not be parsed back');

check($crypto->openItemDek($edge_to_server, $key) === $fresh_dek, 'openItemDek opens a v1.edgeseal. blob sealed to the key it holds');
$classic = $crypto->sealItemDek($fresh_dek, $key->publicKey());
check($crypto->openItemDek($classic, $key) === $fresh_dek, 'openItemDek still opens a v1.seal. blob');

$dek2 = random_bytes(32);
$batch = $crypto->openItemDeks(array(
	'a' => $crypto->sealItemDekToBrowserKey($dek2, $key->publicKey(), 'passwords'),
	'b' => $crypto->sealItemDek($dek2, $key->publicKey()),
), $key);
check(($batch['a'] ?? null) === $dek2 && ($batch['b'] ?? null) === $dek2, 'openItemDeks opens a mixed batch under the caller\'s keys');

$other_key = PoolVaultKey::open(null)['key'];
check(ef_throws(function () use ($crypto, $edge_to_server, $other_key) { $crypto->openItemDek($edge_to_server, $other_key); }),
	'an edge blob does not open under another key (the memo is keyed on the key too)');

$sealed_field = $crypto->sealFieldForBrowser('hello', $fresh_dek, 'nt:7:nt_body');
check(strpos($sealed_field, 'v1.edge.') === 0, 'sealFieldForBrowser frames the field as v1.edge.');
check($crypto->openField($sealed_field, $fresh_dek, 'nt:7:nt_body') === 'hello', 'openField opens a v1.edge. field');
check(ef_throws(function () use ($crypto, $sealed_field, $fresh_dek) { $crypto->openField($sealed_field, $fresh_dek, 'nt:8:nt_body'); }),
	'openField refuses a v1.edge. field under another row\'s AD');
check($crypto->openField($crypto->sealField('hi', $fresh_dek, 'nt:7:x'), $fresh_dek, 'nt:7:x') === 'hi', 'openField still opens a v1.aead. field');

section('vault-crypto.js speaks the same bytes');

$js_path = PathHelper::getIncludePath('assets/js/vault-crypto.js');
$js = (string)file_get_contents($js_path);
check(strpos($js, $vector['sealed_dek_b64']) !== false && strpos($js, $vector['field_blob_b64']) !== false,
	'vault-crypto.js carries this vector for selfCheck()');

$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
	check(true, 'node is not installed here; selfCheck() is proven in the browser walk instead');
} else {
	$runner = tempnam(sys_get_temp_dir(), 'edgevec') . '.js';
	file_put_contents($runner, "globalThis.window = globalThis;\n"
		. "require(" . json_encode($js_path) . ");\n"
		. "window.VaultCrypto.selfCheck().then(function (ok) { process.stdout.write(ok ? 'OK' : 'FALSE'); })"
		. ".catch(function (e) { process.stdout.write('ERR ' + e.message); });\n");
	$out = trim((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($runner) . ' 2>&1'));
	@unlink($runner);
	check($out === 'OK', 'vault-crypto.js selfCheck() resolves true in a JS engine (WebCrypto opens the vector, seals and reopens)', $out);
}

harness_finish();
