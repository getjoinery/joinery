<?php
/** @joinery-test
 * name: node_status_fold
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Node status is folded, not overwritten — and every key says who measured it.
 *
 * Three writers keep mgn_last_status_data: a check_status job (over the agent
 * channel, an API envelope, or SSH text), the dashboard's synchronous API
 * refresh, and the recovery-key report. They do not measure the same things.
 * The agent reads /etc/letsencrypt, which the API — running as the web user —
 * cannot. The API calls BackupRecoveryKey::key_report(), which the agent has no
 * way to invoke. So whichever writer ran last used to decide what the node
 * appeared to know, and a dashboard page load could delete the certificate facts
 * an agent had just collected.
 *
 * Carrying a key forward fixes that and creates the next problem: a carried
 * value looks exactly as current as a measured one. On the fleet that showed up
 * as a health badge staying green off figures nobody had taken in months, and as
 * retired key names (databases, five ssl_*) sitting in all nine nodes' blobs
 * forever because carry-forward has no expiry.
 *
 * What is pinned here is the fold's arithmetic, which is pure: given a previous
 * blob and a set of measurements, what survives, what it is stamped with, and
 * what ages out. Also pinned is the plugin-installer output reader, because that
 * runner exits 0 on every path it takes — a job that asked it to run gets its
 * colour from reading the output or it gets a colour that means nothing.
 *
 * Run: php plugins/server_manager/tests/node_status_fold_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));

/** A UTC stamp $days in the past, in the format the fold and the DB both use. */
function nsf_days_ago($days) {
	return gmdate('Y-m-d H:i:s', time() - ($days * 86400));
}

// ---------------------------------------------------------------------------
section('A writer stamps only what it measured');

$fold = JobResultProcessor::fold_status_data(
	null,
	['disk_usage_percent' => 41, 'load_1m' => 0.2],
	'primitive',
	'2026-08-28 10:00:00');

check(($fold['disk_usage_percent'] ?? null) === 41,
	'a measurement lands in the folded blob');

$meta = JobResultProcessor::status_meta($fold);
check(($meta['disk_usage_percent']['t'] ?? null) === 'primitive'
	&& ($meta['disk_usage_percent']['m'] ?? null) === '2026-08-28 10:00:00',
	'each measured key records the transport that measured it and when',
	var_export($meta['disk_usage_percent'] ?? null, true));

check(($meta['_fold']['t'] ?? null) === 'primitive',
	'the fold itself is stamped, so a fold that measured nothing is still dated');

check(!isset($fold['status_carried_keys']),
	'nothing is carried when there was nothing to carry from');

// ---------------------------------------------------------------------------
section('A narrower transport cannot delete what a wider one measured');

// The case that broke the nightly fleet backup: backup_recovery_state comes only
// from the API/SSH path and RecoveryKeyFleet gates every backup on it, so a
// primitive status check that replaced the blob refused backups fleet-wide with
// "not known yet".
$wide = JobResultProcessor::fold_status_data(
	null,
	['backup_recovery_state' => 'proven', 'ssl_certificate_count' => 2, 'load_1m' => 1.0],
	'api',
	'2026-08-28 10:00:00');

$narrow = JobResultProcessor::fold_status_data(
	$wide,
	['load_1m' => 3.0],
	'primitive',
	'2026-08-28 11:00:00');

check(($narrow['backup_recovery_state'] ?? null) === 'proven',
	'a fact this transport cannot measure survives the fold');
check(($narrow['load_1m'] ?? null) === 3.0,
	'a fact it did measure is replaced, not merged');

$meta = JobResultProcessor::status_meta($narrow);
check(($meta['backup_recovery_state']['m'] ?? null) === '2026-08-28 10:00:00',
	'a carried key keeps the time it was ORIGINALLY measured',
	var_export($meta['backup_recovery_state'] ?? null, true));
check(($meta['backup_recovery_state']['t'] ?? null) === 'api',
	'a carried key keeps the transport that measured it, not the one that carried it');
