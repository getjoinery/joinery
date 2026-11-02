<?php
/** @joinery-test
 * name: release_history_map
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The commit map after a public history rewrite (ReleaseHistoryMap, spec
 * specs_private_repo D3-D5), against a log built here:
 *
 *  - current() follows two chained rewrites; a commit no rewrite lists maps
 *    to itself
 *  - verifiedFor() follows the same chain only through rewrites the release
 *    trusts, only for the version named; a map signed by a key the release
 *    does not trust, an unlogged map, and a tampered map are refused
 *  - a map file that is there but broken throws; none at all is none()
 *  - ReleaseProvenance::commit_url() links a core commit through the map, and
 *    never an agent commit
 *
 * Offline, no DB. Run: php tests/run.php safe --filter=release_history_map
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/release_log_fixtures.php');
harness_boot();

function rhm_refusal(callable $fn) {
	try { $fn(); } catch (Exception $e) { return $e->getMessage(); }
	return null;
}
function rhm_key() {
	$sk = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
	openssl_pkey_export($sk, $pem);
	return array('pem' => $pem, 'der' => ReleaseLogClient::publicDer($pem));
}
/** One logged rewrite: {envelope, entry}. */
function rhm_rewrite($log, $key, array $commits) {
	$env = ReleaseLogClient::signEnvelope(ReleaseHistoryMap::payload($commits, 'test rewrite', 1790000000), $key['pem'], TransparencyProof::COMMIT_MAP_TYPE);
	return array('envelope' => $env, 'entry' => $log['client']->submitEntry($log['shard'], $env, $key['der']));
}

$log = fake_release_log('log2025-1.rekor.sigstore.dev');
$log_keys = array($log['origin'] => $log['der']);
$trusted = rhm_key();
$stranger = rhm_key();

$a = str_repeat('a', 40); $b = str_repeat('b', 40); $c = str_repeat('c', 40);
$x = str_repeat('1', 40); $y = str_repeat('2', 40);

$first = rhm_rewrite($log, $trusted, array(array('version' => '0.8.466', 'old' => $a, 'new' => $b), array('version' => '0.8.467', 'old' => $x, 'new' => $y)));
$second = rhm_rewrite($log, $trusted, array(array('version' => '0.8.466', 'old' => $b, 'new' => $c)));
$bytes = ReleaseHistoryMap::append(ReleaseHistoryMap::append('', $first['envelope'], $first['entry']), $second['envelope'], $second['entry']);
$map = ReleaseHistoryMap::parse($bytes);

// ---------------------------------------------------------------------------
section('current(): the links and the drafter');

check($map->count() === 2, 'append() keeps both rewrites, in order');
check($map->current($a) === $c, 'a commit rewritten twice maps to the newest', $map->current($a));
check($map->current($x) === $y, 'a commit rewritten once maps to its new commit');
check($map->current(str_repeat('f', 40)) === str_repeat('f', 40), 'a commit no rewrite lists maps to itself');
check(ReleaseHistoryMap::none()->current($a) === $a, 'with no map, every commit stands for itself');

// ---------------------------------------------------------------------------
section('verifiedFor(): the release verifier');

$found = $map->verifiedFor('0.8.466', $a, array($trusted['der']), $log_keys);
check($found !== null && $found['commit'] === $c && count($found['hops']) === 2, 'the chain is followed through both trusted rewrites',
	json_encode($found));
check($found !== null && $found['hops'][0]['log_index'] === 1 && $found['hops'][0]['log_origin'] === $log['origin']
	&& $found['hops'][0]['rewritten_at'] === '2026-09-21T14:13:20Z', 'each hop says where it is logged and when');
check($map->verifiedFor('0.8.467', $a, array($trusted['der']), $log_keys) === null,
	'a rewrite lists a commit only for its own version');
check($map->verifiedFor('0.8.466', str_repeat('f', 40), array($trusted['der']), $log_keys) === null, 'an unlisted commit is not found');

$why = rhm_refusal(function () use ($map, $a, $stranger, $log_keys) { $map->verifiedFor('0.8.466', $a, array($stranger['der']), $log_keys); });
check($why !== null && strpos($why, 'not proven by a key that release trusts') !== false,
	'a release that does not trust the map\'s signer refuses it (D4)', (string)$why);

