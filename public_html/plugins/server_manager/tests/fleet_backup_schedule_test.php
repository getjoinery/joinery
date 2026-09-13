<?php
/** @joinery-test
 * name: fleet_backup_schedule
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * When this management node backs each node up, and what it prunes.
 *
 * Both halves are decisions that cannot be un-made. A due-calculation that
 * drifts either backs a node up every fifteen minutes or never; a retention pass
 * that groups objects wrongly deletes a chain's full out from under its
 * incrementals, which is not a smaller backup but no backup — and it looks like
 * a restore point right up until someone needs it.
 *
 * Both are pure, so both are asserted directly rather than inferred from a run.
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/FleetBackupPolicy.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/FleetBackupRetention.php'));

/** A stand-in for a job row: is_due only ever asks it when it was created. */
class FbsJob {
	private $created;
	public function __construct($created) { $this->created = $created; }
	public function get($field) { return ($field === 'mjb_create_time') ? $this->created : null; }
}

/** A stand-in for a node row: the policy readers only ever ask it for the policy. */
class FbsNode {
	private $policy;
	public function __construct($policy) { $this->policy = $policy; }
	public function get($field) { return ($field === 'mgn_backup_policy') ? $this->policy : null; }
}

/** A stand-in with arbitrary fields, for the health check. */
class FbsHealthNode {
	private $fields;
	public function __construct(array $fields) { $this->fields = $fields; }
	public function get($field) { return $this->fields[$field] ?? null; }
}

$policy = array_merge(FleetBackupPolicy::DEFAULTS, array(
	'window_start' => '03:00', 'window_minutes' => 120, 'frequency' => 'daily'));

// ── Spread ──────────────────────────────────────────────────────────────────
section('Nodes are spread across the window, not stacked on its first minute');

$slots = array();
foreach (array('alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot', 'golf', 'hotel') as $slug) {
	$slots[$slug] = FleetBackupPolicy::slot_minute($policy, $slug);
}

$start = 3 * 60;
$all_in_window = true;
foreach ($slots as $slug => $minute) {
	if ($minute < $start || $minute >= $start + 120) { $all_in_window = false; }
}
check($all_in_window, 'every node lands inside the configured window', implode(',', $slots));
check(count(array_unique($slots)) > 1,
	'and they do not all get the same minute', implode(',', $slots));

// Stable, because "is it due yet" is meaningless against a value that moves.
check(FleetBackupPolicy::slot_minute($policy, 'alpha') === $slots['alpha'],
	'a node\'s slot is the same answer every time it is asked');

check(strpos(FleetBackupPolicy::slot_time($policy, 'alpha'), 'UTC') !== false,
	'the slot reads as a UTC time for a person', FleetBackupPolicy::slot_time($policy, 'alpha'));

// A window crossing midnight must still produce a real minute of the day.
$wrap = array_merge($policy, array('window_start' => '23:30', 'window_minutes' => 120));
$m = FleetBackupPolicy::slot_minute($wrap, 'alpha');
check($m >= 0 && $m < 1440, 'a window crossing midnight still yields a valid minute', (string)$m);

// ── Due ─────────────────────────────────────────────────────────────────────
section('Due means the slot has passed and nothing has run since');

$slot   = FleetBackupPolicy::slot_minute($policy, 'alpha');
$day    = '2026-08-06';
$before = gmdate('Y-m-d H:i:s', strtotime($day . ' UTC') + (($slot - 5) * 60));
$after  = gmdate('Y-m-d H:i:s', strtotime($day . ' UTC') + (($slot + 5) * 60));

check(!FleetBackupPolicy::is_due($policy, 'alpha', null, $before),
	'before the slot, not due');
check(FleetBackupPolicy::is_due($policy, 'alpha', null, $after),
	'after the slot with no run today, due');

$ran_today = new FbsJob(gmdate('Y-m-d H:i:s', strtotime($day . ' UTC') + ($slot * 60) + 60));
check(!FleetBackupPolicy::is_due($policy, 'alpha', $ran_today, $after),
	'a run started after the slot means not due again today');

$ran_yesterday = new FbsJob(gmdate('Y-m-d H:i:s', strtotime($day . ' UTC') - 3600));
check(FleetBackupPolicy::is_due($policy, 'alpha', $ran_yesterday, $after),
	'yesterday\'s run does not satisfy today\'s slot');

// Keyed on the attempt, not the outcome: retrying a failing node every fifteen
// minutes until its next slot would hammer a node that is already unwell.
check(!FleetBackupPolicy::is_due($policy, 'alpha', $ran_today, $after),
	'a failed attempt still counts as attempted until the next slot');