check(($meta['load_1m']['m'] ?? null) === '2026-08-28 11:00:00',
	'a re-measured key is re-stamped');

check(in_array('backup_recovery_state', $narrow['status_carried_keys'] ?? [], true)
	&& !in_array('load_1m', $narrow['status_carried_keys'] ?? [], true),
	'status_carried_keys names the inherited keys and only those',
	var_export($narrow['status_carried_keys'] ?? null, true));

// The blob's own bookkeeping is never data, and never carried as data.
check(!in_array('status_meta', $narrow['status_carried_keys'] ?? [], true)
	&& !in_array('status_carried_keys', $narrow['status_carried_keys'] ?? [], true),
	'the fold does not carry its own bookkeeping keys as facts');

// ---------------------------------------------------------------------------
section('A key nothing measures is dropped rather than carried forever');

// `databases` was superseded by `db_list`; five ssl_* fields were replaced by
// the certificate enumeration. Every one of them sat in all nine nodes' blobs
// because carry-forward alone has no expiry.
$stale = [
	'databases'  => ['old', 'list'],
	'load_1m'    => 1.0,
	'status_meta' => [
		'databases' => ['t' => 'ssh', 'm' => nsf_days_ago(45)],
		'load_1m'   => ['t' => 'ssh', 'm' => nsf_days_ago(45)],
	],
];
$aged = JobResultProcessor::fold_status_data($stale, ['load_1m' => 2.0], 'primitive');

check(!array_key_exists('databases', $aged),
	'a key nothing has measured for 30 days is dropped');
check(!array_key_exists('databases', JobResultProcessor::status_meta($aged)),
	'its provenance entry goes with it, rather than outliving the key');
check(($aged['load_1m'] ?? null) === 2.0,
	'a key that IS still measured is unaffected by its previous age');

$fresh = [
	'databases'   => ['old', 'list'],
	'status_meta' => ['databases' => ['t' => 'ssh', 'm' => nsf_days_ago(20)]],
];
$kept = JobResultProcessor::fold_status_data($fresh, [], 'primitive');
check(array_key_exists('databases', $kept),
	'a key measured within the window is still carried');

// ---------------------------------------------------------------------------
section('A blob written before provenance existed is dated honestly');

// Every node in the fleet has one of these. We hold the values and we do not
// know when they were taken, and the whole point of the exercise is that a
// figure of unknown age must not read as a fresh one.
$legacy = ['disk_usage_percent' => 88, 'load_1m' => 9.0, 'postgres_status' => 'accepting connections'];
$first  = JobResultProcessor::fold_status_data($legacy, ['uptime' => '3 days'], 'primitive',
	'2026-08-28 10:00:00');

$meta = JobResultProcessor::status_meta($first);
check(($meta['disk_usage_percent']['t'] ?? null) === 'legacy',
	'an inherited pre-provenance key is marked legacy, not attributed to this fold');
// array_key_exists, not ??: the value being null is the whole assertion, and ??
// cannot tell a null value from a missing one.
check(array_key_exists('m', $meta['disk_usage_percent'] ?? [])
	&& $meta['disk_usage_percent']['m'] === null,
	'it carries no measurement time, because none is known',
	var_export($meta['disk_usage_percent'] ?? null, true));
check(($meta['disk_usage_percent']['s'] ?? null) === '2026-08-28 10:00:00',
	'it records when it was first seen, which starts its expiry clock');

check(in_array('disk_usage_percent', $first['status_carried_keys'] ?? [], true),
	'a legacy key reads as carried, not as measured by the fold that first saw it');

check(JobResultProcessor::status_last_measured($first) === '2026-08-28 10:00:00',
	'"last measured" reports the one key this fold actually measured',
	var_export(JobResultProcessor::status_last_measured($first), true));

check(JobResultProcessor::status_age_seconds($first,
		['disk_usage_percent', 'postgres_status', 'load_1m']) === null,
	'the age of an undateable figure is null — unknown, which is not fresh');

