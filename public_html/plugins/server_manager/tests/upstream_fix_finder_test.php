<?php
/** @joinery-test
 * name: upstream_fix_finder
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * Finding upstream's fix commits for a CVE (specs/package_fixes_automatic.md
 * WP2), over a small fixture repository made in scratch: a CVE named in a
 * commit message, a project id (GHSA) named instead, none, a commit that only
 * touches the news, the release a range ends at, and a branch searched where
 * a line makes no point releases. Also: the doc-part filter, fixes/UPSTREAMS
 * as committed, and the PHP driver's ids(). Nothing reaches the network.
 *
 * Run: php tests/run.php --only=plugins/server_manager/tests/upstream_fix_finder_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$scratch = harness_scratch_dir('upstream_fix_finder');
$repo = $scratch . '/repo';
$sh = function (string $cmd) use ($repo) {
	$env = 'GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 GIT_AUTHOR_NAME=T GIT_AUTHOR_EMAIL=t@example.com GIT_COMMITTER_NAME=T GIT_COMMITTER_EMAIL=t@example.com '
		. 'GIT_AUTHOR_DATE=2026-11-01T00:00:00Z GIT_COMMITTER_DATE=2026-11-01T00:00:00Z ';
	exec('cd ' . escapeshellarg($repo) . ' && ' . $env . $cmd . ' 2>&1', $o, $code);
	if ($code !== 0) { throw new RuntimeException("fixture: {$cmd}: " . implode("\n", $o)); }
	return implode("\n", $o);
};
$put = function (string $rel, string $text) use ($repo) {
	@mkdir(dirname($repo . '/' . $rel), 0700, true);
	file_put_contents($repo . '/' . $rel, $text);
};
$commit = function (string $msg) use ($sh) {
	$sh('git add -A && git commit -q -m ' . escapeshellarg($msg));
	return $sh('git rev-parse HEAD');
};

mkdir($repo, 0700, true);
$sh('git init -q -b master .');
$put('src/a.c', "int a = 1;\n");
$put('NEWS', "PHP NEWS\n");
$commit('Base');
$sh('git tag php-8.5.4');
$sh('git tag glibc-2.43');
$put('src/a.c', "int a = 2; /* bounds checked */\n");
$put('NEWS', "PHP NEWS\n- Fixed GH-1 (CVE-2026-1111)\n");
$sha_cve = $commit("Fix GH-1: overflow in a\n\nFixes CVE-2026-1111.");
$put('src/b.c', "int b = 1;\n");
$put('docs/b.md', "about b\n");
$sha_ghsa = $commit('Fix GHSA-abcd-efgh-ijkl: b was missing');
$put('NEWS', "PHP NEWS\n- Fixed GH-1 (CVE-2026-1111)\n- CVE-2026-2222 noted\n");
$commit('Note CVE-2026-2222 in the news');
$put('src/c.c', "int c = 1;\n");
$sha_longer = $commit('Fix CVE-2026-11112, a different hole');
$sh('git tag php-8.5.5');
$put('src/e.c', "int e = 1;\n");
$sha_late = $commit('Fix CVE-2026-5555');
$sh('git tag php-8.5.6');
$sh('git tag php-8.5.7RC1');
$sh('git tag php-8.4.9 php-8.5.4');
$sh('git tag php-9.0.0');
$sh('git branch release/2.43/master');
$git_dir = $repo . '/.git';

$upstreams = $scratch . '/UPSTREAMS';
file_put_contents($upstreams, "# fixture\n"
	. "source=php8.5 git=https://github.com/example/php-src.git tag=php-{v} line=2 ids=GHSA release_url= sig_url=\n"
	. "source=glibc git=https://sourceware.org/git/glibc.git tag=glibc-{major}.{minor} line=2 branch=release/{major}.{minor}/master\n"
	. "source=openssh git=https://github.com/openssh/openssh-portable.git tag=V_{major}_{minor}_P{patch} line=1 version={major}.{minor}p{patch}\n");
$ctx = function (array $more = array()) use ($git_dir, $upstreams) {
	// The fixture's tags are not signed: the signature check stands in here,
	// and is exercised for real below (owner R-Q1 b).
	return $more + array('base_upstream' => '8.5.4', 'fixed_in' => '', 'ids' => array(), 'git_dir' => $git_dir, 'upstreams_file' => $upstreams,
		'tag_verifier' => function () { return ''; });
};

section('A CVE named in a commit message');