$weekly = array_merge($policy, array('frequency' => 'weekly', 'day_of_week' => 3));
check((int)gmdate('w', strtotime($after . ' UTC')) !== 3
	? !FleetBackupPolicy::is_due($weekly, 'alpha', null, $after)
	: FleetBackupPolicy::is_due($weekly, 'alpha', null, $after),
	'a weekly policy only fires on its weekday');

// ── Policy resolution ───────────────────────────────────────────────────────
section('A node with no policy of its own is backed up anyway');

$defaults = FleetBackupPolicy::fleet_defaults();
check($defaults['enabled'] === true,
	'the fleet default is ENABLED — a node nobody decided about must not fall through');
check($defaults['mode'] === 'chain' || $defaults['mode'] === 'full',
	'and mode always resolves to something real', $defaults['mode']);
check($defaults['keep'] >= 1, 'retention never resolves to zero', (string)$defaults['keep']);

check(FleetBackupPolicy::max_concurrent() >= 1,
	'the concurrency cap is always at least one, so the fleet never deadlocks');

// ── The three stored positions ──────────────────────────────────────────────
section('A node\'s stored policy is one of three positions');

check(FleetBackupPolicy::stored_mode(new FbsNode(null)) === 'default',
	'nothing stored means the node follows the fleet default');
check(FleetBackupPolicy::stored_mode(new FbsNode('')) === 'default',
	'and so does an empty string from an older row');
check(FleetBackupPolicy::stored_mode(new FbsNode(array('enabled' => false))) === 'off',
	'a stored enabled=false is OFF — somebody\'s decision, kept as one');
check(FleetBackupPolicy::stored_mode(new FbsNode(json_encode(array_merge(
		FleetBackupPolicy::DEFAULTS, array('keep' => 9))))) === 'custom',
	'a stored schedule of its own is custom, whether it arrives decoded or as json');

$off = FleetBackupPolicy::for_node(new FbsNode(json_encode(array('enabled' => false))));
check($off['enabled'] === false,
	'and for_node honours the stored OFF over the enabled fleet default');

section('A posted custom schedule normalizes to a full, valid policy');

$p = FleetBackupPolicy::from_form(array('policy_schedule' => '3',
	'policy_window_start' => '04:30', 'policy_window_minutes' => '60',
	'policy_mode' => 'full', 'policy_keep' => '6', 'policy_full_interval_days' => '14'));
check($p['enabled'] === true && $p['frequency'] === 'weekly' && $p['day_of_week'] === 3,
	'one schedule field carries both frequency and weekday — they are one decision');
check($p['window_start'] === '04:30' && $p['window_minutes'] === 60
	&& $p['mode'] === 'full' && $p['keep'] === 6 && $p['full_interval_days'] === 14,
	'every field the operator saw and saved is stored as chosen');

$p = FleetBackupPolicy::from_form(array('policy_schedule' => 'daily',
	'policy_window_start' => 'garbage', 'policy_keep' => '0'));
check($p['frequency'] === 'daily', 'daily is daily');
check($p['window_start'] === FleetBackupPolicy::DEFAULTS['window_start'],
	'an unparseable window start falls back to the shipped default', $p['window_start']);
check($p['keep'] === 1, 'retention never normalizes to zero', (string)$p['keep']);

// ── Retention grouping ──────────────────────────────────────────────────────
section('A chain is one restore point, kept or deleted whole');

$base = 'joinery-backups/demo/manager/';
$objects = array();
foreach (array('chain-20260801_000000', 'chain-20260802_000000', 'chain-20260803_000000') as $chain) {
	$objects[] = array('key' => $base . $chain . '/manifest.json');
	$objects[] = array('key' => $base . $chain . '/files-0000.tar.gz.enc');
	$objects[] = array('key' => $base . $chain . '/db-0000.sql.gz.enc');
	$objects[] = array('key' => $base . $chain . '/files-0001.tar.gz.enc');
}

$groups = FleetBackupRetention::group($objects, $base);
check(count($groups) === 3, 'three chains are three restore points', (string)count($groups));

$names = array_keys($groups);
check($names[0] === 'chain-20260803_000000',
	'newest first, so keeping the first N keeps the newest', $names[0]);
check(count($groups['chain-20260801_000000']['keys']) === 4,
	'a chain carries every object it owns into one group',
	(string)count($groups['chain-20260801_000000']['keys']));

section('A standalone archive travels with its envelope');