check(JobResultProcessor::status_figures_are_stale($first,
		['disk_usage_percent', 'postgres_status', 'load_1m']) === true,
	'and figures of unknown age are treated as too old to judge health by');

// A legacy key measured for real stops being legacy.
$measured = JobResultProcessor::fold_status_data($first, ['disk_usage_percent' => 12], 'api',
	'2026-08-28 12:00:00');
$meta = JobResultProcessor::status_meta($measured);
check(($meta['disk_usage_percent']['t'] ?? null) === 'api'
	&& ($meta['disk_usage_percent']['m'] ?? null) === '2026-08-28 12:00:00'
	&& !isset($meta['disk_usage_percent']['s']),
	'measuring a legacy key replaces the guess with a real stamp',
	var_export($meta['disk_usage_percent'] ?? null, true));

// ---------------------------------------------------------------------------
section('A conclusion is only as fresh as its stalest input');

// The health badge reads disk, postgres and load together. Being current on one
// of them is not being current, which is how a node that filled its disk a month
// after its last real status check stayed green the whole time.
$mixed = JobResultProcessor::fold_status_data(null,
	['disk_usage_percent' => 40, 'postgres_status' => 'accepting connections'],
	'api', nsf_days_ago(4));
$mixed = JobResultProcessor::fold_status_data($mixed, ['load_1m' => 0.1], 'api');

$age = JobResultProcessor::status_age_seconds($mixed,
	['disk_usage_percent', 'postgres_status', 'load_1m']);
check(is_int($age) && $age > 3 * 86400,
	'the age reported is the age of the OLDEST reading, not the newest',
	var_export($age, true));
check(JobResultProcessor::status_figures_are_stale($mixed,
		['disk_usage_percent', 'postgres_status', 'load_1m']) === true,
	'four-day-old figures do not colour a health badge');

$now_all = JobResultProcessor::fold_status_data(null,
	['disk_usage_percent' => 40, 'postgres_status' => 'accepting connections', 'load_1m' => 0.1],
	'api');
check(JobResultProcessor::status_figures_are_stale($now_all,
		['disk_usage_percent', 'postgres_status', 'load_1m']) === false,
	'figures measured just now are fresh enough to judge health by');

// A key that is simply absent is not a stale key — a DNS box that reports no
// postgres_status at all must not be greyed out for it.
check(JobResultProcessor::status_figures_are_stale($now_all,
		['disk_usage_percent', 'load_1m', 'postgres_status', 'never_reported']) === false,
	'a key this node does not report is skipped, not counted as stale');

// ---------------------------------------------------------------------------
section('Garbage in does not become facts');

check(JobResultProcessor::status_meta('not json at all') === [],
	'an unparseable blob yields no provenance rather than an error');
check(JobResultProcessor::status_last_measured(null) === null,
	'a node with no status data has no measurement time');
check(JobResultProcessor::status_age_seconds(null, ['load_1m']) === null,
	'and no age');

// 1.5 rather than 1.0 on purpose: json_encode(1.0) is "1", which decodes back as
// an int, and a test that tripped on that would be testing JSON, not the fold.
$from_string = JobResultProcessor::fold_status_data(
	json_encode(['load_1m' => 1.5]), ['uptime' => '1 day'], 'ssh');
check(($from_string['load_1m'] ?? null) === 1.5,
	'the previous blob may arrive as raw JSON, which is how the model stores it');

// A writer cannot smuggle its own provenance in through the measured set.
$forged = JobResultProcessor::fold_status_data(null,
	['load_1m' => 1.0, 'status_meta' => ['load_1m' => ['t' => 'forged', 'm' => '2030-01-01 00:00:00']]],
	'primitive', '2026-08-28 10:00:00');
$meta = JobResultProcessor::status_meta($forged);
check(($meta['load_1m']['t'] ?? null) === 'primitive'
	&& ($meta['load_1m']['m'] ?? null) === '2026-08-28 10:00:00',
	'provenance comes from the fold, never from the payload being folded',
	var_export($meta['load_1m'] ?? null, true));

