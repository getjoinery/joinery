<?php
/** @joinery-test
 * name: job_result_processor
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * JobResultProcessor — turning a remote node's output into management-node state.
 *
 * Job output is the least trustworthy input the management node handles. It is
 * whatever a remote server wrote to a pipe: a node under load, a half-finished
 * command, an agent from a different release, or a box that is no longer the one
 * it claims to be. That text is then parsed into records the admin UI presents
 * as fact and other jobs act on.
 *
 * So the parsers are tested for what they do with output that is not the happy
 * case — truncated, doubled, empty, carrying markers a compromised node would
 * like believed. The rule being pinned throughout is that unrecognised output
 * yields no data rather than wrong data: a missing reading is visibly missing in
 * the UI, while a misparsed one is indistinguishable from a real measurement.
 *
 * The pure parsers are reached by reflection. They are private because nothing
 * outside the class should call them, but they are also where every one of these
 * decisions is actually made, and driving them through process() would need a
 * large fixture for each case while asserting less. The public dispatch and the
 * end-to-end node update are exercised through process() itself.
 *
 * Sections: dispatch; the API envelope; SSH status parsing; markers and SSL
 * tokens; size formatting; end to end.
 *
 * Run: php plugins/server_manager/tests/job_result_processor_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_nodes_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_jobs_class.php'));

/** Call a private static on JobResultProcessor. */
function jrp_call($method, array $args) {
	$m = new ReflectionMethod('JobResultProcessor', $method);
	return $m->invokeArgs(null, $args);
}

function jrp_node(array $fields = array()) {
	$node = new ManagedNode(NULL);
	$suffix = bin2hex(random_bytes(3));
	$node->set('mgn_name', 'HarnessTest RP ' . $suffix);
	$node->set('mgn_slug', 'harnessrp-' . $suffix);
	$node->set('mgn_host', '192.0.2.20');
	$node->set('mgn_ssh_user', 'root');
	$node->set('mgn_ssh_key_path', '/tmp/nokey');
	// A paired node is a current one unless the test says otherwise: an agent
	// at the version floor reporting every word the plane can build.
	if (!empty($fields['mgn_agent_public_key'])) {
		$node->set('mgn_agent_version', AgentVocabulary::FLOOR);
		$words = array();
		foreach (get_class_methods('JobCommandBuilder') as $m) {
			if (preg_match('/^build_([a-z0-9_]+)_primitive$/', $m, $mm)) { $words[] = $mm[1]; }
		}
		$node->set('mgn_agent_primitives', implode(',', $words));
	}
	foreach ($fields as $k => $v) { $node->set($k, $v); }
	$node->save();
	$node->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node->key);
	return $node;
}

function jrp_job($node, $type, $output) {
	$job = new ManagementJob(NULL);
	$job->set('mjb_mgn_managed_node_id', $node ? $node->key : null);
	$job->set('mjb_job_type', $type);
	$job->set('mjb_status', 'completed');
	$job->set('mjb_commands', array());
	$job->set('mjb_output', $output);
	$job->save();
	$job->load();
	harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $job->key);
	return $job;
}

// Realistic SSH status output, of the shape check_status actually returns.
$GOOD_SSH = <<<TXT
Filesystem      Size  Used Avail Use% Mounted on
/dev/sda1        40G   12G   26G  32% /
              total        used        free
Mem:           7982        3120        1204
Swap:          2047         183        1864
 14:22:01 up 12 days,  3:41,  1 user,  load average: 0.42, 0.55, 0.61
/var/run/postgresql:5432 - accepting connections
VERSION = '2.14.3';
CRON_LAST_RUN=2026-07-18 14:20:01
CURRENT_DB=joineryprod
DB:joineryprod
DB:joinerytest
TXT;

// ---------------------------------------------------------------------------
section('Dispatch');

$node = jrp_node();

// An unknown job type must be inert. New job types ship with the agent before
// the management node knows them, so this is a normal state, not an error.
$job = jrp_job($node, 'no_such_job_type', 'anything at all');
$threw = false;
try { JobResultProcessor::process($job); } catch (\Throwable $e) { $threw = true; }
check(!$threw, 'an unknown job type is ignored rather than raising');

// A job whose output never arrived must not be read as a set of zero readings.
$job = jrp_job($node, 'check_status', '');
$threw = false;
try { JobResultProcessor::process($job); } catch (\Throwable $e) { $threw = true; }
check(!$threw, 'empty job output is handled without raising',
	$threw ? $e->getMessage() : '');

// ---------------------------------------------------------------------------
section('The API envelope');

$envelope = json_encode(array('api_version' => '1', 'data' => array(
	'disk_usage_percent' => 41, 'joinery_version' => '2.14.3')));

$data = jrp_call('extract_api_envelope_data', array($envelope));
check(is_array($data) && $data['disk_usage_percent'] === 41,
	'a clean envelope yields its data', var_export($data, true));

// Agents prepend log lines and append step footers; the envelope has to be
// found inside that noise rather than only at the start of the stream.
$noisy = "Connecting...\n" . $envelope . "\n[Step 1/1 OK 0.4s]";
$data = jrp_call('extract_api_envelope_data', array($noisy));
check(is_array($data) && $data['joinery_version'] === '2.14.3',
	'an envelope surrounded by agent chatter is still found', var_export($data, true));

// Anything that is not a recognisable envelope yields nothing, so the caller
// falls back to SSH parsing instead of acting on a half-decoded structure.
$cases = array(
	'not json at all' => 'total garbage output',
	'json without envelope keys' => '{"foo":"bar"}',
	'envelope missing data' => '{"api_version":"1"}',
	'data not an array' => '{"api_version":"1","data":"nope"}',
	'truncated json' => '{"api_version":"1","data":{"disk_usage_percent":4',
	'empty string' => '',
);
foreach ($cases as $label => $bad) {
	check(jrp_call('extract_api_envelope_data', array($bad)) === null,
		'no envelope data is extracted from ' . $label);
}

// ---------------------------------------------------------------------------
section('SSH status parsing');

$r = jrp_call('parse_check_status_ssh_output', array($GOOD_SSH));

check(($r['disk_usage_percent'] ?? null) === 32, 'disk usage percent is read',
	var_export($r['disk_usage_percent'] ?? null, true));
check(($r['disk_total'] ?? null) === '40G', 'disk total is read',
	var_export($r['disk_total'] ?? null, true));
check(($r['disk_available'] ?? null) === '26G', 'disk available is read',
	var_export($r['disk_available'] ?? null, true));
check(($r['memory_total_mb'] ?? null) === 7982, 'memory total is read',
	var_export($r['memory_total_mb'] ?? null, true));
check(($r['memory_used_mb'] ?? null) === 3120, 'memory used is read');
check(($r['swap_total_mb'] ?? null) === 2047, 'swap total is read',
	var_export($r['swap_total_mb'] ?? null, true));
check(($r['swap_used_mb'] ?? null) === 183, 'swap used is read');

// A box with no swap says so with zeros. That is a measurement - a node
// running without swap is a fact the dashboard should show - not an absence.
$swapless = jrp_call('parse_check_status_ssh_output',
	array("Mem: 961 700 261\nSwap: 0 0 0\n"));
check(($swapless['swap_total_mb'] ?? null) === 0 && ($swapless['swap_used_mb'] ?? null) === 0,
	'a swapless box reads as zero swap, not as no reading',
	var_export($swapless, true));
check(($r['load_1m'] ?? null) === 0.42, 'the one-minute load average is read',
	var_export($r['load_1m'] ?? null, true));
check(($r['load_15m'] ?? null) === 0.61, 'the fifteen-minute load average is read');
check(strpos((string)($r['uptime'] ?? ''), '12 days') !== false, 'uptime is read',
	var_export($r['uptime'] ?? null, true));
check(($r['postgres_status'] ?? null) === 'accepting connections',
	'a healthy database is reported as such');
check(($r['joinery_version'] ?? null) === '2.14.3', 'the platform version is read',
	var_export($r['joinery_version'] ?? null, true));
check(($r['current_db'] ?? null) === 'joineryprod', 'the current database name is read');
check(($r['db_list'] ?? null) === array('joineryprod', 'joinerytest'),
	'every listed database is collected',
	var_export($r['db_list'] ?? null, true));

// A database that is down must read as down, not as absent — the two mean
// different things on the dashboard.
$down = jrp_call('parse_check_status_ssh_output',
	array("/var/run/postgresql:5432 - no response\n"));
check(($down['postgres_status'] ?? null) === 'not responding',
	'a database that does not answer is reported as not responding',
	var_export($down['postgres_status'] ?? null, true));

// Output that says nothing must produce nothing. A parser that defaulted to
// zero here would paint an empty disk and no load on the dashboard.
foreach (array('' => 'empty output', 'command not found' => 'an error message',
	'###' => 'punctuation') as $bad => $label) {
	$empty = jrp_call('parse_check_status_ssh_output', array($bad));
	check($empty === array(), 'no readings are invented from ' . $label,
		var_export($empty, true));
}

// A truncated stream yields the fields that did arrive and nothing more.
$partial = jrp_call('parse_check_status_ssh_output',
	array("Filesystem Size Used Avail Use% Mounted on\n/dev/sda1 40G 12G 26G 32% /\n"));
check(($partial['disk_usage_percent'] ?? null) === 32,
	'a truncated stream still yields the readings it contained');
check(!isset($partial['memory_total_mb']),
	'a truncated stream invents no reading for the part that never arrived');
check(!isset($partial['swap_total_mb']), 'no swap reading is invented either');
check(!isset($partial['load_1m']), 'no load average is invented either');

// Two status blocks in one stream (a retried step) must not produce a blend of
// the two — the later reading is the current one.
$doubled = jrp_call('parse_check_status_ssh_output',
	array("Mem: 1000 100 900\n" . "Mem: 2000 200 1800\n"));
check(($doubled['memory_total_mb'] ?? null) === 1000,
	'a repeated reading resolves to one value rather than a blend',
	var_export($doubled['memory_total_mb'] ?? null, true));

// ---------------------------------------------------------------------------
section('SSL tokens');

$ssl = jrp_call('parse_ssl_tokens', array("SSL_CERT_FOUND domain=example.test expiry=Aug 30 12:00:00 2026 GMT\n"));
check(is_array($ssl) && $ssl['found'] === true, 'a found certificate is recognised');
check($ssl['domain'] === 'example.test', 'the certificate domain is read', var_export($ssl, true));
check(strpos($ssl['expiry_raw'], '2026') !== false, 'the expiry is carried through',
	var_export($ssl['expiry_raw'] ?? null, true));

$ssl = jrp_call('parse_ssl_tokens', array("SSL_CERT_MISSING domain=example.test\n"));
check(is_array($ssl) && $ssl['found'] === false, 'a missing certificate is recognised');
check($ssl['domain'] === 'example.test', 'the domain is read for a missing certificate');