$objects = array(
	array('key' => $base . 'demo-20260801_000000.tar.gz.enc'),
	array('key' => $base . 'demo-20260801_000000.tar.gz.enc.keys.json'),
	array('key' => $base . 'demo-20260802_000000.tar.gz.enc'),
	array('key' => $base . 'demo-20260802_000000.tar.gz.enc.keys.json'),
);
$groups = FleetBackupRetention::group($objects, $base);
check(count($groups) === 2, 'two archives are two restore points', (string)count($groups));
foreach ($groups as $name => $group) {
	check(count($group['keys']) === 2,
		$name . ' keeps its envelope with it — an archive without one is noise',
		(string)count($group['keys']));
}

section('Restore points order by age, never by family');

// Chains are chain-<stamp>; standalone archives are <slug>-<stamp>. A shelf
// holds both after a mode switch, and a name sort would order it by prefix —
// old fulls hogging the keep slots forever while newer chains get pruned.
$objects = array(
	array('key' => $base . 'zzz-20260801_000000.tar.gz.enc'),
	array('key' => $base . 'zzz-20260801_000000.tar.gz.enc.keys.json'),
	array('key' => $base . 'chain-20260803_000000/manifest.json'),
	array('key' => $base . 'aaa-20260804_000000.tar.gz.enc'),
	array('key' => $base . 'chain-20260802_000000/manifest.json'),
);
$names = array_keys(FleetBackupRetention::group($objects, $base));
check($names === array(
		'aaa-20260804_000000.tar.gz.enc',
		'chain-20260803_000000',
		'chain-20260802_000000',
		'zzz-20260801_000000.tar.gz.enc',
	),
	'a mixed shelf sorts newest first by timestamp, whatever each name starts with',
	implode(' > ', $names));

section('Nothing outside this node\'s own shelf is ever grouped');

$groups = FleetBackupRetention::group(array(
	array('key' => $base . 'chain-20260801_000000/manifest.json'),
	array('key' => 'joinery-backups/demo/site/chain-20260801_000000/manifest.json'),
	array('key' => 'joinery-backups/othernode/manager/chain-20260801_000000/manifest.json'),
), $base);
check(count($groups) === 1,
	'the site profile\'s shelf and another node\'s shelf are both out of scope',
	(string)count($groups));

// ── The bucket's testimony ──────────────────────────────────────────────────
section('The listing says when something last actually landed');

check(FleetBackupRetention::newest_object_time(array()) === '',
	'an empty shelf has no newest write');

// Write time, not name stamp: a chain directory keeps its start stamp for its
// whole life, but every run that extends it writes new objects.
$when = FleetBackupRetention::newest_object_time(array(
	array('key' => 'a', 'last_modified' => '2026-08-01T03:10:00.000Z'),
	array('key' => 'b', 'last_modified' => '2026-08-05T03:12:30.000Z'),
	array('key' => 'c', 'last_modified' => '2026-08-03T03:11:00.000Z'),
));
check($when === '2026-08-05 03:12:30',
	'the newest write wins, as a UTC timestamp', $when);

section('A node claiming success while nothing lands is caught');

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeMonitorHealth.php'));

$now = time();
$run = gmdate('Y-m-d H:i:s', $now - 7200);   // claimed successful run, 2h ago

$health_node = function (array $extra) use ($run) {
	return new FbsHealthNode(array_merge(array(
		'mgn_last_backup_time'    => $run,
		'mgn_last_backup_outcome' => 'success',
		'mgn_slug'                => 'demo',
	), $extra));
};

// Honest node: the shelf was listed after the run and holds a write from it.
$h = NodeMonitorHealth::fleet_backup_health($health_node(array(
	'mgn_backup_shelf_checked_time' => gmdate('Y-m-d H:i:s', $now - 600),
	'mgn_backup_shelf_newest_time'  => gmdate('Y-m-d H:i:s', $now - 7000),
)), $policy);
check(!$h['is_problem'], 'a run that landed is healthy', $h['label']);

// The lie: success reported, shelf listed since, nothing arrived.
$h = NodeMonitorHealth::fleet_backup_health($health_node(array(
	'mgn_backup_shelf_checked_time' => gmdate('Y-m-d H:i:s', $now - 600),
	'mgn_backup_shelf_newest_time'  => gmdate('Y-m-d H:i:s', $now - 200000),
)), $policy);
check($h['is_problem'] && $h['label'] === 'Backups are not landing',
	'a claimed success with no new object on the shelf is a problem', $h['label']);

$h = NodeMonitorHealth::fleet_backup_health($health_node(array(
	'mgn_backup_shelf_checked_time' => gmdate('Y-m-d H:i:s', $now - 600),
	'mgn_backup_shelf_newest_time'  => null,
)), $policy);
check($h['is_problem'] && $h['label'] === 'Backups are not landing',
	'an empty shelf listed after a claimed success is the same lie', $h['label']);

