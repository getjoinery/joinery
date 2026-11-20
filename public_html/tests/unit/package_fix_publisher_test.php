<?php
/** @joinery-test
 * name: package_fix_publisher
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * Package fixes ahead of Ubuntu (specs/package_fixes_ahead_of_ubuntu.md WP2):
 * publish builds each active recipe whose files changed, publishes its archive
 * beside the release, and records it in fixes/ACTIVE, which the signed manifest
 * covers. A stand-in builder writes a small archive and its .sha256, as
 * build_root.sh build does, and a stand-in tracker answers for Ubuntu; the
 * real build is fixes/build.sh's gate (package_fix_build) and a box check.
 *
 * Run: php tests/run.php --only=tests/unit/package_fix_publisher_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$work = harness_scratch_dir('package_fix_publisher') . '/run-' . getmypid();
harness_defer(function () use ($work) { exec('rm -rf ' . escapeshellarg($work)); });
$site = $work . '/site';
$cache = $work . '/cache';
$src = $site . '/' . PackageFixPublisher::SOURCE_SUBDIR;
@mkdir($src, 0755, true);
file_put_contents($src . '/build.sh', "#!/bin/bash\n");

$recipe = function ($source, $state, $base = '', $rev = 1, $cves = 'CVE-2026-129289', $from = '') use ($src) {
	@mkdir($src . '/' . $source . '/patches', 0755, true);
	$text = "state={$state}\n";
	if ($state === 'active') {
		$text .= "ubuntu_source={$source} {$base}\ndsc_sha256=" . str_repeat('a', 64) . "\nrevision={$rev}\ncves={$cves}\n"
			. 'changelog_from=' . ($from !== '' ? $from : $source) . "\ndate=2026-11-20\ntest=default\n";
		file_put_contents($src . '/' . $source . '/patches/0001-' . explode(' ', $cves)[0] . '.patch', "Origin: x\n--- a/x\n+++ b/x\n");
	}
	file_put_contents($src . '/' . $source . '/RECIPE', $text);
};

$built = array();
$mode = 'good';
$builder = function ($recipe_dir, $dir) use (&$built, &$mode) {
	$source = basename($recipe_dir);
	$built[] = $source;
	if ($mode === 'throws') { throw new Exception('the build exited 1'); }
	if ($mode === 'nothing') { return; }
	$v = array();
	foreach (file($recipe_dir . '/RECIPE', FILE_IGNORE_NEW_LINES) as $l) {
		if (preg_match('/^([a-z_]+)=(.*)$/', $l, $m)) { $v[$m[1]] = $m[2]; }
	}
	$version = explode(' ', $v['ubuntu_source'])[1] . '+joinery' . $v['revision'];
	$name = 'joinery-fix-' . $source . '-' . preg_replace('/^\d+:/', '', $version) . '-amd64.tar.xz';
	if ($mode === 'misnamed') { $name = 'joinery-fix-' . $source . '-9.9-0ubuntu1+joinery1-amd64.tar.xz'; }
	file_put_contents($dir . '/' . $name, 'fix ' . $source . ' ' . $version . ' ' . microtime(true));
	$sha = hash_file('sha256', $dir . '/' . $name);
	if ($mode === 'lying') { $sha = str_repeat('0', 64); }
	file_put_contents($dir . '/' . $name . '.sha256', $sha . '  ' . $name . "\n");
};
// Ubuntu's answer per CVE: released at a version, or not yet.
$ubuntu = array();
$tracker_down = false;
$tracker = new UbuntuCveTracker(function ($url) use (&$ubuntu, &$tracker_down) {
	if ($tracker_down) { return null; }
	preg_match('~/cves/(CVE-[0-9-]+)\.json~', $url, $m);
	$cve = $m[1] ?? '';
	$packages = array();
	foreach (array('php8.5', 'postfix', 'postgresql-18') as $pkg) {
		$st = $ubuntu[$pkg][$cve] ?? array('needs-triage', '');
		$packages[] = array('name' => $pkg, 'statuses' => array(array('release_codename' => 'resolute', 'status' => $st[0], 'description' => $st[1])));
	}
	return json_encode(array('id' => $cve, 'priority' => 'medium', 'packages' => $packages));
});
$opts = array('builder' => $builder, 'cache_dir' => $cache, 'tracker' => $tracker);
$published = function ($name) use ($site) { return $site . '/' . PackageFixPublisher::STATIC_SUBDIR . '/' . $name; };
$active = function () use ($src) { return (string)@file_get_contents($src . '/' . PackageFixPublisher::STAMP_FILE); };

section('Nothing to build');

$r = PackageFixPublisher::publish($work . '/elsewhere', null, $opts);
check($r['status'] === PackageFixPublisher::STATUS_ABSENT && $built === array(), 'no fixes/ in the tree: absent, nothing built', $r['message']);
$recipe('php8.5', 'none');
$recipe('postfix', 'none');
$r = PackageFixPublisher::publish($site, null, $opts);
check($r['status'] === PackageFixPublisher::STATUS_BUILT && $built === array(), 'every recipe at state=none: nothing built, ACTIVE written', $r['message']);
check(PackageFixPublisher::readStamp($src) === array() && strpos($active(), '# Written by the publish') === 0, 'ACTIVE names no fix');
$before = $active();
$r = PackageFixPublisher::publish($site, null, $opts);
check($r['status'] === PackageFixPublisher::STATUS_SKIPPED && $active() === $before, 'again: skipped, ACTIVE byte-identical', $r['message']);

section('An active recipe is built, published and recorded');

$recipe('php8.5', 'active', '8.5.4-0ubuntu1.5', 1, 'CVE-2026-129289 CVE-2026-115560', 'php8.5-common');
$r = PackageFixPublisher::publish($site, null, $opts);
$name = 'joinery-fix-php8.5-8.5.4-0ubuntu1.5+joinery1-amd64.tar.xz';
$lines = PackageFixPublisher::readStamp($src);
check($r['status'] === PackageFixPublisher::STATUS_BUILT && $built === array('php8.5'), 'built once, only the active recipe', implode(',', $built));
check(is_file($published($name)) && hash_file('sha256', $published($name)) === ($lines['php8.5']['sha256'] ?? ''), 'the archive is published under static_files/fixes with the hash ACTIVE names');
check(is_file($published($name) . '.sha256'), 'its .sha256 is published beside it');
check(($lines['php8.5']['version'] ?? '') === '8.5.4-0ubuntu1.5+joinery1' && ($lines['php8.5']['ubuntu_base'] ?? '') === '8.5.4-0ubuntu1.5',
	'ACTIVE names the version and the Ubuntu base');
check(($lines['php8.5']['cves'] ?? '') === 'CVE-2026-129289 CVE-2026-115560' && ($lines['php8.5']['changelog_from'] ?? '') === 'php8.5-common',
	'ACTIVE names the CVEs and changelog_from');
check(($lines['php8.5']['recipe_sha256'] ?? '') === PackageFixPublisher::recipeHash($src, 'php8.5'), 'ACTIVE names the recipe hash it was built from');
check(is_file($cache . '/' . $name) && count(glob($cache . '/.build-*') ?: array()) === 0, 'the build cache keeps the archive; no build folder is left');

section('Unchanged recipes are not built again');

$built = array();
$before = $active();
$r = PackageFixPublisher::publish($site, null, $opts);
check($r['status'] === PackageFixPublisher::STATUS_SKIPPED && $built === array() && $active() === $before, 'skipped, ACTIVE byte-identical', $r['message']);
unlink($published($name));
$r = PackageFixPublisher::publish($site, null, $opts);
check($built === array() && is_file($published($name)) && $active() === $before, 'the published archive gone: copied back from the cache, no build');
unlink($published($name));
unlink($cache . '/' . $name);
$r = PackageFixPublisher::publish($site, null, $opts);
check($built === array('php8.5') && is_file($published($name)), 'gone from both: built again');

section('A changed recipe is built again, and only it');

$built = array();
$recipe('postfix', 'active', '3.10.6-4ubuntu2.2', 1, 'CVE-2026-5000');
$r = PackageFixPublisher::publish($site, null, $opts);
$lines = PackageFixPublisher::readStamp($src);
check($built === array('postfix') && count($lines) === 2, 'a second recipe: only it is built, both are in ACTIVE', implode(',', $built));
check(array_keys($lines) === array('php8.5', 'postfix'), 'ACTIVE lists them sorted by source');
$built = array();
$keep_active = $active();
file_put_contents($src . '/php8.5/patches/0002-CVE-2026-115560.patch', "Origin: y\n--- a/y\n+++ b/y\n");
$r = PackageFixPublisher::publish($site, null, $opts);
check($r['status'] === PackageFixPublisher::STATUS_FAILED && $built === array() && $active() === $keep_active
	&& strpos($r['message'], 'bump its revision') !== false,
	'a patch added under the same revision: refused, nothing built (reviewer2 G7)', $r['message']);
$built = array();
$recipe('php8.5', 'active', '8.5.4-0ubuntu1.5', 2, 'CVE-2026-129289 CVE-2026-115560', 'php8.5-common');
file_put_contents($src . '/php8.5/patches/0002-CVE-2026-115560.patch', "Origin: y\n--- a/y\n+++ b/y\n");
$r = PackageFixPublisher::publish($site, null, $opts);
$lines = PackageFixPublisher::readStamp($src);
check($built === array('php8.5') && ($lines['php8.5']['version'] ?? '') === '8.5.4-0ubuntu1.5+joinery2', 'a new revision: built as +joinery2');
check(is_file($published($name)), 'the earlier archive stays published, for a machine still on the release that names it');
$built = array();
file_put_contents($src . '/build.sh', "#!/bin/bash\n# changed\n");
$r = PackageFixPublisher::publish($site, null, $opts);
check($built === array() && $r['status'] === PackageFixPublisher::STATUS_SKIPPED,
	'build.sh changed: nothing is rebuilt under a version already published (reviewer2 G7)', implode(',', $built));

section('A recipe set to none drops out');

$built = array();
$recipe('postfix', 'none');
$r = PackageFixPublisher::publish($site, null, $opts);
$lines = PackageFixPublisher::readStamp($src);
check($built === array() && array_keys($lines) === array('php8.5'), 'postfix at state=none: gone from ACTIVE, nothing built');

section('A recipe Ubuntu has already covered is left out (Q2)');

$ubuntu['php8.5']['CVE-2026-129289'] = array('released', '8.5.4-0ubuntu1.6');
$built = array();
$r = PackageFixPublisher::publish($site, null, $opts);
check(isset(PackageFixPublisher::readStamp($src)['php8.5']), 'one of its two CVEs released: still in ACTIVE');
$ubuntu['php8.5']['CVE-2026-115560'] = array('released', '8.5.4-0ubuntu1.6');
$said = array();
$r = PackageFixPublisher::publish($site, function ($m) use (&$said) { $said[] = $m; }, $opts);
check(PackageFixPublisher::readStamp($src) === array() && $built === array(), 'both released: left out of ACTIVE, nothing built');
check(count(preg_grep('/php8\.5 left out - Ubuntu has released every CVE.*8\.5\.4-0ubuntu1\.6/', $said)) === 1, 'with a line saying so and naming Ubuntu\'s version', implode(' | ', $said));
$tracker_down = true;
$r = PackageFixPublisher::publish($site, null, $opts);
check(isset(PackageFixPublisher::readStamp($src)['php8.5']), 'the tracker cannot be read: the recipe stays in');
$tracker_down = false;
$ubuntu = array();

section('A build owed and not done refuses');

$keep = $active();
foreach (array('throws' => 'the build fails', 'nothing' => 'the build leaves no archive', 'lying' => 'the archive does not match its .sha256',
	'misnamed' => 'the archive is not the recipe\'s version') as $m => $label) {
	$mode = $m;
	$recipe('postgresql-18', 'active', '18.6-0ubuntu0.26.04.1', 1, 'CVE-2026-7000', 'postgresql-18');
	$r = PackageFixPublisher::publish($site, null, $opts);
	check($r['status'] === PackageFixPublisher::STATUS_FAILED && $active() === $keep, $label . ': failed, ACTIVE untouched', $r['message']);
}
$mode = 'good';
$recipe('postgresql-18', 'none');

section('A recipe that is not whole refuses');

foreach (array("state=active\nubuntu_source=php8.5\n" => 'no version', "state=maybe\n" => 'an unknown state',
	"state=active\nubuntu_source=php8.5 8.5.4-0ubuntu1.5\nrevision=1\ncves=CVE-26-1\nchangelog_from=php8.5-common\n" => 'a bad CVE id',
	"state=active\nubuntu_source=php8.4 8.4.1-1\nrevision=1\ncves=CVE-2026-1000\nchangelog_from=php8.4-common\n" => 'another source\'s name') as $text => $label) {
	file_put_contents($src . '/openssl/RECIPE', $text) || (@mkdir($src . '/openssl') && file_put_contents($src . '/openssl/RECIPE', $text));
	$r = PackageFixPublisher::publish($site, null, $opts);
	check($r['status'] === PackageFixPublisher::STATUS_FAILED, $label . ': failed', $r['message']);
}
file_put_contents($src . '/openssl/RECIPE', "state=none\n");
file_put_contents($src . '/ACTIVE', "source=php8.5 version=1\n");
$r = PackageFixPublisher::publish($site, null, $opts);
check($r['status'] === PackageFixPublisher::STATUS_FAILED, 'a committed ACTIVE that is not whole: failed', $r['message']);
file_put_contents($src . '/ACTIVE', $keep);

section('ACTIVE lines parse back as written');

$line = array('source' => 'postgresql-18', 'version' => '18.6-0ubuntu0.26.04.1+joinery3', 'ubuntu_base' => '18.6-0ubuntu0.26.04.1',
	'archive' => 'joinery-fix-postgresql-18-18.6-0ubuntu0.26.04.1+joinery3-amd64.tar.xz', 'sha256' => str_repeat('b', 64),
	'recipe_sha256' => str_repeat('c', 64), 'changelog_from' => 'postgresql-18', 'cves' => 'CVE-2026-1 CVE-2026-22222');
$text = PackageFixPublisher::stampText(array($line));
$back = PackageFixPublisher::parseLine(trim(explode("\n", trim($text))[2]));
check($back === null, 'a CVE id with fewer than four digits is refused');
$line['cves'] = 'CVE-2026-1000 CVE-2026-22222';
$back = PackageFixPublisher::parseLine(trim(explode("\n", trim(PackageFixPublisher::stampText(array($line))))[2]));
check($back == $line, 'a line round-trips', json_encode($back));
check(PackageFixPublisher::parseLine(str_replace('joinery-fix-postgresql-18-', 'joinery-fix-php8.5-', trim(explode("\n", trim(PackageFixPublisher::stampText(array($line))))[2]))) === null,
	'an archive named for another source is refused');

section('A republishing site fetches and serves what its received ACTIVE names');

$rsite = $work . '/republisher';
$rsrc = $rsite . '/' . PackageFixPublisher::SOURCE_SUBDIR;
@mkdir($rsrc, 0755, true);
copy($src . '/ACTIVE', $rsrc . '/ACTIVE');
$want = PackageFixPublisher::readStamp($rsrc);
$fetched = array();
$fetch_mode = 'good';
$fetcher = function ($url, $dest) use (&$fetched, &$fetch_mode, $site) {
	$fetched[] = $url;
	if ($fetch_mode === 'bad') { file_put_contents($dest, 'not it'); return; }
	copy($site . '/' . PackageFixPublisher::STATIC_SUBDIR . '/' . rawurldecode(basename($url)), $dest);
};
$ropts = array('republish' => true, 'upstream' => 'https://origin.example', 'fetcher' => $fetcher, 'builder' => $builder);
$built = array();
$r = PackageFixPublisher::publish($rsite, null, $ropts);
$rname = $want['php8.5']['archive'];
check($r['status'] === PackageFixPublisher::STATUS_FETCHED && $built === array(), 'fetched, never built', $r['message']);
check($fetched === array('https://origin.example/static_files/fixes/' . rawurlencode($rname)), 'from upstream\'s static_files/fixes/', implode(' ', $fetched));
check(hash_file('sha256', $rsite . '/' . PackageFixPublisher::STATIC_SUBDIR . '/' . $rname) === $want['php8.5']['sha256'], 'served, with ACTIVE\'s hash');
$fetched = array();
$r = PackageFixPublisher::publish($rsite, null, $ropts);
check($r['status'] === PackageFixPublisher::STATUS_SKIPPED && $fetched === array(), 'again: already served, nothing fetched');
unlink($rsite . '/' . PackageFixPublisher::STATIC_SUBDIR . '/' . $rname);
$fetch_mode = 'bad';
$r = PackageFixPublisher::publish($rsite, null, $ropts);
check($r['status'] === PackageFixPublisher::STATUS_FAILED && !is_file($rsite . '/' . PackageFixPublisher::STATIC_SUBDIR . '/' . $rname),
	'upstream serves other bytes: failed, nothing served', $r['message']);

section('Release rules');

check(ReleaseCommit::heldPath('maintenance_scripts/install_tools/fixes/php8.5/RECIPE'), 'a recipe is held: an uncommitted one refuses the release');
check(ReleaseCommit::heldPath('maintenance_scripts/install_tools/fixes/build.sh'), 'build.sh is held');
check(ReleaseCommit::publishWrite(dirname(PathHelper::getIncludePath('includes')), 'maintenance_scripts/install_tools/fixes/ACTIVE'), 'ACTIVE is publish\'s to commit');
$contents = (new ReflectionClass('SupportBundlePublisher'))->getStaticPropertyValue('contents');
check(in_array('maintenance_scripts/install_tools/fixes/ACTIVE', $contents, true) && in_array('maintenance_scripts/install_tools/install_package_fixes.sh', $contents, true),
	'the support bundle carries ACTIVE and install_package_fixes.sh');

harness_finish();
