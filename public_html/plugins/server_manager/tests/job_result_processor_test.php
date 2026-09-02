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
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));

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
	foreach ($fields as $k => $v) { $node->set($k, $v); }
	$node->save();
	$node->load();
	harness_register_row('mgn_managed_nodes', 'mgn_id', $node->key);
	return $node;
}

function jrp_job($node, $type, $output) {
	$job = new ManagementJob(NULL);
	$job->set('mjb_mgn_node_id', $node ? $node->key : null);
	$job->set('mjb_job_type', $type);
	$job->set('mjb_status', 'completed');
	$job->set('mjb_commands', array());
	$job->set('mjb_output', $output);
	$job->save();
	$job->load();
	harness_register_row('mjb_management_jobs', 'mjb_id', $job->key);
	return $job;
}

// Realistic SSH status output, of the shape check_status actually returns.
$GOOD_SSH = <<<TXT
Filesystem      Size  Used Avail Use% Mounted on
/dev/sda1        40G   12G   26G  32% /
              total        used        free
Mem:           7982        3120        1204
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
check(!isset($partial['load_1m']), 'no load average is invented either');

// Two status blocks in one stream (a retried step) must not produce a blend of
// the two — the later reading is the current one.
$doubled = jrp_call('parse_check_status_ssh_output',
	array("Mem: 1000 100 900\n" . "Mem: 2000 200 1800\n"));
check(($doubled['memory_total_mb'] ?? null) === 1000,
	'a repeated reading resolves to one value rather than a blend',
	var_export($doubled['memory_total_mb'] ?? null, true));

// ---------------------------------------------------------------------------
section('Markers and SSL tokens');

check(jrp_call('extract_marker', array("TENANT_SLUG=main\n", 'TENANT_SLUG')) === 'main',
	'a marker value is extracted');
check(jrp_call('extract_marker', array("noise\nTENANT_SLUG=main\nmore noise\n", 'TENANT_SLUG')) === 'main',
	'a marker is found among other output');
check(jrp_call('extract_marker', array("TENANT_SLUG=first\nTENANT_SLUG=second\n", 'TENANT_SLUG')) === 'second',
	'the last occurrence of a marker wins, so a retried step supersedes the first');
check(jrp_call('extract_marker', array("OTHER=x\n", 'TENANT_SLUG')) === '',
	'an absent marker yields an empty string rather than null');
check(jrp_call('extract_marker', array('', 'TENANT_SLUG')) === '',
	'no output yields no marker');

// A marker name must match at the start of a line: a value mentioning the key
// must not be mistaken for the key itself.
check(jrp_call('extract_marker', array("SOME_TENANT_SLUG=wrong\n", 'TENANT_SLUG')) === '',
	'a marker name embedded in a longer name does not match');

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
harness_register_row('mjb_management_jobs', 'mjb_id', $dj4->key);
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
harness_register_row('mjb_management_jobs', 'mjb_id', $dj5->key);
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

harness_finish();