// No listing since the run: no verdict either way. The check only ever speaks
// from evidence gathered AFTER the claim it is judging.
$h = NodeMonitorHealth::fleet_backup_health($health_node(array(
	'mgn_backup_shelf_checked_time' => gmdate('Y-m-d H:i:s', $now - 90000),
	'mgn_backup_shelf_newest_time'  => null,
)), $policy);
check(!$h['is_problem'],
	'a shelf not listed since the claimed run casts no verdict', $h['label']);

section('A failing node is reported from its run history, not just its stamp');

// Newest first, as backup_runs_from_here() returns them. Two refusals, a skip
// in between (stepped over), then the run that worked.
$rows = array(
	array('id' => 40, 'outcome' => 'error',   'time' => '2026-09-02 04:45:12', 'message' => 'Refused by the node: tree manifest signature does not verify'),
	array('id' => 39, 'outcome' => 'skipped', 'time' => '2026-09-01 12:00:00', 'message' => ''),
	array('id' => 38, 'outcome' => 'error',   'time' => '2026-08-31 04:45:20', 'message' => 'Refused by the node: tree manifest signature does not verify'),
	array('id' => 37, 'outcome' => 'success', 'time' => '2026-08-30 23:20:23', 'message' => 'Backed up'),
	array('id' => 36, 'outcome' => 'error',   'time' => '2026-08-29 04:45:00', 'message' => 'older failure, before the success'),
);
$sum = NodeMonitorHealth::backup_run_summary($rows);
check($sum['failures'] === 2, 'failures are counted back to the last success only', $sum['failures']);
check($sum['since'] === '2026-08-31 04:45:20', 'since is the oldest failure in the streak', $sum['since']);
check($sum['last_success'] === '2026-08-30 23:20:23', 'the last success is named', $sum['last_success']);
check($sum['job_id'] === 40 && strpos($sum['reason'], 'Refused by the node') === 0,
	'the reason and the job come from the newest failure', var_export($sum, true));

// A job the sweep has not read yet has no outcome and casts no vote.
$sum = NodeMonitorHealth::backup_run_summary(array(
	array('id' => 41, 'outcome' => '', 'time' => '2026-09-03 04:45:00', 'message' => ''),
	array('id' => 40, 'outcome' => 'error', 'time' => '2026-09-02 04:45:12', 'message' => 'x'),
));
check($sum['failures'] === 1 && $sum['job_id'] === 40, 'an unread job is stepped over', var_export($sum, true));

$sum = NodeMonitorHealth::backup_run_summary(array());
check($sum['failures'] === 0 && $sum['last_success'] === null && $sum['reason'] === '',
	'no history summarises to nothing, not to an error');

// Without a job history to hand (the stand-in has no key), the failed stamp
// is still an alarm and still says when.
$h = NodeMonitorHealth::fleet_backup_health($health_node(array(
	'mgn_last_backup_outcome' => 'failed',
)), $policy);
check($h['is_problem'] && $h['label'] === 'Last backup failed', 'a failed stamp is a problem', $h['label']);
check(strpos($h['detail'], 'failed (') !== false && strpos($h['detail'], 'ago)') !== false,
	'and the detail says how long ago', $h['detail']);

section('A management node\'s copy is recoverable here only when this site\'s key opens it');

$fpr_a = str_repeat('a', 64);
$fpr_b = str_repeat('b', 64);
check(NodeMonitorHealth::copy_opens_here(BackupProfile::SITE, '', ''),
	'the site\'s own run always counts');
check(NodeMonitorHealth::copy_opens_here(BackupProfile::MANAGER, $fpr_a, $fpr_a),
	'a management node\'s copy sealed to the key proven here counts');
check(!NodeMonitorHealth::copy_opens_here(BackupProfile::MANAGER, $fpr_b, $fpr_a),
	'one sealed to another party\'s key does not');
check(!NodeMonitorHealth::copy_opens_here(BackupProfile::MANAGER, $fpr_a, ''),
	'nothing counts while no key is proven here');
check(!NodeMonitorHealth::copy_opens_here(BackupProfile::MANAGER, '', $fpr_a),
	'a copy that recorded no recipient does not');

// ── Verification ────────────────────────────────────────────────────────────
section('A verify is due every verify_every_days, of a backup newer than the last verify');