check(jrp_call('parse_ssl_tokens', array("nothing relevant here\n")) === null,
	'output with no SSL token yields nothing rather than a false negative',
	'null and found=false mean different things: unknown versus known-absent');

// ---------------------------------------------------------------------------
section('Size formatting');

check(jrp_call('format_size', array(0)) !== '', 'zero bytes formats to something');
$mb = jrp_call('format_size', array(5 * 1024 * 1024));
check(strpos($mb, '5') !== false, 'five megabytes mentions five', $mb);
$gb = jrp_call('format_size', array(3 * 1024 * 1024 * 1024));
check(stripos($gb, 'G') !== false, 'gigabyte-scale sizes use a gigabyte unit', $gb);
check(jrp_call('format_size', array(512)) !== jrp_call('format_size', array(512 * 1024)),
	'different magnitudes format differently');

// ---------------------------------------------------------------------------
section('Upgrades that stopped to refresh their own tooling');

// upgrade.php exits 0 after copying new deployment files into place. Versions
// before 0.8.112 then wait for a human. Nothing in the exit code says so, so the
// job result has to read it out of the output or report a success that never was.
$halt_old = "=== SELF-UPDATE REQUIRED ===\n"
	. "  - utils/upgrade.php\n\n"
	. "  SELF-UPDATE COMPLETE — PLEASE RE-RUN THE UPGRADE\n\n"
	. "  Re-run with the same command to continue.\n";
check(jrp_call('halted_at_self_update', array($halt_old)) === true,
	'an upgrade that asked to be re-run is recognised as unfinished');

check(jrp_call('halted_at_self_update',
	array("  Automatic re-run already attempted once and deployment files still differ.\n")) === true,
	'an automatic re-run that gave up is recognised as unfinished');

check(jrp_call('halted_at_self_update',
	array("=== SYNCING THEMES AND PLUGINS ===\n<h2>✓ Upgrade Complete!</h2>System upgraded to version: 0.8.177\n")) === false,
	'a completed upgrade is not mistaken for one that stopped early');

check(jrp_call('halted_at_self_update', array('')) === false,
	'empty output is not treated as a self-update halt');

// ---------------------------------------------------------------------------
section('Why a node is still behind');

// A refusal never reaches the upgrader at all. Reporting it as "upgrade
// finished but the node is still on X" describes a working upgrader that came
// up short, which is the opposite of what happened, and buries the one line
// that says how to fix it.
$refusal = 'Refused by the node: primitive "apply_update" refused: no script from this '
	. 'release can be verified before running as root: tree manifest signature does not '
	. 'verify against the compiled-in release key';
$v = jrp_call('behind_verdict', array('refused', $refusal, '', '0.8.356', '0.8.370'));
check($v['reason'] === $refusal, 'a refused upgrade keeps the reason the node gave', $v['reason']);
check($v['rewrite_message'] === false, 'the node-written message is left alone');
check($v['node_outcome'] === 'refused', 'the result records that the node refused');

// Same for a primitive that ran and failed.
$v = jrp_call('behind_verdict', array('failed', 'php: out of memory', '', '0.8.356', '0.8.370'));
check($v['reason'] === 'php: out of memory', 'a failed upgrade keeps the reason the node gave');
check($v['rewrite_message'] === false, 'a failed run keeps its own message too');

// A refusal with nothing said still names the node as the source, rather than
// blaming an upgrader that was never reached.
$v = jrp_call('behind_verdict', array('refused', '', '', '0.8.356', '0.8.370'));
check(strpos($v['reason'], 'refused') !== false && strpos($v['reason'], '0.8.370') !== false,
	'a silent refusal still says the node refused and names the target', $v['reason']);
check($v['rewrite_message'] === true, 'a silent refusal gets a message written for it');

// The node said it completed, and the version did not move: that IS the
// upgrader coming up short, and the probe's verdict is the right one.
$v = jrp_call('behind_verdict', array('completed', '', '', '0.8.356', '0.8.370'));
check(strpos($v['reason'], 'Upgrade finished') === 0,
	'a completed job that did not move the version reports the version verdict', $v['reason']);
check($v['node_outcome'] === '', 'a completed job records no node outcome');

$v = jrp_call('behind_verdict',
	array('completed', '', "  SELF-UPDATE COMPLETE — PLEASE RE-RUN THE UPGRADE\n", '0.8.356', '0.8.370'));
check(strpos($v['reason'], 'second pass') !== false,
	'a two-pass halt still says a second pass is needed', $v['reason']);

// ---------------------------------------------------------------------------
section('End to end');

// A completed check_status job should leave the node carrying what it reported.
$node = jrp_node();
$job = jrp_job($node, 'check_status', $GOOD_SSH);
JobResultProcessor::process($job);

$node->load();
check($node->get('mgn_joinery_version') === '2.14.3',
	'processing a status job records the version on the node',
	var_export($node->get('mgn_joinery_version'), true));
check(!empty($node->get('mgn_last_status_check')),
	'processing a status job stamps when it was checked');

$stored = $node->get('mgn_last_status_data');
if (is_string($stored)) { $stored = json_decode($stored, true); }
check(is_array($stored) && ($stored['disk_usage_percent'] ?? null) === 32,
	'the parsed readings are stored on the node',
	var_export($stored, true));

// Output that says nothing must not overwrite a good reading with an empty one.
// A node that fails one poll should show its last known state, not a blank.
$job = jrp_job($node, 'check_status', 'ssh: connect to host port 22: Connection refused');
JobResultProcessor::process($job);
$node->load();
check($node->get('mgn_joinery_version') === '2.14.3',
	'a failed poll leaves the previously known version in place',
	var_export($node->get('mgn_joinery_version'), true));

// ---------------------------------------------------------------------------
section('host_report: the object lands in its own two columns, capped on intake');

// What the agent posts for a script primitive: its envelope, with the script's
// stdout as text. The stdout here is what host_report.sh prints as root.
$hr_object = array(
	'failed_units' => array('nginx.service'),
	'expected_units' => array('fail2ban' => 'active', 'apache2' => 'active', 'php-fpm' => 'active', 'cron' => 'active', 'postgresql' => 'absent'),
	'fail2ban_jails' => array(array('name' => 'sshd', 'banned' => 3), array('name' => 'apache-auth', 'banned' => 0)),
	'ssh_auth_failures_24h' => 41,
	'sshd' => array('password_authentication' => 'no', 'permit_root_login' => 'prohibit-password'),
	'disk' => array('path' => '/var/www/html/site/public_html', 'used_bytes' => 1000, 'total_bytes' => 4000),
	'memory' => array('used_bytes' => 2000, 'total_bytes' => 8000),
	'swap' => array('used_bytes' => 0, 'total_bytes' => 1024),
	'reboot_required' => true,
	'unattended_upgrades_last_run' => 1789281613,
	'os' => array('id' => 'ubuntu', 'version' => '24.04.4', 'codename' => 'noble',
		'release_upgrade' => array('offered' => '26.04.1', 'checked_at' => 1789280000)),
	'generated_at' => 1789341744,
);
$hr_envelope = "=== [Step 1/1] host_report ===\n" . json_encode(array('api_version' => '1.0', 'data' => array(
	'output' => json_encode($hr_object) . "\n", 'output_bytes' => 400))) . "\n[Step 1/1 OK]";

$hr_node = jrp_node();
check(empty($hr_node->get('mgn_last_host_report')) && empty($hr_node->get('mgn_last_host_report_time')),
	'a fresh node holds no host report');
$hr_job = jrp_job($hr_node, 'host_report', $hr_envelope);
JobResultProcessor::process($hr_job);
$hr_node->load();
$stored = $hr_node->get('mgn_last_host_report');
if (is_string($stored)) { $stored = json_decode($stored, true); }
check(is_array($stored) && $stored['failed_units'] === array('nginx.service'),
	'the failed units are stored on the node', var_export($stored, true));
check(is_array($stored) && $stored['expected_units']['postgresql'] === 'absent' && $stored['expected_units']['fail2ban'] === 'active',
	'the expected units keep their states');
check(is_array($stored) && $stored['fail2ban_jails'][0] === array('name' => 'sshd', 'banned' => 3),
	'jails keep their ban counts');
check(is_array($stored) && $stored['ssh_auth_failures_24h'] === 41 && $stored['reboot_required'] === true
	&& $stored['sshd']['permit_root_login'] === 'prohibit-password',
	'the count, the reboot flag and the sshd posture survive');
check(is_array($stored) && $stored['os'] === $hr_object['os'],
	'the operating system and the upgrade it is offered survive as sent', var_export($stored['os'] ?? null, true));
check(!empty($hr_node->get('mgn_last_host_report_time')),
	'and the read time is stamped');
$hr_status = $hr_node->get('mgn_last_status_data');
if (is_string($hr_status)) { $hr_status = json_decode($hr_status, true); }
check(empty($hr_status) || !isset($hr_status['failed_units']),
	'nothing of it reaches mgn_last_status_data: the two shapes stay apart (Q2)');
$hr_result = json_decode((string)$hr_job->get('mjb_result'), true);
check(is_array($hr_result) && ($hr_result['measured'] ?? null) === true && ($hr_result['ssh_auth_failures_24h'] ?? null) === 41,
	'the job result carries the report too');

// Intake caps: a hostile node cannot put anything but the known keys and
// bounded values on the plane.
$hostile = array(
	'failed_units' => array_merge(array('<script>alert(1)</script>.service', "a\nb; rm -rf /"), array_fill(0, 40, 'x.service')),
	'expected_units' => array('fail2ban' => 'exploded', 'apache2' => array('nested'), 'php-fpm' => 'active', 'extra' => 'active'),
	'fail2ban_jails' => array_merge(array(array('name' => str_repeat('j', 500), 'banned' => -5), array('name' => '', 'banned' => 1), 'notanobject'), array_fill(0, 40, array('name' => 'z', 'banned' => 1))),
	'ssh_auth_failures_24h' => 'eve from 203.0.113.9',
	'sshd' => array('password_authentication' => 'YES<b>', 'permit_root_login' => 12),
	'disk' => array('path' => '../../etc/passwd?<x>', 'used_bytes' => '12', 'total_bytes' => 'lots'),
	'memory' => 'none',
	'reboot_required' => 'true',
	'unattended_upgrades_last_run' => -1,
	'generated_at' => 1.5,
	'os' => array('id' => 'Ubuntu <b>', 'version' => '24.04; reboot', 'codename' => array('x'),
		'release_upgrade' => array('offered' => "New release '26.04'", 'checked_at' => 'yesterday')),
	'surprise' => 'key',
);
$capped = JobResultProcessor::sanitise_host_report($hostile);
check(!isset($capped['surprise']) && count($capped) === 16, 'unknown keys are dropped and every known key is present', var_export(array_keys($capped), true));
// Fields an older node never sent are "not reported" (null), never a value
// (specs/agent_recipes_and_vocabulary.md, rule 11).
check($capped['answers'] === null && $capped['served_certificates'] === null && $capped['containers'] === null,
	'answers, served_certificates and containers absent from the report are null, not no');
