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

harness_finish();