require_once(PathHelper::getIncludePath('includes/BackupVerifier.php'));
$vnow = '2026-09-13 12:00:00';
$vnode = function (array $extra) {
	return new FbsHealthNode(array_merge(array(
		'mgn_slug'                => 'demo',
		'mgn_last_backup_time'    => '2026-09-13 04:45:00',
		'mgn_last_backup_outcome' => 'success',
	), $extra));
};
$vpolicy = array_merge($policy, array('verify_every_days' => 30));
check(FleetBackupPolicy::DEFAULTS['verify_every_days'] === 30, 'the shipped default is every 30 days');
check(FleetBackupPolicy::fleet_defaults()['verify_every_days'] === 30, 'and the fleet default reads it from the declared setting');

check(FleetBackupPolicy::is_verify_due($vpolicy, $vnode(array()), $vnow),
	'never verified and the first backup is hours old: due — the backup stamp comes from a completed upload, and on a daily node the next backup would always beat a settling wait');
check(FleetBackupPolicy::is_verify_due($vpolicy, $vnode(array('mgn_last_backup_time' => '2026-09-11 04:45:00')), $vnow),
	'never verified and the backup is older than a day: due');
check(!FleetBackupPolicy::is_verify_due($vpolicy, $vnode(array(
		'mgn_last_backup_time' => '2026-09-11 04:45:00', 'mgn_last_backup_outcome' => 'failed')), $vnow),
	'no successful backup: never due — there is nothing to prove');
check(!FleetBackupPolicy::is_verify_due($vpolicy, $vnode(array('mgn_last_backup_time' => null)), $vnow),
	'no backup at all: never due');
check(!FleetBackupPolicy::is_verify_due($vpolicy, $vnode(array('mgn_backup_verify_time' => '2026-08-15 12:00:00')), $vnow),
	'verified 29 days ago: not due');
check(FleetBackupPolicy::is_verify_due($vpolicy, $vnode(array('mgn_backup_verify_time' => '2026-08-13 11:00:00')), $vnow),
	'verified 31 days ago and backed up since: due');
check(!FleetBackupPolicy::is_verify_due($vpolicy, $vnode(array(
		'mgn_backup_verify_time' => '2026-08-13 11:00:00', 'mgn_last_backup_time' => '2026-08-10 04:45:00')), $vnow),
	'verified 31 days ago but no backup since: not due — the last verify still speaks for the newest backup');
check(!FleetBackupPolicy::is_verify_due(array_merge($vpolicy, array('verify_every_days' => 0)),
		$vnode(array('mgn_last_backup_time' => '2026-08-01 04:45:00')), $vnow),
	'0 means never, however old the backup');
check(FleetBackupPolicy::is_verify_due($vpolicy, $vnode(array(
		'mgn_backup_verify_time' => '2026-08-13 11:00:00', 'mgn_backup_verify_outcome' => 'fail')), $vnow),
	'a failed verify counts as a verify: due again only after the interval, never retried on the next tick');
check(!FleetBackupPolicy::is_verify_due($vpolicy, $vnode(array(
		'mgn_backup_verify_time' => '2026-09-12 11:00:00', 'mgn_backup_verify_outcome' => 'fail')), $vnow),
	'and a verify that failed yesterday is not re-run today');

// A verify that fails ON THE NODE exits 1, comes back as a failed job, and a
// failed job is never folded into the node's columns: the stamp stays empty.
// The attempt is the job, so the rule reads the job.
check(!FleetBackupPolicy::is_verify_due($vpolicy, $vnode(array('mgn_last_backup_time' => '2026-09-11 04:45:00')), $vnow,
		new FbsJob('2026-09-12 05:00:00')),
	'never stamped, but a verify job was created yesterday (it failed): not due — the attempt counts, whatever the job\'s status');
check(!FleetBackupPolicy::is_verify_due($vpolicy, $vnode(array('mgn_last_backup_time' => '2026-09-13 04:45:00')), $vnow,
		new FbsJob('2026-09-12 05:00:00')),
	'and a backup taken since that failed attempt does not bring it forward: due again only after the interval');
check(FleetBackupPolicy::is_verify_due($vpolicy, $vnode(array('mgn_last_backup_time' => '2026-09-13 04:45:00')), $vnow,
		new FbsJob('2026-08-13 05:00:00')),
	'a failed attempt 31 days ago with a backup since: due');
check(!FleetBackupPolicy::is_verify_due($vpolicy, $vnode(array(
		'mgn_backup_verify_time' => '2026-08-01 12:00:00', 'mgn_last_backup_time' => '2026-09-13 04:45:00')), $vnow,
		new FbsJob('2026-09-12 05:00:00')),
	'an old stamp and a newer failed job: the newer of the two is the last attempt');