$r = UpstreamFixFinder::find('php8.5', 'CVE-2026-1111', $ctx(array('fixed_in' => '8.4.30, 8.5.5')));
check(count($r['commits']) === 1 && $r['commits'][0]['sha'] === $sha_cve, 'the one commit naming it, not the one naming CVE-2026-11112', json_encode(array_column($r['commits'], 'subject')));
check($r['reason'] === '', 'no reason when found', $r['reason']);
check($r['fixed_version'] === '8.5.5' && $r['range'] === 'php-8.5.4..php-8.5.5', 'the range ends at the fixed release on the base\'s line, not 8.4.30', $r['range']);
$p = $r['commits'][0]['patch'] ?? '';
check(strpos($p, 'diff --git a/src/a.c b/src/a.c') !== false && strpos($p, 'diff --git a/NEWS') === false, 'the patch keeps the code and leaves out NEWS');
check(strpos($p, 'Subject: [PATCH] Fix GH-1: overflow in a') !== false, 'as git format-patch writes it');
check(($r['commits'][0]['url'] ?? '') === 'https://github.com/example/php-src/commit/' . $sha_cve, 'the commit\'s GitHub page', $r['commits'][0]['url'] ?? '');

section('A project id named instead of the CVE');

$r = UpstreamFixFinder::find('php8.5', 'CVE-2026-3333', $ctx(array('fixed_in' => '8.5.5', 'ids' => array('GHSA-abcd-efgh-ijkl'))));
check(count($r['commits']) === 1 && $r['commits'][0]['sha'] === $sha_ghsa, 'the commit naming the GHSA', $r['reason']);
check(strpos($r['commits'][0]['patch'] ?? '', 'docs/b.md') === false && strpos($r['commits'][0]['patch'] ?? '', 'src/b.c') !== false, 'its docs part left out');
$r = UpstreamFixFinder::find('php8.5', 'CVE-2026-3333', $ctx(array('fixed_in' => '8.5.5')));
check($r['commits'] === array() && strpos($r['reason'], 'no upstream commit between php-8.5.4 and php-8.5.5 names CVE-2026-3333') !== false, 'without the id, nothing', $r['reason']);

section('None, and only news');

$r = UpstreamFixFinder::find('php8.5', 'CVE-2026-4444', $ctx(array('fixed_in' => '8.5.5')));
check($r['commits'] === array() && $r['reason'] !== '', 'no commit: empty, with a reason', $r['reason']);
$r = UpstreamFixFinder::find('php8.5', 'CVE-2026-2222', $ctx(array('fixed_in' => '8.5.5')));
check($r['commits'] === array() && strpos($r['reason'], 'news and docs') !== false, 'a commit touching only NEWS is no fix', $r['reason']);

section('Where the range ends');

$r = UpstreamFixFinder::find('php8.5', 'CVE-2026-5555', $ctx());
check($r['range'] === 'php-8.5.4..php-8.5.6' && count($r['commits']) === 1 && $r['commits'][0]['sha'] === $sha_late,
	'no fixed release named: the line\'s newest release (not an RC, not 8.4 or 9.0)', $r['range'] . ' ' . $r['reason']);
$r = UpstreamFixFinder::find('php8.5', 'CVE-2026-5555', $ctx(array('fixed_in' => '8.5.5')));
check($r['commits'] === array(), 'a fix after the fixed release is outside the range');
$r = UpstreamFixFinder::find('php8.5', 'CVE-2026-1111', $ctx(array('base_upstream' => '8.5.3')));
check($r['commits'] === array() && strpos($r['reason'], 'no tag php-8.5.3') !== false, 'no tag for the base: a reason', $r['reason']);
$r = UpstreamFixFinder::find('php8.5', 'CVE-2026-1111', $ctx(array('fixed_in' => '8.5.99')));
check($r['commits'] === array() && strpos($r['reason'], 'no tag php-8.5.99') !== false, 'no tag for the fixed release: a reason', $r['reason']);
$r = UpstreamFixFinder::find('glibc', 'CVE-2026-5555', $ctx(array('base_upstream' => '2.43')));
check($r['range'] === 'glibc-2.43..refs/heads/release/2.43/master' && $r['commits'] === array() && strpos($r['reason'], 'branch head') !== false,
	'a line with no point release ends at a branch head, which no one signs: not used automatically (owner R-Q1 b)', $r['range'] . ' ' . $r['reason']);
check(UpstreamFixFinder::commit_url('https://sourceware.org/git/glibc.git', 'abc') === 'https://sourceware.org/git/?p=glibc.git;a=commit;h=abc', 'sourceware\'s web view');
$r = UpstreamFixFinder::find('postfix', 'CVE-2026-1111', $ctx());
check($r['commits'] === array() && strpos($r['reason'], 'lists no upstream for postfix') !== false, 'a source UPSTREAMS does not list', $r['reason']);
$r = UpstreamFixFinder::find('php8.5', 'CVE-2026-1111', $ctx(array('base_upstream' => '')));
check($r['commits'] === array() && $r['reason'] !== '', 'no base version: a reason', $r['reason']);

