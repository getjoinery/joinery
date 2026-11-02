<?php
/**
 * A public log built in the test: the stand-in for a Rekor v2 shard that the
 * release tests write statements and commit maps to.
 *
 *   require_once(__DIR__ . '/../lib/release_log_fixtures.php');
 *   $log = fake_release_log('log2025-1.rekor.sigstore.dev');
 *   // $log: origin, der (the checkpoint key, Ed25519 SPKI), client (a ReleaseLogClient whose
 *   //       transport answers a submission with a leaf, an inclusion proof and a signed
 *   //       checkpoint), shard (as ReleaseLogClient::discover() returns one)
 *   $logged = $log['client']->submitEntry($log['shard'], $envelope, $statement_der);
 *
 * Each submission is placed at index 1 of a three-leaf tree.
 */

if (!function_exists('fake_release_log')) {
/** A log built here: origin, key pair, DER, and a transport that answers a submission with a proof. */
function fake_release_log($origin) {
	$pair = sodium_crypto_sign_keypair();
	$der = TransparencyProof::ED25519_SPKI_PREFIX . sodium_crypto_sign_publickey($pair);
	$mth = function (array $leaves) use (&$mth) {
		$n = count($leaves);
		if ($n === 1) { return TransparencyProof::leafHash($leaves[0]); }
		$k = 1; while ($k * 2 < $n) { $k *= 2; }
		return TransparencyProof::nodeHash($mth(array_slice($leaves, 0, $k)), $mth(array_slice($leaves, $k)));
	};
	$path = function ($m, array $leaves) use (&$path, $mth) {
		$n = count($leaves);
		if ($n === 1) { return array(); }
		$k = 1; while ($k * 2 < $n) { $k *= 2; }
		if ($m < $k) { return array_merge($path($m, array_slice($leaves, 0, $k)), array($mth(array_slice($leaves, $k)))); }
		return array_merge($path($m - $k, array_slice($leaves, $k)), array($mth(array_slice($leaves, 0, $k))));
	};
	$transport = function ($method, $url, $body) use ($pair, $origin, $mth, $path) {
		$hr = json_decode($body, true)['hashedRekordRequestV002'];
		$leaf = TransparencyProof::canonicalJson(array('apiVersion' => '0.0.2', 'kind' => 'hashedrekord', 'spec' => array('hashedRekordV002' => array(
			'data' => array('algorithm' => 'SHA2_256', 'digest' => $hr['digest']),
			'signature' => array('content' => $hr['signature']['content'], 'verifier' => $hr['signature']['verifier'])))));
		$leaves = array('a', $leaf, 'c');
		$root = $mth($leaves);
		$note_body = "{$origin}\n3\n" . base64_encode($root) . "\n";
		$kh = substr(hash('sha256', $origin . "\n\x01" . sodium_crypto_sign_publickey($pair), true), 0, 4);
		$note = $note_body . "\n\u{2014} {$origin} " . base64_encode($kh . sodium_crypto_sign_detached($note_body, sodium_crypto_sign_secretkey($pair))) . "\n";
		return array('status' => 201, 'body' => json_encode(array('logIndex' => '1', 'kindVersion' => array('kind' => 'hashedrekord', 'version' => '0.0.2'),
			'inclusionProof' => array('logIndex' => '1', 'rootHash' => base64_encode($root), 'treeSize' => '3',
				'hashes' => array_map('base64_encode', $path(1, $leaves)), 'checkpoint' => array('envelope' => $note)),
			'canonicalizedBody' => base64_encode($leaf))));
	};
	$client = new ReleaseLogClient(array($origin => $der), null, $transport);
	$shard = array('url' => 'https://' . $origin, 'origin' => $origin, 'key' => $der, 'ahead' => array(), 'waiting' => array(), 'genesis' => true);
	return array('origin' => $origin, 'der' => $der, 'client' => $client, 'shard' => $shard);
}
}
