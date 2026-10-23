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
	// backup_target_id: the node backs up to that target (its active storage space there).
	$target_id = (int)($fields['backup_target_id'] ?? 0);
	unset($fields['backup_target_id']);
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
	if ($target_id) {
		$space = StorageSpace::open(new BackupTarget($target_id, TRUE), StorageSpace::OWNER_NODE, (int)$node->key,
			(string)$node->get('mgn_slug'));
		harness_register_row('sps_storage_spaces', 'sps_storage_space_id', $space->key);
	}
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

// The PHP a node runs, and whether old code still serves (agent 1.88.0, fleet_security_patching WP1).
$php_job = jrp_job($node, 'check_status', "=== [Step 1/1] check_status ===\n" . json_encode(array('api_version' => '1.0', 'data' => array(
	'joinery_version' => '2.14.3', 'disk_usage_percent' => 30,
	'php' => array(
		'packages' => array(array('name' => 'php8.5-fpm', 'version' => '8.5.4-0ubuntu1.3'), array('name' => '<b>', 'version' => '1'), 'junk'),
		'fpm' => array(array('binary' => '/usr/sbin/php-fpm8.5', 'running_current' => false, 'started_at' => 1761000500, 'oldest_worker_started_at' => 1761000600),
			array('binary' => '/usr/sbin/php-fpm8.3;rm', 'running_current' => 'yes')),
	)))));
JobResultProcessor::process($php_job);
$node->load();
$stored = $node->get('mgn_last_status_data');
if (is_string($stored)) { $stored = json_decode($stored, true); }
check(($stored['php']['packages'] ?? null) == array(array('name' => 'php8.5-fpm', 'version' => '8.5.4-0ubuntu1.3', 'installed_at' => 'unknown'))
		&& $stored['php']['fpm'][0] == array('binary' => '/usr/sbin/php-fpm8.5', 'running_current' => false, 'started_at' => 1761000500, 'oldest_worker_started_at' => 1761000600)
		&& $stored['php']['fpm'][0]['running_current'] === false,
	'php is stored: each package with its version, each php-fpm with whether it runs the installed binary', var_export($stored['php'] ?? null, true));
check($stored['php']['fpm'][1]['binary'] === '' && $stored['php']['fpm'][1]['running_current'] === null,
	'a path that is not a path is dropped, and a running_current that is not true or false is null (unknown), never true');

// The packages a package run left half done (agent 1.90.0, fleet_security_patching B17).
$dpkg_job = jrp_job($node, 'check_status', "=== [Step 1/1] check_status ===\n" . json_encode(array('api_version' => '1.0', 'data' => array(
	'joinery_version' => '2.14.3', 'disk_usage_percent' => 30,
	'dpkg_unfinished' => array('count' => 31, 'packages' => array(
		array('name' => 'php8.5-fpm', 'state' => 'unpacked'), array('name' => 'libc-bin', 'state' => 'triggers-pending'),
		array('name' => 'odd', 'state' => 'on fire'), array('name' => '$(rm)', 'state' => 'unpacked'), 'junk')),
	))));
JobResultProcessor::process($dpkg_job);
$node->load();
$stored = $node->get('mgn_last_status_data');
if (is_string($stored)) { $stored = json_decode($stored, true); }
check(($stored['dpkg_unfinished'] ?? null) == array('count' => 31, 'packages' => array(
		array('name' => 'php8.5-fpm', 'state' => 'unpacked'), array('name' => 'libc-bin', 'state' => 'triggers-pending'), array('name' => 'odd', 'state' => 'unknown')),
		'in_progress' => null, 'since' => null),
	'dpkg_unfinished is stored: the count, and each package with its state; a state not dpkg\'s reads unknown, a name not a package name is dropped',
	var_export($stored['dpkg_unfinished'] ?? null, true));
check(JobResultProcessor::status_dpkg_unfinished(array('count' => '3', 'packages' => array())) === null
		&& JobResultProcessor::status_dpkg_unfinished(array('count' => 0, 'packages' => array())) === array('count' => 0, 'packages' => array(), 'in_progress' => null, 'since' => null)
		&& JobResultProcessor::status_dpkg_unfinished(array('count' => 0, 'packages' => array(array('name' => 'a', 'state' => 'unpacked'))))['count'] === 1,
	'a count that is not a number is not believed; zero is clean; the count is never fewer than the packages named');
$held = JobResultProcessor::status_dpkg_unfinished(array('count' => 0, 'packages' => array(), 'in_progress' => true, 'since' => 1761000000));
$odd = JobResultProcessor::status_dpkg_unfinished(array('count' => 0, 'packages' => array(), 'in_progress' => 'yes', 'since' => -4));
check($held['in_progress'] === true && $held['since'] === 1761000000 && $odd['in_progress'] === null && $odd['since'] === null,
	'a run holding its lock is kept with when it started; an in_progress not true or false, or a start not a time, is unknown');

// ---------------------------------------------------------------------------
section('host_report: root_ssh keeps fingerprints, and carries only well-formed bare keys (WP15)');
$wp15_fp = 'SHA256:' . str_repeat('A', 43);
$wp15_in = array('root_ssh' => array('keys' => array(
	array('fingerprint' => $wp15_fp, 'carry' => true, 'type' => 'ssh-ed25519', 'key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIPyy+kqQ21YuiI+qWRCicmjdsq8hkABB6c3m50p31z4N', 'comment' => 'jeremy@laptop'),
	array('fingerprint' => $wp15_fp, 'carry' => true, 'type' => 'ssh-ed25519', 'key' => "AAAA\nssh-rsa evil", 'comment' => ''),
	array('fingerprint' => 'not-a-fingerprint', 'carry' => true, 'type' => 'ssh-rsa', 'key' => 'AAAB', 'comment' => ''),
	array('fingerprint' => $wp15_fp, 'carry' => false, 'type' => '', 'key' => '', 'comment' => ''),
)));
$wp15_out = JobResultProcessor::sanitise_host_report($wp15_in)['root_ssh'];
check($wp15_out['keys'][0]['carry'] === true && $wp15_out['keys'][0]['comment'] === 'jeremy@laptop',
	'a well-formed bare key is carryable');
check($wp15_out['keys'][1]['carry'] === false && $wp15_out['keys'][1]['key'] === '',
	'a key with anything but base64 in it is listed, not carried');
check($wp15_out['keys'][2]['carry'] === false && $wp15_out['keys'][2]['fingerprint'] === 'unknown',
	'a key with no real fingerprint is not carried');
check($wp15_out['keys'][3]['carry'] === false && $wp15_out['keys'][3]['fingerprint'] === $wp15_fp,
	'a restricted key shows its fingerprint and is not carried');
check(JobResultProcessor::sanitise_host_report(array())['root_ssh'] === null
	&& JobResultProcessor::sanitise_host_report(array('root_ssh' => 'x'))['root_ssh'] === 'unknown',
	'an older node reports none; junk reads unknown');

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
	'reboot_required_since' => 1789280500,
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
check(is_array($stored) && ($stored['reboot_required_since'] ?? null) === 1789280500,
	'and when the reboot was first asked for');
check(is_array($stored) && $stored['os'] === $hr_object['os'],
	'the operating system and the upgrade it is offered survive as sent', var_export($stored['os'] ?? null, true));
check(!empty($hr_node->get('mgn_last_host_report_time')),
	'and the read time is stamped');

// A machine placed in a host record with no host node is named its agent by its
// own report (fleet_security_patching B26: getjoinery's docker-prod never was).
$hr_place = new ManagedHost(NULL);
$hr_place->set('mgh_slug', 'harnessrp-b26-' . bin2hex(random_bytes(3)));
$hr_place->set('mgh_name', 'HarnessTest RP b26 host');
$hr_place->set('mgh_host', '198.51.100.' . random_int(10, 250));
$hr_place->set('mgh_provisioning_enabled', false);
$hr_place->prepare();
$hr_place->save();
harness_register_row('mgh_managed_hosts', 'mgh_managed_host_id', $hr_place->key);
$hr_machine = jrp_node(array('mgn_host' => (string)$hr_place->get('mgh_host'), 'mgn_mgh_managed_host_id' => (int)$hr_place->key));
JobResultProcessor::process(jrp_job($hr_machine, 'host_report', $hr_envelope));
$hr_place->load();
check((int)$hr_place->get('mgh_mgn_managed_node_id') === (int)$hr_machine->key,
	'a host report from a machine placed in a host record with no host node names it that host\'s agent (B26)');
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
	'cpus' => '4; reboot',
	'surprise' => 'key',
);
$capped = JobResultProcessor::sanitise_host_report($hostile);
check(!isset($capped['surprise']) && count($capped) === 34 && array_key_exists('security_pending', $capped) && array_key_exists('services_needing_restart', $capped) && array_key_exists('data_root_encryption', $capped) && array_key_exists('sites', $capped) && array_key_exists('site_security', $capped)
	&& array_key_exists('mail_gateway', $capped) && array_key_exists('host_restrictions', $capped)
	&& array_key_exists('data_root_script', $capped) && array_key_exists('boot_id', $capped)
	&& array_key_exists('site_engine', $capped) && array_key_exists('data_root_stopped', $capped)
	&& array_key_exists('hardening', $capped) && array_key_exists('site_refresh', $capped),
	'unknown keys are dropped and every known key is present (data_root_encryption, host_restrictions, data_root_script, data_root_stopped, boot_id, site_engine, hardening, site_refresh, security_pending, services_needing_restart, sites among them)', var_export(array_keys($capped), true));
check($capped['data_root_encryption'] === null, 'a node that sends no data_root_encryption: not reported (null), never "none"');
check($capped['mail_gateway'] === null, 'a node that sends no mail_gateway: not reported (null), never "none"');
$mg = JobResultProcessor::sanitise_host_report(array('mail_gateway' => array('version' => '1.0.0<x>', 'state' => 'running', 'restarts' => '2',
	'up_seconds' => -5, 'connections' => 3, 'sites' => array(array('site' => 'shop', 'name' => 'mail.shop.example', 'imap' => 'ok',
	'submission' => 'maybe', 'days_left' => 40), array('site' => '', 'imap' => 'ok'), 'junk'))))['mail_gateway'];
check($mg['version'] === '1.0.0x' && $mg['state'] === 'unknown' && $mg['restarts'] === 2 && $mg['up_seconds'] === 'unknown'
	&& count($mg['sites']) === 1 && $mg['sites'][0]['imap'] === 'ok' && $mg['sites'][0]['submission'] === 'unknown' && $mg['sites'][0]['days_left'] === 40,
	'mail_gateway is bounded: names reduced, an unknown state or answer reads unknown, a site with no name dropped', json_encode($mg));
check(JobResultProcessor::sanitise_host_report(array('mail_gateway' => 'none'))['mail_gateway'] === 'none', 'mail_gateway none is kept');
check($capped['cpus'] === 'unknown', 'a processor count that is not a count reads unknown');
check(JobResultProcessor::sanitise_host_report(array('cpus' => 4))['cpus'] === 4, 'a processor count is kept');
$quiet = JobResultProcessor::sanitise_host_report(array('answers' => array('apache2' => 'yes', 'php-fpm' => 'quiet', 'postgresql' => 'maybe')));
check($quiet['answers'] === array('apache2' => 'yes', 'php-fpm' => 'quiet', 'postgresql' => 'unknown'),
	'a quiet site\'s PHP stays quiet, never no, and an answer outside the set reads unknown', var_export($quiet['answers'], true));
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
check($widened['served_certificates'] === array(array('domain' => 'a.exampleb', 'days_left' => 12, 'primary' => false, 'self_signed' => false)),
	'a served certificate needs a name and whole days', var_export($widened['served_certificates'], true));
$self_signed = JobResultProcessor::sanitise_host_report(array('served_certificates' => array(
	array('domain' => 'c.example', 'days_left' => 3600, 'self_signed' => true),
	array('domain' => 'd.example', 'days_left' => 3600, 'self_signed' => 'yes'))))['served_certificates'];
check($self_signed[0]['self_signed'] === true && $self_signed[1]['self_signed'] === false,
	'self_signed is kept when it is true and nothing else', var_export($self_signed, true));
check($widened['containers'] === array(array('name' => 'site1', 'state' => 'running', 'health' => 'none', 'answers' => 'no')),
	'a container keeps its four facts; anything else in the list is dropped', var_export($widened['containers'], true));
$held_c = JobResultProcessor::sanitise_host_report(array('containers' => array(
	array('name' => 'old1', 'state' => 'exited', 'health' => 'none', 'answers' => 'no', 'held' => true),
	array('name' => 'site2', 'state' => 'running', 'health' => 'none', 'answers' => 'yes', 'held' => false),
	array('name' => 'site3', 'state' => 'running', 'health' => 'none', 'answers' => 'yes', 'held' => 'yes'))))['containers'];
check(($held_c[0]['held'] ?? null) === true && !array_key_exists('held', $held_c[1]) && !array_key_exists('held', $held_c[2]),
	'a container held stopped keeps its mark; false or anything but true leaves none', var_export($held_c, true));
check(JobResultProcessor::sanitise_host_report(array('containers' => 'none'))['containers'] === 'none',
	'a machine with no docker says none');
// A container its host records as quiet (host_report 1.27: a dormant copy, a frozen source).
$quiet_c = JobResultProcessor::sanitise_host_report(array('containers' => array(
	array('name' => 'copy1', 'state' => 'running', 'health' => 'healthy', 'answers' => 'quiet'),
	array('name' => 'site4', 'state' => 'running', 'health' => 'none', 'answers' => 'maybe'))))['containers'];
