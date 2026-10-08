<?php
/** @joinery-test
 * name: log_tile_reader
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Reading a Rekor v2 log as it is served (LogTileReader), and Sigstore's
 * trusted root on its own (ReleaseLogClient::trustedRoot(), rootListsKey()),
 * for utils/verify_release.php (spec release_transparency, D7):
 *
 *  - a tile number written as C2SP paths it (x557/493, 005)
 *  - an entry bundle split into its entries; a bundle cut short is refused
 *  - an entry fetched from the bundle that holds it, the newest bundle of a
 *    tree read as a partial one (or the full one, once the log has filled it),
 *    an entry past the end of the tree refused
 *  - a checkpoint verified against the log's key, and refused under another
 *
 * Runs offline: the recorded Rekor v2 answer in tests/fixtures/release_log/
 * (log2025-1, index 142362519) stands in for the log.
 * Run: php tests/run.php safe --filter=log_tile_reader
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$fx = __DIR__ . '/../fixtures/release_log';
$origin = 'log2025-1.rekor.sigstore.dev';
$log_key = base64_decode('MCowBQYDK2VwAyEAt8rlp1knGwjfbcXAYPYAkn0XiLz1x8O4t0YkEhie244=');
$response = json_decode(file_get_contents($fx . '/rekor/response.json'), true);
$leaf = base64_decode($response['canonicalizedBody']);
$index = (int)$response['logIndex'];
$tree_size = (int)$response['inclusionProof']['treeSize'];
$checkpoint = $response['inclusionProof']['checkpoint']['envelope'];

/** The refusal $fn throws, or null. */
function ltr_refusal(callable $fn) {
	try { $fn(); } catch (Exception $e) { return $e->getMessage(); }
	return null;
}

section('Tile paths and entry bundles');

check(LogTileReader::tilePath(557493) === 'x557/493', 'tile 557493 is x557/493');
check(LogTileReader::tilePath(5) === '005', 'tile 5 is 005');
check(LogTileReader::tilePath(1234067) === 'x001/x234/067', 'tile 1234067 is x001/x234/067');
check(LogTileReader::tilePath(0) === '000', 'tile 0 is 000');

$bundle = pack('n', 3) . 'abc' . pack('n', 0) . pack('n', 2) . 'de';
check(LogTileReader::bundleEntries($bundle) === array('abc', '', 'de'), 'a bundle splits into its entries, an empty one included');
check(ltr_refusal(function () use ($bundle) { LogTileReader::bundleEntries(substr($bundle, 0, -1)); }) !== null,
	'a bundle cut inside an entry is refused');
check(ltr_refusal(function () use ($bundle) { LogTileReader::bundleEntries($bundle . "\x00"); }) !== null,
	'a bundle cut inside a length is refused');

section('An entry and a checkpoint, from a log that serves the recorded answer');

$tile = intdiv($index, 256);
$in_tile = $tree_size - $tile * 256;
$entries = '';
for ($i = 0; $i < $in_tile; $i++) {
	$bytes = ($i === $index % 256) ? $leaf : 'entry ' . $i;
	$entries .= pack('n', strlen($bytes)) . $bytes;
}
$asked = array();
$reader = new LogTileReader(function ($url) use (&$asked, $origin, $checkpoint, $entries, $tile, $in_tile) {
	$asked[] = $url;
	if ($url === "https://{$origin}/api/v2/checkpoint") { return array('status' => 200, 'body' => $checkpoint); }
	if ($url === "https://{$origin}/api/v2/tile/entries/" . LogTileReader::tilePath($tile) . ".p/{$in_tile}") {
		return array('status' => 200, 'body' => $entries);
	}
	return array('status' => 404, 'body' => '');
});

$cp = $reader->checkpoint($origin, array($origin => $log_key));
check($cp['tree_size'] === $tree_size, 'the checkpoint verifies against the log\'s key', 'tree size ' . $cp['tree_size']);
check(ltr_refusal(function () use ($reader, $origin) {
	$reader->checkpoint($origin, array($origin => sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())));
}) !== null, 'a checkpoint is refused under any other key');
$stranger = TransparencyProof::ED25519_SPKI_PREFIX . random_bytes(32);
$cp2 = $reader->checkpoint($origin, array($origin => array($stranger, $log_key)));
check($cp2['tree_size'] === $tree_size, 'a log held by two keys (rotating in place): a checkpoint signed by either verifies');
check(ltr_refusal(function () use ($reader, $origin, $stranger) {
	$reader->checkpoint($origin, array($origin => array($stranger)));
}) !== null, 'and a set holding none of its keys refuses it');

check($reader->entry($origin, $index, $tree_size) === $leaf, 'the entry comes from the bundle that holds it');
check(substr(end($asked), -strlen(".p/{$in_tile}")) === ".p/{$in_tile}", 'the newest bundle of the tree is read as a partial one', end($asked));
$filled = new LogTileReader(function ($url) use ($origin, $entries, $tile) {
	return $url === "https://{$origin}/api/v2/tile/entries/" . LogTileReader::tilePath($tile)
		? array('status' => 200, 'body' => $entries) : array('status' => 404, 'body' => '');
});
check($filled->entry($origin, $index, $tree_size) === $leaf,
	'a partial bundle the log no longer serves is read from the full one');
check(ltr_refusal(function () use ($reader, $origin, $tree_size) { $reader->entry($origin, $tree_size, $tree_size); }) !== null,
	'an entry past the end of the tree is refused');
check(ltr_refusal(function () use ($reader) { $reader->entry('not a host/', 1, 10); }) !== null, 'an origin that is not a host is refused');

section('Sigstore\'s trusted root');

$tuf = function ($method, $url, $body) use ($fx) {
	$file = $fx . '/tuf/' . substr($url, strlen(ReleaseLogClient::TUF_BASE) + 1);
	return is_file($file) ? array('status' => 200, 'body' => file_get_contents($file)) : array('status' => 404, 'body' => '');
};
$root = (new ReleaseLogClient(array(), null, $tuf))->trustedRoot();
check(is_array($root['tlogs'] ?? null) && count($root['tlogs']) > 0, 'the trusted root is read from the recorded TUF copy');
check(ReleaseLogClient::rootListsKey($root, $origin, $log_key), 'it publishes log2025-1\'s checkpoint key');
check(!ReleaseLogClient::rootListsKey($root, $origin, sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
	'a key Sigstore does not publish is not found');
check(!ReleaseLogClient::rootListsKey($root, 'log.example.com', $log_key), 'nor is the right key under another log');

harness_finish();