// ---------------------------------------------------------------------------
section('The plugin-installer runner is read, not trusted for its exit code');

/** Call a private static on the processor. */
function nsf_call($method, array $args) {
	$m = new ReflectionMethod('JobResultProcessor', $method);
	$m->setAccessible(true);
	return $m->invokeArgs(null, $args);
}

check(in_array('run_plugin_installers', JobResultProcessor::processable_types(), true),
	'run_plugin_installers has a handler, so the dashboard sweep reconciles it');
check(in_array('restart_agent', JobResultProcessor::processable_types(), true),
	'restart_agent is listed deliberately rather than missing by omission');

/** A stand-in job: enough surface for the handler, none of the database. */
class NsfFakeJob {
	private $fields;
	function __construct(array $fields) { $this->fields = $fields; }
	function get($k) { return $this->fields[$k] ?? null; }
	function set($k, $v) { $this->fields[$k] = $v; }
	function save() { /* nothing to persist in a safe-tier test */ }
}

$good = "core installers: running install_agent.sh\n"
	. "core installers: install_agent.sh: ok\n"
	. "plugin installers: mailbox: running provisioning/install_email.sh\n"
	. "plugin installers: mailbox: ok\n";
$job = new NsfFakeJob(['mjb_output' => $good, 'mjb_status' => 'completed']);
nsf_call('process_run_plugin_installers', [$job]);
$result = json_decode($job->get('mjb_result'), true);

check($job->get('mjb_status') === 'completed',
	'a run where every installer succeeded stays green');
check(($result['installers_run'] ?? []) === ['install_agent.sh', 'mailbox'],
	'the result names what actually ran',
	var_export($result['installers_run'] ?? null, true));

$failed = $good . "plugin installers: WARNING - mailbox installer failed; its services may be down.\n";
$job = new NsfFakeJob(['mjb_output' => $failed, 'mjb_status' => 'completed']);
nsf_call('process_run_plugin_installers', [$job]);
$result = json_decode($job->get('mjb_result'), true);

check($job->get('mjb_status') === 'failed',
	'an installer that failed turns the job red, whatever the runner exited with');
check(count($result['failures'] ?? []) === 1,
	'and the failure is recorded rather than only implied by the colour');
check(strpos((string)$job->get('mjb_error_message'), 'mailbox installer failed') !== false,
	'the error message says which installer',
	var_export($job->get('mjb_error_message'), true));

// The exact shape node 176 hit during the 08-28 fleet upgrade: the runner could
// not reach the database, so the plugin loop never ran, and it said so and
// exited 0.
$skipped = "plugin installers: could not read the site database - skipping\n";
$job = new NsfFakeJob(['mjb_output' => $skipped, 'mjb_status' => 'completed']);
nsf_call('process_run_plugin_installers', [$job]);
check($job->get('mjb_status') === 'failed',
	'a run where the plugin loop was skipped is not a successful run');

$nothing = "core installers: install_agent.sh: ok\nplugin installers: no active plugins - nothing to run\n";
$job = new NsfFakeJob(['mjb_output' => $nothing, 'mjb_status' => 'completed']);
nsf_call('process_run_plugin_installers', [$job]);
$result = json_decode($job->get('mjb_result'), true);
check($job->get('mjb_status') === 'completed' && ($result['nothing_to_run'] ?? null) === true,
	'"no active plugins" is a complete answer, not a skip');

// A missing declared PHP extension degrades a plugin without stopping its
// installer — worth surfacing, not worth failing the job over.
$ext = "plugin installers: WARNING - could not install php8.3-sqlite3 (or php-sqlite3)\n"
	. "plugin installers: mailbox: ok\n";
