<?php
/** @joinery-test
 * name: package_fix_recipe_writer
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 * covers: [public_html/plugins/server_manager/includes/PackageFixRecipeWriter.php, public_html/plugins/server_manager/includes/UpstreamFixFinder.php, maintenance_scripts/install_tools/fixes/build.sh, maintenance_scripts/install_tools/fixes/UPSTREAMS]
 */
/**
 * The automatic recipe writer (specs/package_fixes_automatic.md WP3, WP4),
 * with a fake Ubuntu source and a fake finder, all in scratch: a person's
 * recipe as the start, a patch that applies and one that does not, patches
 * that need the one before them, the revision bump, an unchanged rerun, a new
 * Ubuntu base, upstream's release when nothing applies, and build.sh's own
 * RECIPE checks run on what it wrote.
 *
 * Run: php tests/run.php --only=plugins/server_manager/tests/package_fix_recipe_writer_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$scratch = harness_scratch_dir('package_fix_recipe_writer');
$base = '8.5.4-0ubuntu1.6';
$sha = hash('sha256', 'a fake .dsc');

// Ubuntu's unpacked source, as the build root would leave it.
$tree = $scratch . '/tree';
mkdir($tree . '/src', 0700, true);
mkdir($tree . '/debian', 0700, true);
$a_c = "line1\nline2\nline3\nline4\nline5\nline6\nline7\nline8\n";
file_put_contents($tree . '/src/a.c', $a_c);
file_put_contents($tree . '/debian/control', "Source: php8.5\n\nPackage: php8.5-common\nArchitecture: any\n");
$provided = 0;
$provider = function ($source, $version, $out) use ($tree, $sha, &$provided) {
	$provided++;
	return array('dsc_sha256' => $sha, 'tree' => $tree);
};

// A unified diff of one file, as upstream's commit would carry it.
$diff = function (string $rel, string $old, string $new) use ($scratch) {
	file_put_contents($scratch . '/old', $old);
	file_put_contents($scratch . '/new', $new);
	exec('diff -u --label ' . escapeshellarg($old === '' ? '/dev/null' : 'a/' . $rel) . ' --label ' . escapeshellarg('b/' . $rel) . ' '
		. escapeshellarg($scratch . '/old') . ' ' . escapeshellarg($scratch . '/new'), $o);
	return "diff --git a/{$rel} b/{$rel}\n" . implode("\n", $o) . "\n";
};
$commit = function (string $sha, string $subject, string $diff) {
	return array('sha' => $sha, 'url' => 'https://github.com/php/php-src/commit/' . $sha, 'subject' => $subject,
		'patch' => "From {$sha} Mon Sep 17 00:00:00 2001\nSubject: [PATCH] {$subject}\n\n---\n" . $diff);
};
$sha1 = str_repeat('1', 40);
$patched = function (array $lines) use ($a_c) {
	$l = explode("\n", $a_c);
	foreach ($lines as $i => $v) { $l[$i - 1] = $v; }
	return implode("\n", $l);
};
// Commits by CVE, as the finder answers.
$found = array(
	'CVE-2026-1001' => array($commit(str_repeat('a', 40), 'Fix one', $diff('src/a.c', $a_c, $patched(array(2 => 'line2 fixed'))))),
	'CVE-2026-1002' => array($commit(str_repeat('b', 40), 'Fix two', $diff('src/a.c', str_replace('line3', 'lineX', $a_c), str_replace('line3', 'lineY', $a_c)))),
	'CVE-2026-1003' => array(
		$commit(str_repeat('c', 40), 'Add d', $diff('src/d.c', '', "int d = 1;\n")),
		$commit(str_repeat('d', 40), 'Fix d', $diff('src/d.c', "int d = 1;\n", "int d = 2;\n")),
	),
);
$asked = array();
$finder = function ($source, $cve, $ctx) use (&$found, &$asked) {
	$asked[] = $cve . ' ' . $ctx['base_upstream'] . ' ' . $ctx['fixed_in'];
	return isset($found[$cve]) ? array('commits' => $found[$cve], 'reason' => '', 'fixed_version' => '8.5.13')
		: array('commits' => array(), 'reason' => 'no upstream commit names ' . $cve, 'fixed_version' => '8.5.13');
};

// The tree's fixes/: a person's php8.5 recipe on the same base, one patch.
$fixes = $scratch . '/fixes';
mkdir($fixes . '/php8.5/patches', 0700, true);
file_put_contents($fixes . '/php8.5/RECIPE', "state=active\nubuntu_source=php8.5 {$base}\ndsc_sha256={$sha}\nrevision=1\ncves=CVE-2026-129291\n"
	. "changelog_from=php8.5-common\ndate=2026-11-20\ntest=default\ntest_skip=ext/mysqli/tests ext/pdo_mysql/tests\n");
file_put_contents($fixes . '/php8.5/patches/0001-CVE-2026-129291.patch', "Description: a person's\nOrigin: https://github.com/php/php-src/commit/{$sha1}\n\n"
	. $diff('src/a.c', $a_c, $patched(array(7 => 'line7 person'))));
file_put_contents($fixes . '/UPSTREAMS', "source=php8.5 git=https://github.com/php/php-src.git tag=php-{v} line=2 ids=GHSA "
	. "release_url=https://www.php.net/distributions/php-{v}.tar.xz sig_url=https://www.php.net/distributions/php-{v}.tar.xz.asc\n"
	. "source=postgresql-18 git=https://git.postgresql.org/git/postgresql.git tag=REL_{major}_{minor} line=1 release_url= sig_url=\n");
$auto = $scratch . '/auto';
$opts = function (array $more = array()) use ($auto, $fixes, $provider, $finder, $base, $scratch) {
	return $more + array('ubuntu_version' => $base, 'auto_dir' => $auto, 'fixes_dir' => $fixes, 'source_dir' => $scratch . '/source',
		'provider' => $provider, 'finder' => $finder, 'date' => '2026-11-22');
};
$recipe_of = function ($source) use ($auto) { return (string)@file_get_contents($auto . '/' . $source . '/RECIPE'); };
$patch_names = function ($source) use ($auto) { return array_map('basename', glob($auto . '/' . $source . '/patches/*.patch') ?: array()); };

// build.sh's own RECIPE checks: in its test mode, with no source to fetch, a
// recipe it accepts stops only at the missing .dsc, after every check.
$build_sh = PathHelper::getSiteRoot() . '/maintenance_scripts/install_tools/fixes/build.sh';
$build_checks = function ($dir) use ($build_sh, $scratch) {
	@mkdir($scratch . '/empty', 0700);
	@mkdir($scratch . '/buildwork', 0700);
	$cmd = 'FIX_BUILD_TEST=1 FIX_BUILD_SOURCE_DIR=' . escapeshellarg($scratch . '/empty') . ' FIX_BUILD_WORK_BASE=' . escapeshellarg($scratch . '/buildwork')
		. ' bash ' . escapeshellarg($build_sh) . ' ' . escapeshellarg($dir) . ' ' . escapeshellarg($scratch . '/out') . ' 2>&1';
	exec($cmd, $o, $code);
	return implode("\n", $o);
};

section('The first recipe: a person\'s as the start, one fix that applies, one that does not');

$r = PackageFixRecipeWriter::write('php8.5', array('CVE-2026-1001' => array('fixed_in' => '8.5.13', 'ids' => array('GHSA-aaaa-bbbb-cccc')), 'CVE-2026-1002' => array('fixed_in' => '8.5.13')), $opts());
check($r['status'] === 'written' && $r['revision'] === 2 && $r['version'] === $base . '+joinery2', 'written, one above the person\'s revision', json_encode($r));
check($r['carried'] === array('CVE-2026-129291', 'CVE-2026-1001'), 'it carries the person\'s CVE and the one that applied', implode(' ', $r['carried']));
check(array_keys($r['failed']) === array('CVE-2026-1002') && strpos($r['failed']['CVE-2026-1002'], 'does not apply cleanly') !== false, 'the one that does not apply is left out, and why', json_encode($r['failed']));
check($r['fallback'] === null, 'no fallback while something applied');
check($patch_names('php8.5') === array('0001-CVE-2026-129291.patch', '0002-CVE-2026-1001.patch'), 'the person\'s patch first, then the new one', implode(' ', $patch_names('php8.5')));
$p2 = (string)@file_get_contents($auto . '/php8.5/patches/0002-CVE-2026-1001.patch');
check(strpos($p2, "Origin: https://github.com/php/php-src/commit/" . str_repeat('a', 40) . "\n") !== false && PackageFixRecipeWriter::origin($p2) === 'https://github.com/php/php-src/commit/' . str_repeat('a', 40),
	'its header names the commit as Origin');
$rec = $recipe_of('php8.5');
foreach (array('state=active', "ubuntu_source=php8.5 {$base}", "dsc_sha256={$sha}", 'revision=2', 'cves=CVE-2026-129291 CVE-2026-1001', 'changelog_from=php8.5-common',
	'date=2026-11-22', 'test=default', 'test_skip=ext/mysqli/tests ext/pdo_mysql/tests', 'made_by=automatic') as $line) {
	check(preg_match('/^' . preg_quote($line, '/') . '$/m', $rec) === 1, "RECIPE: {$line}");
}
check(strpos($asked[0], 'CVE-2026-1001 8.5.4 8.5.13') === 0, 'the finder is asked from Ubuntu\'s base upstream version', $asked[0] ?? '');
check(is_file($fixes . '/php8.5/RECIPE') && strpos((string)file_get_contents($fixes . '/php8.5/RECIPE'), 'revision=1') !== false, 'the person\'s recipe is untouched');
$out = $build_checks($auto . '/php8.5');
check(strpos($out, "no php8.5_{$base}.dsc was fetched") !== false, 'build.sh accepts the recipe (it stops only at the missing source)', $out);
check(!glob($auto . '/.work-*') && !glob($auto . '/.php8.5.*'), 'no work folders left behind');

section('A rerun, a floor, and fixes that need the patch before them');

$asked = array();
$r = PackageFixRecipeWriter::write('php8.5', array('CVE-2026-1001' => array('fixed_in' => '8.5.13'), 'CVE-2026-1002' => array('fixed_in' => '8.5.13')), $opts());
check($r['status'] === 'unchanged' && $r['revision'] === 2, 'the same inputs again: unchanged', json_encode($r));
check(strpos($recipe_of('php8.5'), 'revision=2') !== false, 'and nothing rewritten');
check($asked === array('CVE-2026-1002 8.5.4 8.5.13'), 'only the CVE not yet carried is looked for again', implode(' | ', $asked));
$r = PackageFixRecipeWriter::write('php8.5', array('CVE-2026-1003' => array('fixed_in' => '8.5.13')), $opts(array('min_revision' => 5)));
check($r['status'] === 'written' && $r['revision'] === 6, 'above the caller\'s floor', json_encode($r));
check($r['carried'] === array('CVE-2026-129291', 'CVE-2026-1001', 'CVE-2026-1003'), 'the earlier automatic CVE is kept', implode(' ', $r['carried']));
check($patch_names('php8.5') === array('0001-CVE-2026-129291.patch', '0002-CVE-2026-1001.patch', '0003-CVE-2026-1003.patch', '0004-CVE-2026-1003-2.patch'),
	'two commits for one CVE, in order, the second applying on the first', implode(' ', $patch_names('php8.5')));
$out = $build_checks($auto . '/php8.5');
check(strpos($out, "no php8.5_{$base}.dsc was fetched") !== false, 'build.sh still accepts it', $out);

section('A new Ubuntu base');

$asked = array();
$r = PackageFixRecipeWriter::write('php8.5', array(), $opts(array('ubuntu_version' => '8.5.4-0ubuntu1.7')));
check($r['status'] === 'written' && $r['revision'] === 7 && $r['version'] === '8.5.4-0ubuntu1.7+joinery7', 'written on the new base', json_encode($r));
sort($asked);
check($asked === array('CVE-2026-1001 8.5.4 ', 'CVE-2026-1003 8.5.4 ', 'CVE-2026-129291 8.5.4 '),
	'every CVE the last automatic recipe carried is looked for again (the person\'s on its old base is not the start, and the automatic one must not drop its CVE)', implode(' | ', $asked));
check(strpos($recipe_of('php8.5'), 'ubuntu_source=php8.5 8.5.4-0ubuntu1.7') !== false, 'its RECIPE names the new base');
check(array_keys($r['failed']) === array('CVE-2026-129291') && $r['fallback'] === null, 'one the finder cannot find is said, the rest still carried', json_encode($r['failed']));

section('Nothing applies: upstream\'s release, or a person');

$r = PackageFixRecipeWriter::write('php8.5', array('CVE-2026-1002' => array('fixed_in' => '8.5.13')), $opts(array('auto_dir' => $scratch . '/auto2')));
check($r['status'] === 'written' && $r['fallback'] === 'upstream_release', 'every CVE asked for failed: the recipe rebuilds on upstream\'s release', json_encode($r));
$rec = (string)@file_get_contents($scratch . '/auto2/php8.5/RECIPE');
check(preg_match('~^upstream_release=8\.5\.13 https://www\.php\.net/distributions/php-8\.5\.13\.tar\.xz https://www\.php\.net/distributions/php-8\.5\.13\.tar\.xz\.asc$~m', $rec) === 1,
	'upstream_release=<version> <tarball> <signature>', $rec);
check(preg_match('/^cves=CVE-2026-129291 CVE-2026-1002$/m', $rec) === 1 && !glob($scratch . '/auto2/php8.5/patches/*.patch'), 'carrying every CVE, with no patches', $rec);
check(isset($r['failed']['CVE-2026-1002']), 'why the patch failed is still said');
$r = PackageFixRecipeWriter::write('postgresql-18', array('CVE-2026-2001' => array('fixed_in' => '18.2')), $opts(array('ubuntu_version' => '18.1-1')));
check($r['status'] === 'failed' && !is_dir($auto . '/postgresql-18') && strpos($r['reason'], 'publishes no signed release') !== false, 'no signed release: failed, nothing written', json_encode($r));
check(strpos($r['failed']['CVE-2026-2001'] ?? '', 'no upstream commit') !== false, 'with the finder\'s reason per CVE');

section('Refusals');

$r = PackageFixRecipeWriter::write('php8.5', array('CVE-2026-1001' => array()), $opts(array('auto_dir' => $scratch . '/auto3', 'provider' => function () { return array('error' => 'apt could not fetch it'); })));
check($r['status'] === 'failed' && ($r['failed']['CVE-2026-1001'] ?? '') === 'apt could not fetch it' && !is_dir($scratch . '/auto3/php8.5'), 'no Ubuntu source: failed, with apt\'s reason', json_encode($r));
$r = PackageFixRecipeWriter::write('php8.5', array('nope' => array()), $opts());
check($r['status'] === 'failed', 'a key that is not a CVE id is refused');
$r = PackageFixRecipeWriter::write('php8.5', array('CVE-2026-1001' => array()), $opts(array('ubuntu_version' => 'x y')));
check($r['status'] === 'failed', 'a base that is not a package version is refused');
$r = PackageFixRecipeWriter::write('php8.5', array('CVE-2026-129291' => array()), $opts(array('auto_dir' => $scratch . '/auto4')));
check($r['status'] === 'unchanged' && $r['revision'] === 1 && !is_dir($scratch . '/auto4/php8.5'), 'a CVE the person\'s recipe already carries: nothing to write', json_encode($r));

section('Pieces');

check(PackageFixRecipeWriter::touched("--- a/x/y.c\n+++ b/x/y.c\n--- /dev/null\n+++ b/new.c\n--- a/../evil\n") === array('x/y.c', 'new.c'), 'the files a patch touches, never outside the tree');
PackageFixRecipeWriter::$newest_provider = function ($s) { return $s === 'php8.5' ? '8.5.4-0ubuntu1.7' : '8.5.4-0ubuntu1.6+joinery1'; };
check(PackageFixRecipeWriter::newestUbuntuVersion('php8.5') === '8.5.4-0ubuntu1.7', 'newestUbuntuVersion: the build root\'s answer');
$threw = false;
try { PackageFixRecipeWriter::newestUbuntuVersion('other'); } catch (RuntimeException $e) { $threw = true; }
check($threw, 'a +joinery build is never the newest Ubuntu version');
PackageFixRecipeWriter::$newest_provider = null;

harness_finish();
