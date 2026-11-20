<?php
/** @joinery-test
 * name: upstream_fix_watch
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * The watch for security fixes upstream has shipped and Ubuntu has not
 * (specs/package_fixes_ahead_of_ubuntu.md WP5), against fixture feeds: Ubuntu's
 * tracker (its package listing and its per-CVE answer) and PHP's advisories.
 * Nothing here reaches the network or the database: the watch folds each
 * look into a state, and the two incidents are read from that state.
 *
 * Run: php tests/run.php --only=plugins/server_manager/tests/upstream_fix_watch_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$now = strtotime('2026-11-20 03:00:00 UTC');
$day = 86400;
$iso = function ($t) { return gmdate('Y-m-d\TH:i:s', $t); };

// --- Ubuntu's tracker, as fixtures ------------------------------------------------
// $open[source] = list of CVEs open on resolute; $cve_status[cve][source] = [status, version].
$open = array();
$cve_status = array();
$down = array();
$cve = function ($id, $status, $priority, $published, $severity = '') {
	$c = array('id' => $id, 'priority' => $priority, 'published' => $published, 'packages' => array());
	if ($severity !== '') {
		$c['impact'] = array('baseMetricV4' => array('cvssV4' => array('baseSeverity' => strtoupper($severity))));
	}
	$c['_status'] = $status;
	return $c;
};
$tracker = new UbuntuCveTracker(function ($url) use (&$open, &$cve_status, &$down) {
	if (preg_match('~cves\.json\?package=([^&]+)&limit=(\d+)&offset=(\d+)~', $url, $m)) {
		$source = rawurldecode($m[1]);
		if (in_array($source, $down, true)) { return null; }
		$list = array();
		foreach ($open[$source] ?? array() as $c) {
			$st = $c['_status'];
			unset($c['_status']);
			$c['packages'] = array(array('name' => $source, 'statuses' => array(array('release_codename' => 'resolute', 'status' => $st, 'description' => ''))));
			$list[] = $c;
		}
		$page = array_slice($list, (int)$m[3], (int)$m[2]);
		return json_encode(array('cves' => $page, 'offset' => (int)$m[3], 'limit' => (int)$m[2], 'total_results' => count($list)));
	}
	if (preg_match('~/cves/(CVE-[0-9-]+)\.json~', $url, $m)) {
		$packages = array();
		foreach ($cve_status[$m[1]] ?? array() as $source => $st) {
			$packages[] = array('name' => $source, 'statuses' => array(array('release_codename' => 'resolute', 'status' => $st[0], 'description' => $st[1])));
		}
		return json_encode(array('id' => $m[1], 'priority' => 'medium', 'packages' => $packages));
	}
	return null;
});
// PHP's advisories, as GitHub lists them.
$php_advisories = array();
$php_down = false;
$php = new UpstreamAdvisoryPhp(function ($url) use (&$php_advisories, &$php_down) {
	return $php_down ? null : json_encode($php_advisories);
});
$advisory = function ($cve, $severity, $patched) {
	return array('ghsa_id' => 'GHSA-x', 'cve_id' => $cve, 'severity' => $severity, 'html_url' => 'https://github.com/php/php-src/security/advisories/GHSA-x',
		'vulnerabilities' => array(array('patched_versions' => $patched)));
};
$look = function ($recipes = array(), $nodes = array(), $state = array(), $at = null) use ($tracker, $php, &$now) {
	return UpstreamFixWatch::check($state, $tracker, array($php), $recipes, $nodes, $at ?? $now);
};

section('PHP\'s 19 November release, as it stood on the 20th');

$open['php8.5'] = array(
	// PHP rated critical; Ubuntu's day-one priority is its triage default.
	$cve('CVE-2026-129289', 'needs-triage', 'medium', $iso($now - $day)),
	$cve('CVE-2026-115560', 'needs-triage', 'medium', $iso($now - $day)),
	// Medium by PHP: waits for Ubuntu (Q1).
	$cve('CVE-2026-15353', 'needs-triage', 'medium', $iso($now - $day)),
	// No advisory from PHP: not known whether upstream fixed it.
	$cve('CVE-2026-129999', 'needs-triage', 'medium', $iso($now - $day)),
	// Years old and deferred: not what the watch is for.
	$cve('CVE-2024-1111', 'deferred', 'high', $iso($now - 400 * $day), 'high'),
);
$php_advisories = array(
	$advisory('CVE-2026-129289', 'critical', '8.2.36, 8.3.37, 8.4.28, 8.5.13'),
	$advisory('CVE-2026-115560', 'critical', '8.5.13'),
	$advisory('CVE-2026-15353', 'medium', '8.5.13'),
);
$state = $look();
$u = $state['sources']['php8.5']['unfixed'];
check(($u['CVE-2026-129289']['rating'] ?? '') === 'critical' && ($u['CVE-2026-129289']['rated_by'] ?? '') === 'PHP',
	'a CVE Ubuntu rates medium at triage, PHP critical: rated critical, by PHP', json_encode($u['CVE-2026-129289'] ?? null));
check(($u['CVE-2026-129289']['upstream_fixed'] ?? null) === true && ($u['CVE-2026-129289']['fixed_in'] ?? '') === '8.2.36, 8.3.37, 8.4.28, 8.5.13',
	'fixed upstream, with the releases that carry it, in order');
check(array_key_exists('upstream_fixed', $u['CVE-2026-129999'] ?? array()) && $u['CVE-2026-129999']['upstream_fixed'] === null,
	'no advisory from PHP: not known, never "not fixed" (reviewer2 G4)');
$c = UpstreamFixWatch::conclusions($state, $now);
check(array_column($c['build'], 'cve') === array('CVE-2026-115560', 'CVE-2026-129289'), 'the two critical ones are to build; medium and old ones are not',
	json_encode(array_column($c['build'], 'cve')));
check(array_column($c['unrated'], 'cve') === array('CVE-2026-129999'), 'one with no advisory and no rating is listed as not rated yet', json_encode(array_column($c['unrated'], 'cve')));
$inc = IncidentSourceFixAheadOfUbuntu::from_state($state, $now);
check(($inc['severity'] ?? '') === IncidentRecord::SEVERITY_CRITICAL && strpos($inc['title'] ?? '', '2 serious security fixes upstream has shipped and Ubuntu has not, for php8.5') === 0,
	'the incident opens at once, critical (Q2: no wait)', $inc['title'] ?? 'none');
check(strpos($inc['detail']['CVEs'], 'CVE-2026-129289 (critical, rated by PHP; Ubuntu: needs-triage; fixed upstream in 8.2.36, 8.3.37, 8.4.28, 8.5.13') !== false,
	'it names each CVE, its rating and who gave it, Ubuntu\'s status and upstream\'s fix', $inc['detail']['CVEs']);
check(strpos($inc['detail']['What to do'], 'fixes/<source>/RECIPE') !== false, 'and says how to write the recipe');

section('A source no driver reads: NVD and Ubuntu past triage');

$open['postgresql-18'] = array(
	$cve('CVE-2026-7001', 'needs-triage', 'medium', $iso($now - 2 * $day), 'high'),
	$cve('CVE-2026-7002', 'needed', 'high', $iso($now - 2 * $day)),
	$cve('CVE-2026-7003', 'needed', 'low', $iso($now - 2 * $day), 'medium'),
	$cve('CVE-2026-7004', 'needs-triage', 'medium', $iso($now - 3600)),
);
$state = $look();
$u = $state['sources']['postgresql-18']['unfixed'];
check($u['CVE-2026-7001']['rating'] === 'high' && $u['CVE-2026-7001']['rated_by'] === 'NVD', 'NVD\'s high (as the tracker mirrors it) counts');
check($u['CVE-2026-7002']['rating'] === 'high' && $u['CVE-2026-7002']['rated_by'] === 'Ubuntu', 'Ubuntu\'s priority past triage counts');
check($u['CVE-2026-7003']['rating'] === 'medium' && $u['CVE-2026-7003']['rated_by'] === 'NVD', 'the highest of the ratings wins');
check($u['CVE-2026-7004']['rating'] === '' && $u['CVE-2026-7004']['upstream_fixed'] === null, 'in triage with no NVD score: unrated; upstream not known');
$c = UpstreamFixWatch::conclusions($state, $now);
check(in_array('CVE-2026-7001', array_column($c['build'], 'cve'), true) && in_array('CVE-2026-7002', array_column($c['build'], 'cve'), true)
	&& !in_array('CVE-2026-7003', array_column($c['build'], 'cve'), true), 'high from either is to build; medium waits for Ubuntu');
check(in_array('CVE-2026-7004', array_column($c['unrated'], 'cve'), true), 'the unrated one is listed as not rated yet', json_encode(array_column($c['unrated'], 'cve')));
$inc = IncidentSourceFixAheadOfUbuntu::from_state($state, $now);
check(strpos($inc['detail']['Not rated yet'], 'CVE-2026-7004 (not rated yet') !== false, 'beside the critical ones, the incident lists it');

section('Only the unrated: a warning, "not rated yet"');

$open['php8.5'] = array();
$open['postgresql-18'] = array($cve('CVE-2026-7004', 'needs-triage', 'medium', $iso($now - 3600)));
$state = $look();
$inc = IncidentSourceFixAheadOfUbuntu::from_state($state, $now);
check(($inc['severity'] ?? '') === IncidentRecord::SEVERITY_WARNING && strpos($inc['title'], 'not rated yet') !== false, 'a warning, not rated yet', $inc['title'] ?? 'none');

section('Covered by a recipe, or released by Ubuntu: nothing to build');

$open['php8.5'] = array($cve('CVE-2026-129289', 'needs-triage', 'medium', $iso($now - $day)), $cve('CVE-2026-115560', 'needs-triage', 'medium', $iso($now - $day)));
$open['postgresql-18'] = array();
$recipe = array('php8.5' => array('source' => 'php8.5', 'version' => '8.5.4-0ubuntu1.5+joinery1', 'ubuntu_base' => '8.5.4-0ubuntu1.5',
	'cves' => 'CVE-2026-129289 CVE-2026-115560'));
$cve_status['CVE-2026-129289'] = array('php8.5' => array('needs-triage', ''));
$cve_status['CVE-2026-115560'] = array('php8.5' => array('needs-triage', ''));
$state = $look($recipe);
check(UpstreamFixWatch::conclusions($state, $now)['build'] === array() && IncidentSourceFixAheadOfUbuntu::from_state($state, $now) === null,
	'an active recipe carries both: the incident clears');
check(IncidentSourcePackageFixUbuntuNewer::from_state($state, $now) === null, 'Ubuntu has released neither: no recipe warning');
$open['php8.5'] = array();
$state = $look();
check(IncidentSourceFixAheadOfUbuntu::from_state($state, $now) === null, 'the tracker shows them released (gone from the open list): it clears');

section('Ubuntu catches up with a recipe');

$cve_status['CVE-2026-129289'] = array('php8.5' => array('released', '8.5.4-0ubuntu1.6'));
$state = $look($recipe);
$inc = IncidentSourcePackageFixUbuntuNewer::from_state($state, $now);
check(($inc['severity'] ?? '') === IncidentRecord::SEVERITY_WARNING && strpos($inc['title'], 'rebuild the package fix for php8.5 on Ubuntu\'s newer build') !== false,
	'one of two released: rebuild on Ubuntu\'s build', $inc['title'] ?? 'none');
check(strpos($inc['detail']['Recipes'], 'Ubuntu\'s 8.5.4-0ubuntu1.6 lacks CVE-2026-115560') !== false, 'naming the build and the CVE it lacks', $inc['detail']['Recipes']);
$cve_status['CVE-2026-115560'] = array('php8.5' => array('released', '8.5.4-0ubuntu1.6'));
$nodes = array(
	array('id' => 1, 'name' => 'a', 'fixes' => array(array('source' => 'php8.5', 'version' => '8.5.4-0ubuntu1.5+joinery1', 'installed' => true,
		'handed_back' => '8.5.4-0ubuntu1.6', 'ubuntu_lacks' => null, 'ahead_of_build' => null, 'source_refused' => false))),
	array('id' => 2, 'name' => 'b', 'fixes' => array(array('source' => 'php8.5', 'version' => '8.5.4-0ubuntu1.5+joinery1', 'installed' => true,
		'handed_back' => null, 'ubuntu_lacks' => array('version' => '8.5.4-0ubuntu1.6', 'cves' => array('CVE-2026-115560')), 'ahead_of_build' => null, 'source_refused' => false))),
);
$state = $look($recipe, $nodes);
$inc = IncidentSourcePackageFixUbuntuNewer::from_state($state, $now);
check(strpos($inc['title'] ?? '', 'rebuild the package fix for php8.5') !== false && strpos($inc['title'], 'retire') === false,
	'both released by the tracker, but a machine read Ubuntu\'s build lacking one: rebuild, never retire (owner Q6)', $inc['title'] ?? 'none');
check(strpos($inc['detail']['Recipes'], 'Ubuntu\'s 8.5.4-0ubuntu1.6 lacks CVE-2026-115560') !== false
	&& strpos($inc['detail']['Recipes'], '1 machine handed back, 1 holding at ours') !== false,
	'naming the CVE the machine found missing, and what the machines say', $inc['detail']['Recipes']);
$state = $look($recipe, array($nodes[0]));
$inc = IncidentSourcePackageFixUbuntuNewer::from_state($state, $now);
check(strpos($inc['title'] ?? '', 'retire the package fix for php8.5') !== false, 'both released and no machine holding: retire it', $inc['title'] ?? 'none');
check(strpos($inc['detail']['Recipes'], '1 machine handed back, 0 holding at ours.') !== false, 'with what the machines say', $inc['detail']['Recipes']);
$cve_status['CVE-2026-115560'] = array('php8.5' => array('needs-triage', ''));
$cve_status['CVE-2026-129289'] = array('php8.5' => array('needs-triage', ''));
$state = $look($recipe, array($nodes[1]));
$inc = IncidentSourcePackageFixUbuntuNewer::from_state($state, $now);
check(strpos($inc['title'] ?? '', 'rebuild') !== false && strpos($inc['detail']['Recipes'], 'Ubuntu\'s 8.5.4-0ubuntu1.6 lacks') !== false,
	'the tracker shows nothing released, but a machine holds because Ubuntu\'s newer build lacks a CVE: rebuild', $inc['title'] ?? 'none');
$rebuilt = array('php8.5' => array('source' => 'php8.5', 'version' => '8.5.4-0ubuntu1.6+joinery1', 'ubuntu_base' => '8.5.4-0ubuntu1.6',
	'cves' => 'CVE-2026-115560'));
$cve_status['CVE-2026-115560'] = array('php8.5' => array('needs-triage', ''));
$state = $look($rebuilt, array());
check(IncidentSourcePackageFixUbuntuNewer::from_state($state, $now) === null, 'rebuilt on Ubuntu\'s newest build: the warning clears');

section('The tracker cannot be read');

$open['php8.5'] = array($cve('CVE-2026-129289', 'needs-triage', 'medium', $iso($now - $day)));
$state = $look();
$down = array('php8.5');
$state2 = $look(array(), array(), $state, $now + 3600);
check($state2['sources']['php8.5']['unfixed'] === $state['sources']['php8.5']['unfixed'] && $state2['sources']['php8.5']['error'] !== ''
	&& $state2['sources']['php8.5']['checked_at'] === $now, 'it keeps the last answer, says it could not read the tracker, and since when');
check(UpstreamFixWatch::conclusions($state2, $now + 3600)['build'] !== array(), 'the last answer still raises the incident');
$open['php8.5'] = array();
$state = $look();
$state3 = $look(array(), array(), $state, $now + 7 * 3600);
$inc = IncidentSourceFixAheadOfUbuntu::from_state($state3, $now + 7 * 3600);
check(($inc['severity'] ?? '') === IncidentRecord::SEVERITY_WARNING && strpos($inc['title'], 'Ubuntu\'s CVE tracker for php8.5 not read in six hours') !== false,
	'unread for over six hours, with nothing else to say: a warning', $inc['title'] ?? 'none');
$down = array();

section('PHP\'s advisories cannot be read (reviewer2 G4)');

$open['php8.5'] = array($cve('CVE-2026-129289', 'needs-triage', 'medium', $iso($now - $day)));
$state = $look();
check(count(UpstreamFixWatch::conclusions($state, $now)['build']) === 1, 'read: the critical one is to build');
$php_down = true;
$state = $look(array(), array(), $state, $now + 3600);
check($state['drivers']['PHP']['error'] !== '' && count(UpstreamFixWatch::conclusions($state, $now + 3600)['build']) === 1,
	'unread an hour later: the last good answer holds, and the incident stays open');
$state = $look(array(), array(), $state, $now + 8 * 3600);
$inc = IncidentSourceFixAheadOfUbuntu::from_state($state, $now + 8 * 3600);
check(($inc['severity'] ?? '') === IncidentRecord::SEVERITY_CRITICAL, 'still unread after eight hours: still critical');
$open['php8.5'] = array();
$state = $look(array(), array(), $state, $now + 9 * 3600);
$inc = IncidentSourceFixAheadOfUbuntu::from_state($state, $now + 9 * 3600);
check(strpos($inc['title'] ?? '', 'PHP\'s own advisories not read in six hours') !== false, 'with nothing else to say, the blind warning names the advisories', $inc['title'] ?? 'none');
$php_down = false;

section('A raised CVE stays raised past the 30 days (reviewer2 G8)');

$open['php8.5'] = array($cve('CVE-2026-129289', 'needs-triage', 'medium', $iso($now - 29 * $day)));
$state = $look();
check(count(UpstreamFixWatch::conclusions($state, $now)['build']) === 1, 'day 29: raised');
$state = $look(array(), array(), $state, $now + 3 * $day);
check(count(UpstreamFixWatch::conclusions($state, $now + 3 * $day)['build']) === 1, 'day 32, still unfixed and uncovered: still raised');
$open['php8.5'] = array();
$state = $look(array(), array(), $state, $now + 4 * $day);
$open['php8.5'] = array($cve('CVE-2026-129289', 'needs-triage', 'medium', $iso($now - 29 * $day)));
$state = $look(array(), array(), $state, $now + 5 * $day);
check(UpstreamFixWatch::conclusions($state, $now + 5 * $day)['build'] === array(), 'once released (gone from the open list) it is no longer raised; an old one is not raised again');
$open['php8.5'] = array();

section('What is watched, and the task');

$state = $look(array('libpng1.6' => array('source' => 'libpng1.6', 'version' => '1.6.50-1ubuntu1+joinery1', 'ubuntu_base' => '1.6.50-1ubuntu1', 'cves' => 'CVE-2026-9000')));
check(array_keys($state['sources']) === array('apache2', 'curl', 'glibc', 'imagemagick', 'libpng1.6', 'libxml2', 'openssh', 'openssl', 'php8.5', 'postfix', 'postgresql-18'),
	'every source the nodes run, and any with an active recipe', implode(',', array_keys($state['sources'])));
$r = UpstreamFixWatch::run(array('tracker' => $tracker, 'drivers' => array($php), 'recipes' => array(), 'nodes' => array(), 'now' => $now, 'save' => false));
check($r['status'] === 'success' && strpos($r['message'], 'CVEs to build ahead of Ubuntu') !== false, 'the task\'s run reports what it found', $r['message']);
check(class_exists('WatchUpstreamSecurityFixes') || is_file(__DIR__ . '/../tasks/WatchUpstreamSecurityFixes.php'), 'the task is shipped');
$task = json_decode((string)file_get_contents(__DIR__ . '/../tasks/WatchUpstreamSecurityFixes.json'), true);
check(($task['default_frequency'] ?? '') === 'hourly' && ($task['activate_on_install'] ?? false) === true, 'hourly, on from install');

harness_finish();