section('Pieces');

$fp = "From abc Mon Sep 17 00:00:00 2001\nSubject: [PATCH] x\n\n---\n"
	. "diff --git a/NEWS b/NEWS\n--- a/NEWS\n+++ b/NEWS\n@@ -1 +1,2 @@\n x\n+y\n"
	. "diff --git a/main/x.c b/main/x.c\n--- a/main/x.c\n+++ b/main/x.c\n@@ -1 +1 @@\n-a\n+b\n"
	. "diff --git a/doc/src/sgml/x.sgml b/doc/src/sgml/x.sgml\n--- a/doc/src/sgml/x.sgml\n+++ b/doc/src/sgml/x.sgml\n@@ -1 +1 @@\n-a\n+b\n"
	. "diff --git a/CHANGES.md b/CHANGES.md\n--- a/CHANGES.md\n+++ b/CHANGES.md\n@@ -1 +1 @@\n-a\n+b\n";
$kept = UpstreamFixFinder::drop_doc_parts($fp);
check(strpos($kept, 'main/x.c') !== false && strpos($kept, 'NEWS') === false && strpos($kept, 'sgml') === false && strpos($kept, 'CHANGES') === false,
	'drop_doc_parts keeps code, drops NEWS, CHANGES* and doc/', $kept);
check(strpos($kept, "Subject: [PATCH] x\n") === 0 || strpos($kept, 'From abc') === 0, 'and keeps the message above');
check(UpstreamFixFinder::drop_doc_parts("Subject: x\n---\ndiff --git a/ChangeLog b/ChangeLog\n--- a/ChangeLog\n+++ b/ChangeLog\n@@ -1 +1 @@\n-a\n+b\n") === '', 'a patch of news only is nothing');
check(UpstreamFixFinder::expand('V_{major}_{minor}_P{patch}', '10.2p1') === 'V_10_2_P1', 'OpenSSH\'s tag from 10.2p1');
check(UpstreamFixFinder::expand('{major}.{minor}.{patch}-{build}', '7.1.2.18') === '7.1.2-18', 'ImageMagick\'s tag from Ubuntu\'s 7.1.2.18');
check(UpstreamFixFinder::expand('REL_{major}_{minor}', '18.1') === 'REL_18_1', 'PostgreSQL\'s tag');
check(UpstreamFixFinder::upstream_of('1:10.2p1-2ubuntu3.8') === '10.2p1' && UpstreamFixFinder::upstream_of('8:7.1.2.18+dfsg1-1') === '7.1.2.18'
	&& UpstreamFixFinder::upstream_of('8.5.4-0ubuntu1.6') === '8.5.4' && UpstreamFixFinder::upstream_of('2.15.2+dfsg-0.1ubuntu0.3') === '2.15.2', 'the upstream version of an Ubuntu version');
check(UpstreamFixFinder::line_version('8.5.4', '8.3.30, 8.4.20, 8.5.13', 2) === '8.5.13' && UpstreamFixFinder::line_version('18.1', '17.9, 18.2', 1) === '18.2'
	&& UpstreamFixFinder::line_version('8.5.4', '8.4.20', 2) === '', 'the fixed release on the base\'s line');
check(UpstreamFixFinder::commit_url('https://gitlab.gnome.org/GNOME/libxml2.git', 'abc') === 'https://gitlab.gnome.org/GNOME/libxml2/-/commit/abc'
	&& UpstreamFixFinder::commit_url('https://git.postgresql.org/git/postgresql.git', 'abc') === 'https://git.postgresql.org/gitweb/?p=postgresql.git;a=commit;h=abc'
	&& UpstreamFixFinder::commit_url('https://example.org/x.git', 'abc') === 'https://example.org/x.git#abc', 'commit URLs: GitLab, gitweb, and the git URL elsewhere');
// OpenSSH's release from its tag, spelled by version=: a fixture tag.
$sh('git tag V_10_2_P1 php-8.5.4 && git tag V_10_3_P1 php-8.5.6');
$r = UpstreamFixFinder::find('openssh', 'CVE-2026-5555', $ctx(array('base_upstream' => '10.2p1')));
check($r['fixed_version'] === '10.3p1' && count($r['commits']) === 1, 'a release read back from a tag with no {v}, as upstream spells it', $r['fixed_version'] . ' ' . $r['reason']);

section('Owner R-Q1 (b): the release tag must verify against the committed key');