check(($quiet_c[0]['answers'] ?? null) === 'quiet' && ($quiet_c[1]['answers'] ?? null) === 'unknown',
	'a quiet container stays quiet; an answer outside the set reads unknown', var_export($quiet_c, true));
// A site's code check and the host's restrictions (host_report 1.21, specs/host_restrictions.md D8).
$cc = JobResultProcessor::sanitise_host_report(array('host_restrictions' => array('unsigned_packages' => 'refuse', 'Bad Name' => 'x', 'other' => '<b>'),
	'containers' => array(
	array('name' => 's1', 'state' => 'running', 'code_check' => array('state' => 'failed', 'at' => '2026-10-13T01:40:00Z', 'extra' => 1,
		'failures' => array(array('kind' => 'plugin', 'name' => 'mail box<', 'verdict' => 'tampered', 'file' => 'inc/a b.php;rm'),
			array('kind' => 'evil', 'name' => 'x', 'verdict' => 'whatever', 'file' => array()), 'junk'))),
	array('name' => 's2', 'state' => 'running', 'code_check' => array('state' => 'ok', 'at' => 'yesterday')),
	array('name' => 's3', 'state' => 'running', 'code_check' => 'none'),
	array('name' => 's4', 'state' => 'running', 'code_check' => array('state' => 'fine')),
	array('name' => 's5', 'state' => 'running'))));
check($cc['host_restrictions'] === array('unsigned_packages' => 'refuse'),
	'host restrictions keep a well-formed name and value only', var_export($cc['host_restrictions'], true));
check($cc['containers'][0]['code_check'] === array('state' => 'failed', 'at' => '2026-10-13T01:40:00Z', 'failures' => array(
		array('kind' => 'plugin', 'name' => 'mailbox', 'verdict' => 'tampered', 'file' => 'inc/ab.phprm'),
		array('kind' => 'unknown', 'name' => 'x', 'verdict' => 'unknown', 'file' => ''))),
	'a failed code check keeps each failure, every field reduced to what it may hold', var_export($cc['containers'][0]['code_check'], true));
check($cc['containers'][1]['code_check']['state'] === 'ok' && $cc['containers'][1]['code_check']['at'] === 'unknown'
	&& $cc['containers'][2]['code_check'] === 'none' && $cc['containers'][3]['code_check'] === 'unknown'
	&& !array_key_exists('code_check', $cc['containers'][4]),
	'a code check: a bad time is unknown, none kept, a state it does not know is unknown, an older report absent', var_export($cc['containers'], true));
check(JobResultProcessor::sanitise_host_report(array('host_restrictions' => 'none'))['host_restrictions'] === 'none'
	&& JobResultProcessor::sanitise_host_report(array('host_restrictions' => 'yes'))['host_restrictions'] === 'unknown'
	&& JobResultProcessor::sanitise_host_report(array())['host_restrictions'] === null,
	'host restrictions: none kept, anything else unknown, an older report absent');
// A Docker host's disk pool (host_report 1.15): its figures kept, none kept,
// anything else unknown, an older report's absence kept absent (null).
$dp = JobResultProcessor::sanitise_host_report(array('disk_pool' => array('used_bytes' => 5, 'total_bytes' => 10, 'avail_bytes' => 4, 'prjquota' => true, 'evil' => '<b>')))['disk_pool'];
check($dp === array('used_bytes' => 5, 'total_bytes' => 10, 'avail_bytes' => 4, 'prjquota' => true, 'device' => ''),
	'a disk pool keeps its figures and quota state, nothing else', var_export($dp, true));
$dv = JobResultProcessor::sanitise_host_report(array('disk_pool' => array('used_bytes' => 5, 'total_bytes' => 10, 'avail_bytes' => 4,
	'prjquota' => true, 'device' => '/dev/disk/by-id/scsi-0Linode_Volume_mgr-b-vol')))['disk_pool']['device'];
$dx = JobResultProcessor::sanitise_host_report(array('disk_pool' => array('device' => '/dev/x; rm -rf /')))['disk_pool']['device'];
check($dv === '/dev/disk/by-id/scsi-0Linode_Volume_mgr-b-vol' && $dx === '',
	'and the device it is on, held to the shape of a device path (host_report 1.22)');
check(JobResultProcessor::sanitise_host_report(array('disk_pool' => 'not_mounted'))['disk_pool'] === 'not_mounted',
	'a data root declared and not mounted stays not_mounted, never none (host_report 1.18)');
check(JobResultProcessor::sanitise_host_report(array('disk_pool' => 'none'))['disk_pool'] === 'none'
	&& JobResultProcessor::sanitise_host_report(array('disk_pool' => 'rm -rf'))['disk_pool'] === 'unknown'
	&& JobResultProcessor::sanitise_host_report(array())['disk_pool'] === null,
	'no pool is none, a stranger value unknown, an older report null');
// A multi-tenant host takes 50 sites; the report lists up to 100 (host_report
// 1.13), each with its outbound figures.
$many = array(); $many_figs = array();
for ($i = 1; $i <= 120; $i++) {
	$many[] = array('name' => sprintf('site%03d', $i), 'state' => 'running', 'health' => 'none', 'answers' => 'yes');
	$many_figs[sprintf('site%03d', $i)] = array('ceiling_mbit' => 200, 'conn_rate' => 40, 'conn_burst' => 120, 'open_conns' => 512);
}
$many = JobResultProcessor::sanitise_host_report(array('containers' => $many, 'outbound_limits' => array(
	'state' => 'on', 'since' => 1, 'web_user_dropped' => 'none', 'figures' => array(), 'sites' => $many_figs)));
check(JobResultProcessor::HOST_REPORT_MAX_SITES === 100 && count($many['containers']) === 100
	&& $many['containers'][99]['name'] === 'site100' && count($many['outbound_limits']['sites']) === 100
	&& array_key_last($many['outbound_limits']['sites']) === 'site100',
	'a host\'s sites are kept up to 100, the first hundred, in its containers and its outbound figures',
	count($many['containers']) . '/' . count($many['outbound_limits']['sites']));
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
	'kernel_events_24h' => array('oom' => 2, 'enospc' => '1', 'io_error' => 'lots', 'kernel_warning' => 3, 'extra' => 9),
	'disk' => array('avail_bytes' => 5, 'inodes_used_pct' => 120),
));
check($ke['kernel_events_24h'] === array('oom' => 2, 'enospc' => 1, 'io_error' => 'unknown', 'kernel_warning' => 3),
	'each kernel count is a number or unknown, kernel_warning kept, and an extra key is dropped', var_export($ke['kernel_events_24h'], true));
$ke_old = JobResultProcessor::sanitise_host_report(array('kernel_events_24h' => array('oom' => 0, 'enospc' => 0, 'io_error' => 0)));
check($ke_old['kernel_events_24h']['kernel_warning'] === 'unknown',
	'a report from before kernel_warning reads it as unknown, never 0');

// The pinned kernel settings (host_report 1.29, multi_tenant_host_hardening D1).
$hd = function ($h) { return JobResultProcessor::sanitise_host_report(array('hardening' => $h))['hardening']; };
check($hd(array('state' => 'drift', 'pinned' => '38', 'unread' => 1, 'drift' => array(array('key' => 'kernel.yama.ptrace_scope', 'live' => '0', 'want' => '1'))))
		=== array('state' => 'drift', 'pinned' => 38, 'unread' => 1, 'drift' => array(array('key' => 'kernel.yama.ptrace_scope', 'live' => '0', 'want' => '1'))),
	'hardening keeps its state, counts and each setting not held');
check($hd(array('state' => 'loose')) === 'unknown' && $hd('ok') === 'unknown' && $hd('unknown') === 'unknown',
	'a state that is not ok or drift, or no object, is unknown');
$many = array();
for ($i = 0; $i < 30; $i++) { $many[] = array('key' => 'net.ipv4.k' . $i, 'live' => '1', 'want' => '0'); }
$many[] = array('key' => 'Kernel.X', 'live' => '1', 'want' => '0');
check(count($hd(array('state' => 'drift', 'pinned' => 38, 'unread' => 0, 'drift' => $many))['drift']) === JobResultProcessor::HOST_REPORT_MAX_LIST,
	'the drift list is capped');
check($hd(array('state' => 'drift', 'pinned' => 38, 'unread' => 0, 'drift' => array(array('key' => 'Kernel.X', 'live' => '1', 'want' => '0'),
		array('key' => 'fs.protected_fifos', 'live' => '$(id)', 'want' => '2'))))['drift'] === array(array('key' => 'fs.protected_fifos', 'live' => 'unknown', 'want' => '2')),
	'a key that is not a lowercase dotted name is dropped; a value that is not a word is unknown');
check(JobResultProcessor::sanitise_host_report(array())['hardening'] === null
		&& JobResultProcessor::sanitise_host_report(JobResultProcessor::sanitise_host_report(array()))['hardening'] === null,
	'a report from before hardening keeps it null, read back too (reviewer2 R5)');
// The nightly replacement of the site containers (host_report 1.31, multi_tenant_host_hardening D9).
$srf = function ($r) { return JobResultProcessor::sanitise_host_report(array('site_refresh' => $r))['site_refresh']; };
$sr_in = array('installed_at' => '2026-10-10T01:00:00Z', 'checked_at' => '2026-10-17T01:40:00Z', 'checked_ok_at' => '2026-10-17T01:40:00Z',
	'check' => 'ok', 'check_error' => '', 'base_version' => '2.2', 'base_id' => '88bf90ef691d', 'base_built_at' => '2026-10-17T01:42:00Z',
	'base_due' => 'fix', 'ran_at' => '2026-10-17T01:40:00Z', 'run' => 'held', 'run_error' => '', 'pending' => 10, 'pending_security' => 9,
	'sites' => 3, 'sites_due' => 1, 'security_packages' => array('apache2', 'curl'),
	'replaced' => array(array('site' => 'sa', 'down_seconds' => 10.4)), 'passed_over' => array(array('site' => 'wd5', 'why' => 'busy')),
	'holds' => array(array('site' => 'sb', 'outcome' => 'rolled_back', 'reason' => 'not healthy within 180 s', 'at' => '2026-10-17T01:50:00Z', 'base' => '88bf90ef691d')),
	'scheduled' => true);
$sr = $srf($sr_in);
check(is_array($sr) && $sr['pending_security'] === 9 && $sr['base_due'] === 'fix' && $sr['run'] === 'held'
		&& $sr['replaced'] === array(array('site' => 'sa', 'down_seconds' => 10.4)) && $sr['passed_over'] === array(array('site' => 'wd5', 'why' => 'busy'))
		&& $sr['holds'][0]['site'] === 'sb' && $sr['holds'][0]['outcome'] === 'rolled_back' && $sr['scheduled'] === true && $sr['checked_ok_at'] === '2026-10-17T01:40:00Z',
	'site_refresh keeps its check, its run, each site replaced and passed over, the hold and the timer', json_encode($sr));
check($srf($sr) === $sr, 'site_refresh reads back the same from a stored report');
// The security fixes waiting and what runs replaced code (host_report 1.37, fleet_security_patching WP1).
$spf = function ($r) { return JobResultProcessor::sanitise_host_report(array('security_pending' => $r))['security_pending']; };
$sp_in = array('installed_at' => '2026-10-22T00:00:00Z', 'checked_at' => '2026-10-22T13:50:00Z', 'checked_ok_at' => '2026-10-22T13:50:00Z',
	'check' => 'ok', 'check_error' => '', 'lists_at' => '2026-10-22T13:49:00Z', 'lists_error' => '', 'pending' => 2, 'critical' => 1,
	'packages' => array(array('package' => 'php8.3-fpm', 'installed' => '8.3.6-0ubuntu0.24.04.12', 'candidate' => '1:8.3.6~rc1+x', 'critical' => true),
		array('package' => 'curl', 'installed' => 'none', 'candidate' => '8.5.0', 'critical' => false)),
	'scheduled' => true);
$sp = $spf($sp_in);
check(is_array($sp) && $sp['pending'] === 2 && $sp['critical'] === 1 && $sp['packages'][0]['candidate'] === '1:8.3.6~rc1+x' && $sp['packages'][0]['critical'] === true
		&& $sp['scheduled'] === true && $spf($sp) === $sp,
	'security_pending keeps its counts, each package with its versions (an epoch, a tilde, a plus) and whether critical; reads back the same', json_encode($sp));
$spb = $spf(array_merge($sp_in, array('check' => 'maybe', 'checked_at' => 'now', 'packages' => array(array('package' => 'a b'), array('package' => 'x', 'critical' => 'yes')))));
check($spb['check'] === '' && $spb['checked_at'] === '' && count($spb['packages']) === 1 && $spb['packages'][0]['critical'] === false,
	'security_pending: a word off its list or a time not its shape is empty; a name not a package\'s is dropped; critical only when literally true', json_encode($spb));
check($spf('none') === 'none' && $spf(7) === 'unknown' && JobResultProcessor::sanitise_host_report(array())['security_pending'] === null,
	'security_pending: none where it never ran, unknown for anything not its shape, null from an older node');
