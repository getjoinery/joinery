<?php
/** @joinery-test
 * name: verify_release
 * tier: safe
 * env: dev-only
 * needs: []
 * timeout: 600
 */
/**
 * utils/verify_release.php's checks (ReleaseVerifier, spec release_transparency
 * D7), on the newest release this publishing box has logged, offline: the
 * commits are read from the local clones and Sigstore is not asked, so the
 * test needs no network. The binaries are not rebuilt here; the live run does
 * that (php utils/verify_release.php <version> --source=...).
 *
 *  - the release as published verifies, every check passing
 *  - a core archive with one byte changed is refused
 *  - a statement whose log entry is moved is refused
 *  - a version the statement does not name is refused
 *  - a core commit main no longer holds verifies through a commit map naming
 *    an equal-tree commit, and is refused with no map, a map for another
 *    version, or a map signed by a key the release does not trust
 *
 * Run: php tests/run.php safe --filter=verify_release
 *
 * @version 1.2 - a release built before the rewrite: its commit is read through the commit map
 * @version 1.1 - a core commit the public history rewrote (specs_private_repo D6)
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$static = PathHelper::getSiteRoot() . '/static_files';
$archives = glob($static . '/joinery-core-*.tar.gz') ?: array();
usort($archives, function ($a, $b) {
	return version_compare(basename($a, '.tar.gz'), basename($b, '.tar.gz'));
});
$archive = end($archives);
if (!$archive) {
	check(false, 'this box has published a release to verify', 'no core archive in ' . $static);
	harness_finish();
}
$version = substr(basename($archive, '.tar.gz'), strlen('joinery-core-'));
// A directory of this run's own: the host converger re-owns cache/ to the
// web user within a minute, and git will not read a repository another user
// owns, so a stand-in left by an earlier run is unusable.
$tmp = harness_scratch_dir('verify_release') . '/run-' . getmypid();
mkdir($tmp);
harness_defer(function () use ($tmp) { exec('rm -rf ' . escapeshellarg($tmp)); });

/** Run the verifier offline from the local clones: [verified, results]. */
function vr_run(array $opts) {
	$v = new ReleaseVerifier($opts + array(
		'core_repo'  => PathHelper::getSiteRoot(),
		'agent_repo' => AgentDistPublisher::DEFAULT_SOURCE_PATH,
		'offline'    => true,
		'rebuild'    => false,
	), function ($line) {});
	return array($v->run(), $v->results());
}
function vr_failures(array $results) {
	return array_values(array_map(function ($r) { return $r['what']; },
		array_filter($results, function ($r) { return $r['status'] === 'fail'; })));
}

section("Release {$version}, as published");
ReleaseVerifier::requirePluginClasses();
list($ok, $results) = vr_run(array('version' => $version, 'core_archive' => $archive));
check($ok, "release {$version} verifies", implode('; ', vr_failures($results)));
$passed = array_filter($results, function ($r) { return $r['status'] === 'ok'; });
check(count($passed) >= 10, 'every check that ran passed', count($passed) . ' passed');
foreach (array('signed by a statement key the commit lists', 'the manifest the statement records',
		'Every line of the core manifest derives from the commits') as $phrase) {
	$found = false;
	foreach ($passed as $r) { if (strpos($r['what'], $phrase) !== false) { $found = true; } }
	check($found, "the run includes: {$phrase}");
}

