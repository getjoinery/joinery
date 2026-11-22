<?php
/** @joinery-test
 * name: package_fix_channel
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * The package fix channel (specs/package_fixes_automatic.md WP6-WP8): a set is
 * written, signed with a throwaway key, given a stand-in statement, and served;
 * it reads back and verifies; a byte changed is refused; a mirror serves only a
 * wide issue with a higher serial. Nothing reaches the network, the log or the
 * database: the log and the ledger are injected, and state is not saved.
 *
 * Run: php tests/run.php --only=plugins/server_manager/tests/package_fix_channel_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$scratch = harness_scratch_dir();
$pair = sodium_crypto_sign_keypair();
$keys = array('secret' => sodium_crypto_sign_secretkey($pair), 'public' => sodium_crypto_sign_publickey($pair));
$keys_file = $scratch . '/release_verify_keys';
file_put_contents($keys_file, base64_encode($keys['public']) . "\n");

$line = function ($source, $version, $sha) {
	return array('source' => $source, 'version' => $version, 'ubuntu_base' => preg_replace('/\+joinery\d+$/', '', $version),
		'archive' => 'joinery-fix-' . $source . '-' . $version . '-amd64.tar.xz', 'sha256' => $sha,
		'recipe_sha256' => str_repeat('c', 64), 'changelog_from' => $source . '-common', 'cves' => 'CVE-2026-115560');
};
$a = str_repeat('a', 64);
$b = str_repeat('b', 64);

section('The set: merged, written, hashed');

$tree = array('php8.5' => $line('php8.5', '8.5.4-0ubuntu1.6+joinery1', $a));
$auto = array('php8.5' => $line('php8.5', '8.5.4-0ubuntu1.6+joinery2', $b), 'openssl' => $line('openssl', '3.5.5-1ubuntu3.7+joinery1', $a));
$m = PackageFixChannel::merge($tree, $auto, array());
check(array_keys($m) === array('openssl', 'php8.5') && $m['php8.5']['version'] === '8.5.4-0ubuntu1.6+joinery2',
	'per source the higher version wins; sources from either side are kept', json_encode(array_keys($m)));
$m = PackageFixChannel::merge($tree, $auto, array('php8.5' => array('8.5.4-0ubuntu1.6+joinery2')));
check($m['php8.5']['version'] === '8.5.4-0ubuntu1.6+joinery1', 'a withdrawn version is never offered: the next one down is');
$threw = false;
try {
	PackageFixChannel::merge($tree, array('php8.5' => $line('php8.5', '8.5.4-0ubuntu1.6+joinery1', $b)), array());
} catch (Exception $e) {
	$threw = strpos($e->getMessage(), 'two different builds') !== false;
}
check($threw, 'one version with two different archives is refused, naming it');
check(PackageFixChannel::setHash($tree, array()) !== PackageFixChannel::setHash($tree, array('php8.5' => array('x')))
	&& PackageFixChannel::setHash($tree, array()) === PackageFixChannel::setHash($tree, array()),
	'the set\'s hash changes with a withdrawal and with nothing else');

section('Issued: signed, stated, served');

$site = $scratch . '/site';
mkdir($site . '/static_files/fixes', 0755, true);
$recorded = array();
$statement = function ($facts) {
	return json_encode(array('format' => 1, 'envelope' => array('payload' => base64_encode(json_encode(array('version' => $facts['version'],
		'artifacts' => array('fixes_channel' => $facts['subject']))))), 'entry' => array('log_origin' => 'test.log', 'log_index' => 7))) . "\n";
};
$opts = array('keys' => $keys, 'log' => $statement, 'record' => function ($v, $bytes) use (&$recorded) { $recorded[] = $v; },
	'save' => false, 'state' => array('serial' => 4), 'release' => '0.8.641', 'now' => strtotime('2026-11-22 12:00:00 UTC'),
	'skip_line_check' => true);
$r = PackageFixChannel::issue($site, $tree, array(), false, $opts);
check($r['status'] === 'issued' && $r['serial'] === 5, 'issued as the next serial', json_encode($r));
check($recorded === array('0.8.641+fixes.5'), 'its statement is recorded in the ledger as the release plus the channel serial', json_encode($recorded));
$dir = PackageFixChannel::dir($site);
$v = PackageFixChannel::verify($dir, $keys_file);
check($v->signed(), 'it verifies against the release key', $v->line());
$got = PackageFixChannel::read($dir);
check($got !== null && $got['serial'] === 5 && $got['wide'] === false && $got['release'] === '0.8.641'
	&& array_keys($got['lines']) === array('php8.5') && $got['withdrawn'] === array(), 'it reads back whole', json_encode($got));
check(strpos((string)file_get_contents($dir . '/RELEASE_MANIFEST'), 'RELEASE_STATEMENT') !== false, 'the manifest lists the statement');

section('Changed bytes, or a stranger\'s key, are refused');

$tampered = $scratch . '/tampered';
mkdir($tampered);
foreach (PackageFixChannel::SERVED as $f) { copy($dir . '/' . $f, $tampered . '/' . $f); }
file_put_contents($tampered . '/ACTIVE', str_replace('joinery1', 'joinery9', (string)file_get_contents($tampered . '/ACTIVE')));
check(!PackageFixChannel::verify($tampered, $keys_file)->signed(), 'an edited ACTIVE is not ours');
$other = sodium_crypto_sign_keypair();
file_put_contents($scratch . '/other_keys', base64_encode(sodium_crypto_sign_publickey($other)) . "\n");
check(!PackageFixChannel::verify($dir, $scratch . '/other_keys')->signed(), 'nor is one checked against keys that did not sign it');

section('A withdrawal is carried and read');

$r = PackageFixChannel::issue($site, array(), array('php8.5' => array('8.5.4-0ubuntu1.6+joinery1')), true,
	array('state' => array('serial' => 5)) + $opts);
$got = PackageFixChannel::read($dir);
check($r['status'] === 'issued' && $got['serial'] === 6 && $got['wide'] === true && $got['withdrawn'] === array('php8.5' => array('8.5.4-0ubuntu1.6+joinery1')),
	'a wide issue carries the withdrawn build', json_encode($got));

section('A mirror serves only a wide issue with a higher serial');

$upstream_site = $site;
$mirror_site = $scratch . '/mirror';
mkdir($mirror_site . '/static_files/fixes', 0755, true);
$fetcher = function ($url, $dest) use ($upstream_site) {
	$rel = substr($url, strlen('https://up.example'));
	$src = $upstream_site . $rel;
	if (!is_file($src)) { throw new Exception('404 ' . $rel); }
	copy($src, $dest);
};
$mopts = array('upstream' => 'https://up.example', 'fetcher' => $fetcher, 'keys_file' => $scratch . '/release_verify_keys');
// Upstream now: serial 6, wide, no lines (a withdrawal only): nothing to fetch beyond the channel.
$r = PackageFixChannel::mirror($mirror_site, $mopts);
check($r['status'] === 'mirrored', 'a wide issue is mirrored', $r['message']);
check((PackageFixChannel::read(PackageFixChannel::dir($mirror_site))['serial'] ?? null) === 6, 'and served as it was signed');
$r = PackageFixChannel::mirror($mirror_site, $mopts);
check($r['status'] === 'skipped', 'the same serial again: nothing to do', $r['message']);
// Upstream issues serial 7 to its test machines first.
PackageFixChannel::issue($site, array(), array(), false, array('state' => array('serial' => 6)) + $opts);
$r = PackageFixChannel::mirror($mirror_site, $mopts);
check($r['status'] === 'skipped' && (PackageFixChannel::read(PackageFixChannel::dir($mirror_site))['serial'] ?? null) === 6,
	'a test-first issue is not mirrored; the wide one stays served', $r['message']);
check((PackageFixChannel::read(PackageFixChannel::wideDir($site))['serial'] ?? null) === 6 && (PackageFixChannel::read($dir)['serial'] ?? null) === 7,
	'upstream serves the test-first set in channel/ and keeps the last wide one in channel-wide/ (reviewer2 B18)');
// A lower serial than the one served (an old channel replayed) is never taken:
// one signed earlier, served now from another upstream.
$old_site = $scratch . '/old';
mkdir($old_site . '/static_files/fixes', 0755, true);
PackageFixChannel::issue($old_site, array(), array(), true, array('state' => array('serial' => 2)) + $opts);
$upstream_site = $old_site;
$fetcher = function ($url, $dest) use (&$upstream_site) {
	$src = $upstream_site . substr($url, strlen('https://up.example'));
	if (!is_file($src)) { throw new Exception('404'); }
	copy($src, $dest);
};
$mopts['fetcher'] = $fetcher;
$r = PackageFixChannel::mirror($mirror_site, $mopts);
check($r['status'] === 'skipped' && (PackageFixChannel::read(PackageFixChannel::dir($mirror_site))['serial'] ?? null) === 6,
	'a lower serial is never served over a higher one', $r['message']);

section('reviewer2 B18: a withdrawal issued wide, then a test-first set, still reaches the mirror');

$b18 = $scratch . '/b18up';
$b18m = $scratch . '/b18mirror';
mkdir($b18 . '/static_files/fixes', 0755, true);
mkdir($b18m . '/static_files/fixes', 0755, true);
PackageFixChannel::issue($b18, array(), array('php8.5' => array('8.5.4-0ubuntu1.6+joinery9')), true, array('state' => array()) + $opts);
PackageFixChannel::issue($b18, array(), array('php8.5' => array('8.5.4-0ubuntu1.6+joinery9')), false, array('state' => array()) + $opts);
$upstream_site = $b18;
$r = PackageFixChannel::mirror($b18m, $mopts);
$got = PackageFixChannel::read(PackageFixChannel::dir($b18m));
check($r['status'] === 'mirrored' && ($got['withdrawn']['php8.5'] ?? null) === array('8.5.4-0ubuntu1.6+joinery9') && $got['wide'] === true,
	'the mirror serves the wide set with the withdrawal', $r['message']);
check((PackageFixChannel::read(PackageFixChannel::wideDir($b18m))['serial'] ?? null) === $got['serial'], 'and passes it on at channel-wide/ too');

section('The serial and the withdrawals follow what is served, not only the record');

$r = PackageFixChannel::issue($site, array(), array(), true, array('state' => array()) + $opts);
check($r['status'] === 'issued' && $r['serial'] === 8, 'a lost record still issues above the served serial (7)', json_encode($r['serial'] ?? null));
$got = PackageFixChannel::read($dir);
check(($got['withdrawn']['php8.5'] ?? null) === array('8.5.4-0ubuntu1.6+joinery1'), 'and a withdrawal once served is carried again', json_encode($got['withdrawn'] ?? null));
$r = PackageFixChannel::issue($site, array('php8.5' => $line('php8.5', '8.5.4-0ubuntu1.6+joinery1', $a)), array(), true, array('state' => array()) + $opts);
check(!isset(PackageFixChannel::read($dir)['lines']['php8.5']), 'a withdrawn build is never offered again, even when the caller asks');

section('Every line is checked before it is signed');

$static = $site . '/static_files/fixes';
$good = $line('php8.5', '8.5.4-0ubuntu1.6+joinery3', $a);
file_put_contents($static . '/' . $good['archive'], 'bytes');
$good['sha256'] = hash('sha256', 'bytes');
check(PackageFixChannel::lineRefusal($good, $static) === '', 'a whole line whose archive is served with its hash may be signed');
check(strpos(PackageFixChannel::lineRefusal(array('sha256' => str_repeat('0', 64)) + $good, $static), 'not served with that hash') !== false,
	'a hash the served archive does not have is refused');
check(strpos(PackageFixChannel::lineRefusal(array('archive' => '../../etc/passwd') + $good, $static), 'archive') !== false, 'an archive name that leaves static_files is refused');
check(strpos(PackageFixChannel::lineRefusal(array('cves' => 'CVE-2026-1 ; rm') + $good, $static), 'cves') !== false, 'a field not in its shape is refused');
$o = $opts; unset($o['skip_line_check']);
$r = PackageFixChannel::issue($site, array('php8.5' => array('sha256' => str_repeat('0', 64)) + $good), array(), true, array('state' => array()) + $o);
check($r['status'] === 'failed' && strpos($r['message'], 'refusing to sign the set') !== false, 'issue refuses to sign such a set', $r['message']);

harness_finish();