$nr = JobResultProcessor::sanitise_host_report(array('services_needing_restart' => array('source' => 'needrestart', 'services' => array('php8.3-fpm.service', 'a b;c', 7))))['services_needing_restart'];
check($nr === array('source' => 'needrestart', 'services' => array('php8.3-fpm.service')), 'services_needing_restart keeps its source and the names that are names', json_encode($nr));
check(JobResultProcessor::sanitise_host_report(array('services_needing_restart' => 'unknown'))['services_needing_restart'] === 'unknown'
		&& JobResultProcessor::sanitise_host_report(array())['services_needing_restart'] === null,
	'services_needing_restart: unknown when the node could not tell, null from an older node');
check($sr['running'] === false && $srf(array_merge($sr_in, array('running' => true)))['running'] === true
		&& $srf(array_merge($sr_in, array('running' => 'yes')))['running'] === false,
	'site_refresh.running (host_report 1.36): true only when the report says literally true; absent is false');
$bad = $srf(array_merge($sr_in, array('checked_at' => 'yesterday', 'base_due' => 'always', 'run' => 'rm -rf', 'base_id' => 'zz',
	'security_packages' => array('curl', '-o evil', array('x')), 'replaced' => array(array('site' => '../x', 'down_seconds' => 1), array('site' => 'sa', 'down_seconds' => 'fast')),
	'passed_over' => array(array('site' => 'sb', 'why' => 'tired'), array('site' => 'sc', 'why' => 'timed_out')),
	'holds' => array(array('site' => 'sb', 'outcome' => 'kaput', 'reason' => '<script>x</script>"q"', 'at' => 'now', 'base' => 'x'), array('site' => '../x'), 'junk'), 'scheduled' => 'yes')));
check($bad['checked_at'] === '' && $bad['base_due'] === '' && $bad['run'] === '' && $bad['base_id'] === ''
		&& $bad['security_packages'] === array('curl') && $bad['replaced'] === array(array('site' => 'sa', 'down_seconds' => null))
		&& $bad['passed_over'] === array(array('site' => 'sb', 'why' => 'refused'), array('site' => 'sc', 'why' => 'timed_out'))
		&& $bad['holds'] === array(array('site' => 'sb', 'outcome' => 'rolled_back', 'reason' => 'scriptx/scriptq', 'at' => '', 'base' => '')) && $bad['scheduled'] === false,
	'site_refresh: a word off its list, a time or id not its shape, a name that is not a site\'s, a reason with markup: each reduced or dropped', json_encode($bad));
check($srf('none') === 'none' && $srf('x') === 'unknown' && JobResultProcessor::sanitise_host_report(array())['site_refresh'] === null,
	'site_refresh: none where it never ran, unknown for anything not its shape, null from a report before the field');
check(JobResultProcessor::sanitise_host_report(array('containers' => array(array('name' => 'sa', 'state' => 'running', 'replacing' => true),
		array('name' => 'sb', 'state' => 'running', 'replacing' => 'yes'))))['containers'][0]['replacing'] === true
	&& !isset(JobResultProcessor::sanitise_host_report(array('containers' => array(array('name' => 'sb', 'state' => 'running', 'replacing' => 'yes'))))['containers'][0]['replacing']),
	'a container being replaced keeps replacing; anything but true is not kept');
check($ke['disk']['avail_bytes'] === 5 && $ke['disk']['inodes_used_pct'] === 'unknown',
	'a percentage outside 0..100 is unknown');
check($capped['memory'] === array('used_bytes' => 'unknown', 'total_bytes' => 'unknown') && $capped['swap'] === array('used_bytes' => 'unknown', 'total_bytes' => 'unknown'),
	'a gauge that is not an object, or is missing, is unknown in both figures');
check($capped['reboot_required'] === 'unknown' && $capped['unattended_upgrades_last_run'] === 'unknown' && $capped['generated_at'] === 'unknown',
	'a string "true", a negative time and a fractional time are all unknown');
$rb = JobResultProcessor::sanitise_host_report(array('reboot_required' => true, 'reboot_required_since' => 'Tuesday'));
$rb_none = JobResultProcessor::sanitise_host_report(array('reboot_required' => false, 'reboot_required_since' => 1789280500));
$rb_old = JobResultProcessor::sanitise_host_report(array('reboot_required' => true));
check($rb['reboot_required_since'] === 'unknown' && $rb_none['reboot_required_since'] === null && $rb_old['reboot_required_since'] === 'unknown',
	'a pending reboot\'s time is a time or unknown (an older node sends none); with none pending it is null, whatever was sent');
check($capped['os'] === array('id' => 'ubuntub', 'version' => 'unknown', 'codename' => 'unknown',
		'release_upgrade' => array('offered' => 'unknown', 'checked_at' => 'unknown')),
	'os words are lowercased and bounded, versions must be dotted digits, and text is never kept as a version', var_export($capped['os'], true));
$os_none = JobResultProcessor::sanitise_host_report(array('os' => array('release_upgrade' => array('offered' => 'none', 'checked_at' => 5))));
check($os_none['os']['release_upgrade'] === array('offered' => 'none', 'checked_at' => 5),
	'none is kept: the node checked and was offered nothing');
check(JobResultProcessor::sanitise_host_report(array())['os'] === 'unknown',
	'a node too old to report its operating system says unknown for the whole object');
check(json_encode($capped) !== false, 'the capped object encodes');

// A site container's own figures (host_report 1.7, multi_tenant_docker_hosts WP1).
$fig = function ($usec, $tx, $started = 1000) {
	return array('name' => 'site1', 'state' => 'running', 'health' => 'none', 'answers' => 'yes',
		'started_at' => $started,
		'memory' => array('used_bytes' => 200, 'peak_bytes' => 500, 'limit_bytes' => 'none', 'oom_kills' => 2),
		'cpu' => array('usage_usec' => $usec, 'limit_millicores' => 1500),
		'pids' => array('current' => 70, 'limit' => '<b>'),
		'net_tx_bytes' => $tx, 'disk_bytes' => -5, 'extra' => 'dropped',
		'since_last' => array('seconds' => 1, 'cpu_millicores' => 999999, 'net_tx_bytes' => 1));
};
$figs = JobResultProcessor::sanitise_host_report(array('containers' => array($fig(1000000, 5000))))['containers'][0];
check($figs['memory'] === array('used_bytes' => 200, 'peak_bytes' => 500, 'limit_bytes' => 'none', 'oom_kills' => 2)
	&& $figs['cpu'] === array('usage_usec' => 1000000, 'limit_millicores' => 1500)
	&& $figs['pids'] === array('current' => 70, 'limit' => 'unknown')
	&& $figs['started_at'] === 1000 && $figs['net_tx_bytes'] === 5000 && $figs['disk_bytes'] === 'unknown'
	&& !isset($figs['extra']),
	'a container\'s figures are kept as counts, none or unknown; anything else is dropped', var_export($figs, true));
$old_node = JobResultProcessor::sanitise_host_report(array('containers' => array(array('name' => 'site1', 'state' => 'running'))))['containers'][0];
check(!array_key_exists('memory', $old_node) && !array_key_exists('since_last', $old_node),
	'an older node\'s container carries no figures, rather than unknown ones');

$prev = JobResultProcessor::sanitise_host_report(array('generated_at' => 10000, 'containers' => array($fig(1000000, 5000))));
$next = JobResultProcessor::sanitise_host_report(array('generated_at' => 13600, 'containers' => array($fig(1000000 + 3600 * 250000, 5000 + 7200))));
$rated = JobResultProcessor::host_report_container_rates($prev, $next)['containers'][0];
check(($rated['since_last'] ?? null) === array('seconds' => 3600, 'cpu_millicores' => 250, 'net_tx_bytes' => 7200),
	'two reports an hour apart: a quarter of a core on average, and the bytes sent between them', var_export($rated['since_last'] ?? null, true));
check(json_decode(json_encode($rated), true) === JobResultProcessor::sanitise_host_report(array('containers' => array($rated)))['containers'][0],
	'the computed figure survives the sanitiser when the stored report is read back');
$first = JobResultProcessor::host_report_container_rates(null, $next)['containers'][0];
check(!isset($first['since_last']), 'the first report has nothing to compare with, and a figure the node sent is not kept');
$restarted = JobResultProcessor::sanitise_host_report(array('generated_at' => 13600, 'containers' => array($fig(600 * 100000, 900, 13000))));
$rs = JobResultProcessor::host_report_container_rates($prev, $restarted)['containers'][0];
check(($rs['since_last'] ?? null) === array('seconds' => 600, 'cpu_millicores' => 100, 'net_tx_bytes' => 900),
	'a container restarted between reports: its counters since the restart are the interval', var_export($rs['since_last'] ?? null, true));
$backwards = JobResultProcessor::sanitise_host_report(array('generated_at' => 13600, 'containers' => array($fig(10, 5000))));
check(!isset(JobResultProcessor::host_report_container_rates($prev, $backwards)['containers'][0]['since_last']),
	'a counter that went backwards with no restart gives no figure');

// Outbound limits (host_report 1.11, node_outbound_and_transfer WP3): the
// machine's state, and the packets each site's limits dropped.
// Which engine a machine's sites run on (host_report 1.26): one of four words,
// anything else unknown, null from a node that does not say.
foreach (array('podman' => 'podman', 'docker' => 'docker', 'none' => 'none', 'rm -rf /' => 'unknown', 7 => 'unknown') as $said => $kept) {
	$se = JobResultProcessor::sanitise_host_report(array('site_engine' => $said))['site_engine'];
	check($se === $kept, 'site_engine ' . var_export($said, true) . ' is kept as ' . $kept, var_export($se, true));
}
check(JobResultProcessor::sanitise_host_report(array())['site_engine'] === null, 'an older report: site_engine null');

$lim = function ($since, $web = 'none', $state = 'on', $reason = 'none') {
	return array('state' => $state, 'reason' => $reason, 'since' => $since, 'web_user_dropped' => $web);
};
$with_drops = function ($c, $dropped) { $c['outbound_dropped'] = $dropped; return $c; };
$ol = JobResultProcessor::sanitise_host_report(array('outbound_limits' => $lim(9000, 7)))['outbound_limits'];
check($ol === array('state' => 'on', 'reason' => null, 'since' => 9000, 'web_user_dropped' => 7),
	'limits in force keep their since and the web user\'s drops', var_export($ol, true));
$ol = JobResultProcessor::sanitise_host_report(array('outbound_limits' => $lim(9000, 'none', 'refused', 'resolver_not_loopback')))['outbound_limits'];
check($ol['state'] === 'refused' && $ol['reason'] === 'resolver_not_loopback' && $ol['since'] === null,
	'refused limits keep their reason code and no since', var_export($ol, true));
$ol = JobResultProcessor::sanitise_host_report(array('outbound_limits' => array('state' => 'rm -rf', 'reason' => '<b>x</b>', 'since' => -1, 'web_user_dropped' => 'lots')))['outbound_limits'];
check($ol === array('state' => 'unknown', 'reason' => null, 'since' => null, 'web_user_dropped' => 'unknown'),
	'a state the plane does not know is unknown, and nothing else of it is kept', var_export($ol, true));
$ol = JobResultProcessor::sanitise_host_report(array('outbound_limits' => $lim(9000, 7, 'on', 'nft_refused')))['outbound_limits'];
check($ol['state'] === 'on' && $ol['reason'] === 'nft_refused' && $ol['since'] === 9000,
	'on with a reason keeps it: the last change was refused and the table before it is in force', var_export($ol, true));
check(JobResultProcessor::sanitise_host_report(array())['outbound_limits'] === null
	&& JobResultProcessor::sanitise_host_report(array('outbound_limits' => 'x'))['outbound_limits'] === 'unknown',
	'an older node reports no limits (null); one that is not an object is unknown');
// The figures in force (host_report 1.12, WP5): the machine's, who set them,
// the web server's user's ceiling, and each limited site's.
$with_figs = $lim(9000, 7);
$with_figs['figures'] = array('ceiling_mbit' => 300, 'conn_rate' => 40, 'conn_burst' => 120, 'open_conns' => 512, 'set_by' => 'plane');
$with_figs['web_ceiling_mbit'] = 25;
$with_figs['sites'] = array('s1' => array('ceiling_mbit' => 'none', 'conn_rate' => 5, 'conn_burst' => 100, 'open_conns' => 256, 'set_by' => 'plane'),
	'<b>' => array('ceiling_mbit' => 1, 'set_by' => 'someone'), 's2' => 'x');
$ol = JobResultProcessor::sanitise_host_report(array('outbound_limits' => $with_figs))['outbound_limits'];
check(($ol['figures'] ?? null) === array('ceiling_mbit' => 300, 'conn_rate' => 40, 'conn_burst' => 120, 'open_conns' => 512, 'set_by' => 'plane')
	&& ($ol['web_ceiling_mbit'] ?? null) === 25
	&& ($ol['sites'] ?? null) === array('s1' => array('ceiling_mbit' => 'none', 'conn_rate' => 5, 'conn_burst' => 100, 'open_conns' => 256, 'set_by' => 'plane'),
		'b' => array('ceiling_mbit' => 1, 'conn_rate' => 'unknown', 'conn_burst' => 'unknown', 'open_conns' => 'unknown', 'set_by' => '')),
	'limits in force keep their figures: the machine\'s and each site\'s, who set each (plane or nothing), the web server\'s user\'s ceiling, names cleaned and an entry that is not one dropped', var_export($ol, true));