check(FleetBackupPolicy::last_verify_attempt($vnode(array('mgn_backup_verify_time' => '2026-09-12 12:00:00')), new FbsJob('2026-09-12 05:00:00'))
		=== strtotime('2026-09-12 12:00:00 UTC'),
	'a stamp newer than the job (the job completed and was folded) is the last attempt');
check(FleetBackupPolicy::last_verify_attempt($vnode(array()), null) === false, 'neither: never attempted');

$vform = FleetBackupPolicy::from_form(array('policy_schedule' => 'daily', 'policy_verify_every_days' => '14'));
check($vform['verify_every_days'] === 14, 'the policy editor\'s field is read', (string)$vform['verify_every_days']);
$vform = FleetBackupPolicy::from_form(array('policy_schedule' => 'daily', 'policy_verify_every_days' => '-3'));
check($vform['verify_every_days'] === 0, 'a negative interval normalizes to never');

section('The card says whether a backup is verified restorable, in the page\'s words');

$hn = function (array $extra) use ($now) {
	return new FbsHealthNode(array_merge(array(
		'mgn_slug'                      => 'demo',
		'mgn_last_backup_time'          => gmdate('Y-m-d H:i:s', $now - 7200),
		'mgn_last_backup_outcome'       => 'success',
		'mgn_backup_shelf_checked_time' => gmdate('Y-m-d H:i:s', $now - 600),
		'mgn_backup_shelf_newest_time'  => gmdate('Y-m-d H:i:s', $now - 7000),
		'mgn_create_time'               => gmdate('Y-m-d H:i:s', $now - 10 * 86400),
	), $extra));
};
$h = NodeMonitorHealth::fleet_backup_health($hn(array()), $vpolicy);
check(!$h['is_problem'] && $h['label'] === 'Backed up' && strpos($h['detail'], 'Not yet verified restorable') !== false,
	'never verified, ten days in: information on a healthy card, not a problem', $h['detail']);

// The first backup is read from the job history; here it is handed in.
$h = NodeMonitorHealth::verify_state($hn(array()), $vpolicy, $now - 50 * 86400);
check($h['is_problem'] && $h['label'] === 'Backups never verified restorable',
	'never verified, 50 days after the first backup: a problem', $h['label']);
check(strpos($h['detail'], 'opened and read') !== false, 'in the page\'s words', $h['detail']);
$h = NodeMonitorHealth::verify_state($hn(array()), $vpolicy, $now - 40 * 86400);
check(!$h['is_problem'], 'and 40 days after: still information');

$h = NodeMonitorHealth::fleet_backup_health($hn(array(
	'mgn_backup_verify_time' => gmdate('Y-m-d H:i:s', $now - 5 * 86400), 'mgn_backup_verify_level' => 2,
	'mgn_backup_verify_outcome' => 'pass',
	'mgn_backup_verify_message' => 'Opened and read the backup of 2026-09-08 04:45 UTC: 3 archives, 717 MB, 1,842 files.')), $vpolicy);
check(!$h['is_problem'] && strpos($h['detail'], 'Verified restorable 5 days ago (opened and read)') !== false,
	'a pass five days ago: healthy, dated, named by level', $h['detail']);

$h = NodeMonitorHealth::fleet_backup_health($hn(array(
	'mgn_backup_verify_time' => gmdate('Y-m-d H:i:s', $now - 5 * 86400), 'mgn_backup_verify_level' => 3,
	'mgn_backup_verify_outcome' => 'pass', 'mgn_backup_verify_message' => 'Rehearsed a restore of the backup of 2026-09-08 04:45 UTC: 3 archives, 717 MB, 1,842 files, 214 tables, 12 users.')), $vpolicy);
check(!$h['is_problem'] && strpos($h['detail'], '(rehearsed)') !== false, 'a rehearsal is named as one', $h['detail']);

$h = NodeMonitorHealth::fleet_backup_health($hn(array(
	'mgn_backup_verify_time' => gmdate('Y-m-d H:i:s', $now - 5 * 86400), 'mgn_backup_verify_level' => 2,
	'mgn_backup_verify_outcome' => 'fail',
	'mgn_backup_verify_message' => 'Verification of the backup of 2026-09-08 04:45 UTC failed: files-0001.tar.gz.enc does not match its recorded hash.')), $vpolicy);
check($h['is_problem'] && $h['label'] === 'Backup verification failed', 'a failed verify is a problem', $h['label']);
check(strpos($h['detail'], 'The node said: Verification of the backup of 2026-09-08 04:45 UTC failed: files-0001') !== false
	&& strpos($h['detail'], 'Nothing is retried automatically') !== false,
	'with the node\'s own reason, and no automatic retry', $h['detail']);