check(!array_key_exists('pubkey_authentication', $capped['sshd']) && !array_key_exists('ports', $capped['sshd']),
	'and sshd\'s widened settings absent from the report are absent, not unknown');
$widened = JobResultProcessor::sanitise_host_report(array(
	'sshd' => array('password_authentication' => 'no', 'permit_root_login' => 'no', 'pubkey_authentication' => 'YES',
		'kbd_interactive_authentication' => 'no', 'max_auth_tries' => '6', 'ports' => array('22', '2222;x'), 'allow_users' => 'ops', 'allow_groups' => array()),
	'answers' => array('apache2' => 'yes', 'php-fpm' => 'maybe', 'extra' => 'yes'),
	'served_certificates' => array(array('domain' => 'a.example<b>', 'days_left' => 12), array('domain' => '', 'days_left' => 3), array('domain' => 'b.example', 'days_left' => '9')),
	'containers' => array(array('name' => 'site1', 'state' => 'running', 'health' => 'none', 'answers' => 'no'), 'junk'),
));
check($widened['sshd']['pubkey_authentication'] === 'yes' && $widened['sshd']['max_auth_tries'] === 6
	&& $widened['sshd']['ports'] === array('22', '2222x') && $widened['sshd']['allow_users'] === 'unknown' && $widened['sshd']['allow_groups'] === array(),
	'sshd\'s widened settings are kept, sanitised, a non-list list read as unknown', var_export($widened['sshd'], true));
check($widened['answers'] === array('apache2' => 'yes', 'php-fpm' => 'unknown', 'postgresql' => 'unknown'),
	'answers keep the three services only, each yes, no or unknown', var_export($widened['answers'], true));
check($widened['served_certificates'] === array(array('domain' => 'a.exampleb', 'days_left' => 12, 'primary' => false)),
	'a served certificate needs a name and whole days', var_export($widened['served_certificates'], true));
check($widened['containers'] === array(array('name' => 'site1', 'state' => 'running', 'health' => 'none', 'answers' => 'no')),
	'a container keeps its four facts; anything else in the list is dropped', var_export($widened['containers'], true));
check(JobResultProcessor::sanitise_host_report(array('containers' => 'none'))['containers'] === 'none',
	'a machine with no docker says none');
check(count($capped['failed_units']) === JobResultProcessor::HOST_REPORT_MAX_LIST, 'failed units are capped at the list bound');
check($capped['failed_units'][0] === 'scriptalert1script.service' && $capped['failed_units'][1] === 'abrm-rf',
	'unit names are reduced to safe characters', var_export(array_slice($capped['failed_units'], 0, 2), true));
check($capped['expected_units']['fail2ban'] === 'unknown' && $capped['expected_units']['apache2'] === 'unknown'
	&& $capped['expected_units']['php-fpm'] === 'active' && $capped['expected_units']['cron'] === 'unknown' && !isset($capped['expected_units']['extra']),
	'an unrecognised state is unknown, a missing unit is unknown, an extra unit is dropped');
check(count($capped['fail2ban_jails']) <= JobResultProcessor::HOST_REPORT_MAX_LIST
	&& strlen($capped['fail2ban_jails'][0]['name']) === JobResultProcessor::HOST_REPORT_MAX_NAME
	&& $capped['fail2ban_jails'][0]['banned'] === 'unknown',
	'jail names are capped, a negative count is unknown, a nameless or non-object jail is dropped');
check($capped['ssh_auth_failures_24h'] === 'unknown', 'a count that is not a number is unknown: no text from the node is kept in it');
check($capped['sshd'] === array('password_authentication' => 'yesb', 'permit_root_login' => 'unknown'),
	'sshd values are lowercased letters and dashes only', var_export($capped['sshd'], true));
check($capped['disk']['path'] === '../../etc/passwdx' && $capped['disk']['used_bytes'] === 12 && $capped['disk']['total_bytes'] === 'unknown',
	'the disk path is reduced to path characters and byte figures must be numbers');
check($capped['disk']['avail_bytes'] === 'unknown' && $capped['disk']['inodes_used_pct'] === 'unknown',
	'a node too old to report free space or inode use says unknown, which reads differently from zero');
check($capped['kernel_events_24h'] === 'unknown',
	'kernel events that are not an object are unknown for the whole object, not three zeros');
$ke = JobResultProcessor::sanitise_host_report(array(
	'kernel_events_24h' => array('oom' => 2, 'enospc' => '1', 'io_error' => 'lots', 'extra' => 9),
	'disk' => array('avail_bytes' => 5, 'inodes_used_pct' => 120),
));
check($ke['kernel_events_24h'] === array('oom' => 2, 'enospc' => 1, 'io_error' => 'unknown'),
	'each kernel count is a number or unknown, and an extra key is dropped', var_export($ke['kernel_events_24h'], true));
check($ke['disk']['avail_bytes'] === 5 && $ke['disk']['inodes_used_pct'] === 'unknown',
	'a percentage outside 0..100 is unknown');
check($capped['memory'] === array('used_bytes' => 'unknown', 'total_bytes' => 'unknown') && $capped['swap'] === array('used_bytes' => 'unknown', 'total_bytes' => 'unknown'),
	'a gauge that is not an object, or is missing, is unknown in both figures');
check($capped['reboot_required'] === 'unknown' && $capped['unattended_upgrades_last_run'] === 'unknown' && $capped['generated_at'] === 'unknown',
	'a string "true", a negative time and a fractional time are all unknown');
check($capped['os'] === array('id' => 'ubuntub', 'version' => 'unknown', 'codename' => 'unknown',
		'release_upgrade' => array('offered' => 'unknown', 'checked_at' => 'unknown')),
	'os words are lowercased and bounded, versions must be dotted digits, and text is never kept as a version', var_export($capped['os'], true));
$os_none = JobResultProcessor::sanitise_host_report(array('os' => array('release_upgrade' => array('offered' => 'none', 'checked_at' => 5))));
check($os_none['os']['release_upgrade'] === array('offered' => 'none', 'checked_at' => 5),
	'none is kept: the node checked and was offered nothing');
check(JobResultProcessor::sanitise_host_report(array())['os'] === 'unknown',
	'a node too old to report its operating system says unknown for the whole object');
check(json_encode($capped) !== false, 'the capped object encodes');

// An answer that is not the object: the columns are left alone.
$before = $hr_node->get('mgn_last_host_report_time');
$hr_bad = jrp_job($hr_node, 'host_report', "=== [Step 1/1] host_report ===\n" . json_encode(array('api_version' => '1.0', 'data' => array('output' => "bash: host_report.sh: No such file\n"))));
JobResultProcessor::process($hr_bad);
$hr_node->load();
check((string)$hr_node->get('mgn_last_host_report_time') === (string)$before,
	'a job whose output is not the object changes no stored report');
check(json_decode((string)$hr_bad->get('mjb_result'), true) === array('measured' => false),
	'and records that it measured nothing');
check(in_array('host_report', JobResultProcessor::processable_types(), true),
	'host_report is a type this processor knows');

// ---------------------------------------------------------------------------
section('host_converge: a completed run asks for the machine after it, once');

// A node whose agent offers both words; the run's transcript says the
// installer ran. process() is the real path here (the sweep and the job page
// both come through it), so the queued report is a real row.
$hc_node = jrp_node(array(
	'mgn_agent_public_key' => base64_encode(str_repeat("\x09", 32)),
	'mgn_agent_version'    => AgentVocabulary::FLOOR,
	'mgn_agent_primitives' => 'check_status,host_report,host_converge',
));
function jrp_pending_host_reports($node_id) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare("SELECT mjb_management_job_id FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = 'host_report' AND mjb_status = 'pending' AND mjb_delete_time IS NULL ORDER BY mjb_management_job_id");
	$q->execute(array((int)$node_id));
	return $q->fetchAll(PDO::FETCH_COLUMN);
}
check(jrp_pending_host_reports($hc_node->key) === array(), 'nothing is queued for the node before the run');

$hc_ok = "=== [Step 1/1] host_converge ===\n" . json_encode(array('api_version' => '1.0', 'data' => array(
	'output' => "core installers: running host_housekeeping.sh\ncore installers: host_housekeeping.sh: ok\n", 'output_bytes' => 90))) . "\n[Step 1/1 OK]";
$hc_job = jrp_job($hc_node, 'host_converge', $hc_ok);
JobResultProcessor::process($hc_job);
$hc_job->load();
check($hc_job->get('mjb_status') === 'completed', 'the run is green');
$queued = jrp_pending_host_reports($hc_node->key);
foreach ($queued as $id) { harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $id); }
check(count($queued) === 1, 'one host_report is queued for the node so the Host card shows the machine after the run', var_export($queued, true));
if ($queued) {
	$follow = new ManagementJob($queued[0], TRUE);
	$cmd = $follow->get('mjb_commands');
	if (is_string($cmd)) { $cmd = json_decode($cmd, true); }
	check(is_array($cmd) && ($cmd['primitive'] ?? null) === 'host_report',
		'and it is the host_report primitive, dispatched through createFromBuild', var_export($cmd, true));
}

// A second run while that report is still pending does not pile another on.
$hc_job2 = jrp_job($hc_node, 'host_converge', $hc_ok);
JobResultProcessor::process($hc_job2);
check(count(jrp_pending_host_reports($hc_node->key)) === 1,
	'a report already pending is left to answer: a second completed run queues no second report');

// A run that did not complete asks for nothing: the machine is as it was.
$hc_node2 = jrp_node(array(
	'mgn_agent_public_key' => base64_encode(str_repeat("\x0a", 32)),
	'mgn_agent_version'    => AgentVocabulary::FLOOR,
	'mgn_agent_primitives' => 'check_status,host_report,host_converge',
));
$hc_bad = jrp_job($hc_node2, 'host_converge', "=== [Step 1/1] host_converge ===\n" . json_encode(array('api_version' => '1.0', 'data' => array(
	'output' => "core installers: WARNING - host_housekeeping.sh failed\n"))));
JobResultProcessor::process($hc_bad);
$hc_bad->load();
check($hc_bad->get('mjb_status') === 'failed', 'a failed installer turns the job red through process() too');
check(jrp_pending_host_reports($hc_node2->key) === array(),
	'and a red run queues no report');
check(in_array('host_converge', JobResultProcessor::processable_types(), true),
	'host_converge is a type this processor knows');

// ---------------------------------------------------------------------------
section('Terminal jobs always record a result (the sweep can never re-process forever)');