$with_figs['figures']['set_by'] = '<script>';
$with_figs['figures']['ceiling_mbit'] = '200; reboot';
$ol = JobResultProcessor::sanitise_host_report(array('outbound_limits' => $with_figs))['outbound_limits'];
check($ol['figures']['set_by'] === '' && $ol['figures']['ceiling_mbit'] === 'unknown', 'a set_by but plane is nothing; a figure that is not one is unknown', var_export($ol['figures'], true));
$with_figs['state'] = 'off';
$ol = JobResultProcessor::sanitise_host_report(array('outbound_limits' => $with_figs))['outbound_limits'];
check(($ol['figures']['conn_rate'] ?? null) === 40 && array_keys($ol['sites'] ?? array()) === array('s1', 'b') && $ol['since'] === null,
	'limits off keep the figures turning them on brings back, and no since (host_report 1.14)', var_export($ol, true));
$with_figs['state'] = 'refused';
$ol = JobResultProcessor::sanitise_host_report(array('outbound_limits' => $with_figs))['outbound_limits'];
check(!array_key_exists('figures', $ol) && !array_key_exists('sites', $ol), 'limits refused carry no figures', var_export($ol, true));
$c_none = JobResultProcessor::sanitise_host_report(array('containers' => array($with_drops($fig(1, 1), 'none'))))['containers'][0];
$c_bad = JobResultProcessor::sanitise_host_report(array('containers' => array($with_drops($fig(1, 1), '1;reboot'))))['containers'][0];
check($c_none['outbound_dropped'] === 'none' && $c_bad['outbound_dropped'] === 'unknown'
	&& !array_key_exists('outbound_dropped', $figs),
	'a container\'s drops are a count, none (not limited) or unknown; an older node\'s carry none at all');

$lp = JobResultProcessor::sanitise_host_report(array('generated_at' => 10000, 'outbound_limits' => $lim(9000, 40),
	'containers' => array($with_drops($fig(1000000, 5000), 100))));
$ln = JobResultProcessor::sanitise_host_report(array('generated_at' => 13600, 'outbound_limits' => $lim(9000, 45),
	'containers' => array($with_drops($fig(1000000 + 3600 * 250000, 5000 + 7200), 130))));
$lr = JobResultProcessor::host_report_container_rates($lp, $ln);
check(($lr['containers'][0]['since_last']['outbound_dropped'] ?? null) === 30 && ($lr['outbound_limits']['dropped_since_last'] ?? null) === 5,
	'the same run of counters: the drops between two reports, for each site and the web user', var_export($lr['containers'][0]['since_last'] ?? null, true));
check(json_decode(json_encode($lr), true)['containers'][0] === JobResultProcessor::sanitise_host_report(json_decode(json_encode($lr), true))['containers'][0]
	&& JobResultProcessor::sanitise_host_report(json_decode(json_encode($lr), true))['outbound_limits']['dropped_since_last'] === 5,
	'the drop figures survive the sanitiser when the stored report is read back');
$lboot = JobResultProcessor::sanitise_host_report(array('generated_at' => 13600, 'outbound_limits' => $lim(12000, 3),
	'containers' => array($with_drops($fig(1000000 + 3600 * 250000, 5000 + 7200), 4))));
$lb = JobResultProcessor::host_report_container_rates($lp, $lboot);
check(($lb['containers'][0]['since_last']['outbound_dropped'] ?? null) === 4 && ($lb['outbound_limits']['dropped_since_last'] ?? null) === 3,
	'counters that began again between the reports (a reboot): the count is the interval');
$lold = JobResultProcessor::sanitise_host_report(array('generated_at' => 13600, 'outbound_limits' => $lim(5000, 3),
	'containers' => array($with_drops($fig(1000000 + 3600 * 250000, 5000 + 7200), 4))));
$lo = JobResultProcessor::host_report_container_rates($lp, $lold);
check(!isset($lo['containers'][0]['since_last']['outbound_dropped']) && !isset($lo['outbound_limits']['dropped_since_last'])
	&& isset($lo['containers'][0]['since_last']['cpu_millicores']),
	'a since older than the last report that differs from it says nothing about drops, and the rest still rates');
$lback = JobResultProcessor::sanitise_host_report(array('generated_at' => 13600, 'outbound_limits' => $lim(9000, 2),
	'containers' => array($with_drops($fig(1000000 + 3600 * 250000, 5000 + 7200), 50))));
$lbk = JobResultProcessor::host_report_container_rates($lp, $lback);
check(!isset($lbk['containers'][0]['since_last']['outbound_dropped']) && !isset($lbk['outbound_limits']['dropped_since_last']),
	'a drop counter that went backwards in the same run gives no figure');
$lrest = JobResultProcessor::sanitise_host_report(array('generated_at' => 13600, 'outbound_limits' => $lim(9000, 40),
	'containers' => array($with_drops($fig(600 * 100000, 900, 13000), 160))));
check((JobResultProcessor::host_report_container_rates($lp, $lrest)['containers'][0]['since_last']['outbound_dropped'] ?? null) === 60,
	'a container restart does not restart its drops: they belong to the machine\'s limits');
$lnolim = JobResultProcessor::host_report_container_rates(null, JobResultProcessor::sanitise_host_report(array('generated_at' => 13600, 'outbound_limits' => $lim(9000, 40))));
check(!isset($lnolim['outbound_limits']['dropped_since_last']), 'the first report has no drops to compare');

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
$hc_dr = jrp_job($hc_node2, 'host_converge', "=== [Step 1/1] host_converge ===\n" . json_encode(array('api_version' => '1.0', 'data' => array(
	'output' => "joinery_data_root: /srv/joinery is not mounted (see: journalctl -u srv-joinery.mount)\nhost installers: the data root is not ready - nothing runs until it is (joinery_data_root.sh status says why)\n"))));
JobResultProcessor::process($hc_dr);
$hc_dr->load();
check($hc_dr->get('mjb_status') === 'failed' && strpos((string)$hc_dr->get('mjb_error_message'), 'the data root is not ready') !== false,
	'a run the data root held back is red, and says so rather than "gives no reason" (one_data_root, reviewer2 F2)',
	var_export($hc_dr->get('mjb_error_message'), true));
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
check(!empty($dn->get('mgn_site_removed_time')),
	'and marks its site removed, which Remove Permanently waits for (node_hide_and_remove)');

// A failed job leaves the node intact — never a half-deleted record over a live site.
$dn2 = jrp_node(array('mgn_container_name' => 'decomrp2', 'mgn_web_root' => '/var/www/html/decomrp2/public_html'));
$dj2 = jrp_job($dn2, 'decommission_node', 'host teardown errored');
$dj2->set('mjb_status', 'failed');
$dj2->save();
JobResultProcessor::process($dj2);
$dn2->load();
check(empty($dn2->get('mgn_delete_time')), 'a failed decommission leaves the node intact');
check(empty($dn2->get('mgn_site_removed_time')), 'and never marks its site removed');
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

section('moved_site_check: the host\'s answer lands on the old machine of a switch-over');

/** File a moved_site_check through the real path and finish it with $data (or fail it). */
function jrp_moved_check($host, $victim, $data, $status = 'completed', $error = '') {
	$job = ManagementJob::createFromBuild($host->key, 'moved_site_check',
		array('primitive' => 'moved_site_check', 'params' => array('site' => 'movedrp')),
		array('victim_node_id' => (int)$victim->key, 'site' => 'movedrp'), 1);
	harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $job->key);
	$job->set('mjb_status', $status);
	$job->set('mjb_output', $data === null ? '' : json_encode(array('api_version' => 1, 'data' => $data)));
	if ($error !== '') { $job->set('mjb_error_message', $error); }
	$job->save();
	JobResultProcessor::process($job);
	$victim->load();
	return $job;
}

$mhost = jrp_node(array());
// Removed from the dashboard at the switch-over, as the old machine always is.
$mvictim = jrp_node(array('mgn_container_name' => 'movedrp', 'mgn_install_state' => 'retired'));
$mvictim->soft_delete();
$mj = jrp_moved_check($mhost, $mvictim, array('state' => 'moved',
	'detail' => 'moved.example.com: answered 200 from another server.', 'names' => array('moved.example.com')));
check($mvictim->get('mgn_moved_check_state') === 'moved'
	&& strpos((string)$mvictim->get('mgn_moved_check_detail'), 'answered 200') !== false
	&& (string)$mvictim->get('mgn_moved_check_time') !== '',
	'a completed check records its state, detail and time on the VICTIM, though it is removed from the dashboard',
	json_encode(array($mvictim->get('mgn_moved_check_state'), $mvictim->get('mgn_moved_check_detail'))));
check(empty($mhost->get('mgn_moved_check_state')), 'and never on the host that ran it');
check((json_decode((string)$mj->get('mjb_result'), true)['names'] ?? null) === array('moved.example.com'),
	'the job keeps the names it checked');

jrp_moved_check($mhost, $mvictim, array('state' => 'here', 'detail' => 'https://moved.example.com still reaches this site.'));
check($mvictim->get('mgn_moved_check_state') === 'here', 'a later answer replaces the earlier one');

jrp_moved_check($mhost, $mvictim, array('state' => 'nonsense', 'detail' => 'x'));
check($mvictim->get('mgn_moved_check_state') === 'failed',
	'a state the agent does not report is not shown as an answer', (string)$mvictim->get('mgn_moved_check_state'));

jrp_moved_check($mhost, $mvictim, null, 'failed', 'the vhost for movedrp: it names an unusable port');
check($mvictim->get('mgn_moved_check_state') === 'failed'
	&& strpos((string)$mvictim->get('mgn_moved_check_detail'), 'unusable port') !== false,
	'a check that could not run says why, rather than leaving an older answer looking current');

// Its removal, verified, records the container gone: nothing left to check.
$mjd = ManagementJob::createFromBuild($mhost->key, 'decommission_node',
	array('primitive' => 'decommission_moved_site', 'params' => array('site' => 'movedrp')),
	array('victim_node_id' => (int)$mvictim->key, 'site' => 'movedrp'), 1);
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $mjd->key);
$mjd->set('mjb_status', 'completed');
$mjd->set('mjb_output', json_encode(array('api_version' => 1, 'data' => array(
	'output' => "REMOVE_ACCOUNT_OK movedrp\nDECOMMISSION_VERIFIED movedrp"))));
$mjd->save();
JobResultProcessor::process($mjd);
$mvictim->load();
check($mvictim->get('mgn_moved_check_state') === 'absent' && !MovedSiteCheck::is_stale($mvictim),
	'a verified removal of the old machine records its container gone, and that is never asked again');

section('moved_site_reach: the domain must reach the NEW server, not just any other');

$rnew = jrp_node(array('mgn_name' => 'HarnessTest Reach New Server', 'mgn_agent_public_key' => base64_encode(str_repeat("\x0d", 32)),
	'mgn_joinery_version' => ProvisionPendingSsl::PROBE_MIN_CORE_VERSION));
$rvictim = jrp_node(array('mgn_container_name' => 'reachrp', 'mgn_install_state' => 'retired',
	'mgn_site_url' => 'https://reach.example.com', 'mgn_copy_of_node_id' => $rnew->key,
	'mgn_moved_check_state' => 'moved', 'mgn_moved_check_detail' => 'reach.example.com: answered 200 from another server.',
	'mgn_moved_check_time' => gmdate('Y-m-d H:i:s')));
$rvictim->soft_delete();

/** File a reach job through the real path, finish it, and fetch with $answer (status, body, error). */
function jrp_reach($new, $victim, $answer, $status = 'completed', $error = '') {
	$token = JobCommandBuilder::mint_ssl_probe_token();
	$job = ManagementJob::createFromBuild($new->key, MovedSiteCheck::REACH_JOB_TYPE,
		JobCommandBuilder::build_ssl_probe_place_primitive($new, array('token' => $token)),
		array('victim_node_id' => (int)$victim->key, 'domain' => 'reach.example.com'), 1);
	harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $job->key);
	$job->set('mjb_status', $status);
	if ($error !== '') { $job->set('mjb_error_message', $error); }
	$job->save();
	$GLOBALS['jrp_reach_url'] = '';
	MovedSiteCheck::$fetcher = function ($url) use ($answer, $token) {
		$GLOBALS['jrp_reach_url'] = $url;
		return array('status' => $answer[0], 'body' => str_replace('{token}', $token, $answer[1]), 'error' => $answer[2] ?? '');
	};
	JobResultProcessor::process($job);
	MovedSiteCheck::$fetcher = null;
	$victim->load();
	return $job;
}
function jrp_reach_clears($new) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare("SELECT mjb_management_job_id FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = ?");
	$q->execute(array((int)$new->key, MovedSiteCheck::REACH_CLEAR_JOB_TYPE));
	$ids = $q->fetchAll(PDO::FETCH_COLUMN);
	foreach ($ids as $id) { harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $id); }
	return count($ids);
}

$rfirst = jrp_reach($rnew, $rvictim, array(200, "{token}\n"));
check($rvictim->get('mgn_moved_reach_state') === 'reached' && MovedSiteCheck::new_server_refusal($rvictim) === null,
	'the token placed on the new server coming back over the domain is a pass that allows the removal',
	json_encode(array($rvictim->get('mgn_moved_reach_state'), $rvictim->get('mgn_moved_reach_detail'))));