$job = new NsfFakeJob(['mjb_output' => $ext, 'mjb_status' => 'completed']);
nsf_call('process_run_plugin_installers', [$job]);
$result = json_decode($job->get('mjb_result'), true);
check($job->get('mjb_status') === 'completed' && count($result['warnings'] ?? []) === 1,
	'a missing extension is reported as a warning and does not fail the job',
	var_export($result, true));

$job = new NsfFakeJob(['mjb_output' => '', 'mjb_status' => 'completed']);
nsf_call('process_run_plugin_installers', [$job]);
check($job->get('mjb_status') === 'failed',
	'a run that produced no output at all cannot be reported as a success');

// Script primitives return their text inside the agent's JSON envelope, where
// the lines are separated by escaped \n that no /m anchor matches.
$enveloped = "=== [Step 1/1] Agent primitive: run_plugin_installers ===\n"
	. json_encode(['api_version' => 1, 'data' => ['output' => $failed]]);
$job = new NsfFakeJob(['mjb_output' => $enveloped, 'mjb_status' => 'completed']);
nsf_call('process_run_plugin_installers', [$job]);
check($job->get('mjb_status') === 'failed',
	'the same reading works through the agent envelope, not only on raw text');

// A job that already failed is not "re-failed" into a different error message.
$job = new NsfFakeJob(['mjb_output' => $failed, 'mjb_status' => 'failed',
	'mjb_error_message' => 'the agent could not reach the node']);
nsf_call('process_run_plugin_installers', [$job]);
check($job->get('mjb_error_message') === 'the agent could not reach the node',
	'an already-failed job keeps the error that failed it');

section('A manager backup is stamped by its verdict, through the agent envelope');

// The exact shape every node's nightly backup_run now takes: since the fleet
// reports its vocabulary and backup_run routes to the primitive transport, the
// runner's text arrives wrapped in the agent's JSON envelope, where BACKUP_RESULT
// sits behind an escaped \n. Parsing the envelope as raw text read every one of
// the nine nodes as 'failed' on 08-29 while the jobs had all completed.
$runner = "[2026-08-29 05:00:30 UTC] manager success: Full backup (47.1 MB of files) "
	. "in chain-20260829_050016 to Backblaze B2 Joinery\n"
	. "BACKUP_RESULT=success\nBACKUP_TIME=2026-08-29 05:00:16\n";
$enveloped = json_encode(['api_version' => '1.0', 'data' => ['output' => $runner, 'output_bytes' => 177]]);

$v = JobResultProcessor::parse_backup_run_verdict($enveloped, 'completed');
check($v['status'] === 'success',
	'a successful run reads as success through the envelope, not failed',
	var_export($v, true));
check($v['time'] === '2026-08-29 05:00:16',
	'and the start time is read from BACKUP_TIME, not defaulted to now');

// The plain SSH path has no envelope; the same reading still holds.
$v = JobResultProcessor::parse_backup_run_verdict($runner, 'completed');
check($v['status'] === 'success',
	'the raw SSH text parses identically');

// A run the runner skipped (another backup already in progress) is neither
// success nor failure, so it must not refresh or alarm the stamp.
$skip = json_encode(['api_version' => '1.0', 'data' => ['output' =>
	"[2026-08-29 05:00:30 UTC] manager skipped: another backup is already running\n"
	. "BACKUP_RESULT=skipped\n"]]);
check(JobResultProcessor::parse_backup_run_verdict($skip, 'completed')['status'] === 'skipped',
	'a skipped run is reported as skipped, not folded into failed');

// A job that died before the runner said anything is a failed backup, not an
// unknown one — the only case that leans on the job status.
check(JobResultProcessor::parse_backup_run_verdict('', 'failed')['status'] === 'error',
	'a failed job with no verdict line is an error, not unknown');

// A completed job that somehow carried no verdict line stays 'unknown' and
// therefore stamps 'failed' — the honest reading, distinct from the envelope bug.
check(JobResultProcessor::parse_backup_run_verdict('nothing useful here', 'completed')['status'] === 'unknown',
	'a completed job with no BACKUP_RESULT is unknown, not silently success');

harness_finish();