// The dashboard sweep selects mjb_result IS NULL; a handler path that returns
// without recording would make that job re-processed on every render, forever.
// Handler early-returns (no node on the job) are covered by the process()
// backstop, for every job type at once.
$orphan = jrp_job(null, 'provision_certificate', 'whatever');
JobResultProcessor::process($orphan);
check((string)$orphan->get('mjb_result') !== '',
	'a job whose handler returns early still records a result via the backstop',
	var_export($orphan->get('mjb_result'), true));

// The SSH certificate path is gone with its builder (specs/ssh_single_bootstrap.md).
check(!in_array('provision_ssl', JobResultProcessor::processable_types(), true),
	'provision_ssl is no longer a job type this processor knows');
check(!in_array('discover_nodes', JobResultProcessor::processable_types(), true),
	'and neither is discover_nodes');

// ---------------------------------------------------------------------------
section('A certificate issued on the host is stamped on the site it was for');

// A container's certificate job is filed against its HOST's agent and names
// the site in for_node_id. The SITE goes active; the host is untouched.
$issued = "\033[0;32m[OK]\033[0m Issued LE certificate for site.example.com (HTTP-01)\nApache reloaded.\n";
$cert_host = jrp_node(array('mgn_ssl_state' => null));
$cert_site = jrp_node(array('mgn_ssl_state' => 'pending', 'mgn_site_url' => 'https://site.example.com',
	'mgn_container_name' => 'certsite'));
$cert_job = jrp_job($cert_host, 'provision_certificate', $issued);
$cert_job->set('mjb_parameters', array('domain' => 'site.example.com', 'for_node_id' => (int)$cert_site->key));
$cert_job->save();
JobResultProcessor::process($cert_job);
$cert_site->load(); $cert_host->load();
check($cert_site->get('mgn_ssl_state') === 'active', 'the site the job was for goes active',
	var_export($cert_site->get('mgn_ssl_state'), true));
check($cert_host->get('mgn_ssl_state') !== 'active', 'the host the job ran on is not marked');

// Without for_node_id (a bare-metal node issuing for itself) the job\'s own node is stamped.
$self_node = jrp_node(array('mgn_ssl_state' => 'pending', 'mgn_site_url' => 'https://site.example.com'));
JobResultProcessor::process(jrp_job($self_node, 'provision_certificate', $issued));
$self_node->load();
check($self_node->get('mgn_ssl_state') === 'active', 'a node issuing for itself is stamped');

// ---------------------------------------------------------------------------
section('The secret a compiled-names job carried does not outlive the job');

$arm_node = jrp_node(array());
$arm_job = jrp_job($arm_node, 'clone_export_arm', json_encode(array('api_version' => '1.0',
	'data' => array('output' => "CLONE_EXPORT_ARM=armed\n"))));
$arm_job->set('mjb_commands', array('primitive' => 'clone_export_arm', 'params' => array('export_key' => 'deadbeefdeadbeefdeadbeef')));
$arm_job->set('mjb_parameters', array('export_key' => 'deadbeefdeadbeefdeadbeef', 'provision_id' => 7));
$arm_job->save();
JobResultProcessor::process($arm_job);
$arm_job->load();
$arm_result = json_decode((string)$arm_job->get('mjb_result'), true);
check(!empty($arm_result['armed']), 'the arm job records that the source armed', (string)$arm_job->get('mjb_result'));
check(strpos((string)json_encode($arm_job->get('mjb_commands')), 'deadbeef') === false
	&& strpos((string)json_encode($arm_job->get('mjb_parameters')), 'deadbeef') === false,
	'the export key is blanked out of both the envelope and the record');
$arm_record = $arm_job->get('mjb_parameters');
if (is_string($arm_record)) { $arm_record = json_decode($arm_record, true); }
check((int)($arm_record['provision_id'] ?? 0) === 7, 'the rest of the record survives', json_encode($arm_record));

$fe_node = jrp_node(array());
$fe_job = jrp_job($fe_node, 'fleet_enroll', json_encode(array('api_version' => '1.0',
	'data' => array('output' => "FLEET_ENROLL=ok\nservice_url=https://x\n"))));
$fe_job->set('mjb_commands', array('primitive' => 'fleet_enroll', 'params' => array(
	'service_url' => 'https://x', 'public_key' => 'public_abcdefgh12345678', 'secret_key' => 'secret_abcdefgh12345678')));
$fe_job->set('mjb_parameters', array('service_url' => 'https://x', 'public_key' => 'public_abcdefgh12345678', 'secret_key' => 'secret_abcdefgh12345678'));
$fe_job->save();
JobResultProcessor::process($fe_job);
$fe_job->load();
check(!empty(json_decode((string)$fe_job->get('mjb_result'), true)['seeded']), 'a completed fleet_enroll that said ok is seeded');
check(strpos((string)json_encode($fe_job->get('mjb_commands')), 'secret_abcdefgh') === false
	&& strpos((string)json_encode($fe_job->get('mjb_parameters')), 'secret_abcdefgh') === false,
	'the secret key is blanked; the public key may stay',
	(string)json_encode($fe_job->get('mjb_parameters')));
check(strpos((string)json_encode($fe_job->get('mjb_parameters')), 'public_abcdefgh') !== false,
	'the public half is still on the record');

// ---------------------------------------------------------------------------
section('Decommission: soft-delete only when verified');

// A completed job that verified the site is gone soft-deletes the node record.
// Nothing else has to be preserved alongside it: the node's backups carry their
// own keys, sealed to the recovery key, so decommissioning a node has no effect
// on whether its archives can still be opened.
$dn = jrp_node(array('mgn_container_name' => 'decomrp', 'mgn_web_root' => '/var/www/html/decomrp/public_html'));

$dj = jrp_job($dn, 'decommission_node', "REMOVE_ACCOUNT_OK decomrp\nDECOMMISSION_VERIFIED");
JobResultProcessor::process($dj);
$dn->load();
check(!empty($dn->get('mgn_delete_time')),
	'a verified decommission soft-deletes the node record',
	var_export($dn->get('mgn_delete_time'), true));

// A failed job leaves the node intact — never a half-deleted record over a live site.
$dn2 = jrp_node(array('mgn_container_name' => 'decomrp2', 'mgn_web_root' => '/var/www/html/decomrp2/public_html'));
$dj2 = jrp_job($dn2, 'decommission_node', 'host teardown errored');
$dj2->set('mjb_status', 'failed');
$dj2->save();
JobResultProcessor::process($dj2);
$dn2->load();
check(empty($dn2->get('mgn_delete_time')), 'a failed decommission leaves the node intact');
check((string)$dj2->get('mjb_result') !== '', 'and the failed decommission still records a result');

// A completed run whose verify FAILED (traces remained) must not delete the node,
// even though the job itself completed.
$dn3 = jrp_node(array('mgn_container_name' => 'decomrp3', 'mgn_web_root' => '/var/www/html/decomrp3/public_html'));
$dj3 = jrp_job($dn3, 'decommission_node', "REMOVE_ACCOUNT_OK decomrp3\nDECOMMISSION_FAILED_VERIFY\nstill present: volumes");
JobResultProcessor::process($dj3);
$dn3->load();
check(empty($dn3->get('mgn_delete_time')),
	'a completed-but-unverified decommission leaves the node intact');

// The primitive shape: the job's SUBJECT is the HOST that ran the teardown,
// the record to finalize is the VICTIM named in the params, and the verdict
// travels inside the primitive's result envelope. Filed through the REAL
// dispatch path (createFromBuild) rather than hand-written parameters — the
// path once DISCARDED caller params for primitive envelopes, so a verified
// teardown finalized the host's record, and a hand-written fixture could not
// have seen it.
$dhost4 = jrp_node(array());
$dvictim4 = jrp_node(array('mgn_container_name' => 'decomrp4', 'mgn_web_root' => '/var/www/html/decomrp4/public_html'));
$dj4 = ManagementJob::createFromBuild($dhost4->key, 'decommission_node',
	array('primitive' => 'decommission_site', 'params' => array('site' => 'decomrp4')),
	array('victim_node_id' => (int)$dvictim4->key, 'site' => 'decomrp4'), 1);
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $dj4->key);
$dj4->set('mjb_status', 'completed');
$dj4->set('mjb_output', json_encode(array('api_version' => 1, 'data' => array(
	'output' => "REMOVE_ACCOUNT_OK decomrp4\nDECOMMISSION_VERIFIED decomrp4"))));
$dj4->save();
JobResultProcessor::process($dj4);
$dvictim4->load();
$dhost4->load();
check(!empty($dvictim4->get('mgn_delete_time')),
	'a verified primitive decommission finalizes the VICTIM named in the params, through the real dispatch path',
	var_export($dvictim4->get('mgn_delete_time'), true));
check(empty($dhost4->get('mgn_delete_time')),
	'the HOST that ran the teardown is untouched');

// The same envelope carrying a failed verify leaves the victim intact.
$dvictim5 = jrp_node(array('mgn_container_name' => 'decomrp5', 'mgn_web_root' => '/var/www/html/decomrp5/public_html'));
$dj5 = ManagementJob::createFromBuild($dhost4->key, 'decommission_node',
	array('primitive' => 'decommission_site', 'params' => array('site' => 'decomrp5')),
	array('victim_node_id' => (int)$dvictim5->key, 'site' => 'decomrp5'), 1);
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $dj5->key);
$dj5->set('mjb_status', 'completed');
$dj5->set('mjb_output', json_encode(array('api_version' => 1, 'data' => array(
	'output' => "REMOVE_ACCOUNT_OK decomrp5\nDECOMMISSION_FAILED_VERIFY decomrp5\nstill present: volumes"))));
$dj5->save();
JobResultProcessor::process($dj5);
$dvictim5->load();
check(empty($dvictim5->get('mgn_delete_time')),
	'an envelope-wrapped failed verify leaves the victim intact');

// A host-subject job that somehow carries NO victim params must never finalize
// the host's record — the legacy subject fallback applies only to a subject
// that looks like a site.
$dhost6 = jrp_node(array());
$dj6 = jrp_job($dhost6, 'decommission_node', "REMOVE_ACCOUNT_OK ghost\nDECOMMISSION_VERIFIED ghost");
JobResultProcessor::process($dj6);
$dhost6->load();
check(empty($dhost6->get('mgn_delete_time')),
	'a victimless verified job never finalizes a siteless (host) subject');

section('A never-measured recovery-key state asks for a report, like a carried one');

// The plane's own node, paired after the API/SSH path retired, had never had
// the state measured: nothing carried, nothing asked, and every fleet backup
// pass skipped it as "awaiting its recovery key" (2026-09-13).
check(JobResultProcessor::wants_recovery_key_report(array('load_1m' => '0.1')),
	'a blob that has never held the state wants it measured');
check(JobResultProcessor::wants_recovery_key_report(array(
		'backup_recovery_state' => 'proven', 'status_carried_keys' => array('backup_recovery_state'))),
	'a state carried forward from an earlier check wants it measured again');