check(preg_match('#^https://reach\.example\.com/sm-ssl-probe\.txt\?moved=[a-f0-9]{16}$#', $GLOBALS['jrp_reach_url']) === 1,
	'fetched over https at the domain, with a fresh query string', $GLOBALS['jrp_reach_url']);
check(jrp_reach_clears($rnew) === 1, 'the new server\'s probe file is emptied after the fetch');
// The agent's result and a page poll fold the same job at once: one fold.
$rtwin = new ManagementJob($rfirst->key, TRUE);
$rtwin->set('mjb_result', null);   // as a second request read it, before the first saved
JobResultProcessor::process($rtwin);
check(jrp_reach_clears($rnew) === 1, 'a job folded twice at once fetches and clears once');
$rlabel = MovedSiteCheck::label_html($rvictim, false, 'UTC');
check(strpos($rlabel, 'text-success') !== false && strpos($rlabel, 'Moved: the domain reaches HarnessTest Reach New Server') !== false,
	'the page says moved, naming the new server, only now', $rlabel);

jrp_reach($rnew, $rvictim, array(200, '<html>This domain is parked</html>'));
check($rvictim->get('mgn_moved_reach_state') === 'elsewhere' && MovedSiteCheck::new_server_refusal($rvictim) !== null,
	'another server answering without the token is NOT the new server, and the removal waits',
	(string)$rvictim->get('mgn_moved_reach_state'));
$rlabel = MovedSiteCheck::label_html($rvictim, false, 'UTC');
check(strpos($rlabel, 'text-success') === false && strpos($rlabel, 'left this container but does not reach HarnessTest Reach New Server') !== false
	&& strpos($rlabel, 'some other server') !== false,
	'the page says the domain left but does not reach the new server, and what it answered', $rlabel);

jrp_reach($rnew, $rvictim, array(502, 'Bad gateway'));
check($rvictim->get('mgn_moved_reach_state') === 'unsure', 'a 5xx could be the new server failing: unsure');
jrp_reach($rnew, $rvictim, array(0, '', 'Could not resolve host'));
check($rvictim->get('mgn_moved_reach_state') === 'unsure'
	&& strpos((string)$rvictim->get('mgn_moved_reach_detail'), 'did not answer') !== false,
	'no answer is unsure, with why');
$before_clears = jrp_reach_clears($rnew);
jrp_reach($rnew, $rvictim, array(200, '{token}'), 'failed', 'Refused by the node: no web root');
check($rvictim->get('mgn_moved_reach_state') === 'failed'
	&& strpos((string)$rvictim->get('mgn_moved_reach_detail'), 'no web root') !== false
	&& jrp_reach_clears($rnew) === $before_clears,
	'a probe that could not be placed is failed, says why, and fetches nothing');

// A check a newer one replaced does not speak for the row: the newer token
// has replaced its own on the new server.
$rold = jrp_reach($rnew, $rvictim, array(200, '{token}'));
$rnewer = ManagementJob::createFromBuild($rnew->key, MovedSiteCheck::REACH_JOB_TYPE,
	JobCommandBuilder::build_ssl_probe_place_primitive($rnew, array('token' => JobCommandBuilder::mint_ssl_probe_token())),
	array('victim_node_id' => (int)$rvictim->key, 'domain' => 'reach.example.com'), 1);
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $rnewer->key);
$rvictim->set('mgn_moved_reach_state', 'unsure');
$rvictim->save();
$rold->set('mjb_result', null);
$rold->save();
JobResultProcessor::process($rold);
$rvictim->load();
check($rvictim->get('mgn_moved_reach_state') === 'unsure'
	&& (json_decode((string)$rold->get('mjb_result'), true)['state'] ?? '') === 'superseded',
	'a superseded check records on itself only');

jrp_reach_clears($rnew);   // registers the clear jobs filed since, for cleanup

// A stale pass no longer allows the removal.
$rvictim->set('mgn_moved_reach_state', 'reached');
$rvictim->set('mgn_moved_reach_time', gmdate('Y-m-d H:i:s', time() - MovedSiteCheck::STALE_SECONDS - 5));
$rvictim->save();
check(MovedSiteCheck::new_server_refusal($rvictim) !== null && MovedSiteCheck::is_stale($rvictim),
	'a pass older than ten minutes is asked again and allows nothing');

section('remove_site_certificate: what the host removed, then a fresh certificate list');

$chost = jrp_node(array('mgn_agent_public_key' => base64_encode(str_repeat("\x0e", 32))));
/** File a remove_site_certificate job and finish it with the script's object (or raw text). */
function jrp_cert_removal($host, $object, $status = 'completed') {
	$job = ManagementJob::createFromBuild($host->key, 'remove_site_certificate',
		JobCommandBuilder::build_remove_site_certificate_primitive($host, array('name' => 'gone.example.com')),
		array('victim_node_id' => 1), 1);
	harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $job->key);
	$job->set('mjb_status', $status);
	$text = is_array($object) ? json_encode($object) : (string)$object;
	$job->set('mjb_output', json_encode(array('api_version' => 1, 'data' => array('output' => $text . "\n"))));
	$job->save();
	JobResultProcessor::process($job);
	$job->load();
	return $job;
}
function jrp_status_jobs($host) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare("SELECT mjb_management_job_id FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = 'check_status'");
	$q->execute(array((int)$host->key));
	$ids = $q->fetchAll(PDO::FETCH_COLUMN);
	foreach ($ids as $id) { harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $id); }
	return count($ids);
}

$cj = jrp_cert_removal($chost, array('name' => 'gone.example.com', 'letsencrypt' => 'removed', 'placeholder' => 'removed'));
$cr = json_decode((string)$cj->get('mjb_result'), true);
check($cj->get('mjb_status') === 'completed' && ($cr['letsencrypt'] ?? '') === 'removed' && ($cr['placeholder'] ?? '') === 'removed',
	'a removal records each part', json_encode($cr));
check(jrp_status_jobs($chost) === 1, 'and a status check follows on the host, so its certificate list is current');
jrp_cert_removal($chost, array('name' => 'gone.example.com', 'letsencrypt' => 'absent', 'placeholder' => 'removed'));
check(jrp_status_jobs($chost) === 1, 'one status check at a time, not one per removal');

$cj = jrp_cert_removal($chost, array('name' => 'gone.example.com', 'letsencrypt' => 'failed', 'placeholder' => 'removed'));
check($cj->get('mjb_status') === 'failed' && strpos((string)$cj->get('mjb_error_message'), "Let's Encrypt: failed") !== false,
	'a part the host could not remove fails the job, saying which', (string)$cj->get('mjb_error_message'));
$cj = jrp_cert_removal($chost, array('name' => 'gone.example.com', 'letsencrypt' => 'gone!', 'placeholder' => 'absent'));
check((json_decode((string)$cj->get('mjb_result'), true)['letsencrypt'] ?? '') === 'failed',
	'a state the script does not print is never read as removed');
$cj = jrp_cert_removal($chost, 'not json');
check((json_decode((string)$cj->get('mjb_result'), true)['read'] ?? null) === false, 'output that is not the object is recorded unread');

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

section('A node still waiting on its recovery key is asked again within minutes');

// A node whose first report read "unconfigured" was not asked again for six
// hours, and every backup of it was refused for those hours after its owner had
// set up and proven the key (site_copy.md B33).
check(JobResultProcessor::recovery_report_window(array('backup_recovery_state' => 'proven'))
		=== JobResultProcessor::RECOVERY_REPORT_PROVEN_SECONDS,
	'a proven key is measured again only after six hours');
foreach (array('unconfigured', 'unproven', 'invalid') as $rk_state) {
	check(JobResultProcessor::recovery_report_window(array('backup_recovery_state' => $rk_state))
			=== JobResultProcessor::RECOVERY_REPORT_PENDING_SECONDS,
		"a node reading '$rk_state' is asked again at the next status check two minutes on");
}
check(JobResultProcessor::recovery_report_window(array('load_1m' => '0.1'))
		=== JobResultProcessor::RECOVERY_REPORT_PENDING_SECONDS,
	'a node never measured is asked at the next status check');
check(JobResultProcessor::RECOVERY_REPORT_PENDING_SECONDS >= 60,
	'the short window still folds a status sweep\'s burst of checks into one report');

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

section('A status check that finds the database down asks for no recovery-key report');

// The report reads the key from the site's database. drs1, its data root
// stopped by hand: the status check measured postgres 'not accepting
// connections', the report it queued failed on a refused connection
// (2026-10-17).
$pg_status = function ($pg) {
	return "=== [Step 1/1] check_status ===\n" . json_encode(array('api_version' => '1.0', 'data' => array(
		'load_1m' => 0.1, 'postgres_status' => $pg, 'uptime' => 'up 2 hours'))) . "\n[Step 1/1 OK]";
};
$pg_node = jrp_node(array(
	'mgn_agent_public_key' => base64_encode(str_repeat("\x1d", 32)),
	'mgn_agent_version'    => AgentVocabulary::FLOOR,
	'mgn_agent_primitives' => 'check_status,host_report,host_converge,recovery_key_report',
	'mgn_web_root'         => '/var/www/html/pgdown/public_html',
));
JobResultProcessor::process(jrp_job($pg_node, 'check_status', $pg_status('not accepting connections')));
check(jrp_pending_jobs($pg_node->key, 'recovery_key_report') === array(),
	'a check that finds the database not accepting connections queues no report');
JobResultProcessor::process(jrp_job($pg_node, 'check_status', $pg_status('accepting connections')));
check(count(jrp_pending_jobs($pg_node->key, 'recovery_key_report')) === 1,
	'the first check that finds it answering again asks, the state still unmeasured');
check(!JobResultProcessor::database_answering(array('postgres_status' => 'not responding')),
	'the SSH transport\'s \'not responding\' is down too');
check(JobResultProcessor::database_answering(array('load_1m' => 0.1)),
	'a check that did not measure the database does not hold the report back');

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

section('A status check fills an empty site address, and leaves a set one alone');

// B38: a node that joins on its own has no site address; the agent reports its
// domain from the site's config, and the status check fills it.
$sd_status = function ($domain) {
	return "=== [Step 1/1] check_status ===\n" . json_encode(array('api_version' => '1.0', 'data' => array(
		'site_domain' => $domain, 'load_1m' => 0.3, 'uptime' => 'up 2 days'))) . "\n[Step 1/1 OK]";
};
$sd_tag = bin2hex(random_bytes(3));
$sd_domain = 'sd' . $sd_tag . '.example.com';
$sd_node = jrp_node(array('mgn_agent_public_key' => base64_encode(str_repeat("\x1d", 32)), 'mgn_site_url' => ''));
JobResultProcessor::process(jrp_job($sd_node, 'check_status', $sd_status($sd_domain)));
$sd_node = new ManagedNode($sd_node->key, TRUE);
check($sd_node->get('mgn_site_url') === 'https://' . $sd_domain,
	'an empty site address is filled as https://<domain>', (string)$sd_node->get('mgn_site_url'));

JobResultProcessor::process(jrp_job($sd_node, 'check_status', $sd_status('other' . $sd_tag . '.example.com')));
$sd_node = new ManagedNode($sd_node->key, TRUE);
check($sd_node->get('mgn_site_url') === 'https://' . $sd_domain,
	'a set site address is never replaced by a different report', (string)$sd_node->get('mgn_site_url'));

// Another live node already holds that address: nothing is filled, so two rows
// never claim one site.
$sd_twin = jrp_node(array('mgn_agent_public_key' => base64_encode(str_repeat("\x1e", 32)), 'mgn_site_url' => ''));
check(ManagedNode::adopt_reported_site_domain($sd_twin, $sd_domain, 'status check') === 'taken'
	&& trim((string)$sd_twin->get('mgn_site_url')) === '',
	'an address another node holds is refused', (string)$sd_twin->get('mgn_site_url'));

// ...unless the two are a copy and its source.
$sd_copy = jrp_node(array('mgn_agent_public_key' => base64_encode(str_repeat("\x1f", 32)), 'mgn_site_url' => '',
	'mgn_install_state' => 'copy', 'mgn_copy_of_node_id' => (int)$sd_node->key));
check(ManagedNode::adopt_reported_site_domain($sd_copy, $sd_domain, 'status check') === 'filled'
	&& $sd_copy->get('mgn_site_url') === 'https://' . $sd_domain,
	'a copy may carry its source\'s address', (string)$sd_copy->get('mgn_site_url'));

// This management node's own address is never taken by another row: is_self()
// reads it, and that row is the plane's own.
$sd_own_host = strtolower((string)parse_url((string)LibraryFunctions::get_absolute_url(), PHP_URL_HOST));
$sd_self = jrp_node(array('mgn_agent_public_key' => base64_encode(str_repeat("\x20", 32)), 'mgn_site_url' => ''));
check(ManagedNode::adopt_reported_site_domain($sd_self, $sd_own_host, 'status check') === 'taken'
	&& trim((string)$sd_self->get('mgn_site_url')) === '',
	'this management node\'s own domain is refused', $sd_own_host);

foreach (array('192.0.2.7', 'localhost', 'example.com:8080', 'https://example.com', 'exa mple.com', '::1', '-bad.example.com') as $bad) {
	check(ManagedNode::valid_site_domain($bad) === null, 'not a site domain: ' . $bad);
}
check(ManagedNode::valid_site_domain('Copytest.Jeremytunnell.INFO') === 'copytest.jeremytunnell.info',
	'a domain is read lowercase');