section('A core archive with one byte changed');
$unpacked = $tmp . '/unpacked';
mkdir($unpacked);
exec('tar -xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($unpacked));
file_put_contents($unpacked . '/public_html/serve.php', "\n", FILE_APPEND);
$doctored = $tmp . '/doctored.tar.gz';
exec('tar -czf ' . escapeshellarg($doctored) . ' -C ' . escapeshellarg($unpacked) . ' .');
list($ok, $results) = vr_run(array('version' => $version, 'core_archive' => $doctored));
$fails = vr_failures($results);
check(!$ok && count(preg_grep('/core archive does not verify.*serve\.php/', $fails)) === 1, 'it is refused, naming the file', implode('; ', $fails));

section('A statement whose log entry is moved');
$statement = json_decode(file_get_contents($unpacked . '/public_html/RELEASE_STATEMENT'), true);
$statement['entry']['log_index']++;
file_put_contents($tmp . '/moved_statement', json_encode($statement));
list($ok, $results) = vr_run(array('version' => $version, 'core_archive' => $archive, 'statement' => $tmp . '/moved_statement'));
check(!$ok && vr_failures($results) !== array(), 'it is refused', implode('; ', vr_failures($results)));

section('A commit that is not on the public main branch');

// GitHub serves any commit in a fork network by hash through the upstream
// URL, so being fetchable proves nothing; the commit must be an ancestor of
// main. Local stand-ins for the public repositories share the clones'
// objects; one has main at the release's commit, one a commit short of it.
$statement_payload = json_decode(base64_decode($statement['envelope']['payload']), true);
// A release built before the rewrite names a commit this clone no longer
// holds; the commit standing for it is the one the installed map names, and
// the main that holds it is this clone's own, which carries the map.
$c = $statement_payload['core_commit'];
$site_git = 'git -C ' . escapeshellarg(PathHelper::getSiteRoot());
exec($site_git . ' cat-file -e ' . escapeshellarg($c . '^{commit}') . ' 2>/dev/null', $o_c, $c_missing);
$c_here = $c_missing ? ReleaseHistoryMap::installed()->current($c) : $c;
$core_main = $c_missing ? trim((string)shell_exec($site_git . ' rev-parse HEAD')) : $c;
$stand_in = function ($label, $source, $main) use ($tmp) {
	$bare = $tmp . '/public_' . $label . '.git';
	exec('git clone -q --bare --shared ' . escapeshellarg($source) . ' ' . escapeshellarg($bare) . ' 2>&1');
	exec('git -C ' . escapeshellarg($bare) . ' config uploadpack.allowFilter true');
	exec('git -C ' . escapeshellarg($bare) . ' update-ref refs/heads/main ' . escapeshellarg($main));
	return 'file://' . $bare;
};
$core_on = $stand_in('core_on', PathHelper::getSiteRoot(), $core_main);
$agent_on = $stand_in('agent_on', AgentDistPublisher::DEFAULT_SOURCE_PATH, $statement_payload['agent_commit']);
$core_off = $stand_in('core_off', PathHelper::getSiteRoot(), $c_here . '~1');
$anchored = array('version' => $version, 'core_archive' => $archive, 'offline' => false, 'sigstore' => false,
	'core_url' => $core_on, 'agent_url' => $agent_on);

list($ok, $results) = vr_run($anchored);
$on_main = array_filter($results, function ($r) { return $r['status'] === 'ok' && strpos($r['what'], 'is on the main branch of') !== false; });
check($ok && count($on_main) === 2, 'both commits on main verify', implode('; ', vr_failures($results)));

list($ok, $results) = vr_run(array('core_url' => $core_off) + $anchored);
$fails = vr_failures($results);
check(!$ok && count(preg_grep('/core commit .* is not on the main branch/', $fails)) === 1,
	'a core commit main does not contain is refused, though the repository holds it', implode('; ', $fails));

section('A core commit the public history rewrote (specs_private_repo D6)');

// A stand-in whose main holds an equal-tree copy of the release's commit (as
// a rewrite that removed only unshipped files leaves it), and, on top, a
// commit map naming it. The map is signed by a key and log built here, which
// map_keys hands the verifier in place of the release's own.
require_once(__DIR__ . '/../lib/release_log_fixtures.php');
$rewritten = function ($label, ?string $map_bytes) use ($tmp, $c_here) {
	$bare = $tmp . '/public_' . $label . '.git';
	exec('git clone -q --bare --shared ' . escapeshellarg(PathHelper::getSiteRoot()) . ' ' . escapeshellarg($bare) . ' 2>&1');
	$g = 'git -C ' . escapeshellarg($bare);
	exec($g . ' config uploadpack.allowFilter true');
	$y = trim((string)shell_exec($g . ' commit-tree ' . escapeshellarg($c_here . '^{tree}') . ' -m rewritten 2>/dev/null'));
	$tip = $y;
	if ($map_bytes !== null) {
		$blob_file = $tmp . '/' . $label . '_map.json';
		file_put_contents($blob_file, $map_bytes);
		$env = 'GIT_INDEX_FILE=' . escapeshellarg($tmp . '/' . $label . '.index') . ' ';
		$blob = trim((string)shell_exec($g . ' hash-object -w ' . escapeshellarg($blob_file)));
		exec($env . $g . ' read-tree ' . escapeshellarg($y));
		exec($env . $g . ' update-index --add --cacheinfo ' . escapeshellarg('100644,' . $blob . ',' . ReleaseHistoryMap::PATH));
		$tree = trim((string)shell_exec($env . $g . ' write-tree'));
		$tip = trim((string)shell_exec($g . ' commit-tree ' . escapeshellarg($tree) . ' -p ' . escapeshellarg($y) . ' -m map 2>/dev/null'));
	}
	exec($g . ' update-ref refs/heads/main ' . escapeshellarg($tip));
	return array('url' => 'file://' . $bare, 'new' => $y);
};
$map_log = fake_release_log('log2025-1.rekor.sigstore.dev');
$sk = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
openssl_pkey_export($sk, $map_pem);
$map_keys = array('statement' => array(ReleaseLogClient::publicDer($map_pem)), 'log' => array($map_log['origin'] => $map_log['der']));
// The new commit is the commit-tree of C's tree, the same in every stand-in
// under fixed dates: read it from one with no map, then sign maps naming it.
$map_for = function ($map_version, $new) use ($map_log, $map_pem, $c) {
	$env = ReleaseLogClient::signEnvelope(ReleaseHistoryMap::payload(array(array('version' => $map_version, 'old' => $c, 'new' => $new)), 'test'),
		$map_pem, TransparencyProof::COMMIT_MAP_TYPE);
	return ReleaseHistoryMap::append('', $env, $map_log['client']->submitEntry($map_log['shard'], $env, ReleaseLogClient::publicDer($map_pem)));
};
putenv('GIT_COMMITTER_DATE=2026-11-01T00:00:00Z'); putenv('GIT_AUTHOR_DATE=2026-11-01T00:00:00Z');
$probe = $rewritten('probe', null);
$mapped = $rewritten('mapped', $map_for($version, $probe['new']));
$wrong_version = $rewritten('wrong_version', $map_for('0.0.1', $probe['new']));
putenv('GIT_COMMITTER_DATE'); putenv('GIT_AUTHOR_DATE');
check($mapped['new'] === $probe['new'], 'the stand-in\'s rewritten commit is the one the map names');

$remapped = array('core_url' => $mapped['url'], 'core_repo' => null, 'map_keys' => $map_keys) + $anchored;
list($ok, $results) = vr_run($remapped);
$line = array_filter($results, function ($r) { return $r['status'] === 'ok' && strpos($r['what'], 'was rewritten to') !== false; });
check($ok && count($line) === 1, 'a release whose commit was rewritten verifies through the map, and says so', implode('; ', vr_failures($results)));

list($ok, $results) = vr_run(array('core_url' => $probe['url'], 'map_keys' => $map_keys) + $anchored);
$fails = vr_failures($results);
check(!$ok && count(preg_grep('/carries no commit map/', $fails)) === 1, 'with no map on main, it is refused', implode('; ', $fails));

list($ok, $results) = vr_run(array('core_url' => $wrong_version['url'], 'map_keys' => $map_keys) + $anchored);
$fails = vr_failures($results);
check(!$ok && count(preg_grep('/does not list it for release/', $fails)) === 1, 'a map naming another version is refused', implode('; ', $fails));

list($ok, $results) = vr_run(array('core_url' => $mapped['url']) + $anchored);
$fails = vr_failures($results);
check(!$ok && count(preg_grep('/not proven by a key that release trusts/', $fails)) === 1,
	'a map signed by a key the release does not trust is refused', implode('; ', $fails));

section('A version the statement does not name');
list($ok, $results) = vr_run(array('version' => '0.0.1', 'core_archive' => $archive));
$fails = vr_failures($results);
check(!$ok && count(preg_grep('/not 0\.0\.1/', $fails)) === 1, 'it is refused', implode('; ', $fails));

harness_finish();