check(!JobResultProcessor::wants_recovery_key_report(array(
		'backup_recovery_state' => 'proven', 'status_carried_keys' => array('load_1m'))),
	'a state this check measured is not asked for again');
check(!JobResultProcessor::wants_recovery_key_report(array('backup_recovery_state' => 'unconfigured')),
	'a measured "unconfigured" is an answer, not a gap');

section('A node that hosts no site is never asked for its recovery key');

// The Docker host, enrolled in machine posture: an agent, no web root, and an
// agent vocabulary that includes recovery_key_report because the binary ships
// it. Its bundle does not carry the reporting script, so asking produced a
// manifest refusal the trust classifier read as a tampered file (2026-09-15).
function jrp_pending_jobs($node_id, $type) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare("SELECT mjb_management_job_id FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = ? AND mjb_status = 'pending' AND mjb_delete_time IS NULL ORDER BY mjb_management_job_id");
	$q->execute(array((int)$node_id, $type));
	$ids = $q->fetchAll(PDO::FETCH_COLUMN);
	foreach ($ids as $id) { harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $id); }
	return $ids;
}
$machine_status = "=== [Step 1/1] check_status ===\n" . json_encode(array('api_version' => '1.0', 'data' => array(
	'load_1m' => 0.4, 'memory_total_mb' => 3916, 'memory_used_mb' => 1685, 'uptime' => 'up 3 days'))) . "\n[Step 1/1 OK]";
$host_node = jrp_node(array(
	'mgn_agent_public_key' => base64_encode(str_repeat("\x0b", 32)),
	'mgn_agent_version'    => AgentVocabulary::FLOOR,
	'mgn_agent_primitives' => 'check_status,host_report,host_converge,recovery_key_report',
	'mgn_web_root'         => '',
));
JobResultProcessor::process(jrp_job($host_node, 'check_status', $machine_status));
check(jrp_pending_jobs($host_node->key, 'recovery_key_report') === array(),
	'a status check on a node with no web root queues no recovery_key_report');

// The same agent with a site: the state is unmeasured, and the report is asked for.
$site_node = jrp_node(array(
	'mgn_agent_public_key' => base64_encode(str_repeat("\x0c", 32)),
	'mgn_agent_version'    => AgentVocabulary::FLOOR,
	'mgn_agent_primitives' => 'check_status,host_report,host_converge,recovery_key_report',
	'mgn_web_root'         => '/var/www/html/fixture/public_html',
));
JobResultProcessor::process(jrp_job($site_node, 'check_status', $machine_status));
check(count(jrp_pending_jobs($site_node->key, 'recovery_key_report')) === 1,
	'the same status check on a node with a site queues one recovery_key_report');

section('A status check fills an empty web root, and leaves a set one alone');

// B20: a node made from a join before agent 1.44.0 has no web root, and so no
// site. The agent's own status report fills it.
$wr_status = function ($web_root) {
	return "=== [Step 1/1] check_status ===\n" . json_encode(array('api_version' => '1.0', 'data' => array(
		'web_root' => $web_root, 'load_1m' => 0.3, 'uptime' => 'up 2 days'))) . "\n[Step 1/1 OK]";
};
$wr_node = jrp_node(array(
	'mgn_agent_public_key' => base64_encode(str_repeat("\x0d", 32)),
	'mgn_web_root'         => '',
));
JobResultProcessor::process(jrp_job($wr_node, 'check_status', $wr_status('/var/www/html/wrfill/public_html')));
$wr_node = new ManagedNode($wr_node->key, TRUE);
check($wr_node->get('mgn_web_root') === '/var/www/html/wrfill/public_html' && $wr_node->hosts_site(),
	'an empty web root is filled from the report, and the node hosts a site', (string)$wr_node->get('mgn_web_root'));
check(count(jrp_pending_jobs($wr_node->key, 'recovery_key_report')) === 1,
	'and the same check asks it for its recovery key');

JobResultProcessor::process(jrp_job($wr_node, 'check_status', $wr_status('/var/www/html/elsewhere/public_html')));
$wr_node = new ManagedNode($wr_node->key, TRUE);
check($wr_node->get('mgn_web_root') === '/var/www/html/wrfill/public_html',
	'a set web root is never replaced by a different report', (string)$wr_node->get('mgn_web_root'));

$wr_bad = jrp_node(array(
	'mgn_agent_public_key' => base64_encode(str_repeat("\x0e", 32)),
	'mgn_web_root'         => '',
));
JobResultProcessor::process(jrp_job($wr_bad, 'check_status', $wr_status('/var/www/html/../../tmp/public_html')));
$wr_bad = new ManagedNode($wr_bad->key, TRUE);
check(trim((string)$wr_bad->get('mgn_web_root')) === '', 'a malformed report is refused and fills nothing');

section('verify_backup: the node\'s VERIFY_* lines become the plane\'s copy, in plain words');

$vpass = "fetching files-0000.tar.gz.enc\nVERIFY_RESULT=pass\nVERIFY_LEVEL=2\nVERIFY_RUN=chain-20260912_044520/3\n"
	. "VERIFY_RUN_TIME=2026-09-13 04:45:20\nVERIFY_ARTIFACTS=3\nVERIFY_BYTES=751829197\nVERIFY_FILES=1842\nVERIFY_DURATION=141\n";
$v = JobResultProcessor::parse_verify_backup_result($vpass, 'completed');
check($v['result'] === 'pass' && $v['level'] === 2 && $v['artifacts'] === 3 && $v['bytes'] === 751829197 && $v['files'] === 1842,
	'a pass reads back with its counts', var_export($v, true));
check($v['message'] === 'Opened and read the backup of 2026-09-13 04:45 UTC: 3 archives, 751.8 MB, 1,842 files.',
	'and its message is the plain-words sentence', $v['message']);

// The primitive transport wraps the text in the API envelope, newlines escaped.
$wrapped = "=== [Step 1/1] verify_backup ===\n" . json_encode(array('api_version' => 1, 'data' => array('output' => $vpass)));
$v = JobResultProcessor::parse_verify_backup_result($wrapped, 'completed');
check($v['result'] === 'pass' && $v['files'] === 1842, 'the envelope is unwrapped first', var_export($v, true));

$vfail = "VERIFY_RESULT=fail\nVERIFY_LEVEL=2\nVERIFY_RUN=chain-20260912_044520/3\nVERIFY_RUN_TIME=2026-09-13 04:45:20\n"
	. "VERIFY_ARTIFACTS=1\nVERIFY_BYTES=1000\nVERIFY_FILES=40\nVERIFY_DURATION=9\n"
	. "VERIFY_REASON=files-0001.tar.gz.enc does not match its recorded hash. It is damaged or was replaced; do not restore from it.\n";
$v = JobResultProcessor::parse_verify_backup_result($vfail, 'failed');
check($v['result'] === 'fail' && strpos($v['reason'], 'files-0001') === 0, 'a fail reads back with the node\'s reason');
check(strpos($v['message'], 'failed: files-0001.tar.gz.enc does not match') !== false, 'and the message carries it', $v['message']);

$vskip = "VERIFY_RESULT=skipped\nVERIFY_LEVEL=3\nVERIFY_RUN=chain-20260912_044520/3\nVERIFY_RUN_TIME=2026-09-13 04:45:20\n"
	. "VERIFY_ARTIFACTS=0\nVERIFY_BYTES=0\nVERIFY_FILES=0\nVERIFY_DURATION=0\nVERIFY_REASON=disk\n"
	. "VERIFY_NEEDS_BYTES=2600000000\nVERIFY_FREE_BYTES=900000000\n";
$v = JobResultProcessor::parse_verify_backup_result($vskip, 'completed');
check($v['result'] === 'skipped' && $v['needs_bytes'] === 2600000000 && $v['free_bytes'] === 900000000, 'a disk skip reads back with both numbers');
check($v['message'] === 'Could not verify the backup of 2026-09-13 04:45 UTC: needs 2.6 GB free, has 900 MB.',
	'and says them for a person', $v['message']);

// A rehearsal that proved the run's offloaded files: the three object lines
// read back as counts and reach the words.
$vobjects = "VERIFY_RESULT=pass\nVERIFY_LEVEL=3\nVERIFY_RUN=chain-20260912_044520/3\nVERIFY_RUN_TIME=2026-09-13 04:45:20\n"
	. "VERIFY_ARTIFACTS=4\nVERIFY_BYTES=751829197\nVERIFY_FILES=1842\nVERIFY_OBJECTS=1204\nVERIFY_OBJECT_BYTES=3435973836\n"
	. "VERIFY_OBJECTS_SAMPLED=20\nVERIFY_TABLES=214\nVERIFY_ROWS=usr_users:12\nVERIFY_DURATION=600\n";
$v = JobResultProcessor::parse_verify_backup_result($vobjects, 'completed');
check($v['objects'] === 1204 && $v['object_bytes'] === 3435973836 && $v['objects_sampled'] === 20, 'the offloaded-files counters read back as integers', var_export($v, true));
check(strpos($v['message'], '1,204 offloaded files (3.4 GB), 20 of them opened') !== false, 'and the message says how many were proven and opened', $v['message']);
$v = JobResultProcessor::parse_verify_backup_result("VERIFY_RESULT=fail\nVERIFY_LEVEL=2\nVERIFY_RUN=chain-20260912_044520/3\nVERIFY_RUN_TIME=2026-09-13 04:45:20\n"
	. "VERIFY_ARTIFACTS=4\nVERIFY_BYTES=100\nVERIFY_FILES=10\nVERIFY_OBJECTS=0\nVERIFY_OBJECT_BYTES=0\nVERIFY_DURATION=2\n"
	. "VERIFY_REASON=gone: objects/epoch-20260901_000000/beach.jpg.enc is no longer in backup storage (HTTP 404 from storage)\n", 'failed');
check($v['result'] === 'fail' && strpos($v['message'], 'failed: gone: objects/epoch-20260901_000000/beach.jpg.enc') !== false,
	'an object gone from backup storage fails the verify by name', $v['message']);

$v = JobResultProcessor::parse_verify_backup_result("=== [Step 1/1] ===\nsome agent noise\n", 'failed', 'Refused by the node: tree manifest signature does not verify');
check($v['result'] === 'fail' && strpos($v['reason'], 'Refused by the node') === 0,
	'a job that died before printing a result is a failed verify with the job\'s own error', var_export($v, true));
$v = JobResultProcessor::parse_verify_backup_result('', 'completed');
check($v['result'] === 'fail', 'and a completed job with no result line is never read as a pass');

// The fold onto the node: pass and fail stamp the four columns; a skip stamps
// only the message and leaves the last real result standing.
$jrp_vnode = new ManagedNode(NULL);
$jrp_vnode->set('mgn_name', 'HarnessTest verify node');
$jrp_vnode->set('mgn_slug', 'harnesstest-verify-' . bin2hex(random_bytes(3)));
$jrp_vnode->set('mgn_host', '192.0.2.44');
$jrp_vnode->save();
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $jrp_vnode->key);