$sd_bad = jrp_node(array('mgn_agent_public_key' => base64_encode(str_repeat("\x21", 32)), 'mgn_site_url' => ''));
JobResultProcessor::process(jrp_job($sd_bad, 'check_status', $sd_status('203.0.113.9')));
$sd_bad = new ManagedNode($sd_bad->key, TRUE);
check(trim((string)$sd_bad->get('mgn_site_url')) === '', 'a reported address that is not a domain fills nothing');

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
section('page_probe: a probe that could not run fails the job with the node\'s reason');

$jrp_probe_node = jrp_node();
// The shape jeremytunnell returned on 2026-09-28 for a member probe.
$probe_env = function (array $over) {
	return json_encode(['api_version' => '1.0', 'data' => ['output' => json_encode(array_merge([
		'page' => '/profile', 'viewer' => 'member', 'status' => 0, 'bytes' => 0, 'render_ms' => null,
		'statements' => null, 'peak_memory' => null, 'warnings' => [], 'failed_assets' => [],
		'landmarks' => ['header' => false, 'main' => false, 'footer' => false, 'forms' => 0],
		'structure_hash' => '', 'reported' => false, 'cleanup' => 'done', 'swept' => 0,
		'failed_assets_unnamed' => 0, 'truncated' => false,
	], $over)) . "\n"]]);
};
$pj = jrp_job($jrp_probe_node, 'page_probe', $probe_env(['error' => 'the probe could not run: DisplayableUserException']));
JobResultProcessor::process($pj);
$pr = json_decode((string)$pj->get('mjb_result'), true);
check(($pr['error'] ?? null) === 'the probe could not run: DisplayableUserException',
	'the node\'s error is kept on the result', json_encode($pr));
check($pj->get('mjb_status') === 'failed'
	&& $pj->get('mjb_error_message') === 'the probe could not run: DisplayableUserException',
	'and the job is failed with it, not recorded as a completed render', $pj->get('mjb_status') . ' / ' . $pj->get('mjb_error_message'));

$pj2 = jrp_job($jrp_probe_node, 'page_probe', $probe_env(['error' => 'the probe could not run: X<script>', 'cleanup' => 'the user is still there']));
JobResultProcessor::process($pj2);
check($pj2->get('mjb_error_message') === 'the probe could not run: Xscript; the probe could not clean up after itself: the user is still there',
	'an error is reduced to plain characters, and a failed cleanup is named beside it', (string)$pj2->get('mjb_error_message'));

$pj3 = jrp_job($jrp_probe_node, 'page_probe', $probe_env(['status' => 200, 'bytes' => 900, 'render_ms' => 40, 'reported' => true]));
JobResultProcessor::process($pj3);
$pr3 = json_decode((string)$pj3->get('mjb_result'), true);
check($pj3->get('mjb_status') === 'completed' && ($pr3['error'] ?? null) === '' && $pr3['render_ms'] === 40,
	'a probe that ran stays completed with no error', json_encode($pr3));

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

// site_log_count: counts, bounded samples, and the coverage flag exactly as sent.
$cnt_env = json_encode(['api_version' => '1.0', 'data' => [
	'file' => 'error', 'since' => '2026-10-22T23:07:00Z', 'present' => true, 'covers_since' => true,
	'covered_from' => '2026-10-22T00:00:01Z', 'not_covered' => '', 'files_read' => ['error.log', 'error.log.1', '../x'],
	'bytes_read' => 400000, 'lines_in_window' => 1200,
	'markers' => [
		'entry_script_missing' => ['count' => 2, 'first' => '2026-10-22T23:10:00Z', 'last' => '2026-10-22T23:10:01Z',
			'samples' => ['one', 'two', 'three', 'four']],
		'invented_marker' => ['count' => 9],
	],
]]);
$cj = jrp_job($jrp_log_node, 'site_log_count', "=== [Step 1/1] site_log_count ===\n" . $cnt_env . "\n[Step 1/1 OK]");
JobResultProcessor::process($cj);
$cr = json_decode((string)$cj->get('mjb_result'), true);
check(is_array($cr) && $cr['read'] === true && $cr['covers_since'] === true && $cr['markers']['entry_script_missing']['count'] === 2,
	'a site_log_count envelope is recorded with its counts and whether it covered the window', json_encode($cr));
check(count($cr['markers']['entry_script_missing']['samples']) === 3 && !isset($cr['markers']['invented_marker'])
	&& array_keys($cr['markers']) === array_keys(JobCommandBuilder::SITE_LOG_COUNT_MARKERS),
	'samples are capped at three and a marker the plane does not know is dropped');
check($cr['files_read'] === ['error.log', 'error.log.1'], 'only error.log and its rotations are kept as file names', json_encode($cr['files_read']));
$uj = jrp_job($jrp_log_node, 'site_log_count', json_encode(['api_version' => '1.0', 'data' => [
	'covers_since' => 'yes', 'markers' => [], 'not_covered' => 'stopped after 8 files']]));
JobResultProcessor::process($uj);
$ur = json_decode((string)$uj->get('mjb_result'), true);
check($ur['covers_since'] === false && $ur['not_covered'] === 'stopped after 8 files',
	'covers_since is true only when the node said exactly true, and its reason is kept');

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
	'backup_target_id' => $ro_bkt->key,
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
		'mgn_agent_primitives' => 'check_status,restore_chain', 'backup_target_id' => $ro_bkt->key));
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
	check(is_array($d) && array_key_exists('data_root', $d) && $d['data_root'] === null,
		'a report with no data root (a host without one, or a script before it) keeps none');

	// The data root (one_data_root WP2): the site's folders are mounts of it,
	// so its own figures and tree are where their space shows.
	$dr_job = jrp_job($uj_node, 'disk_usage', $envelope(array(
		'filesystem' => array('path' => '/var/www/html/x/public_html', 'used_bytes' => 100, 'total_bytes' => 200, 'avail_bytes' => 80),
		'tree' => array('path' => '/var/www/html/x', 'total_bytes' => 90, 'partial' => false, 'entries' => array()),
		'data_root' => array(
			'filesystem' => array('path' => '/srv/joinery', 'used_bytes' => 30, 'total_bytes' => 64, 'avail_bytes' => 34, 'owner' => 'x'),
			'tree' => array('path' => '/srv/joinery', 'total_bytes' => 30, 'partial' => true, 'entries' => array_merge(
				array(array('path' => 'sites/x/uploads', 'bytes' => 20, 'mtime' => 5)),
				array_fill(0, 30, array('path' => 'd', 'bytes' => 1)))),
			'depth' => 3),
		'machine' => array(),
		'generated_at' => 1790050543,
	)));
	JobResultProcessor::process($dr_job);
	$d = json_decode((string)$dr_job->get('mjb_result'), true);
	check(is_array($d['data_root'] ?? null) && $d['data_root']['filesystem'] === array('path' => '/srv/joinery', 'used_bytes' => 30, 'total_bytes' => 64, 'avail_bytes' => 34),
		'the data root\'s figures are kept, and nothing else the node put beside them', var_export($d['data_root']['filesystem'] ?? null, true));
	check(is_array($d['data_root'] ?? null) && $d['data_root']['tree']['partial'] === true
		&& count($d['data_root']['tree']['entries']) === JobResultProcessor::DISK_USAGE_MAX_ENTRIES
		&& $d['data_root']['tree']['entries'][0] === array('path' => 'sites/x/uploads', 'bytes' => 20),
		'its tree is capped and shaped as the site tree is', var_export($d['data_root']['tree']['entries'][0] ?? null, true));
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


section('install_security_updates (agent 1.87.0): what was installed, and a host report behind it');

{
	$su_node = jrp_node(array(
		'mgn_agent_public_key' => base64_encode(str_repeat("\x0e", 32)),
		'mgn_agent_version'    => AgentVocabulary::FLOOR,
		'mgn_agent_primitives' => 'check_status,host_report,install_security_updates',
	));
	$su_envelope = function ($object) {
		return "=== [Step 1/1] word ===\n" . json_encode(array(
			'api_version' => '1.0',
			'data' => array('output' => json_encode($object), 'output_bytes' => 1),
		));
	};
	$su_reports = function () use ($su_node) {
		$n = 0;
		foreach (new MultiManagementJob(array('node_id' => (int)$su_node->key, 'job_type' => 'host_report')) as $j) {
			harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $j->key);
			$n++;
		}
		return $n;
	};
	$su_object = function ($extra = array()) {
		return array_merge(array(
			'outcome' => 'installed', 'reason' => '', 'apt_update' => 'ok', 'upgrade_exit' => 0,
			'upgraded' => array(
				array('package' => 'libnew1:amd64', 'from' => '', 'to' => '1.0-1'),
				array('package' => 'php8.3-fpm:amd64', 'from' => '8.3.35-1', 'to' => '8.3.36-1'),
			),
			'upgraded_count' => 2, 'truncated' => false, 'reboot_required' => true,
			'services_needing_restart' => array('php8.3-fpm.service'),
		), $extra);
	};

	$su_job = jrp_job($su_node, 'install_security_updates', $su_envelope($su_object()));
	JobResultProcessor::process($su_job);
	$r = json_decode((string)$su_job->get('mjb_result'), true);
	check(is_array($r) && $r['read'] === true && $r['outcome'] === 'installed' && $r['upgraded_count'] === 2
		&& $r['upgraded'][1] === array('package' => 'php8.3-fpm:amd64', 'from' => '8.3.35-1', 'to' => '8.3.36-1')
		&& $r['upgraded'][0]['from'] === '' && $r['reboot_required'] === true
		&& $r['services_needing_restart'] === array('php8.3-fpm.service'),
		'each package with its old and new version, the reboot asked for and the service still on old code are kept', var_export($r, true));
	check($su_reports() === 1, 'a run that installed something queues one host report, so the Machine card shows the reboot');

	$none = jrp_job($su_node, 'install_security_updates', $su_envelope($su_object(array(
		'outcome' => 'nothing_to_install', 'upgraded' => array(), 'upgraded_count' => 0, 'reboot_required' => false,
		'services_needing_restart' => 'unknown'))));
	JobResultProcessor::process($none);
	$n = json_decode((string)$none->get('mjb_result'), true);
	check($n['outcome'] === 'nothing_to_install' && $n['upgraded'] === array() && $n['services_needing_restart'] === null,
		'nothing to install reads as such, and a service list the node could not read is null, not empty', var_export($n, true));
	check($su_reports() === 1, 'a run that installed nothing queues no further report');

	$hostile = jrp_job($su_node, 'install_security_updates', $su_envelope($su_object(array(
		'outcome' => 'pwned', 'apt_update' => '<b>', 'reboot_required' => 'yes', 'upgrade_exit' => '0',
		'upgraded' => array(array('package' => '<script>x</script>', 'from' => 'a b', 'to' => "1\n2"), 'not a row', array('from' => '1')),
		'upgraded_count' => -4, 'surprise' => 1,
	))));
	JobResultProcessor::process($hostile);
	$h = json_decode((string)$hostile->get('mjb_result'), true);
	check(is_array($h) && !isset($h['surprise']) && $h['outcome'] === 'unknown' && $h['apt_update'] === 'unknown'
		&& $h['reboot_required'] === false && $h['upgrade_exit'] === null,
		'an outcome or field outside the script\'s words reads as unknown; a reboot not literally true is false', var_export($h, true));
	check(count($h['upgraded']) === 1 && $h['upgraded'][0] === array('package' => 'scriptxscript', 'from' => 'ab', 'to' => '12')
		&& $h['upgraded_count'] === 1,
		'names and versions are reduced to a package\'s characters; a row with no package is dropped; a bad count is the list\'s', var_export($h, true));

	$rl = jrp_job($su_node, 'install_security_updates', $su_envelope($su_object(array(
		'repaired' => true, 'reloaded' => array('php-fpm8.5', '<x>'), 'still_on_old_code' => array('php-fpm8.3')))));
	JobResultProcessor::process($rl);
	$rlr = json_decode((string)$rl->get('mjb_result'), true);
	check($rlr['repaired'] === true && $rlr['reloaded'] === array('php-fpm8.5', 'x') && $rlr['still_on_old_code'] === array('php-fpm8.3'),
		'1.2: a half-done run finished, the php-fpm reloaded and the one still on old code are kept', var_export($rlr, true));
	check($r['reloaded'] === null && $r['still_on_old_code'] === null && $r['repaired'] === false,
		'an older script\'s result (no such keys): reloaded and still_on_old_code null (not reported), repaired false');

	$unread = jrp_job($su_node, 'install_security_updates', "=== [Step 1/1] word ===\nrefused\n");
	JobResultProcessor::process($unread);
	check(json_decode((string)$unread->get('mjb_result'), true) === array('read' => false),
		'a job that came back with no object records read=false');
	check(in_array('install_security_updates', JobResultProcessor::processable_types(), true),
		'install_security_updates is a type this processor knows');
}

section('refresh_site_containers (agent 1.88.0): the run started, and a host report behind it');

