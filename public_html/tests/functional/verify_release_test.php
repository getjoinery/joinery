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
 *
 * Run: php tests/run.php safe --filter=verify_release
 *
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
$stand_in = function ($label, $source, $main) use ($tmp) {
	$bare = $tmp . '/public_' . $label . '.git';
	exec('git clone -q --bare --shared ' . escapeshellarg($source) . ' ' . escapeshellarg($bare) . ' 2>&1');
	exec('git -C ' . escapeshellarg($bare) . ' config uploadpack.allowFilter true');
	exec('git -C ' . escapeshellarg($bare) . ' update-ref refs/heads/main ' . escapeshellarg($main));
	return 'file://' . $bare;
};
$core_on = $stand_in('core_on', PathHelper::getSiteRoot(), $statement_payload['core_commit']);
$agent_on = $stand_in('agent_on', AgentDistPublisher::DEFAULT_SOURCE_PATH, $statement_payload['agent_commit']);
$core_off = $stand_in('core_off', PathHelper::getSiteRoot(), $statement_payload['core_commit'] . '~1');
$anchored = array('version' => $version, 'core_archive' => $archive, 'offline' => false, 'sigstore' => false,
	'core_url' => $core_on, 'agent_url' => $agent_on);

list($ok, $results) = vr_run($anchored);
$on_main = array_filter($results, function ($r) { return $r['status'] === 'ok' && strpos($r['what'], 'is on the main branch of') !== false; });
check($ok && count($on_main) === 2, 'both commits on main verify', implode('; ', vr_failures($results)));

list($ok, $results) = vr_run(array('core_url' => $core_off) + $anchored);
$fails = vr_failures($results);
check(!$ok && count(preg_grep('/core commit .* is not on the main branch/', $fails)) === 1,
	'a core commit main does not contain is refused, though the repository holds it', implode('; ', $fails));

section('A version the statement does not name');
list($ok, $results) = vr_run(array('version' => '0.0.1', 'core_archive' => $archive));
$fails = vr_failures($results);
check(!$ok && count(preg_grep('/not 0\.0\.1/', $fails)) === 1, 'it is refused', implode('; ', $fails));

harness_finish();