$mk_vjob = function ($output, $status) use ($jrp_vnode) {
	$job = new ManagementJob(NULL);
	$job->set('mjb_mgn_managed_node_id', $jrp_vnode->key);
	$job->set('mjb_job_type', 'verify_backup');
	$job->set('mjb_status', $status);
	$job->set('mjb_commands', array());
	$job->set('mjb_output', $output);
	$job->set('mjb_completed_time', '2026-09-13 05:00:00');
	$job->save();
	harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $job->key);
	return $job;
};
JobResultProcessor::process($mk_vjob($vpass, 'completed'));
$jrp_vnode->load();
check((string)$jrp_vnode->get('mgn_backup_verify_outcome') === 'pass' && (int)$jrp_vnode->get('mgn_backup_verify_level') === 2
	&& strpos((string)$jrp_vnode->get('mgn_backup_verify_time'), '2026-09-13 05:00:00') === 0,
	'a pass stamps outcome, level and the job\'s completion time on the node');
check(strpos((string)$jrp_vnode->get('mgn_backup_verify_message'), 'Opened and read') === 0, 'and the sentence');

JobResultProcessor::process($mk_vjob($vskip, 'completed'));
$jrp_vnode->load();
check((string)$jrp_vnode->get('mgn_backup_verify_outcome') === 'pass'
	&& strpos((string)$jrp_vnode->get('mgn_backup_verify_time'), '2026-09-13 05:00:00') === 0,
	'a skip leaves the last real result and its time standing');
check(strpos((string)$jrp_vnode->get('mgn_backup_verify_message'), 'Could not verify') === 0, 'and records why it could not verify');

$vfail_job = $mk_vjob($vfail, 'failed');
JobResultProcessor::process($vfail_job);
$jrp_vnode->load();
check((string)$jrp_vnode->get('mgn_backup_verify_outcome') === 'fail', 'a fail stamps fail');
$vfail_job->load();
$vres = json_decode((string)$vfail_job->get('mjb_result'), true);
check(is_array($vres) && $vres['verify_status'] === 'fail' && strpos($vres['message'], 'failed:') !== false,
	'and the job records the result and the sentence', (string)$vfail_job->get('mjb_result'));

section('A verify the node ran itself is adopted from its status report, never rolled back');

$adopt_node = new ManagedNode(NULL);
$adopt_node->set('mgn_backup_verify_time', '2026-09-10 10:00:00');
$adopt_node->set('mgn_backup_verify_outcome', 'pass');
$adopt_node->set('mgn_backup_verify_level', 2);
check(!JobResultProcessor::adopt_reported_verify($adopt_node, array(
	'last_verify_time' => '2026-09-08 10:00:00', 'last_verify_outcome' => 'pass', 'last_verify_level' => 2)),
	'an older reported verify does not replace a newer stamp');
check(!JobResultProcessor::adopt_reported_verify($adopt_node, array('last_run' => '2026-09-12 04:00:00')),
	'a report with no verify in it changes nothing');
check(JobResultProcessor::adopt_reported_verify($adopt_node, array(
	'last_verify_time' => '2026-09-12 10:00:00', 'last_verify_outcome' => 'fail', 'last_verify_level' => 3,
	'last_verify_message' => 'Verification of the backup of 2026-09-12 04:00 UTC failed: x')),
	'a newer one is adopted');
check((string)$adopt_node->get('mgn_backup_verify_outcome') === 'fail' && (int)$adopt_node->get('mgn_backup_verify_level') === 3
	&& (string)$adopt_node->get('mgn_backup_verify_time') === '2026-09-12 10:00:00',
	'with its time, level, outcome and words');
check(!JobResultProcessor::adopt_reported_verify($adopt_node, array(
	'last_verify_time' => '2026-09-13 10:00:00', 'last_verify_outcome' => 'skipped')),
	'a skip is never adopted — nothing was proven either way');

// ---------------------------------------------------------------------------
section('site_log / log_table_tail: the envelope becomes a bounded result the job page renders');

$jrp_log_node = jrp_node();
$site_env = json_encode(['api_version' => '1.0', 'data' => [
	'file' => 'error', 'previous' => false, 'present' => true, 'size_bytes' => 93703,
	'modified_time' => '2026-09-18T11:54:05Z', 'lines_returned' => 2, 'truncated' => false,
	'text' => "[client <ip>:0] one\n[client <ip>:0] two\n",
]]);
$sj = jrp_job($jrp_log_node, 'site_log', "=== [Step 1/1] site_log ===\n" . $site_env . "\n[Step 1/1 OK]");
JobResultProcessor::process($sj);
$sr = json_decode((string)$sj->get('mjb_result'), true);
check(is_array($sr) && $sr['read'] === true && $sr['file'] === 'error' && $sr['lines_returned'] === 2,
	'a site_log envelope is recorded as the result with its file and line count', json_encode($sr));
check(is_array($sr) && strpos($sr['text'], '[client <ip>:0] two') !== false,
	'and the node-redacted text is carried as it arrived');
check(in_array('site_log', JobResultProcessor::processable_types(), true)
	&& in_array('log_table_tail', JobResultProcessor::processable_types(), true),
	'both log words are types the processor reconciles');

$big = json_encode(['api_version' => '1.0', 'data' => [
	'file' => 'error', 'present' => true, 'lines_returned' => 999999, 'text' => str_repeat('x', JobResultProcessor::LOG_EXCERPT_MAX_BYTES + 100),
]]);
$bj = jrp_job($jrp_log_node, 'site_log', $big);
JobResultProcessor::process($bj);
$br = json_decode((string)$bj->get('mjb_result'), true);
check(strlen($br['text']) === JobResultProcessor::LOG_EXCERPT_MAX_BYTES && $br['truncated'] === true
	&& $br['lines_returned'] === JobCommandBuilder::LOG_MAX_COUNT,
	'text past the node cap is cut on intake and marked truncated; the line count is bounded too');

$nj = jrp_job($jrp_log_node, 'site_log', "nothing like an envelope");
JobResultProcessor::process($nj);
check((string)$nj->get('mjb_result') === json_encode(['read' => false]),
	'output that is not a log envelope records read=false, never nothing', var_export($nj->get('mjb_result'), true));

$rows = [];
for ($i = 0; $i < JobCommandBuilder::LOG_MAX_COUNT + 5; $i++) {
	$rows[] = ['log_login_id' => $i, 'log_login_type' => 2, 'note' => str_repeat('y', JobResultProcessor::LOG_TABLE_MAX_CELL + 10), 'nested' => ['a' => 1]];
}
$tab_env = json_encode(['api_version' => '1.0', 'data' => [
	'table' => 'logins', 'columns' => ['log_login_id', 'log_login_type', 'note', 'nested', 'no such; column'],
	'rows' => $rows, 'truncated' => false,
]]);
$tj = jrp_job($jrp_log_node, 'log_table_tail', $tab_env);
JobResultProcessor::process($tj);
$tr = json_decode((string)$tj->get('mjb_result'), true);
check(is_array($tr) && $tr['read'] === true && $tr['table'] === 'logins'
	&& count($tr['rows']) === JobCommandBuilder::LOG_MAX_COUNT && $tr['truncated'] === true,
	'rows past the cap are dropped on intake and the result says so', json_encode(array_slice((array)$tr, 0, 3)));
check(is_array($tr) && $tr['rows'][0]['log_login_id'] === 0 && $tr['rows'][0]['log_login_type'] === 2,
	'numeric cells stay numbers');
check(is_array($tr) && strlen($tr['rows'][0]['note']) === JobResultProcessor::LOG_TABLE_MAX_CELL,
	'a cell longer than the cell cap is cut');
check(is_array($tr) && $tr['rows'][0]['nested'] === '{"a":1}',
	'a non-scalar cell is stored as its JSON text, never as structure');
check(is_array($tr) && in_array('nosuchcolumn', $tr['columns'], true) && !in_array('no such; column', $tr['columns'], true),
	'a column name is reduced to identifier characters');

$tn = jrp_job($jrp_log_node, 'log_table_tail', json_encode(['api_version' => '1.0', 'data' => ['table' => 'logins']]));
JobResultProcessor::process($tn);
check((string)$tn->get('mjb_result') === json_encode(['read' => false]),
	'a table envelope without rows records read=false');

// ---------------------------------------------------------------------------
section('restore_objects: the node\'s answer is recorded and the next page of the loop is issued');

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/FleetObjectRestore.php'));
require_once(PathHelper::getIncludePath('data/backup_targets_class.php'));

// The pure parse: a survey, a page, a job that died before printing.
$survey_out = "RESTORE_OBJECTS_RESULT=ok\nRESTORE_OBJECTS_MODE=missing\nRESTORE_OBJECTS_RUN=chain-20260901_040000/1\n"
	. "RESTORE_OBJECTS_INDEXED=3\nRESTORE_OBJECTS_NOT_ON_SHELF=1\nRESTORE_OBJECTS_WANTED=2\n"
	. "RESTORE_OBJECTS_EPOCHS=epoch-20260801_000000:1,epoch-20260901_000000:1\nRESTORE_OBJECTS_WANT=beach.jpg,dune.png\n"
	. "RESTORE_OBJECTS_SKIPPED=1\nRESTORE_OBJECTS_DURATION=3\n";
$r = JobResultProcessor::parse_restore_objects_result("=== [Step 1/1] ===\n" . json_encode(array('api_version' => 1, 'data' => array('output' => $survey_out))), 'completed');
check($r['result'] === 'ok' && $r['want'] === array('beach.jpg', 'dune.png') && $r['wanted'] === 2 && $r['more'] === false
	&& $r['epochs'] === array('epoch-20260801_000000' => 1, 'epoch-20260901_000000' => 1),
	'a survey reads back through the envelope with its names and epochs', var_export($r, true));
check($r['message'] === '2 offloaded files to bring home (1 offloaded file never reached backup storage; 1 need nothing: served by the file store, or already here)',
	'and says so for a person', $r['message']);
$page_out = "fetching objects/epoch-20260801_000000/beach.jpg.enc\nrestored beach.jpg (3.9 KB)\nRESTORE_OBJECTS_RESULT=ok\nRESTORE_OBJECTS_MODE=missing\n"
	. "RESTORE_OBJECTS_RUN=chain-20260901_040000/1\nRESTORE_OBJECTS_INDEXED=3\nRESTORE_OBJECTS_NOT_ON_SHELF=1\nRESTORE_OBJECTS_RESTORED=2\n"
	. "RESTORE_OBJECTS_BYTES=5200\nRESTORE_OBJECTS_KEPT=0\nRESTORE_OBJECTS_SKIPPED=0\nRESTORE_OBJECTS_DURATION=9\n";