{
	$rf_node = jrp_node(array(
		'mgn_agent_public_key' => base64_encode(str_repeat("\x0f", 32)),
		'mgn_agent_version'    => AgentVocabulary::FLOOR,
		'mgn_agent_primitives' => 'check_status,host_report,refresh_site_containers',
	));
	$rf_envelope = function ($line) {
		return "=== [Step 1/1] word ===\n" . json_encode(array(
			'api_version' => '1.0',
			'data' => array('output' => $line . "\n", 'output_bytes' => strlen($line) + 1),
		));
	};
	$rf_reports = function () use ($rf_node) {
		$n = 0;
		foreach (new MultiManagementJob(array('node_id' => (int)$rf_node->key, 'job_type' => 'host_report')) as $j) {
			harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $j->key);
			$n++;
		}
		return $n;
	};
	$rf_job = jrp_job($rf_node, 'refresh_site_containers', $rf_envelope('site_refresh: a run has started; the host report shows what it did when it ends'));
	JobResultProcessor::process($rf_job);
	$r = json_decode((string)$rf_job->get('mjb_result'), true);
	check(is_array($r) && $r['read'] === true && $r['started'] === true && strpos($r['said'], 'a run has started') !== false,
		'a run started reads as started, with the host\'s line', var_export($r, true));
	check($rf_reports() === 1, 'a run started queues one host report, so the node page shows it under way');
	$again = jrp_job($rf_node, 'refresh_site_containers', $rf_envelope('site_refresh: a run is already under way'));
	JobResultProcessor::process($again);
	check(json_decode((string)$again->get('mjb_result'), true)['started'] === true && $rf_reports() === 1,
		'a run already under way counts as started, and a report queued a moment ago is not queued again');
	$hostile = jrp_job($rf_node, 'refresh_site_containers', $rf_envelope('<script>x</script> "q"'));
	JobResultProcessor::process($hostile);
	$h = json_decode((string)$hostile->get('mjb_result'), true);
	check($h['started'] === false && $h['said'] === 'scriptx/script q', 'any other line is not started, and is reduced to text', var_export($h, true));
	$unread = jrp_job($rf_node, 'refresh_site_containers', "=== [Step 1/1] word ===\nrefused\n");
	JobResultProcessor::process($unread);
	check(json_decode((string)$unread->get('mjb_result'), true)['read'] === false, 'a job that came back with no output records read=false');
	check(in_array('refresh_site_containers', JobResultProcessor::processable_types(), true),
		'refresh_site_containers is a type this processor knows');
}


section('site_census: the node\'s count is kept for comparing, and nothing else changes');

// specs/site_copy.md WP3. The census is what the node printed as root, inside
// the agent's envelope, with whatever warnings PHP printed around it.
$sc_census = array('version' => SiteCensus::VERSION, 'tables' => array('usr_users' => 3),
	'files' => array('uploads' => array('files' => 2, 'dirs' => 1, 'bytes' => 150, 'digest' => str_repeat('a', 64))),
	'secrets' => array('canary' => 'ok', 'present' => 4, 'dead' => 0, 'dead_locators' => array()),
	'offloaded' => array('total' => 0, 'sampled' => 0, 'answered' => 0, 'missing' => array(), 'error' => ''));
$sc_env = function ($text) {
	return "=== [Step 1/1] site_census ===\n" . json_encode(array('api_version' => '1.0', 'data' => array(
		'output' => $text, 'output_bytes' => strlen($text)))) . "\n[Step 1/1 OK]";
};
$sc_node = jrp_node();
$sc_status_before = (string)$sc_node->get('mgn_last_status_data');
$sc_job = jrp_job($sc_node, 'site_census', $sc_env("PHP Deprecated: x\n" . SiteCensus::format_output($sc_census)));
JobResultProcessor::process($sc_job);
check(JobResultProcessor::census_of($sc_job) === $sc_census, 'the census comes back as the node counted it',
	(string)$sc_job->get('mjb_result'));
$sc_node->load();
check((string)$sc_node->get('mgn_last_status_data') === $sc_status_before, 'and the node\'s stored status is untouched');

$sc_bad = jrp_job($sc_node, 'site_census', $sc_env("ERROR: the census could not be taken: cannot read /x\n"));
JobResultProcessor::process($sc_bad);
$sc_r = json_decode((string)$sc_bad->get('mjb_result'), true);
check(JobResultProcessor::census_of($sc_bad) === null && ($sc_r['measured'] ?? null) === false
	&& strpos((string)($sc_r['error'] ?? ''), 'cannot read /x') !== false,
	'a census that failed is recorded as none, with the node\'s reason', (string)$sc_bad->get('mjb_result'));
$sc_other = jrp_job($sc_node, 'host_report', $sc_env(SiteCensus::format_output($sc_census)));
check(JobResultProcessor::census_of($sc_other) === null, 'only a site_census job carries a census');
$sc_threw = false;
try { JobCommandBuilder::build_site_census($sc_node); } catch (Exception $e) { $sc_threw = true; }
check($sc_threw, 'a node with no agent is not asked');
$sc_cmd = JobCommandBuilder::build_site_census(jrp_node(array('mgn_agent_public_key' => base64_encode(str_repeat("\x0c", 32)))));
check($sc_cmd === array('primitive' => 'site_census', 'params' => array()), 'the job names the word and nothing else',
	var_export($sc_cmd, true));

// ---------------------------------------------------------------------------
section('A full backup asks the node which recovery key it holds');

$rk_node = jrp_node(array('mgn_agent_public_key' => base64_encode(random_bytes(32))));
$rk_count = function () use ($rk_node) {
	return (new MultiManagementJob(array('node_id' => $rk_node->key, 'job_type' => 'recovery_key_report', 'deleted' => false)))->count();
};
$rk_out = "manager success: Incremental backup in chain-x\nBACKUP_RESULT=success\nBACKUP_TIME=2026-10-03 18:00:00\nBACKUP_LEVEL=1\n";
JobResultProcessor::process(jrp_job($rk_node, 'backup_run', $rk_out));
check($rk_count() === 0, 'an incremental asks nothing: its chain, and so its key, did not change');
JobResultProcessor::process(jrp_job($rk_node, 'backup_run', str_replace('BACKUP_LEVEL=1', 'BACKUP_LEVEL=0', $rk_out)));
check($rk_count() === 1, 'a full backup starts a chain (as every changed key does): the node is asked for its key');
JobResultProcessor::process(jrp_job($rk_node, 'backup_run', str_replace('BACKUP_LEVEL=1', 'BACKUP_LEVEL=0', $rk_out)));
check($rk_count() === 1, 'a second within minutes does not ask again');
foreach (new MultiManagementJob(array('node_id' => $rk_node->key, 'job_type' => 'recovery_key_report', 'deleted' => false)) as $rk_job) {
	harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $rk_job->key);
}


// ---------------------------------------------------------------------------
section('A data root job that ends asks for the machine after it (B62)');
// The node page, the enrolment and the address swap's check read the last host
// report; an encryption ends its job once under way, and an enrolment writes
// the holder the machine reports.
$dr_node = jrp_node(array(
	'mgn_agent_public_key' => base64_encode(str_repeat("\x0b", 32)),
	'mgn_agent_version'    => AgentVocabulary::FLOOR,
	'mgn_agent_primitives' => 'check_status,host_report,data_root_encrypt,data_root_enrol,data_root_decrypt',
));
$dr_out = "=== [Step 1/1] data_root_encrypt ===\n" . json_encode(array('api_version' => '1.0', 'data' => array(
	'output' => "The data root is back, and encrypting in the background.\n"))) . "\n[Step 1/1 OK]";
$dr_job = jrp_job($dr_node, 'data_root_encrypt', $dr_out);
check(JobResultProcessor::process_if_due($dr_job), 'a finished data_root_encrypt is a job this processor folds');
$queued = jrp_pending_host_reports($dr_node->key);
foreach ($queued as $id) { harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $id); }
check(count($queued) === 1, 'one host_report is queued behind it', var_export($queued, true));
$dr_enrol = jrp_job($dr_node, 'data_root_enrol', json_encode(array('api_version' => '1.0', 'data' => array('slot' => 1, 'unlock_fingerprint' => str_repeat('a', 16)))));
JobResultProcessor::process($dr_enrol);
check(count(jrp_pending_host_reports($dr_node->key)) === 1, 'an enrolment while that report is pending piles none on');
DbConnector::get_instance()->get_db_link()->prepare("UPDATE mjb_management_jobs SET mjb_status = 'completed', mjb_completed_time = ?
	WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = 'host_report'")->execute(array(gmdate('Y-m-d H:i:s', time() - 5), (int)$dr_node->key));
$dr_enrol2 = jrp_job($dr_node, 'data_root_enrol', json_encode(array('api_version' => '1.0', 'data' => array('slot' => 1, 'unlock_fingerprint' => str_repeat('a', 16)))));
JobResultProcessor::process($dr_enrol2);
$queued = jrp_pending_host_reports($dr_node->key);
foreach ($queued as $id) { harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $id); }
check(count($queued) === 1, 'a report finished before the enrolment ended is no cover: one is asked for after it', var_export($queued, true));
foreach (array('data_root_encrypt', 'data_root_decrypt', 'data_root_rekey', 'data_root_migrate', 'data_root_recipient', 'data_root_enrol') as $t) {
	check(in_array($t, JobResultProcessor::processable_types(), true), "$t is a type this processor knows");
}

section('The running list\'s words (agent 1.76.0) and host_report 1.23');

$rl_env = function ($type, $obj) {
	return "=== [Step 1/1] $type ===\n" . json_encode(array('api_version' => '1.0', 'data' => array(
		'output' => (is_string($obj) ? $obj : json_encode($obj)) . "\n", 'output_bytes' => 400))) . "\n[Step 1/1 OK]";
};

// listening_sockets: each listener held to its shape.
$ls = JobResultProcessor::sanitise_listening_sockets(array('sockets' => array(
	array('proto' => 'tcp', 'family' => 'ipv4', 'address' => '0.0.0.0', 'port' => 80, 'program' => 'apache2', 'unit' => 'apache2.service'),
	array('proto' => 'tcp', 'family' => 'ipv6', 'address' => '::', 'port' => 993, 'program' => 'joinery-mail-ga', 'unit' => 'joinery-mail-gateway.service'),
	array('proto' => 'udp', 'family' => 'any', 'address' => '*', 'port' => 53, 'program' => 'x<script>', 'unit' => ''),
	array('proto' => 'raw', 'family' => 'ipv4', 'address' => '0.0.0.0', 'port' => 1),
	array('proto' => 'tcp', 'family' => 'ipv4', 'address' => 'evil.example', 'port' => 22),
	array('proto' => 'tcp', 'family' => 'ipv4', 'address' => '127.0.0.1', 'port' => 70000),
	'not a row',
), 'truncated' => true));
check($ls['read'] === true && count($ls['sockets']) === 3 && $ls['truncated'] === true,
	'listening_sockets: three listeners kept; a raw socket, a name for an address and a port past 65535 dropped', var_export($ls, true));
check($ls['sockets'][1]['address'] === '::' && $ls['sockets'][1]['family'] === 'ipv6' && $ls['sockets'][2]['program'] === 'xscript',
	'an IPv6 address kept as an address; a program name reduced');
check(JobResultProcessor::sanitise_listening_sockets(array('sockets' => 'unknown'))['sockets'] === 'unknown'
	&& JobResultProcessor::sanitise_listening_sockets(null) === array('read' => false),
	'ss not answering is unknown; output that is not the object is not read');
$ls_node = jrp_node(array('mgn_agent_public_key' => base64_encode(str_repeat("\x51", 32))));
$ls_job = jrp_job($ls_node, 'listening_sockets', $rl_env('listening_sockets', array('sockets' => array(
	array('proto' => 'tcp', 'family' => 'ipv4', 'address' => '0.0.0.0', 'port' => 443, 'program' => 'apache2', 'unit' => 'apache2.service')), 'truncated' => false)));
JobResultProcessor::process($ls_job);
$ls_job->load();
$ls_r = json_decode((string)$ls_job->get('mjb_result'), true);
check(($ls_r['sockets'][0]['port'] ?? null) === 443, 'a listening_sockets job keeps its listeners as its result', var_export($ls_r, true));

// container_inventory: install.sh's and not, mounts by type and volume name.
$ci = JobResultProcessor::sanitise_container_inventory(array('engine' => 'podman', 'containers' => array(
	array('name' => 'siteone', 'install_sh' => true, 'image' => 'joinery/site:0.8.494', 'state' => 'running',
		'mounts' => array(array('type' => 'volume', 'name' => 'siteone_data'), array('type' => 'bind', 'name' => ''), array('type' => 'weird', 'name' => 'x;y')), 'run_spec' => true),
	array('name' => 'postgres', 'install_sh' => 'yes', 'image' => 'postgres:16 ; rm', 'state' => 'exited', 'mounts' => 'unknown', 'run_spec' => 1),
	array('name' => '', 'install_sh' => true),
), 'truncated' => false));
check(count($ci['containers']) === 2 && $ci['engine'] === 'podman', 'container_inventory: the engine kept, a nameless entry dropped', var_export($ci, true));
check($ci['containers'][0]['install_sh'] === true && $ci['containers'][0]['run_spec'] === true && $ci['containers'][0]['mounts'][2]['type'] === 'other'
	&& $ci['containers'][0]['mounts'][2]['name'] === 'xy', 'a site container kept as the node said; a mount type it does not know is other');
check($ci['containers'][1]['install_sh'] === false && $ci['containers'][1]['run_spec'] === false && $ci['containers'][1]['image'] === 'unknown'
	&& $ci['containers'][1]['mounts'] === 'unknown', 'only a literal true says install.sh made it; an image that is not a reference is unknown');