$keys_dir = $scratch . '/keys';
@mkdir($keys_dir, 0700, true);
$r = UpstreamFixFinder::find('php8.5', 'CVE-2026-1111', array('tag_verifier' => null) + $ctx(array('fixed_in' => '8.5.5', 'keys_dir' => $keys_dir)));
check($r['commits'] === array() && strpos($r['reason'], 'no signing key is committed for php8.5') !== false, 'no key committed: nothing is used', $r['reason']);
if (trim((string)shell_exec('command -v gpg')) === '') {
	harness_skip('signed tags: no gpg on this box');
} else {
	$gh = $scratch . '/gnupg';
	@mkdir($gh, 0700, true);
	$g = 'GNUPGHOME=' . escapeshellarg($gh) . ' ';
	$sh($g . 'gpg --batch --quiet --pinentry-mode loopback --passphrase "" --quick-gen-key "Fixture RM <rm@example.invalid>" ed25519 sign never 2>&1');
	$sh($g . 'gpg --batch --armor --export rm@example.invalid > ' . escapeshellarg($keys_dir . '/php8.5.asc'));
	$sh($g . 'git -c user.signingkey=rm@example.invalid -c gpg.program=gpg tag -s -m "PHP 8.5.5" php-8.5.5s php-8.5.5 2>&1');
	$sh('git tag php-8.5.5u php-8.5.5');
	$real = array('tag_verifier' => null, 'keys_dir' => $keys_dir);
	check(UpstreamFixFinder::verify_tag('php8.5', $git_dir, 'php-8.5.5s', $real) === '', 'a tag signed by the committed key verifies');
	$why = UpstreamFixFinder::verify_tag('php8.5', $git_dir, 'php-8.5.5u', $real);
	check($why !== '' && strpos($why, 'is not signed by the key committed for it') !== false, 'an unsigned tag does not', $why);
	$other = $scratch . '/other_keys';
	@mkdir($other, 0700, true);
	$gh2 = $scratch . '/gnupg2';
	@mkdir($gh2, 0700, true);
	$sh('GNUPGHOME=' . escapeshellarg($gh2) . ' gpg --batch --quiet --pinentry-mode loopback --passphrase "" --quick-gen-key "Someone <x@example.invalid>" ed25519 sign never 2>&1');
	$sh('GNUPGHOME=' . escapeshellarg($gh2) . ' gpg --batch --armor --export x@example.invalid > ' . escapeshellarg($other . '/php8.5.asc'));
	check(UpstreamFixFinder::verify_tag('php8.5', $git_dir, 'php-8.5.5s', array('keys_dir' => $other)) !== '', 'nor one signed by a key other than the committed one');
}

section('fixes/UPSTREAMS as committed');

$all = UpstreamFixFinder::upstreams(PathHelper::getSiteRoot() . '/maintenance_scripts/install_tools/fixes/UPSTREAMS');
$watched = UpstreamFixWatch::WATCHED_SOURCES;
sort($watched);
$listed = array_keys($all);
sort($listed);
check($listed === $watched, 'one line per watched source', implode(',', $listed));
$bad = array();
foreach ($all as $s => $u) {
	if ($u['git'] === '' || ($u['release_url'] === '') !== ($u['sig_url'] === '')) { $bad[] = $s; }
	if (strpos($u['tag'], '{') === false) { $bad[] = $s . ' tag'; }
}
check($bad === array(), 'each names its git, a tag template, and a release only with its signature', implode(', ', $bad));
check(($all['php8.5']['ids'] ?? array()) === array('GHSA') && UpstreamFixFinder::expand($all['php8.5']['release_url'], '8.5.13') === 'https://www.php.net/distributions/php-8.5.13.tar.xz',
	'PHP: GHSA ids, and its release tarball');

section('The PHP driver\'s ids()');

$php = new UpstreamAdvisoryPhp(function ($url) {
	return json_encode(array(
		array('ghsa_id' => 'GHSA-abcd-efgh-ijkl', 'cve_id' => 'CVE-2026-115560', 'severity' => 'critical', 'html_url' => 'https://github.com/php/php-src/security/advisories/GHSA-abcd-efgh-ijkl',
			'vulnerabilities' => array(array('patched_versions' => '8.5.13'))),
		array('ghsa_id' => 'not-a-ghsa', 'cve_id' => 'CVE-2026-1', 'severity' => 'low', 'vulnerabilities' => array()),
	));
});
check($php->ids('CVE-2026-115560') === array('GHSA-abcd-efgh-ijkl'), 'the GHSA of a CVE');
check($php->ids('CVE-2026-1') === array() && $php->ids('CVE-2026-9') === array(), 'none for a malformed id or an unknown CVE');
check(($php->advisories()['CVE-2026-115560']['ids'] ?? null) === array('GHSA-abcd-efgh-ijkl'), 'advisories() carries it too');
$down = new UpstreamAdvisoryPhp(function ($url) { return null; });
check($down->ids('CVE-2026-115560') === array(), 'advisories that cannot be read: none');

harness_finish();
