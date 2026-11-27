<?php
/** @joinery-test
 * name: reboot_when_quiet
 * tier: safe
 * env: any
 * needs: []
 * covers: [plugins/server_manager/includes/RebootWhenQuietRunner.php, plugins/server_manager/includes/IncidentSourceRebootNotBack.php, plugins/server_manager/tasks/RebootWhenQuiet.php]
 */
/**
 * Reboot When Quiet (specs/automatic_reboot_when_quiet.md WP1-WP3), from
 * arrays: which machine reboots and when (owed an hour, every answer quiet and
 * fresh, its sites' too, one at a time, this plane's own machine last and
 * never by the deadline, the nightly window, the switch, a refusal); how
 * often a machine is asked; a quiet_check answer read; the incident for a
 * machine that did not come back.
 *
 * Run: php plugins/server_manager/tests/reboot_when_quiet_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

// 2026-11-26 14:00 UTC: outside the nightly window.
$NOW = strtotime('2026-11-26T14:00:00Z');
$NIGHT = strtotime('2026-11-27T04:20:00Z');
$m = function ($id, array $over = array()) use ($NOW) {
	return array_merge(array('id' => $id, 'is_self' => false, 'mode' => 'quiet', 'owed_since' => $NOW - 2 * 3600,
		'containers' => array(), 'can_ask' => true, 'refusal' => null), $over);
};
$quiet = function ($at, $q = true) { return array('asked_at' => $at - 30, 'done' => true, 'failed' => false, 'quiet' => $q, 'completed_at' => $at); };
$free = array('awaited' => false, 'last_back_at' => null, 'not_back' => false);
$decide = function (array $machines, array $asks, array $hold = array(), $now = null) use ($free, $NOW) {
	return RebootWhenQuietRunner::decide($machines, $asks, array_merge($free, $hold), $now ?? $NOW);
};

section('Asking: at most every ten minutes, never over a pending ask');

$d = $decide(array($m(1)), array());
check($d['ask'] === array(1) && $d['reboot'] === null, 'owed two hours, never asked: asked, not rebooted', json_encode($d));
$d = $decide(array($m(1, array('owed_since' => $NOW - 1800))), array());
check($d['ask'] === array() && $d['reboot'] === null, 'owed half an hour: left alone (an install may be finishing)', json_encode($d));
$d = $decide(array($m(1)), array(1 => $quiet($NOW - 200, false)));
check($d['ask'] === array() && $d['reboot'] === null, 'busy three minutes ago: not asked again yet, not rebooted', json_encode($d));
$d = $decide(array($m(1)), array(1 => $quiet($NOW - 700, false)));
check($d['ask'] === array(1), 'busy twelve minutes ago: asked again', json_encode($d));
$d = $decide(array($m(1)), array(1 => array('asked_at' => $NOW - 900, 'done' => false, 'failed' => false, 'quiet' => false, 'completed_at' => null)));
check($d['ask'] === array(), 'an ask pending fifteen minutes (the node is slow): waited for', json_encode($d));
$d = $decide(array($m(1)), array(1 => array('asked_at' => $NOW - 900, 'done' => false, 'failed' => true, 'quiet' => false, 'completed_at' => null)));
check($d['ask'] === array(1), 'an ask that failed: asked again', json_encode($d));
$d = $decide(array($m(1, array('can_ask' => false))), array());
check($d['ask'] === array() && $d['reboot'] === null, 'an agent without the word: not asked, waits for the deadline', json_encode($d));

section('Rebooting when quiet');

$d = $decide(array($m(1)), array(1 => $quiet($NOW - 60)));
check($d['reboot'] === 1 && $d['why'] === 'nobody is busy on it' && $d['ask'] === array(), 'quiet a minute ago: rebooted', json_encode($d));
$d = $decide(array($m(1)), array(1 => $quiet($NOW - 400)));
check($d['reboot'] === null, 'quiet seven minutes ago: too old to act on', json_encode($d));
$d = $decide(array($m(1, array('containers' => array(11, 12)))), array(1 => $quiet($NOW - 60), 11 => $quiet($NOW - 60), 12 => $quiet($NOW - 60, false)));
check($d['reboot'] === null, 'a container host with one busy site: not rebooted', json_encode($d));
$d = $decide(array($m(1, array('containers' => array(11, 12)))), array(1 => $quiet($NOW - 60), 11 => $quiet($NOW - 60)));
check($d['reboot'] === null && $d['ask'] === array(12), 'a site not asked yet: asked, the host waits', json_encode($d));
$d = $decide(array($m(1, array('containers' => array(11, 12)))), array(1 => $quiet($NOW - 60), 11 => $quiet($NOW - 90), 12 => $quiet($NOW - 30)));
check($d['reboot'] === 1, 'the host and every site quiet: rebooted', json_encode($d));
$d = $decide(array($m(1, array('refusal' => 'Its data root is being encrypted'))), array(1 => $quiet($NOW - 60)));
check($d['reboot'] === null && $d['ask'] === array(), 'one reboot_machine would refuse: left alone', json_encode($d));
foreach (array('never', 'window') as $mode) {
	$d = $decide(array($m(1, array('mode' => $mode))), array(1 => $quiet($NOW - 60)));
	check($d['reboot'] === null && $d['ask'] === array(), "set to {$mode}: not rebooted when quiet in the day, not asked", json_encode($d));
}

section('One at a time');

$two = array($m(1, array('owed_since' => $NOW - 5000)), $m(2, array('owed_since' => $NOW - 9000)));
$both = array(1 => $quiet($NOW - 60), 2 => $quiet($NOW - 60));
$d = $decide($two, $both);
check($d['reboot'] === 2, 'two quiet: the one owed longest goes first, only one', json_encode($d));
$d = $decide($two, $both, array('awaited' => true));
check($d['reboot'] === null && $d['ask'] === array() && $d['why'] === 'a reboot is under way', 'a reboot awaited: nothing starts', json_encode($d));
$d = $decide($two, $both, array('last_back_at' => $NOW - 300));
check($d['reboot'] === null, 'one came back five minutes ago: the next waits', json_encode($d));
$d = $decide($two, $both, array('last_back_at' => $NOW - 700));
check($d['reboot'] === 2, 'one came back twelve minutes ago: the next goes', json_encode($d));
$d = $decide($two, $both, array('not_back' => true));
check($d['reboot'] === null && strpos($d['why'], 'did not come back') !== false, 'a machine that did not come back stops the rest', json_encode($d));

section('This plane\'s own machine: last, only when quiet (owner Q2)');

$self = $m(9, array('is_self' => true, 'owed_since' => $NOW - 99999));
$d = $decide(array($self, $m(1)), array(9 => $quiet($NOW - 60), 1 => $quiet($NOW - 60, false)));
check($d['reboot'] === null, 'quiet, but another machine still owes one: it waits', json_encode($d));
$d = $decide(array($self), array(9 => $quiet($NOW - 60)));
check($d['reboot'] === 9, 'the only one left, quiet: rebooted', json_encode($d));
$d = $decide(array($self), array(9 => $quiet($NOW - 60, false)), array(), $NIGHT);
check($d['reboot'] === null, 'busy in the nightly window though owed a day: never by the deadline', json_encode($d));

section('The nightly window (owner Q1: 04:00-05:00 UTC)');

$d = $decide(array($m(1, array('owed_since' => $NIGHT - 2 * 86400))), array(1 => $quiet($NIGHT - 60, false)), array(), $NIGHT);
check($d['reboot'] === 1 && strpos($d['why'], 'over a day') !== false, 'busy, owed two days, in the window: rebooted', json_encode($d));
$d = $decide(array($m(1, array('owed_since' => $NIGHT - 5 * 3600))), array(1 => $quiet($NIGHT - 60, false)), array(), $NIGHT);
check($d['reboot'] === null, 'busy, owed five hours, in the window: waits for a quiet moment', json_encode($d));
$d = $decide(array($m(1, array('owed_since' => $NOW - 3 * 86400))), array(1 => $quiet($NOW - 60, false)));
check($d['reboot'] === null, 'busy, owed three days, at 14:00: waits for the window', json_encode($d));
$d = $decide(array($m(1, array('mode' => 'window', 'owed_since' => $NIGHT - 2 * 3600))), array(), array(), $NIGHT);
check($d['reboot'] === 1 && $d['why'] === 'its nightly window', 'set to the window, owed two hours, in the window: rebooted unasked', json_encode($d));
$d = $decide(array($m(1, array('mode' => 'never', 'owed_since' => $NIGHT - 9 * 86400))), array(), array(), $NIGHT);
check($d['reboot'] === null, 'set to never: not even in the window', json_encode($d));
$d = $decide(array($m(1, array('can_ask' => false, 'owed_since' => $NIGHT - 2 * 86400))), array(), array(), $NIGHT);
check($d['reboot'] === 1, 'an agent too old to ask, owed two days: rebooted in the window', json_encode($d));
$d = $decide(array($m(1, array('owed_since' => $NIGHT - 2 * 86400)), $m(2, array('owed_since' => $NIGHT - 3 * 86400))), array(1 => $quiet($NIGHT - 60)), array(), $NIGHT);
check($d['reboot'] === 2, 'in the window, the one owed longest goes first, one at a time', json_encode($d));

section('A quiet_check answer read');

$r = JobResultProcessor::sanitise_quiet_check(array('quiet' => true, 'busy' => array(), 'unknown' => array(), 'logins' => 0, 'signed_in' => 0,
	'visits' => 4, 'writes' => 0, 'packages' => 0, 'installers' => 0, 'site_work' => 0, 'db_queries' => 'not_reported', 'mail_sent' => 'not_reported'));
check($r['read'] && $r['quiet'] && $r['visits'] === 4 && $r['db_queries'] === 'not_reported', 'a quiet answer is quiet, counts kept', json_encode($r));
$r = JobResultProcessor::sanitise_quiet_check(array('quiet' => true, 'busy' => array('signed_in'), 'signed_in' => 2));
check(!$r['quiet'] && $r['busy'] === array('signed_in'), 'quiet with a busy signal named: not quiet', json_encode($r));
$r = JobResultProcessor::sanitise_quiet_check(array('quiet' => false, 'busy' => array('signed_in', 'db_queries', 'rm -rf'), 'signed_in' => 2, 'db_queries' => 'unknown', 'logins' => '1; drop'));
check(!$r['quiet'] && $r['busy'] === array('signed_in', 'db_queries') && $r['logins'] === 'unknown',
	'a name not a signal is dropped; a value not a count or a word is unknown', json_encode($r));
check(JobResultProcessor::quiet_check_words($r) === 'signed-in visitors (2), a long database query (could not be read)', 'in words', JobResultProcessor::quiet_check_words($r));
$r = JobResultProcessor::sanitise_quiet_check('not json');
check(!$r['read'] && !$r['quiet'] && JobResultProcessor::quiet_check_words($r) === 'its answer could not be read', 'an answer that cannot be read is never quiet');

section('A machine that did not come back (plane:reboot_not_back)');

$v = IncidentSourceRebootNotBack::verdict(true, false, $NOW - 900, array('automatic' => 'reboot_when_quiet', 'why' => 'nobody is busy on it'), $NOW);
check(($v['severity'] ?? '') === IncidentRecord::SEVERITY_CRITICAL && $v['title'] === 'Rebooted 15 minutes ago and has not come back'
	&& strpos($v['detail']['What'], 'rebooted automatically (nobody is busy on it)') !== false, 'fifteen minutes and no new boot id: critical, saying it was automatic', json_encode($v));
check(IncidentSourceRebootNotBack::verdict(true, false, $NOW - 300, array(), $NOW) === null, 'five minutes: still coming back');
check(IncidentSourceRebootNotBack::verdict(true, true, $NOW - 900, array(), $NOW) === null, 'came back: nothing');
check(IncidentSourceRebootNotBack::verdict(false, false, $NOW - 900, array(), $NOW) === null, 'a refused reboot: nothing');
$v = IncidentSourceRebootNotBack::verdict(true, false, $NOW - 900, array(), $NOW);
check(strpos($v['detail']['What'], 'rebooted from its page') !== false, 'a reboot a person pressed counts too');
check(isset(IncidentSources::all()[IncidentSourceRebootNotBack::NAME]), 'the source is registered');

section('The task');

$json = json_decode(file_get_contents(__DIR__ . '/../tasks/RebootWhenQuiet.json'), true);
check(is_array($json) && $json['default_frequency'] === 'every_run' && $json['activate_on_install'] === true, 'the task runs every cron run, on by default');
check(in_array('quiet_check', ObserveWordAsk::WORDS, true), 'quiet_check can be asked from a session (ask_node)');

section('Why a machine waits, on every page');

// The words find the machine's hosted sites themselves: Fleet Security once
// passed none, and called a machine whose sites could not answer ready.
$wp = new ReflectionMethod('RebootWhenQuietRunner', 'waiting_words');
check($wp->getNumberOfParameters() === 1, 'waiting_words takes the machine alone and finds the sites it hosts');
$calls = array();
foreach (array('views/admin/fleet_security.php', 'includes/node_detail_tabs/overview.php') as $f) {
	preg_match_all('/waiting_words\(([^)]*)\)/', file_get_contents(__DIR__ . '/../' . $f), $m);
	foreach ($m[1] as $args) { $calls[] = $f . ': ' . $args; }
}
check(count($calls) === 2, 'Fleet Security and the node page both say why a machine waits', implode(' | ', $calls));
$src = file_get_contents(__DIR__ . '/../includes/RebootWhenQuietRunner.php');
check(strpos($src, "new MultiManagedHost(array('mgh_mgn_managed_node_id'") !== false, 'the sites a machine hosts are found through the host group naming it, as tick() pairs them (the machine itself carries no group)');
check(strpos($src, "a site it hosts (") !== false, 'a hosted site whose agent cannot answer is named, as tick() does not ask that machine');

// Two ticks 4 s apart queued joinerydemo's reboot twice (11-26): the first job
// had not run yet, so nothing counted as under way. A queued reboot holds the
// rest, and a tick decides under its own lock.
check(strpos($src, 'in_array($last[\'status\'], array(\'pending\', \'queued\', \'running\'), true)) { $awaited = true; }') !== false,
	'a reboot job queued and not yet run counts as a reboot under way');
check(strpos($src, "pg_try_advisory_lock(' . self::LOCK_KEY") !== false && strpos($src, 'return self::tick_locked(') !== false,
	'a tick decides only while it holds the lock');
// Every advisory lock key in the plugin is its own: FleetSecurityRunner shared
// ApplyAndPublish's, so each skipped its pass while the other held it.
$keys = array();
foreach (glob(__DIR__ . '/../includes/*.php') as $f) {
	preg_match_all('/const (\w*LOCK_KEY) = (\d+);/', file_get_contents($f), $m, PREG_SET_ORDER);
	foreach ($m as $k) { $keys[$k[2]][] = basename($f) . ' ' . $k[1]; }
}
$shared = array_filter($keys, function ($v) { return count($v) > 1; });
check(count($keys) > 3 && !$shared, 'no two lock constants in server_manager share a key', json_encode($shared));

harness_finish();