$r = JobResultProcessor::parse_restore_objects_result($page_out, 'completed');
check($r['restored'] === 2 && $r['bytes'] === 5200 && strpos($r['message'], 'Brought 2 offloaded files home (5.2 KB)') === 0, 'a page reads back with its counts', $r['message']);
$r = JobResultProcessor::parse_restore_objects_result("noise\n", 'failed', 'Refused by the node: out of vocabulary');
check($r['result'] === 'fail' && strpos($r['reason'], 'Refused by the node') === 0 && strpos($r['message'], 'Could not bring') === 0,
	'a job that died before printing is a failed step with the job\'s own error', var_export($r, true));
check(JobResultProcessor::parse_restore_objects_result('', 'completed')['result'] === 'fail', 'a completed job with no result line is never read as ok');

// The loop, over a stood-in shelf: a node whose agent has the word and a
// chain whose newest run carries an index naming two stored objects.
$ro_bkt = new BackupTarget(NULL);
$ro_bkt->set('bkt_name', 'HarnessTest RO Target ' . bin2hex(random_bytes(3)));
$ro_bkt->set('bkt_provider', 'b2');
$ro_bkt->set('bkt_bucket', 'harness-ro-bucket');
$ro_bkt->set('bkt_enabled', true);
$ro_bkt->set('bkt_credentials', json_encode(array('key_id' => 'k', 'application_key' => 'a')));
$ro_bkt->save();
harness_register_row('bkt_backup_targets', 'bkt_backup_target_id', $ro_bkt->key);
$ro_node = jrp_node(array(
	'mgn_web_root'             => '/var/www/html/rosite/public_html',
	'mgn_slug'                 => 'rosite-' . bin2hex(random_bytes(2)),
	'mgn_bkt_backup_target_id' => $ro_bkt->key,
	'mgn_agent_public_key'     => base64_encode(str_repeat("\x05", 32)),
	'mgn_agent_version'        => AgentVocabulary::FLOOR,
	'mgn_last_status_data'     => json_encode(array('backup_recovery_state' => 'proven')),
	'mgn_backup_recovery_fpr'  => str_repeat('c3', 32)));
$ro_target = JobCommandBuilder::get_target($ro_node);
if (!$ro_target) {
	harness_skip('restore_objects loop', 'no enabled backup target on this management node to resolve a shelf against');
} else {
	$ro_prefix = rtrim((string)($ro_target->get('bkt_path_prefix') ?: 'joinery-backups'), '/') . '/' . $ro_node->get('mgn_slug') . '/manager/chain-20260901_040000/';
	$ro_index = array('version' => 1, 'profile' => 'manager', 'run' => 'chain-20260901_040000/1', 'created' => '2026-09-02T04:00:00Z',
		'epochs' => array('epoch-20260801_000000', 'epoch-20260901_000000'), 'objects' => array(
		array('name' => 'beach.jpg', 'epoch' => 'epoch-20260801_000000', 'object_bytes' => 4000, 'object_sha256' => str_repeat('a', 64), 'stored' => true),
		array('name' => 'dune.png',  'epoch' => 'epoch-20260901_000000', 'object_bytes' => 1200, 'object_sha256' => str_repeat('b', 64), 'stored' => true),
	));
	JobCommandBuilder::set_shelf_listing_for_tests(array(
		array('key' => $ro_prefix . 'manifest.json',         'size' => 900),
		array('key' => $ro_prefix . 'files-0000.tar.gz.enc', 'size' => 1000),
		array('key' => $ro_prefix . 'files-0001.tar.gz.enc', 'size' => 200),
		array('key' => $ro_prefix . 'db-0001.sql.gz.enc',    'size' => 110),
		array('key' => $ro_prefix . 'objects-0001.json.gz',  'size' => 300),
	), array($ro_prefix . 'objects-0001.json.gz' => $ro_index));
	harness_defer(function () { JobCommandBuilder::set_shelf_listing_for_tests(null); });

	$ro_jobs = function ($type, $after_id) use ($ro_node) {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT mjb_management_job_id FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = ?
			AND mjb_management_job_id > ? AND mjb_delete_time IS NULL ORDER BY mjb_management_job_id");
		$q->execute(array($ro_node->key, $type, (int)$after_id));
		$out = array();
		foreach ($q->fetchAll(PDO::FETCH_COLUMN, 0) as $id) {
			harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $id);
			$out[] = new ManagementJob((int)$id, TRUE);
		}
		return $out;
	};
	$finish = function ($job, $output, $status = 'completed') {
		$job->set('mjb_status', $status);
		$job->set('mjb_output', $output);
		$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
		$job->save();
		JobResultProcessor::process($job);
		$job->load();
		return json_decode((string)$job->get('mjb_result'), true);
	};

	// 1. The chain restore's last step starts a survey.
	$rc = new ManagementJob(NULL);
	$rc->set('mjb_mgn_managed_node_id', $ro_node->key);
	$rc->set('mjb_job_type', 'restore_chain');
	$rc->set('mjb_status', 'completed');
	$rc->set('mjb_commands', array());
	$rc->set('mjb_parameters', json_encode(array('chain_id' => 'chain-20260901_040000', 'profile' => 'manager', 'seq' => '', 'domain' => 'x.test')));
	$rc->set('mjb_output', "RESTORE_OK\n");
	$rc->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
	$rc->save();
	harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $rc->key);
	JobResultProcessor::process($rc);
	$rc->load();
	$rc_result = json_decode((string)$rc->get('mjb_result'), true);
	$surveys = $ro_jobs('restore_objects', $rc->key);
	check(count($surveys) === 1 && ($rc_result['objects_job'] ?? null) === (int)$surveys[0]->key,
		'a completed chain restore starts the offloaded-files survey and records its job', json_encode($rc_result));
	$survey_job = $surveys[0];
	$srec = json_decode((string)$survey_job->get('mjb_parameters'), true);
	$scmd = json_decode((string)$survey_job->get('mjb_commands'), true);
	check($scmd['primitive'] === 'restore_objects' && $scmd['params']['mode'] === 'missing' && $scmd['params']['seq'] === 1
		&& !isset($scmd['params']['object_urls']) && $srec['step'] === 'survey' && $srec['root'] === (int)$rc->key,
		'in missing mode, for the newest run, with no object links, recorded as the survey of this restore', json_encode($scmd) . json_encode($srec));

	// 2. The survey's answer issues the first page.
	$sres = $finish($survey_job, $survey_out);
	check($sres['want'] === array('beach.jpg', 'dune.png') && $sres['restore_status'] === 'ok', 'the survey\'s names are recorded on its result', json_encode($sres));
	$pages = $ro_jobs('restore_objects', $survey_job->key);
	check(count($pages) === 1 && ($sres['next_job'] ?? null) === (int)$pages[0]->key, 'and one page job follows it', json_encode($sres));
	$page_job = $pages[0];
	$pcmd = json_decode((string)$page_job->get('mjb_commands'), true);
	$prec = json_decode((string)$page_job->get('mjb_parameters'), true);
	// jsonb keeps a map's members, not their order.
	$pobjects = array_keys($pcmd['params']['object_urls'] ?? array()); sort($pobjects);
	$pepochs  = array_keys($pcmd['params']['epoch_envelope_urls'] ?? array()); sort($pepochs);
	check($pobjects === array('beach.jpg', 'dune.png') && $pepochs === array('epoch-20260801_000000', 'epoch-20260901_000000'),
		'the page carries the two objects and both envelopes', json_encode($pcmd['params']));
	check($prec['step'] === 'page' && $prec['origin'] === (int)$survey_job->key && $prec['cursor'] === 0 && $prec['count'] === 2 && $prec['root'] === (int)$rc->key,
		'its record names the survey, the slice and the restore it belongs to', json_encode($prec));

	// 3. The last page ends the loop.
	$pres = $finish($page_job, $page_out);
	check($pres['restored'] === 2 && $pres['next_job'] === null && $pres['next'] === 'every page of the survey is done',
		'the page\'s counts are recorded and, the answer covered, no further job is issued', json_encode($pres));
	check(count($ro_jobs('restore_objects', $page_job->key)) === 0, 'nothing else was queued');

	// 4. A capped survey: pages, then a fresh survey.
	$survey2 = FleetObjectRestore::start($ro_node, array('chain_id' => 'chain-20260901_040000', 'profile' => 'manager', 'mode' => 'all'), null);
	harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $survey2->key);
	$s2res = $finish($survey2, str_replace("RESTORE_OBJECTS_WANT=beach.jpg,dune.png\n", "RESTORE_OBJECTS_WANT=beach.jpg\nRESTORE_OBJECTS_MORE=1\n", $survey_out));
	$p2 = $ro_jobs('restore_objects', $survey2->key);
	check(count($p2) === 1 && $s2res['more'] === true && array_keys(json_decode((string)$p2[0]->get('mjb_commands'), true)['params']['object_urls']) === array('beach.jpg'),
		'a capped survey pages what it named', json_encode($s2res));
	$p2res = $finish($p2[0], $page_out);
	$s3 = $ro_jobs('restore_objects', $p2[0]->key);
	$s3rec = count($s3) ? json_decode((string)$s3[0]->get('mjb_parameters'), true) : array();
	check(count($s3) === 1 && ($s3rec['step'] ?? '') === 'survey' && ($s3rec['mode'] ?? '') === 'all' && ($p2res['next_job'] ?? null) === (int)$s3[0]->key,
		'and once its pages are done a fresh survey asks for the rest, in the same mode', json_encode($p2res) . json_encode($s3rec));
	$s3res = $finish($s3[0], str_replace("RESTORE_OBJECTS_WANT=beach.jpg,dune.png\n", "RESTORE_OBJECTS_WANT=\n", $survey_out));
	check($s3res['next_job'] === null && $s3res['next'] === 'the survey named nothing to bring home' && count($ro_jobs('restore_objects', $s3[0]->key)) === 0,
		'a survey that names nothing ends the loop', json_encode($s3res));

	// 5. A failed page ends the loop with its reason.
	$survey4 = FleetObjectRestore::start($ro_node, array('chain_id' => 'chain-20260901_040000', 'profile' => 'manager'), null);
	harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $survey4->key);
	$finish($survey4, $survey_out);
	$p4 = $ro_jobs('restore_objects', $survey4->key);
	$p4res = $finish($p4[0], "RESTORE_OBJECTS_RESULT=fail\nRESTORE_OBJECTS_MODE=missing\nRESTORE_OBJECTS_REASON=offloaded file dune.png: dune.png.enc does not match its recorded hash\n", 'failed');
	check($p4res['restore_status'] === 'fail' && strpos($p4res['message'], 'offloaded file dune.png') !== false && $p4res['next_job'] === null
		&& count($ro_jobs('restore_objects', $p4[0]->key)) === 0,
		'a failed page records why and issues nothing after it', json_encode($p4res));

	// 6. A stale chain-restore result does not start anything; a node without the word is told so.
	$rc_old = new ManagementJob(NULL);
	$rc_old->set('mjb_mgn_managed_node_id', $ro_node->key);
	$rc_old->set('mjb_job_type', 'restore_chain');
	$rc_old->set('mjb_status', 'completed');
	$rc_old->set('mjb_commands', array());
	$rc_old->set('mjb_parameters', json_encode(array('chain_id' => 'chain-20260901_040000', 'profile' => 'manager')));
	$rc_old->set('mjb_completed_time', '2026-08-01 00:00:00');
	$rc_old->save();
	harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $rc_old->key);
	JobResultProcessor::process($rc_old);
	$rc_old->load();
	$old_res = json_decode((string)$rc_old->get('mjb_result'), true);
	check(!isset($old_res['objects_job']) && strpos((string)($old_res['objects'] ?? ''), 'before its result was read') !== false
		&& count($ro_jobs('restore_objects', $rc_old->key)) === 0,
		'a chain restore whose result is read long after it finished starts no loop and says why', json_encode($old_res));
	$plain_node = jrp_node(array('mgn_agent_public_key' => base64_encode(str_repeat("\x06", 32)), 'mgn_agent_version' => AgentVocabulary::FLOOR,
		'mgn_agent_primitives' => 'check_status,restore_chain', 'mgn_bkt_backup_target_id' => $ro_bkt->key));
	$rc_plain = jrp_job($plain_node, 'restore_chain', "RESTORE_OK\n");
	$rc_plain->set('mjb_parameters', json_encode(array('chain_id' => 'chain-20260901_040000', 'profile' => 'manager')));
	$rc_plain->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
	$rc_plain->save();
	JobResultProcessor::process($rc_plain);
	$rc_plain->load();
	$plain_res = json_decode((string)$rc_plain->get('mjb_result'), true);
	check(strpos((string)($plain_res['objects'] ?? ''), 'update this node') !== false && count($ro_jobs('restore_objects', $rc_plain->key)) === 0,
		'a node whose agent lacks the word gets its restore recorded and the reason no files followed', json_encode($plain_res));
}