$h = NodeMonitorHealth::fleet_backup_health($hn(array(
	'mgn_backup_verify_time' => gmdate('Y-m-d H:i:s', $now - 61 * 86400), 'mgn_backup_verify_level' => 2,
	'mgn_backup_verify_outcome' => 'pass')), $vpolicy);
check($h['is_problem'] && $h['label'] === 'Backup verification is stale' && strpos($h['detail'], '60 days') !== false,
	'a pass older than 60 days is stale', $h['label'] . ' — ' . $h['detail']);
$h = NodeMonitorHealth::fleet_backup_health($hn(array(
	'mgn_backup_verify_time' => gmdate('Y-m-d H:i:s', $now - 59 * 86400), 'mgn_backup_verify_level' => 2,
	'mgn_backup_verify_outcome' => 'pass')), $vpolicy);
check(!$h['is_problem'], 'and 59 days is not');

$h = NodeMonitorHealth::fleet_backup_health($hn(array(
	'mgn_backup_verify_time' => gmdate('Y-m-d H:i:s', $now - 5 * 86400), 'mgn_backup_verify_level' => 2,
	'mgn_backup_verify_outcome' => 'pass',
	'mgn_backup_verify_message' => 'Could not verify the backup of 2026-09-12 04:45 UTC: needs 2.4 GB free, has 858.3 MB.')), $vpolicy);
check(!$h['is_problem'] && strpos($h['detail'], 'Since then: Could not verify') !== false && strpos($h['detail'], 'needs 2.4 GB free, has 858.3 MB') !== false,
	'a skip after a pass rides beside it with both numbers', $h['detail']);

$h = NodeMonitorHealth::fleet_backup_health($hn(array(
	'mgn_backup_shelf_problem' => 'the backup set begun 2026-09-12 04:45 UTC names files-0001.tar.gz.enc in its manifest but it is not on the shelf')), $vpolicy);
check($h['is_problem'] && $h['label'] === 'A backup on the shelf is incomplete', 'a shelf problem is a problem', $h['label']);
check(strpos($h['detail'], 'names files-0001.tar.gz.enc in its manifest but it is not on the shelf') !== false,
	'with the pass\'s words', $h['detail']);

$h = NodeMonitorHealth::fleet_backup_health($hn(array()), array_merge($vpolicy, array('verify_every_days' => 0)));
check(!$h['is_problem'] && strpos($h['detail'], 'switched off') !== false,
	'verification switched off is a decision, said as one', $h['detail']);

foreach (array(
	NodeMonitorHealth::fleet_backup_health($hn(array()), $vpolicy),
	NodeMonitorHealth::fleet_backup_health($hn(array('mgn_backup_shelf_problem' => 'x')), $vpolicy),
	NodeMonitorHealth::fleet_backup_health($hn(array('mgn_backup_verify_time' => gmdate('Y-m-d H:i:s', $now - 5 * 86400),
		'mgn_backup_verify_level' => 2, 'mgn_backup_verify_outcome' => 'fail')), $vpolicy),
) as $h) {
	check(stripos($h['label'] . ' ' . $h['detail'], 'chain') === false && stripos($h['label'] . ' ' . $h['detail'], 'seq') === false
		&& stripos($h['label'] . ' ' . $h['detail'], 'restore point') === false,
		'nobody reads "chain", "seq" or "restore point" on the card', $h['label'] . ' — ' . $h['detail']);
}

section('The shelf check: every artifact a manifest names, at its size, and an envelope');

$sm = array(
	'version' => 1, 'chain_id' => 'chain-20260912_044520', 'created' => '2026-09-12T04:45:20Z',
	'envelope' => array('version' => 1, 'recipients' => array()),
	'runs' => array(
		array('seq' => 0, 'level' => 0, 'artifacts' => array(
			'files' => array('name' => 'files-0000.tar.gz.enc', 'bytes' => 1000),
			'db'    => array('name' => 'db-0000.sql.gz.enc', 'bytes' => 100))),
		array('seq' => 1, 'level' => 1, 'artifacts' => array(
			'files' => array('name' => 'files-0001.tar.gz.enc', 'bytes' => 200),
			'db'    => array('name' => 'db-0001.sql.gz.enc', 'bytes' => 110))),
	),
);
$whole = array('manifest.json' => 900, 'files-0000.tar.gz.enc' => 1000, 'db-0000.sql.gz.enc' => 100,
	'files-0001.tar.gz.enc' => 200, 'db-0001.sql.gz.enc' => 110);
check(FleetBackupRetention::compare_manifest($sm, $whole) === '', 'a whole set has nothing to say');

