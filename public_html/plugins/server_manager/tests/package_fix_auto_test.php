<?php
/** @joinery-test
 * name: package_fix_auto
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * The automatic package fix runner (specs/package_fixes_automatic.md WP5, WP7,
 * WP9), with every collaborator standing in: the watch's state, the recipe
 * writer, the build, the channel issue, the machines' reports, the incidents
 * and the emails. Nothing reaches the network, the database or a build root.
 *
 * Run: php tests/run.php --only=plugins/server_manager/tests/package_fix_auto_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$now = strtotime('2026-11-22 12:00:00 UTC');
$iso = function ($t) { return gmdate('Y-m-d\TH:i:s', $t); };
$watch = array(
	'checked_at' => $now,
	'sources' => array('php8.5' => array('checked_at' => $now, 'error' => '', 'driver' => 'PHP', 'unfixed' => array(
		'CVE-2026-115560' => array('status' => 'needs-triage', 'rating' => 'critical', 'rated_by' => 'PHP', 'upstream_fixed' => true,
			'fixed_in' => '8.5.13', 'advisory' => 'https://github.com/php/php-src/security/advisories/GHSA-658j-r8rw-q6mx', 'published' => $iso($now - 86400)),
	))),
	'drivers' => array('PHP' => array('error' => '', 'read_at' => $now, 'advisories' => array('CVE-2026-115560' => array('ids' => array('GHSA-658j-r8rw-q6mx'))))),
	'recipes' => array(), 'nodes' => array(),
);
$line = function ($v) {
	return array('source' => 'php8.5', 'version' => $v, 'ubuntu_base' => '8.5.4-0ubuntu1.6', 'archive' => 'joinery-fix-php8.5-' . $v . '-amd64.tar.xz',
		'sha256' => hash('sha256', $v), 'recipe_sha256' => str_repeat('c', 64), 'changelog_from' => 'php8.5-common', 'cves' => 'CVE-2026-115560');
};
$sent = array();
$issued = array();
$writes = array();
$channel = array();
$nodes = array();
$failing = array();
$opts = function (array $extra = array()) use (&$sent, &$issued, &$writes, &$channel, &$nodes, &$failing, $watch, $line) {
	return $extra + array(
		'role' => 'issuer', 'site' => '/nonexistent', 'save' => false, 'soak_hours' => 6, 'withdraw_requests' => array(), 'served_channel' => null,
		'watch_state' => $watch, 'tree_lines' => array(), 'nodes' => $nodes,
		'failures' => function ($since) use (&$failing) { return $failing; },
		'channel_state' => $channel,
		'dispatch' => function ($signal, $payload) use (&$sent) { $sent[] = array($signal, $payload); },
		'write' => function ($source, $want) use (&$writes) { $writes[] = array($source, $want); return array('status' => 'written', 'failed' => array()); },
		'build' => function ($source) use ($line) { return $line('8.5.4-0ubuntu1.6+joinery2'); },
		'issue' => function ($lines, $withdrawn, $wide) use (&$issued, &$channel) {
			$serial = count($issued) + 1;
			$issued[] = array('lines' => $lines, 'withdrawn' => $withdrawn, 'wide' => $wide);
			$channel = array('serial' => $serial, 'set' => PackageFixChannel::setHash($lines, $withdrawn), 'wide' => $wide);
			return array('status' => 'issued', 'serial' => $serial, 'message' => 'issued ' . $serial);
		},
	);
};

section('Found: told once, built, issued to the test machines first');

$r = PackageFixAuto::run($opts(array('now' => $now, 'state' => array())));
$st = $r['state'];
check($r['status'] === 'success', 'the run succeeds', $r['message']);
check(count($sent) === 1 && $sent[0][0] === 'package_fix.news' && $sent[0][1]['kind'] === 'found'
	&& $sent[0][1]['title'] === 'Security fix ahead of Ubuntu: PHP 8.5 (critical)', 'one "found" email, its subject in plain words',
	json_encode($sent[0][1]['title'] ?? null));
check(strpos($sent[0][1]['body'], 'PHP 8.5: 1 critical (CVE-2026-115560)') !== false && strpos($sent[0][1]['body'], 'Fixed upstream in: PHP 8.5 8.5.13') !== false
	&& strpos($sent[0][1]['body'], 'Nothing is needed from you unless a second email') !== false && strpos($sent[0][1]['body'], '6 hours') !== false,
	'the body says what, where it is fixed, and what happens next', $sent[0][1]['body']);
check(($writes[0][1]['CVE-2026-115560']['ids'] ?? null) === array('GHSA-658j-r8rw-q6mx') && ($writes[0][1]['CVE-2026-115560']['fixed_in'] ?? null) === '8.5.13',
	'the writer is given the fixed release and PHP\'s own id for the CVE', json_encode($writes));
check(count($issued) === 1 && $issued[0]['wide'] === false && isset($issued[0]['lines']['php8.5']),
	'a new fix is issued to the test machines first', json_encode(array_map(function ($i) { return $i['wide']; }, $issued)));
check(($st['soak']['sources'] ?? null) === array('php8.5' => '8.5.4-0ubuntu1.6+joinery2') && ($st['attempts']['php8.5 CVE-2026-115560']['status'] ?? '') === 'built',
	'its soak starts, and the attempt is recorded', json_encode($st['soak'] ?? null));

section('Run again: nothing repeated, the soak waits');

$r = PackageFixAuto::run($opts(array('now' => $now + 600, 'state' => $st)));
$st = $r['state'];
check(count($sent) === 1 && count($writes) === 1 && count($issued) === 1, 'no second email, no second build, no second issue');
check(strpos($r['message'], 'soaking serial 1') !== false, 'it says it is soaking', $r['message']);

section('Installed on the test machines, the hours passed: every machine');

$holder = function ($id, $name, $version, $at, array $extra = array()) {
	return array('id' => $id, 'name' => $name, 'reported_at' => $at, 'fixes' => array($extra + array('source' => 'php8.5', 'version' => $version, 'installed' => true,
		'handed_back' => null, 'ahead_of_build' => null, 'for_base' => false, 'source_refused' => false)));
};
$nodes = array($holder(7, 'wd5', '8.5.4-0ubuntu1.6+joinery2', $now + 3 * 3600));
$r = PackageFixAuto::run($opts(array('now' => $now + 3 * 3600, 'state' => $st)));
check(count($issued) === 1, 'before the soak hours are up, it waits', $r['message']);
$nodes = array($holder(7, 'wd5', '8.5.4-0ubuntu1.6+joinery2', $now + 7 * 3600));
$r = PackageFixAuto::run($opts(array('now' => $now + 7 * 3600, 'state' => $r['state'])));
check(count($issued) === 1, 'the hours count from the install (first seen at 3 h), not from the issue', $r['message']);
$nodes = array($holder(7, 'wd5', '8.5.4-0ubuntu1.6+joinery2', $now + 9 * 3600));
$r = PackageFixAuto::run($opts(array('now' => $now + 9 * 3600, 'state' => $r['state'])));
$st = $r['state'];
check(count($issued) === 2 && $issued[1]['wide'] === true && $issued[1]['lines'] === $issued[0]['lines'], 'the same set goes to every machine');
check(end($sent)[0] === 'package_fix.rolled_out' && strpos(end($sent)[1]['title'], 'PHP 8.5 8.5.4-0ubuntu1.6+joinery2') !== false,
	'a bell notice says it rolled out', json_encode(end($sent)));
check(!isset($st['soak']), 'the soak is over');

section('A test machine fails after installing: withdrawn everywhere, a person told');

$sent = array(); $issued = array(); $writes = array(); $channel = array();
$nodes = array($holder(7, 'wd5', '8.5.4-0ubuntu1.6+joinery2', $now + 1800));
$r = PackageFixAuto::run($opts(array('now' => $now, 'state' => array())));
$failing = array(7);
$r = PackageFixAuto::run($opts(array('now' => $now + 1800, 'state' => $r['state'])));
$st = $r['state'];
$needs = array_values(array_filter($sent, function ($s) { return ($s[1]['kind'] ?? '') === 'needs_person'; }));
check(($st['withdrawn']['php8.5'] ?? null) === array('8.5.4-0ubuntu1.6+joinery2') && !isset($st['automatic']['php8.5']),
	'the build is withdrawn and no longer offered', json_encode($st['withdrawn'] ?? null));
check(count($needs) === 1 && strpos($needs[0][1]['body'], 'wd5 failed after taking it') !== false
	&& strpos($needs[0][1]['title'], 'A person is needed') === 0, 'a person is told, naming the machine', json_encode($needs));
$r = PackageFixAuto::run($opts(array('now' => $now + 2400, 'state' => $st)));
check(end($issued)['wide'] === true && end($issued)['withdrawn'] === array('php8.5' => array('8.5.4-0ubuntu1.6+joinery2')),
	'the withdrawal goes to every machine at once', json_encode(end($issued)));
$failing = array();

section('The fix cannot be found: a person told once, never again for it');

$sent = array(); $issued = array(); $channel = array();
$fail_write = function ($source, $want) { return array('status' => 'failed', 'failed' => array('CVE-2026-115560' => 'no commit names it and upstream publishes no signed release')); };
$r = PackageFixAuto::run($opts(array('now' => $now, 'state' => array(), 'write' => $fail_write)));
$needs = array_values(array_filter($sent, function ($s) { return ($s[1]['kind'] ?? '') === 'needs_person'; }));
check(count($needs) === 1 && strpos($needs[0][1]['body'], 'Stopped at: finding the fix') !== false
	&& strpos($needs[0][1]['body'], 'no commit names it') !== false, 'the step and the reason are said', json_encode($needs));
check(($r['state']['attempts']['php8.5 CVE-2026-115560']['status'] ?? '') === 'needs_person', 'the attempt is recorded as needing a person');
$r = PackageFixAuto::run($opts(array('now' => $now + 600, 'state' => $r['state'], 'write' => $fail_write)));
check(count(array_filter($sent, function ($s) { return ($s[1]['kind'] ?? '') === 'needs_person'; })) === 1 && count($sent) === 2,
	'a rerun tells nobody again (one found, one needs a person)', json_encode(array_column($sent, 0)));

section('A person withdraws a build: to every machine at once');

$sent = array(); $issued = array(); $channel = array();
$r = PackageFixAuto::run($opts(array('now' => $now, 'state' => array(), 'tree_lines' => array('php8.5' => $line('8.5.4-0ubuntu1.6+joinery1')),
	'watch_state' => array(), 'withdraw_requests' => array('php8.5' => '8.5.4-0ubuntu1.6+joinery1'))));
check(count($issued) === 1 && $issued[0]['wide'] === true && $issued[0]['lines'] === array()
	&& $issued[0]['withdrawn'] === array('php8.5' => array('8.5.4-0ubuntu1.6+joinery1')), 'issued wide, the build gone and withdrawn', json_encode($issued));

section('Pure helpers');

check(PackageFixAuto::changedLines(array('a' => $line('1+joinery1')), array('a' => $line('1+joinery1'), 'b' => $line('2+joinery1')))
	=== array('b' => '2+joinery1'), 'changedLines names only what is new or changed');
check(PackageFixAuto::maxRevision(array('automatic' => array('php8.5' => $line('x+joinery3')), 'withdrawn' => array('php8.5' => array('x+joinery5'))), 'php8.5') === 5,
	'the next revision goes above every one used, withdrawn ones included');
check(PackageFixAuto::run(array('role' => 'none'))['status'] === 'success', 'a site that neither logs nor serves releases does nothing');

section('reviewer2 B1: a run that stops keeps what it did');

$sent = array(); $issued = array(); $channel = array();
$broken_issue = function () { return array('status' => 'failed', 'message' => 'the log could not be reached'); };
$r = PackageFixAuto::run($opts(array('now' => $now, 'state' => array(), 'issue' => $broken_issue)));
$r = PackageFixAuto::run($opts(array('now' => $now + 600, 'state' => $r['state'], 'issue' => $broken_issue)));
check($r['status'] === 'error' && count(array_filter($sent, function ($x) { return ($x[1]['kind'] ?? '') === 'found'; })) === 1,
	'two stopped runs send one "found" email, not two', json_encode(array_column($sent, 0)));
check(isset($r['state']['attempts']['php8.5 CVE-2026-115560']) && isset($r['state']['automatic']['php8.5']), 'and keep the attempt and the build');
$r = PackageFixAuto::run($opts(array('now' => $now + 1200, 'state' => $r['state'], 'issue' => $broken_issue,
	'tree_lines' => array('openssl' => $line('8.5.4-0ubuntu1.6+joinery1') + array()), 'withdraw_requests' => array('php8.5' => '8.5.4-0ubuntu1.6+joinery2'))));
check(($r['state']['withdrawn']['php8.5'] ?? null) === array('8.5.4-0ubuntu1.6+joinery2'), 'a Withdraw taken in a run that then stops is kept', json_encode($r['state']['withdrawn'] ?? null));

section('reviewer2 B2, B3, B9: what holds the soak');

$sent = array(); $issued = array(); $channel = array(); $failing = array();
$v2 = '8.5.4-0ubuntu1.6+joinery2';
$nodes = array();
$r = PackageFixAuto::run($opts(array('now' => $now, 'state' => array())));
$nodes = array($holder(7, 'wd5', $v2, $now + 600), $holder(8, 'docker-host', $v2, $now + 600, array('installed' => false, 'for_base' => true)),
	$holder(9, 'old', $v2, $now + 600, array('installed' => false, 'handed_back' => '8.5.4-0ubuntu1.7')));
$r = PackageFixAuto::run($opts(array('now' => $now + 600, 'state' => $r['state'])));
$nodes = array($holder(7, 'wd5', $v2, $now + 4 * 3600));
$r = PackageFixAuto::run($opts(array('now' => $now + 7 * 3600, 'state' => $r['state'])));
check(count($issued) === 1 && strpos($r['message'], 'soaking') !== false, 'a holder silent for over two hours does not count as healthy (B2)', $r['message']);
$nodes = array($holder(7, 'wd5', $v2, $now + 7 * 3600), $holder(8, 'docker-host', $v2, $now + 7 * 3600, array('installed' => false, 'for_base' => true)));
$r = PackageFixAuto::run($opts(array('now' => $now + 7 * 3600 + 60, 'state' => $r['state'])));
check(count($issued) === 2 && $issued[1]['wide'] === true, 'a container host taking it for its base never holds the soak (B3)', $r['message']);

$sent = array(); $issued = array(); $channel = array();
$nodes = array();
$r = PackageFixAuto::run($opts(array('now' => $now, 'state' => array())));
$nodes = array($holder(7, 'wd5', $v2, $now + 600), $holder(10, 'flaky', $v2, $now + 600, array('installed' => false, 'source_refused' => true, 'fetch_failed' => true)));
$r = PackageFixAuto::run($opts(array('now' => $now + 600, 'state' => $r['state'])));
$r = PackageFixAuto::run($opts(array('now' => $now + 1200, 'state' => $r['state'])));
check(empty($r['state']['withdrawn']), 'a download that failed is waiting, never a failure (B9)', json_encode($r['state']['withdrawn'] ?? null));
$nodes = array($holder(7, 'wd5', $v2, $now + 1800), $holder(10, 'flaky', $v2, $now + 1800, array('installed' => false, 'source_refused' => true)));
$r = PackageFixAuto::run($opts(array('now' => $now + 1800, 'state' => $r['state'])));
check(empty($r['state']['withdrawn']), 'apt refusing the source once is not yet a failure');
$r = PackageFixAuto::run($opts(array('now' => $now + 2400, 'state' => $r['state'])));
check(empty($r['state']['withdrawn']), 'the same report read again by a later run is not a second refusal (reviewer2 B22)');
$nodes = array($holder(7, 'wd5', $v2, $now + 5400), $holder(10, 'flaky', $v2, $now + 5400, array('installed' => false, 'source_refused' => true)));
$r = PackageFixAuto::run($opts(array('now' => $now + 5400, 'state' => $r['state'])));
check(($r['state']['withdrawn']['php8.5'] ?? null) === array($v2), 'refused in two reports in a row: withdrawn');
$sent = array(); $issued = array(); $channel = array();
$nodes = array();
$r = PackageFixAuto::run($opts(array('now' => $now, 'state' => array())));
$nodes = array($holder(7, 'wd5', $v2, $now + 13 * 3600), $holder(11, 'lagging', '8.5.4-0ubuntu1.6+joinery1', $now + 13 * 3600, array('installed' => false)));
$r = PackageFixAuto::run($opts(array('now' => $now + 13 * 3600, 'state' => $r['state'])));
$needs = array_values(array_filter($sent, function ($x) { return ($x[1]['kind'] ?? '') === 'needs_person'; }));
check(count($needs) === 1 && strpos($needs[0][1]['body'], 'waiting: lagging') !== false,
	'held by some but still waited for past twice the soak: a person is told (B3)', json_encode($needs));

section('reviewer2 B4: a withdrawal goes to every machine while another fix soaks');

$sent = array(); $issued = array(); $channel = array(); $nodes = array();
$ossl = array('source' => 'openssl', 'version' => '3.5.5-1ubuntu3.7+joinery1', 'ubuntu_base' => '3.5.5-1ubuntu3.7', 'archive' => 'joinery-fix-openssl-3.5.5-1ubuntu3.7+joinery1-amd64.tar.xz',
	'sha256' => str_repeat('d', 64), 'recipe_sha256' => str_repeat('c', 64), 'changelog_from' => 'libssl3t64', 'cves' => 'CVE-2026-1000');
$st = array('wide_lines' => array('openssl' => $ossl), 'wide_withdrawn' => array());
$channel = array('set' => PackageFixChannel::setHash(array('openssl' => $ossl), array()));
$r = PackageFixAuto::run($opts(array('now' => $now, 'state' => $st, 'tree_lines' => array('openssl' => $ossl))));
check(count($issued) === 1 && $issued[0]['wide'] === false && isset($r['state']['soak']['sources']['php8.5']), 'php soaks on the test machines');
$r = PackageFixAuto::run($opts(array('now' => $now + 600, 'state' => $r['state'], 'tree_lines' => array('openssl' => $ossl),
	'withdraw_requests' => array('openssl' => '3.5.5-1ubuntu3.7+joinery1'))));
check(count($issued) >= 2 && $issued[1]['wide'] === true && $issued[1]['lines'] === array()
	&& $issued[1]['withdrawn'] === array('openssl' => array('3.5.5-1ubuntu3.7+joinery1')),
	'the withdrawal is issued to every machine at once, without the soaking fix', json_encode(array_slice($issued, 1)));
check(isset($r['state']['soak']['sources']['php8.5']) && end($issued)['wide'] === false, 'and the soaking fix keeps soaking on the test machines');

section('reviewer2 re-review B21, B23, B25-B28');

// B21: a failed build puts the shipped recipe back.
$auto = harness_scratch_dir('pfa_auto');
@mkdir($auto . '/php8.5/patches', 0755, true);
file_put_contents($auto . '/php8.5/RECIPE', "revision=1 (shipped, carries CVE-A)\n");
$sent = array(); $issued = array(); $channel = array();
$r = PackageFixAuto::run($opts(array('now' => $now, 'state' => array(), 'auto_dir' => $auto,
	'write' => function () use ($auto) { file_put_contents($auto . '/php8.5/RECIPE', "revision=2 (A and B)\n"); return array('status' => 'written', 'dir' => $auto . '/php8.5', 'failed' => array()); },
	'build' => function () { throw new Exception('tests failed'); })));
check(trim((string)@file_get_contents($auto . '/php8.5/RECIPE')) === 'revision=1 (shipped, carries CVE-A)' && !is_dir($auto . '/.php8.5.prev'),
	'a build that fails puts the previous automatic recipe back', (string)@file_get_contents($auto . '/php8.5/RECIPE'));

// B23: the served set predates this tree's release: issued again.
$sent = array(); $issued = array();
$ossl = array('source' => 'openssl', 'version' => '3.5.5-1ubuntu3.7+joinery1', 'ubuntu_base' => '3.5.5-1ubuntu3.7', 'archive' => 'joinery-fix-openssl-3.5.5-1ubuntu3.7+joinery1-amd64.tar.xz',
	'sha256' => str_repeat('d', 64), 'recipe_sha256' => str_repeat('c', 64), 'changelog_from' => 'libssl3t64', 'cves' => 'CVE-2026-1000');
$channel = array('set' => PackageFixChannel::setHash(array('openssl' => $ossl), array()));
$r = PackageFixAuto::run($opts(array('now' => $now, 'state' => array('wide_lines' => array('openssl' => $ossl), 'wide_withdrawn' => array()),
	'watch_state' => array(), 'tree_lines' => array('openssl' => $ossl), 'release' => '0.8.700',
	'served_channel' => array('serial' => 5, 'release' => '0.8.641', 'wide' => true, 'lines' => array('openssl' => $ossl), 'withdrawn' => array()))));
check(count($issued) === 1 && $issued[0]['wide'] === true, 'the same set is issued again, to every machine', json_encode(count($issued)));

// B25: a run that stops before taking Withdraws leaves them for the next one.
$r = PackageFixAuto::run($opts(array('now' => $now, 'state' => array(), 'tree_lines' => null, 'withdraw_requests' => array('php8.5' => 'x+joinery1'))));
check($r['status'] === 'error' && strpos($r['message'], 'not whole') !== false, 'a tree whose ACTIVE is not whole stops the run before any Withdraw is taken, so none is cleared', $r['message']);

// B26: an email that fails never loses the record.
$sent = array(); $issued = array(); $channel = array();
$r = PackageFixAuto::run($opts(array('now' => $now, 'state' => array(), 'dispatch' => function () { throw new Exception('mail down'); })));
check($r['status'] === 'success' && isset($r['state']['automatic']['php8.5']) && isset($r['state']['notified']['found php8.5 CVE-2026-115560'])
	&& strpos($r['message'], 'could not send') !== false, 'the build and the "told" mark are kept, and the failure said', $r['message']);

// B27: a test machine that has not taken the soaking set is waited for.
$sent = array(); $issued = array(); $channel = array();
$nodes = array();
$r = PackageFixAuto::run($opts(array('now' => $now, 'state' => array())));
$serial = (int)$r['state']['soak']['serial'];
$nodes = array($holder(7, 'wd5', $v2, $now + 7 * 3600) + array('channel' => $serial),
	array('id' => 12, 'name' => 'behind', 'reported_at' => $now + 7 * 3600, 'channel' => $serial - 1, 'fixes' => array()));
$r = PackageFixAuto::run($opts(array('now' => $now + 7 * 3600, 'state' => $r['state'])));
check(count($issued) === 1, 'one holder and the hours are not enough while another test machine has not taken the set', $r['message']);

// B28: a set issued again during a soak keeps the soak's start.
$started = (int)$r['state']['soak']['started'];
$tree2 = array('openssl' => $ossl);
$r = PackageFixAuto::run($opts(array('now' => $now + 8 * 3600, 'state' => $r['state'], 'tree_lines' => $tree2)));
check(isset($r['state']['soak']['sources']['php8.5']) && (int)$r['state']['soak']['started'] === $started,
	'php keeps soaking from when it started', json_encode($r['state']['soak'] ?? null));

section('reviewer2 B14: a person\'s recipe already carries it');

$sent = array(); $issued = array(); $channel = array();
$built = 0;
$r = PackageFixAuto::run($opts(array('now' => $now, 'state' => array(),
	'write' => function () use ($now) { return array('status' => 'unchanged', 'dir' => '/nonexistent/auto/php8.5', 'failed' => array()); },
	'build' => function () use (&$built) { $built++; throw new Exception('no recipe'); })));
check($built === 0 && ($r['state']['attempts']['php8.5 CVE-2026-115560']['status'] ?? '') === 'carried'
	&& count(array_filter($sent, function ($x) { return ($x[1]['kind'] ?? '') === 'needs_person'; })) === 0,
	'nothing is built and nobody is told a person is needed', json_encode($r['state']['attempts'] ?? null));

harness_finish();