section('unit_journal and disk_usage: the two observe words land as bounded results');

{
	$uj_node = jrp_node();
	$envelope = function ($object) {
		return "=== [Step 1/1] word ===\n" . json_encode(array(
			'api_version' => '1.0',
			'data' => array('output' => json_encode($object), 'output_bytes' => 1),
		));
	};

	// A whole answer, of the shape the node's script prints.
	$uj_job = jrp_job($uj_node, 'unit_journal', $envelope(array(
		'unit' => 'man-db.service', 'load_state' => 'loaded', 'active_state' => 'failed',
		'sub_state' => 'failed', 'result' => 'exit-code', 'exit_status' => 1,
		'last_run_unix' => 1790050543, 'lines_requested' => 100, 'lines_returned' => 2,
		'journal' => array('mandb: No space left on device', 'man-db.service: Failed with result exit-code.'),
	)));
	JobResultProcessor::process($uj_job);
	$r = json_decode((string)$uj_job->get('mjb_result'), true);
	check(is_array($r) && $r['read'] === true && $r['unit'] === 'man-db.service' && $r['result'] === 'exit-code'
		&& $r['exit_status'] === 1 && $r['last_run_unix'] === 1790050543,
		'the verdict survives: the unit, the result systemd recorded and the number the unit left behind',
		var_export($r, true));
	check(is_array($r) && $r['lines_returned'] === 2 && strpos($r['journal'][0], 'No space left') !== false,
		'and so do the journal lines the node redacted before sending them');

	// A hostile node: the shape is rebuilt, never trusted.
	$hostile = jrp_job($uj_node, 'unit_journal', $envelope(array(
		'unit' => "<script>x</script>; rm -rf /",
		'load_state' => array('nested'), 'active_state' => 'failed', 'sub_state' => 'failed',
		'result' => 'exit-code', 'exit_status' => -3, 'last_run_unix' => 'yesterday',
		'journal' => array_merge(array(str_repeat('x', 5000), array('nested')), array_fill(0, 400, 'line')),
		'surprise' => 'key',
	)));
	JobResultProcessor::process($hostile);
	$h = json_decode((string)$hostile->get('mjb_result'), true);
	check(is_array($h) && !isset($h['surprise']) && $h['unit'] === 'scriptxscriptrm-rf'
		&& $h['load_state'] === 'unknown' && $h['exit_status'] === 'unknown' && $h['last_run_unix'] === 'unknown',
		'unknown keys are dropped, a name is reduced to safe characters, a negative status and a word are unknown',
		var_export($h, true));
	// 200 taken, one of them a non-scalar that is dropped rather than coerced:
	// the cap is a maximum, not a quota to fill.
	check(is_array($h) && count($h['journal']) === JobCommandBuilder::LOG_MAX_COUNT - 1
		&& strlen($h['journal'][0]) === JobResultProcessor::UNIT_JOURNAL_MAX_LINE
		&& $h['lines_returned'] === count($h['journal']),
		'the list is capped at the plane\'s own bound, each line is capped, and a non-scalar line is dropped',
		count($h['journal'] ?? array()));

	// Nothing readable: the flag says so rather than the page showing a
	// transcript with no explanation.
	$unread = jrp_job($uj_node, 'unit_journal', "=== [Step 1/1] word ===\nbash: unit_journal.sh: No such file\n");
	JobResultProcessor::process($unread);
	check(json_decode((string)$unread->get('mjb_result'), true) === array('read' => false),
		'a job that came back with no object records read=false');

	// disk_usage: sizes only, and the shape enforces it.
	$du_job = jrp_job($uj_node, 'disk_usage', $envelope(array(
		'filesystem' => array('path' => '/var/www/html/x/public_html', 'used_bytes' => 100, 'total_bytes' => 200, 'avail_bytes' => 80),
		'tree' => array('path' => '/var/www/html/x', 'total_bytes' => 90, 'partial' => true, 'entries' => array_merge(
			array(array('path' => 'uploads', 'bytes' => 50, 'files' => 900), array('path' => '<b>bad</b>', 'bytes' => 1)),
			array_fill(0, 40, array('path' => 'd', 'bytes' => 1)))),
		'machine' => array(array('path' => '/var/log', 'bytes' => 7), array('path' => '/var/lib/docker', 'bytes' => 'absent')),
		'generated_at' => 1790050543,
	)));
	JobResultProcessor::process($du_job);
	$d = json_decode((string)$du_job->get('mjb_result'), true);
	check(is_array($d) && $d['read'] === true && $d['filesystem']['avail_bytes'] === 80 && $d['tree']['partial'] === true,
		'the filesystem figures and the partial flag survive', var_export($d['filesystem'] ?? null, true));
	check(is_array($d) && count($d['tree']['entries']) === JobResultProcessor::DISK_USAGE_MAX_ENTRIES,
		'the entry list is capped on the plane too');
	check(is_array($d) && $d['tree']['entries'][0] === array('path' => 'uploads', 'bytes' => 50),
		'an entry is one path and one byte count: a file count the node sent is dropped, not stored',
		var_export($d['tree']['entries'][0] ?? null, true));
	// The slash survives because a path is made of them; the angle brackets do
	// not, because nothing in a path is.
	check(is_array($d) && $d['tree']['entries'][1]['path'] === 'bbad/b',
		'a path is reduced to path characters', var_export($d['tree']['entries'][1] ?? null, true));
	check(is_array($d) && $d['machine'][1] === array('path' => '/var/lib/docker', 'bytes' => 'absent'),
		'absent is an answer a size may take: the directory is not there');
}

section('reset_failed_unit: before and after, and a fresh host report behind an accepted reset');

{
	$rf_node = jrp_node(array(
		'mgn_agent_public_key' => base64_encode(str_repeat("\x0d", 32)),
		'mgn_agent_version'    => AgentVocabulary::FLOOR,
		'mgn_agent_primitives' => 'check_status,host_report,unit_journal,reset_failed_unit',
	));
	$rf_envelope = function ($object) {
		return "=== [Step 1/1] word ===\n" . json_encode(array(
			'api_version' => '1.0',
			'data' => array('output' => json_encode($object), 'output_bytes' => 1),
		));
	};
	$host_reports = function () use ($rf_node) {
		$n = 0;
		foreach (new MultiManagementJob(array('node_id' => (int)$rf_node->key, 'job_type' => 'host_report')) as $j) {
			harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $j->key);
			$n++;
		}
		return $n;
	};

	$rf_job = jrp_job($rf_node, 'reset_failed_unit', $rf_envelope(array(
		'unit' => 'man-db.service',
		'before' => array('active_state' => 'failed', 'sub_state' => 'failed', 'result' => 'exit-code'),
		'reset' => true,
		'after' => array('active_state' => 'inactive', 'sub_state' => 'dead', 'result' => 'success'),
	)));
	JobResultProcessor::process($rf_job);
	$r = json_decode((string)$rf_job->get('mjb_result'), true);
	check(is_array($r) && $r['read'] === true && $r['unit'] === 'man-db.service' && $r['reset'] === true
		&& $r['before']['active_state'] === 'failed' && $r['after']['active_state'] === 'inactive'
		&& $r['after']['result'] === 'success',
		'the unit\'s state before and after is recorded', var_export($r, true));
	check($host_reports() === 1, 'an accepted reset queues one host report, so the Host card re-measures');

	$again = jrp_job($rf_node, 'reset_failed_unit', $rf_envelope(array(
		'unit' => 'cron.service', 'before' => array(), 'reset' => true, 'after' => array(),
	)));
	JobResultProcessor::process($again);
	check($host_reports() === 1, 'a second reset while that report is queued does not pile on another');

	$hostile = jrp_job($rf_node, 'reset_failed_unit', $rf_envelope(array(
		'unit' => '<b>x</b>', 'before' => 'nope', 'reset' => 'yes',
		'after' => array('active_state' => array('nested')), 'surprise' => 1,
	)));
	JobResultProcessor::process($hostile);
	$h = json_decode((string)$hostile->get('mjb_result'), true);
	check(is_array($h) && !isset($h['surprise']) && $h['reset'] === false && $h['unit'] === 'bxb',
		'a reset that is not literally true is false; unknown keys go; the name is reduced', var_export($h, true));
	check(is_array($h) && $h['before']['active_state'] === 'unknown' && $h['after']['active_state'] === 'unknown',
		'a state that is not a word is unknown');

	$unread = jrp_job($rf_node, 'reset_failed_unit', "=== [Step 1/1] word ===\nrefused\n");
	JobResultProcessor::process($unread);
	check(json_decode((string)$unread->get('mjb_result'), true) === array('read' => false),
		'a job that came back with no object records read=false');
}


harness_finish();
