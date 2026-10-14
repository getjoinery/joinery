<?php
/** @joinery-test
 * name: site_engine_publisher
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * The site container engine's publisher (specs/site_container_engine.md D1,
 * D2): builds only when versions.env or build.sh changed, publishes the archive
 * beside the release, and records it in site_engine/ENGINE, which the signed
 * manifest covers. A stand-in builder writes a small archive and its .sha256
 * into the build cache, as build_root.sh build does; the real build is a box
 * check (about ten minutes in the 26.04 build root).
 *
 * Run: php tests/run.php --only=tests/unit/site_engine_publisher_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$work = harness_scratch_dir('site_engine_publisher') . '/run-' . getmypid();
harness_defer(function () use ($work) { exec('rm -rf ' . escapeshellarg($work)); });
$site = $work . '/site';
$cache = $work . '/cache';
$src = $site . '/' . SiteEnginePublisher::SOURCE_SUBDIR;
@mkdir($src, 0755, true);
file_put_contents($src . '/versions.env', "PODMAN_TAG=\"v6.1.4\"\n");
file_put_contents($src . '/build.sh', "#!/bin/bash\n");

$calls = 0;
$version = '6.1.4';
$mode = 'good';
$builder = function ($dir) use (&$calls, &$version, &$mode) {
	$calls++;
	if ($mode === 'throws') { throw new Exception('the build exited 1'); }
	if ($mode === 'nothing') { return; }
	$name = 'joinery-site-engine-' . $version . '-amd64.tar.xz';
	file_put_contents($dir . '/' . $name, 'engine ' . $version . ' ' . microtime(true));
	$sha = hash_file('sha256', $dir . '/' . $name);
	if ($mode === 'lying') { $sha = str_repeat('0', 64); }
	file_put_contents($dir . '/' . $name . '.sha256', $sha . '  ' . $name . "\n");
};
$opts = array('builder' => $builder, 'cache_dir' => $cache);
$published = function ($name) use ($site) { return $site . '/' . SiteEnginePublisher::STATIC_SUBDIR . '/' . $name; };

section('Nothing to build without the engine\'s source');

$r = SiteEnginePublisher::publish($work . '/elsewhere', null, $opts);
check($r['status'] === SiteEnginePublisher::STATUS_ABSENT && $calls === 0, 'no site_engine/ in the tree: absent, nothing built', $r['message']);

section('The first publish builds, publishes and records');

$r = SiteEnginePublisher::publish($site, null, $opts);
$name = 'joinery-site-engine-6.1.4-amd64.tar.xz';
$stamp = SiteEnginePublisher::readStamp($src);
check($r['status'] === SiteEnginePublisher::STATUS_BUILT && $calls === 1, 'no ENGINE yet: built once', $r['message']);
check(is_file($published($name)) && hash_file('sha256', $published($name)) === $r['sha256'], 'the archive is published under static_files/site_engine with the hash reported');
check(is_file($published($name) . '.sha256'), 'its .sha256 is published beside it');
check($stamp !== null && $stamp['archive'] === $name && $stamp['sha256'] === $r['sha256']
	&& $stamp['source_sha256'] === SiteEnginePublisher::sourceHash($src), 'ENGINE names the archive, its hash and the source it was built from', json_encode($stamp));
check(substr(sprintf('%o', fileperms($src . '/ENGINE')), -3) === '644', 'ENGINE is 644, like every shipped file');

section('Unchanged source: nothing moves');

$engine_bytes = file_get_contents($src . '/ENGINE');
$r = SiteEnginePublisher::publish($site, null, $opts);
check($r['status'] === SiteEnginePublisher::STATUS_SKIPPED && $calls === 1, 'published and current: skipped, no build', $r['message']);
check(file_get_contents($src . '/ENGINE') === $engine_bytes, 'ENGINE is byte-identical (a needless change would move the tree hash)');
unlink($published($name));
$r = SiteEnginePublisher::publish($site, null, $opts);
check($r['status'] === SiteEnginePublisher::STATUS_RESTORED && $calls === 1 && is_file($published($name)),
	'the published copy gone, the cache holding it: copied back, no build', $r['message']);
check(file_get_contents($src . '/ENGINE') === $engine_bytes, '  and ENGINE is unchanged');
unlink($published($name));
unlink($cache . '/' . $name);
$r = SiteEnginePublisher::publish($site, null, $opts);
check($r['status'] === SiteEnginePublisher::STATUS_BUILT && $calls === 2, 'gone from both: built again', $r['message']);

section('A changed source builds a new engine');

file_put_contents($src . '/versions.env', "PODMAN_TAG=\"v6.1.5\"\n");
$version = '6.1.5';
$r = SiteEnginePublisher::publish($site, null, $opts);
$stamp = SiteEnginePublisher::readStamp($src);
check($r['status'] === SiteEnginePublisher::STATUS_BUILT && $calls === 3 && $stamp['archive'] === 'joinery-site-engine-6.1.5-amd64.tar.xz',
	'versions.env changed: built, and ENGINE names the new archive', $r['message']);
file_put_contents($src . '/build.sh', "#!/bin/bash\n# changed\n");
$r = SiteEnginePublisher::publish($site, null, $opts);
check($r['status'] === SiteEnginePublisher::STATUS_BUILT && $calls === 4, 'build.sh changed: built (the same version rebuilt over its old archive)', $r['message']);
check(SiteEnginePublisher::readStamp($src)['sha256'] === hash_file('sha256', $published('joinery-site-engine-6.1.5-amd64.tar.xz')),
	'  ENGINE has the rebuilt archive\'s hash, not the old one\'s');

section('A build owed and not done fails, and changes nothing');

file_put_contents($src . '/versions.env', "PODMAN_TAG=\"v6.1.6\"\n");
$version = '6.1.6';
$engine_bytes = file_get_contents($src . '/ENGINE');
foreach (array('throws' => 'the build fails', 'nothing' => 'the build leaves no archive', 'lying' => 'the archive is not what its .sha256 says') as $m => $label) {
	$mode = $m;
	$r = SiteEnginePublisher::publish($site, null, $opts);
	check($r['status'] === SiteEnginePublisher::STATUS_FAILED, $label . ': failed', $r['message']);
	check(file_get_contents($src . '/ENGINE') === $engine_bytes, '  ENGINE still names the engine before');
}
check(!is_file($published('joinery-site-engine-6.1.6-amd64.tar.xz')), 'nothing that failed was published');
$mode = 'good';
file_put_contents($src . '/ENGINE', "archive=../../etc/passwd\nsha256=x\n");
$r = SiteEnginePublisher::publish($site, null, $opts);
check($r['status'] === SiteEnginePublisher::STATUS_BUILT && SiteEnginePublisher::readStamp($src)['archive'] === 'joinery-site-engine-6.1.6-amd64.tar.xz',
	'an ENGINE that is not whole counts as none: built and rewritten', $r['message']);

section('A republishing site serves the received engine, never builds');

$relay = $work . '/relay';
$rsrc = $relay . '/' . SiteEnginePublisher::SOURCE_SUBDIR;
@mkdir($rsrc, 0755, true);
copy($src . '/versions.env', $rsrc . '/versions.env');
copy($src . '/build.sh', $rsrc . '/build.sh');
$fetched = array();
$serve = $published('joinery-site-engine-6.1.6-amd64.tar.xz');   // what the origin serves
$fetch_ok = function ($url, $dest) use (&$fetched, $serve) { $fetched[] = $url; copy($serve, $dest); };
$ropts = array('republish' => true, 'builder' => $builder, 'cache_dir' => $cache, 'upstream' => 'https://origin.example', 'fetcher' => $fetch_ok);
$calls_before = $calls;
$r = SiteEnginePublisher::publish($relay, null, $ropts);
check($r['status'] === SiteEnginePublisher::STATUS_ABSENT && $fetched === array(), 'a received release with no ENGINE: nothing to serve', $r['message']);
copy($src . '/ENGINE', $rsrc . '/ENGINE');
$rname = SiteEnginePublisher::readStamp($rsrc)['archive'];
$r = SiteEnginePublisher::publish($relay, null, $ropts);
$rserved = $relay . '/' . SiteEnginePublisher::STATIC_SUBDIR . '/' . $rname;
check($r['status'] === SiteEnginePublisher::STATUS_FETCHED && hash_file('sha256', $rserved) === SiteEnginePublisher::readStamp($rsrc)['sha256'],
	'the archive ENGINE names is fetched and served', $r['message']);
check($fetched === array('https://origin.example/static_files/site_engine/' . $rname), '  from upstream\'s static_files/site_engine', json_encode($fetched));
check(is_file($rserved . '.sha256'), '  with its .sha256 beside it');
check(file_get_contents($rsrc . '/ENGINE') === file_get_contents($src . '/ENGINE'), '  and the received ENGINE is untouched');
$r = SiteEnginePublisher::publish($relay, null, $ropts);
check($r['status'] === SiteEnginePublisher::STATUS_SKIPPED && count($fetched) === 1, 'served already: unchanged, nothing fetched', $r['message']);
unlink($rserved);
$r = SiteEnginePublisher::publish($relay, null, array_merge($ropts, array('fetcher' => function ($url, $dest) { file_put_contents($dest, 'not the engine'); })));
check($r['status'] === SiteEnginePublisher::STATUS_FAILED && !is_file($rserved), 'fetched bytes that are not ENGINE\'s hash: refused, nothing served', $r['message']);
check(glob($relay . '/' . SiteEnginePublisher::STATIC_SUBDIR . '/.*.fetch') === array(), '  and the download is removed');
$r = SiteEnginePublisher::publish($relay, null, array_merge($ropts, array('upstream' => '')));
check($r['status'] === SiteEnginePublisher::STATUS_FAILED, 'no upstream to fetch from: failed', $r['message']);
check($calls === $calls_before, 'a republishing site never builds');

section('The publish calls it, in both modes, and refuses on a failure');

$publish_src = file_get_contents(PathHelper::getIncludePath('plugins/server_manager/includes/publish_upgrade.php'));
check(strpos($publish_src, "SiteEnginePublisher::publish(\$full_site_dir, 'publish_output', array('republish' => \$republish))") !== false,
	'publish_upgrade.php calls the publisher on every publish, telling it whether this site republishes');
check(preg_match('/\$site_engine\[\'status\'\] === SiteEnginePublisher::STATUS_FAILED\) \{.*?exit\(1\);/s', $publish_src) === 1,
	'  and refuses the release when it fails');
check(strpos($publish_src, 'SiteEnginePublisher::publish(') < strpos($publish_src, 'publish_require_committed(publish_release_repos($full_site_dir), $version, true'),
	'  before publish commits its own writes, so ENGINE rides the release commit');

section('The real build runs in the owner-made root');

$cls = file_get_contents(PathHelper::getIncludePath('includes/SiteEnginePublisher.php'));
check(strpos($cls, "'bash ' . escapeshellarg(\$script) . ' check 2>&1'") !== false
	&& strpos($cls, "' build ' . escapeshellarg(\$cache_dir)") !== false, 'the default builder checks the build root, then builds in it');

harness_finish();