$short = $whole; $short['files-0001.tar.gz.enc'] = 150;
$p = FleetBackupRetention::compare_manifest($sm, $short);
check($p === 'the backup set begun 2026-09-12 04:45 UTC holds files-0001.tar.gz.enc at 150 bytes on the shelf where its manifest records 200',
	'an artifact short by bytes is named with both numbers', $p);

$missing = $whole; unset($missing['db-0001.sql.gz.enc']);
$p = FleetBackupRetention::compare_manifest($sm, $missing);
check($p === 'the backup set begun 2026-09-12 04:45 UTC names db-0001.sql.gz.enc in its manifest but it is not on the shelf',
	'a missing artifact is named', $p);

$no_env = $sm; unset($no_env['envelope']);
$p = FleetBackupRetention::compare_manifest($no_env, $whole);
check(strpos($p, 'has no envelope in its manifest, so no key can be recovered') !== false,
	'a manifest with no envelope is a backup nobody can open', $p);

$unsized = $whole; $unsized['files-0000.tar.gz.enc'] = null;
check(FleetBackupRetention::compare_manifest($sm, $unsized) === '',
	'an object the provider reported no size for is not called short');

foreach (array($p, FleetBackupRetention::compare_manifest($sm, $short)) as $w) {
	check(stripos($w, 'chain') === false, 'nobody reads "chain" in a shelf problem', $w);
}

// ── The whole shelf: a manifest that cannot be read is not an incomplete backup ──
section('A manifest the shelf check could not read is this pass\'s problem, not the backup\'s');

$base = 'joinery-backups/demo/manager';
$listing = array();
foreach ($whole as $name => $bytes) {
	$listing[] = array('key' => $base . '/chain-20260912_044500/' . $name, 'size' => $bytes);
}
$older = array('chain_id' => 'chain-20260905_044500', 'envelope' => array('version' => 1),
	'runs' => array(array('seq' => 0, 'level' => 0, 'artifacts' => array(
		'files' => array('name' => 'files-0000.tar.gz.enc', 'bytes' => 700)))));
$listing[] = array('key' => $base . '/chain-20260905_044500/manifest.json', 'size' => 500);
$listing[] = array('key' => $base . '/chain-20260905_044500/files-0000.tar.gz.enc', 'size' => 700);
$creds = array('access_key' => 'k', 'secret_key' => 's', 'region' => 'us-east-1', 'endpoint' => 'https://shelf.invalid');

$reader = function (array $answers) {
	return function ($key) use ($answers) {
		foreach ($answers as $dir => $answer) {
			if (strpos($key, '/' . $dir . '/') !== false) {
				if ($answer instanceof Exception) { throw $answer; }
				return $answer;
			}
		}
		throw new Exception('unexpected key ' . $key);
	};
};

$r = FleetBackupRetention::check_shelf($listing, $base, $creds, 'bucket',
	$reader(array('chain-20260912_044500' => $sm, 'chain-20260905_044500' => $older)));
check(is_array($r) && $r['problem'] === '' && $r['unread'] === '', 'both manifests read, both whole: nothing to say', json_encode($r));

$r = FleetBackupRetention::check_shelf($listing, $base, $creds, 'bucket',
	$reader(array('chain-20260912_044500' => $sm, 'chain-20260905_044500' => new Exception('curl failed: Could not resolve host: shelf.invalid'))));
check(is_array($r) && $r['problem'] === '', 'one manifest unreachable: no backup is called incomplete', json_encode($r));
check(is_array($r) && $r['unread'] === 'the manifest of the backup set begun 2026-09-05 04:45 UTC could not be read (curl failed: Could not resolve host: shelf.invalid)',
	'the pass is told which manifest it could not read, and why', is_array($r) ? $r['unread'] : '');

$short_listing = $listing;
foreach ($short_listing as &$o) { if (basename($o['key']) === 'files-0001.tar.gz.enc') { $o['size'] = 150; } }
unset($o);
$r = FleetBackupRetention::check_shelf($short_listing, $base, $creds, 'bucket',
	$reader(array('chain-20260912_044500' => $sm, 'chain-20260905_044500' => new Exception('HTTP 503'))));
check(is_array($r) && strpos($r['problem'], 'holds files-0001.tar.gz.enc at 150 bytes') !== false
	&& strpos($r['unread'], 'begun 2026-09-05 04:45 UTC could not be read (HTTP 503)') !== false,
	'a real shortfall and an unread manifest in one pass are two answers, not one line', json_encode($r));
check(is_array($r) && stripos($r['problem'], 'could not be read') === false,
	'and "could not be read" never appears in what is stamped on the card');

harness_finish();