$other_log = fake_release_log('log2026-1.rekor.sigstore.dev');
$why = rhm_refusal(function () use ($map, $a, $trusted, $other_log) {
	$map->verifiedFor('0.8.466', $a, array($trusted['der']), array($other_log['origin'] => $other_log['der']));
});
check($why !== null && strpos($why, 'holds no key') !== false, 'a map logged on a log the release does not list is refused', (string)$why);

// Signed by the trusted key, but never logged: the entry is another map's.
$unlogged_env = ReleaseLogClient::signEnvelope(ReleaseHistoryMap::payload(array(array('version' => '0.8.466', 'old' => $a, 'new' => $y)), 'unlogged'),
	$trusted['pem'], TransparencyProof::COMMIT_MAP_TYPE);
$unlogged = ReleaseHistoryMap::parse(ReleaseHistoryMap::append('', $unlogged_env, $first['entry']));
$why = rhm_refusal(function () use ($unlogged, $a, $trusted, $log_keys) { $unlogged->verifiedFor('0.8.466', $a, array($trusted['der']), $log_keys); });
check($why !== null && strpos($why, 'records a different statement') !== false, 'a map with no log entry of its own is refused', (string)$why);

// Tampered: the payload's new commit changed after signing.
$doc = json_decode($bytes, true);
$payload = json_decode(base64_decode($doc['rewrites'][0]['envelope']['payload']), true);
$payload['commits'][0]['new'] = $y;
$doc['rewrites'][0]['envelope']['payload'] = base64_encode(json_encode($payload));
$tampered = ReleaseHistoryMap::parse(json_encode($doc));
$why = rhm_refusal(function () use ($tampered, $a, $trusted, $log_keys) { $tampered->verifiedFor('0.8.466', $a, array($trusted['der']), $log_keys); });
check($why !== null && strpos($why, 'not signed by a statement key') !== false, 'a map changed after signing is refused', (string)$why);

// A rewrite of some other repository is never followed.
$elsewhere = json_decode($bytes, true);
$p2 = json_decode(base64_decode($elsewhere['rewrites'][0]['envelope']['payload']), true);
$p2['repository'] = 'https://github.com/someone/else';
$elsewhere['rewrites'][0]['envelope']['payload'] = base64_encode(json_encode($p2));
check(ReleaseHistoryMap::parse(json_encode($elsewhere))->current($x) === $x, 'a rewrite of another repository is not followed');

// ---------------------------------------------------------------------------
section('The file');

$tmp = harness_scratch_dir('release_history_map') . '/run-' . getmypid();
mkdir($tmp . '/' . dirname(ReleaseHistoryMap::PATH), 0755, true);
harness_defer(function () use ($tmp) { exec('rm -rf ' . escapeshellarg($tmp)); });
check(ReleaseHistoryMap::installed($tmp . '/nowhere')->count() === 0, 'a site with no map has none');
file_put_contents($tmp . '/' . ReleaseHistoryMap::PATH, $bytes);
check(ReleaseHistoryMap::installed($tmp)->current($a) === $c, 'installed() reads the map at its path under the site root');
file_put_contents($tmp . '/' . ReleaseHistoryMap::PATH, '{"format":"joinery-commit-map-1"}');
check(rhm_refusal(function () use ($tmp) { ReleaseHistoryMap::installed($tmp); }) !== null, 'a map that is there but broken throws');
check(rhm_refusal(function () use ($first) {
	ReleaseHistoryMap::parse(ReleaseHistoryMap::append('', array('payloadType' => TransparencyProof::PAYLOAD_TYPE) + $first['envelope'], $first['entry']));
}) !== null, 'a release statement envelope is not a rewrite');
check(rhm_refusal(function () { ReleaseHistoryMap::payload(array(array('version' => '0.8.466', 'old' => 'main', 'new' => str_repeat('b', 40))), 'x'); }) !== null,
	'a map names commits by full hash');

// ---------------------------------------------------------------------------
section('The Updates and Releases page links');

check(ReleaseProvenance::commit_url(ReleaseProvenance::CORE_REPO, $a, $map) === ReleaseProvenance::CORE_REPO . '/commit/' . $c,
	'an old core commit links to the commit that stands for it');
check(ReleaseProvenance::commit_url(ReleaseProvenance::AGENT_REPO, $a, $map) === ReleaseProvenance::AGENT_REPO . '/commit/' . $a,
	'an agent commit is never mapped: the agent repository was not rewritten');
check(ReleaseProvenance::commit_url(ReleaseProvenance::CORE_REPO, 'main', $map) === '', 'a branch name still gets no link');

harness_finish();