check(JobResultProcessor::sanitise_container_inventory(array('engine' => 'lxc', 'containers' => 'none'))['engine'] === 'unknown'
	&& JobResultProcessor::sanitise_container_inventory(array('containers' => 'none'))['containers'] === 'none'
	&& JobResultProcessor::sanitise_container_inventory(array('containers' => 'nonsense'))['containers'] === 'unknown',
	'no docker is none; anything else that is not a list is unknown');
// A site's sixteen volumes are all kept (B65); a container with more than 32, or more containers than 150, is a cut list.
$vols = function ($n) { $m = array(); for ($i = 1; $i <= $n; $i++) { $m[] = array('type' => 'volume', 'name' => 'siteone_v' . $i); } return $m; };
$ci16 = JobResultProcessor::sanitise_container_inventory(array('engine' => 'docker', 'truncated' => false,
	'containers' => array(array('name' => 'siteone', 'install_sh' => true, 'image' => 'joinery/site:0.8.495', 'state' => 'running', 'mounts' => $vols(16), 'run_spec' => true))));
check(count($ci16['containers'][0]['mounts']) === 16 && $ci16['truncated'] === false, 'container_inventory: a site\'s sixteen volumes all kept, nothing said cut (B65)', var_export($ci16['truncated'], true));
$ci40 = JobResultProcessor::sanitise_container_inventory(array('engine' => 'docker', 'truncated' => false,
	'containers' => array(array('name' => 'siteone', 'install_sh' => true, 'image' => 'joinery/site:0.8.495', 'state' => 'running', 'mounts' => $vols(40), 'run_spec' => true))));
$many = array(); for ($i = 1; $i <= 151; $i++) { $many[] = array('name' => 'site' . $i, 'mounts' => array()); }
$ci151 = JobResultProcessor::sanitise_container_inventory(array('engine' => 'docker', 'truncated' => false, 'containers' => $many));
check(count($ci40['containers'][0]['mounts']) === 32 && $ci40['truncated'] === true && count($ci151['containers']) === 150 && $ci151['truncated'] === true,
	'a list cut here, by mounts or by containers, says truncated though the node said not');

// spam_learning_status: counts, a time, booleans.
$sl = JobResultProcessor::sanitise_spam_learning_status(array('mailbox' => 'active', 'window_days' => 7,
	'corrections' => array('recorded' => 5, 'taught' => 3, 'pending_clear' => 1, 'pending_sealed' => 1, 'skipped_imap' => '2', 'subject' => 'Buy now'),
	'corpus' => array('tokens' => 153, 'newest_token' => '2026-10-14T05:41:03Z', 'spam_messages' => 5, 'ham_messages' => 2, 'trained' => false),
	'policy' => array('filtering_enabled' => true, 'learning_enabled' => 'yes')));
check($sl['corrections'] === array('recorded' => 5, 'taught' => 3, 'pending_clear' => 1, 'pending_sealed' => 1, 'skipped_imap' => 2),
	'spam_learning_status: the five counts and nothing else the node sent', var_export($sl, true));
check($sl['corpus']['newest_token'] === '2026-10-14T05:41:03Z' && $sl['policy'] === array('filtering_enabled' => true, 'learning_enabled' => false),
	'the newest token time kept; only a literal true switches a policy on');
check(JobResultProcessor::sanitise_spam_learning_status(array('mailbox' => 'inactive')) === array('read' => true, 'mailbox' => 'inactive')
	&& JobResultProcessor::sanitise_spam_learning_status(array('corrections' => array())) === array('read' => false),
	'mailbox inactive is said; an answer with no mailbox state is not read');

// host_report 1.23: the data root script and the boot id.
$drs = JobResultProcessor::host_report_data_root_script(array('installed_version' => '1.21', 'release_version' => '1.22', 'consumers' => array(
	array('unit' => 'postgresql.service', 'drop_in' => true, 'installed' => true),
	array('unit' => 'podman-restart.service', 'drop_in' => true, 'installed' => false),
	array('unit' => 'evil', 'drop_in' => true, 'installed' => true))));
check($drs['installed_version'] === '1.21' && $drs['release_version'] === '1.22' && count($drs['consumers']) === 2,
	'data_root_script: both versions, and only units that are services', var_export($drs, true));
check(JobResultProcessor::host_report_data_root_script(array('installed_version' => 'none', 'release_version' => '1.x; rm'))['release_version'] === 'unknown'
	&& JobResultProcessor::host_report_data_root_script('none') === 'none',
	'a version that is not a version is unknown; no data root is none');
check(JobResultProcessor::boot_id('c42ff0bb-667f-4a49-ac78-e96690792931') === 'c42ff0bb-667f-4a49-ac78-e96690792931'
	&& JobResultProcessor::boot_id('nope') === 'unknown', 'a boot id is a UUID or unknown');
$s23 = JobResultProcessor::sanitise_host_report(array('boot_id' => 'c42ff0bb-667f-4a49-ac78-e96690792931', 'data_root_script' => 'none'));
check($s23['boot_id'] === 'c42ff0bb-667f-4a49-ac78-e96690792931' && $s23['data_root_script'] === 'none'
	&& JobResultProcessor::sanitise_host_report(array())['boot_id'] === null,
	'the host report keeps both keys; an older node\'s report has neither (null, not reported)');

// reboot_machine: the boot id before, and the next host report's new one says it came back.
$rb_node = jrp_node(array('mgn_agent_public_key' => base64_encode(str_repeat("\x52", 32))));
$boot_a = '11111111-2222-4333-8444-555555555555';
$boot_b = '66666666-7777-4888-9999-aaaaaaaaaaaa';
$rb_job = jrp_job($rb_node, 'reboot_machine', $rl_env('reboot_machine', array('scheduled' => true, 'already_scheduled' => false,
	'in_seconds' => 20, 'boot_id_before' => $boot_a, 'refused' => '', 'reason' => '')));
JobResultProcessor::process($rb_job);
$rb_job->load();
$rb_r = json_decode((string)$rb_job->get('mjb_result'), true);
check(!empty($rb_r['scheduled']) && $rb_r['boot_id_before'] === $boot_a && $rb_r['came_back_at'] === null,
	'a scheduled reboot keeps the boot id it was asked from, not yet back', var_export($rb_r, true));
check(JobResultProcessor::reboot_awaited($rb_node), 'the plane awaits it: the uptime pass asks for host reports more often');
$rb_report = function ($boot) use ($rb_node, $rl_env) {
	$job = jrp_job($rb_node, 'host_report', $rl_env('host_report', array('boot_id' => $boot, 'generated_at' => time())));
	JobResultProcessor::process($job);
};
$rb_report($boot_a);
check(JobResultProcessor::reboot_awaited($rb_node), 'a report with the same boot id (taken before the reboot) is not the machine coming back');
$rb_report('unknown');
check(JobResultProcessor::reboot_awaited($rb_node), 'nor is a report that cannot say its boot id');
$rb_report($boot_b);
check(!JobResultProcessor::reboot_awaited($rb_node), 'a new boot id is the reboot seen to have happened');
$rb_last = JobResultProcessor::last_reboot($rb_node);
check(!empty($rb_last['came_back_at']) && (int)$rb_last['job']->key === (int)$rb_job->key,
	'the reboot job says when it came back', var_export($rb_last['came_back_at'] ?? null, true));

$rb_refused = jrp_job($rb_node, 'reboot_machine', $rl_env('reboot_machine', array('scheduled' => false, 'already_scheduled' => false,
	'in_seconds' => 20, 'boot_id_before' => $boot_b, 'refused' => 'no_escrow', 'reason' => 'the data root is encrypted and names no escrow holder')));
JobResultProcessor::process($rb_refused);
$rb_refused->load();
$rr = json_decode((string)$rb_refused->get('mjb_result'), true);
check(empty($rr['scheduled']) && $rr['refused'] === 'no_escrow' && !JobResultProcessor::reboot_awaited($rb_node),
	'a refused reboot is not scheduled and nothing is awaited', var_export($rr, true));
check(JobResultProcessor::sanitise_reboot_machine(array('scheduled' => true, 'refused' => 'container'))['scheduled'] === false,
	'a result that both schedules and refuses reads as refused');

section('site_state_report (agent 1.86.0): the records are kept, and a value only on a plain record');

$ss_entry = function ($name, $extra = array()) {
	return array_merge(array('name' => $name, 'kind' => 'file', 'bytes' => 12, 'mode' => '0600', 'root_owned' => true,
		'modified' => '2026-10-21T01:12:15Z'), $extra);
};
$ss = JobResultProcessor::sanitise_site_state_report(array(
	'sites_dir' => '/etc/joinery/sites', 'exists' => true, 'truncated' => false,
	'reading'   => array('site' => 'src3', 'reason' => 'copy', 'copy_of' => 37),
	'sites'     => array(array('site' => 'src3', 'entries' => array(
		$ss_entry('state', array('value' => 'quiet copy')),
		$ss_entry('copy_of', array('value' => '37')),
		$ss_entry('look_secret', array('value' => 'a1b2c3d4e5f6')),
		$ss_entry('forward', array('value' => '<script>alert(1)</script>')),
		$ss_entry('held', array('kind' => 'dir', 'bytes' => 4096, 'mode' => '0755')),
		$ss_entry('held/state', array('value' => 'not a top-level record')),
		$ss_entry("bad\x01name", array('kind' => 'fifo', 'mode' => 'rwx', 'modified' => 'yesterday', 'root_owned' => 'yes')),
		array('name' => 'vouched', 'error' => 'permission denied'),
		'not a row',
	))),
));
$ss_rows = array();
foreach ($ss['sites'][0]['entries'] as $e) { $ss_rows[$e['name']] = $e; }
check($ss['read'] === true && $ss['exists'] === true && $ss['reading'] === array('site' => 'src3', 'reason' => 'copy', 'copy_of' => 37)
	&& count($ss['sites'][0]['entries']) === 8,
	'site_state_report: the reading and eight records kept; a row that is not an object dropped', var_export($ss, true));
check(($ss_rows['state']['value'] ?? null) === 'quiet copy' && ($ss_rows['copy_of']['value'] ?? null) === '37',
	'the state line and copy_of keep their values');
check(!array_key_exists('value', $ss_rows['look_secret']) && ($ss_rows['look_secret']['bytes'] ?? null) === 12,
	'a value sent on the look secret is dropped; the record is still named and sized');
check(!array_key_exists('value', $ss_rows['forward']) && !array_key_exists('value', $ss_rows['held/state']),
	'a value that is not plain text, and one on a nested record, are dropped');
check(!array_key_exists('bytes', $ss_rows['held']) && $ss_rows['held']['kind'] === 'dir',
	'a directory carries no size');
check(isset($ss_rows['badname']) && $ss_rows['badname']['kind'] === 'other' && $ss_rows['badname']['mode'] === ''
	&& $ss_rows['badname']['modified'] === '' && !array_key_exists('root_owned', $ss_rows['badname']),
	'a control byte is stripped from a name; an unknown kind, a bad mode, a bad time and a non-boolean owner are not kept',
	var_export($ss_rows['badname'] ?? null, true));
check(($ss_rows['vouched']['error'] ?? null) === 'permission denied' && count($ss_rows['vouched']) === 2,
	'a record the node could not read keeps its name and the reason only');

$ss_big = JobResultProcessor::sanitise_site_state_report(array('exists' => true, 'truncated' => false,
	'reading' => array('site' => 'x', 'reason' => 'gone', 'copy_of' => -4),
	'sites' => array_merge(array(array('site' => 'many', 'entries' => array_fill(0, 70, $ss_entry('history')))),
		array_fill(0, 120, array('site' => 's', 'entries' => array())))));
check($ss_big['truncated'] === true && count($ss_big['sites']) === 100 && count($ss_big['sites'][0]['entries']) === 64,
	'past 100 sites or 64 records the result is cut and says so, though the node said it was not');
check($ss_big['reading']['reason'] === 'unknown' && $ss_big['reading']['copy_of'] === 0,
	'a reading the word never gives is unknown, and a copy_of that is not a node id is none');
check(JobResultProcessor::sanitise_site_state_report(null) === array('read' => false)
	&& JobResultProcessor::sanitise_site_state_report(array('sites' => 'all')) === array('read' => false),
	'output that is not the report is not read');
check(!array_key_exists('reading', JobResultProcessor::sanitise_site_state_report(array('exists' => false, 'sites' => array()))),
	'a machine with no site gives no reading, and none is made up');

// End to end: the Go word answers in the envelope itself, no transcript around it.
$ss_node = jrp_node(array('mgn_agent_public_key' => base64_encode(str_repeat("\x52", 32))));
$ss_job = jrp_job($ss_node, 'site_state_report', json_encode(array('api_version' => '1.0', 'data' => array(
	'exists' => true, 'reading' => array('copy_of' => 0, 'reason' => '', 'site' => 'src3'), 'sites' => array(),
	'sites_dir' => '/etc/joinery/sites', 'truncated' => false))));
JobResultProcessor::process($ss_job);
$ss_job->load();
$ss_r = json_decode((string)$ss_job->get('mjb_result'), true);
check(($ss_r['read'] ?? null) === true && ($ss_r['reading']['site'] ?? null) === 'src3' && $ss_r['sites'] === array(),
	'a site_state_report job keeps its report as its result, which the job page renders', var_export($ss_r, true));
check(in_array('site_state_report', JobResultProcessor::processable_types(), true), 'site_state_report is a type this processor knows');

harness_finish();
