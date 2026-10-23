<?php
/** @joinery-test
 * name: site_copy_on_host
 * tier: db
 * env: dev-only
 * needs: []
 */

/**
 * site_copy_on_host (specs/site_container_engine.md D9): a container site
 * copied onto another host, as a container there. Its host installs it from
 * the site's release (site_install) and its agent's join is let in by the key
 * its install printed; the run is a copy from backups; the switch-over freezes
 * the site, from outside by its own host as well, and makes a final copy that
 * must match exactly; the start lifts the copy's quiet on its host, has its
 * host issue the certificate, and holds the old container stopped. Going back
 * before the node is taken unfreezes the site; discarding removes the copy
 * from its host.
 *
 * @version 1.2 - holds the copy lock for the run, so the live tick never refuses one of its actions
 * @version 1.1 - the way back after take-over (specs/site_copy_anything_to_anything.md D7); a copy onto a host of a running
 *               site is alive, its runs from backups (D5)
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/lib/node_space_fixture.php');
harness_boot();

// The task that advances the live copies on this database takes the same lock
// for each tick; held for the run, the tick skips while these copies are driven,
// instead of an action here refusing ('being moved along right now').
DbConnector::get_instance()->get_db_link()->query('SELECT pg_advisory_lock(' . SiteCopyRunner::LOCK_KEY . ')');

$tag = bin2hex(random_bytes(3));
$source_key = base64_encode(random_bytes(32));
$copy_key = base64_encode(random_bytes(32));
$copy_fpr = AgentJoinRequest::fingerprint(base64_decode($copy_key));
$look = '/.joinery-look/' . str_repeat('6b', 16);
$H_SRC = '192.0.2.71';
$H_COPY = '198.51.100.72';
$release = '0.8.496';

// The owner's recovery key, and a chain whose data key is sealed to it.
$recovery = sodium_crypto_box_keypair();
$recovery_pub = sodium_crypto_box_publickey($recovery);
$recovery_fpr = hash('sha256', $recovery_pub);
$chain_id = 'chain-' . gmdate('Ymd_His', time() - 2 * 86400);
$manifest = json_encode(array('version' => 1, 'chain_id' => $chain_id, 'slug' => 'x',
	'envelope' => array('version' => 1, 'recipients' => array(
		array('kind' => 'recovery', 'fingerprint' => $recovery_fpr, 'sealed' => base64_encode(sodium_crypto_box_seal(random_bytes(32), $recovery_pub))),
	)),
	'runs' => array(array('seq' => 0, 'time' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600)))));
SiteCopyRunner::$manifest_reader = function ($source, $chain) use (&$manifest) {
	return array('body' => $manifest, 'url' => 'https://x.invalid/manifest.json');
};
$carries = true;
SiteCopyRunner::$release_carrier = function ($version, $file) use (&$carries) {
	return $carries && $file === SiteCopyRunner::HOST_COPY_RELEASE_FILE;
};

$mk_node = function (string $suffix, array $set) use ($tag) {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'HarnessTest SCH ' . $suffix);
	$n->set('mgn_slug', 'harnesssch-' . $suffix . '-' . $tag);
	$n->set('mgn_uptime_enabled', false);
	foreach ($set as $k => $v) {
		$n->set($k, $v);
	}
	$n->save();
	$n->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $n->key);
	return $n;
};
$mk_host = function (string $suffix, string $ip, ManagedNode $agent) use ($tag) {
	$h = new ManagedHost(NULL);
	foreach (array('mgh_slug' => 'harnesssch-h' . $suffix . '-' . $tag, 'mgh_name' => 'HarnessTest SCH host ' . $suffix,
		'mgh_host' => $ip, 'mgh_mgn_managed_node_id' => (int)$agent->key) as $k => $v) {
		$h->set($k, $v);
	}
	$h->prepare();
	$h->save();
	$h->load();
	harness_register_row('mgh_managed_hosts', 'mgh_managed_host_id', $h->key);
	return $h;
};
$census = function (int $rows) {
	return array('measured' => true, 'census' => array('version' => SiteCensus::VERSION,
		'tables' => array('usr_users' => 3, 'rql_request_logs' => $rows),
		'files' => array('uploads' => array('files' => 4, 'dirs' => 1, 'bytes' => 400, 'digest' => 'd1')),
		'secrets' => array('canary' => SecretBox::OPEN_OK, 'dead' => 0, 'present' => 3),
		'offloaded' => array('total' => 0, 'sampled' => 0, 'answered' => 0)));
};
$envelope = function (string $op, array $data) {
	return "=== [Step 1/1] {$op} ===\n" . json_encode(array('api_version' => '1.0', 'data' => $data));
};
$install_output = function (int $port, string $fpr) {
	return "[OK] Container started, quiet (a copy of node 1)\n[OK] Quiet: a visitor gets 503\nDORMANT_COPY_OF=1\n"
		. "SITE_AGENT_KEY={$fpr}\nCONTAINER_PORT={$port}\n";
};

// The site's own host (Docker, its agent able to freeze it and hold its old
// container), the copy's host (the site container engine), and the site.
$src_host_agent = $mk_node('srchost', array('mgn_host' => $H_SRC, 'mgn_agent_public_key' => base64_encode(random_bytes(32)),
	'mgn_agent_version' => '1.78.0', 'mgn_agent_primitives' => 'host_report,hold_container,site_certificate_stage,site_quiet_host,provision_certificate',
	'mgn_last_host_report' => json_encode(array('site_engine' => 'docker', 'containers' => array(
		array('name' => 'schsite', 'memory' => array('limit_bytes' => 402653184)))))));
$src_host = $mk_host('s', $H_SRC, $src_host_agent);
$copy_host_agent = $mk_node('copyhost', array('mgn_host' => $H_COPY, 'mgn_agent_public_key' => base64_encode(random_bytes(32)),
	'mgn_agent_version' => '1.78.0',
	'mgn_agent_primitives' => 'host_report,hold_container,site_install,site_quiet_host,provision_certificate,discard_copy',
	'mgn_last_host_report' => json_encode(array('site_engine' => 'podman', 'containers' => array()))));
$copy_host = $mk_host('c', $H_COPY, $copy_host_agent);
$src = $mk_node('src', array('mgn_web_root' => '/var/www/html/schsite/public_html', 'mgn_site_url' => 'https://sch.example.org',
	'mgn_host' => $H_SRC, 'mgn_container_name' => 'schsite', 'mgn_mgh_managed_host_id' => (int)$src_host->key,
	'mgn_agent_public_key' => $source_key, 'mgn_agent_version' => '1.78.0',
	'mgn_agent_primitives' => 'copy_vouch,site_census,site_quiet,backup_run,check_status',
	'mgn_joinery_version' => $release, 'mgn_backup_recovery_fpr' => $recovery_fpr, 'mgn_agent_server_manager' => 'inactive',
	'mgn_last_status_data' => json_encode(array('backup_recovery_state' => 'proven'))));
sm_test_node_space($src);
$listing = array('chains' => array(array('chain_id' => $chain_id, 'profile' => BackupProfile::MANAGER, 'bytes' => 70000000,
	'runs' => array(array('seq' => 0, 'time' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
		'artifacts' => array('files' => 69000000, 'db' => 140000))))), 'objects' => array(), 'error' => null);
SiteCopyRunner::$chain_lister = function ($node) use (&$listing) { return $listing; };
$staged_with = array();
SiteCopyRunner::$stage_builder = function ($node, $chain) use (&$staged_with) {
	$staged_with[] = $chain;
	return array('primitive' => 'copy_stage', 'params' => array('chain_id' => $chain['chain_id'],
		'manifest_url' => 'https://x.invalid/m', 'artifact_urls' => array()));
};
SiteCopyRunner::$worker_starter = function ($id) {};
JobCommandBuilder::set_shelf_listing_for_tests(array());

// ---------------------------------------------------------------------------
section('Which host can take the copy');

check(SiteCopyRunner::host_refusals($src, $copy_host) === array(), 'a host on the site container engine, with the words, takes it',
	implode(' | ', SiteCopyRunner::host_refusals($src, $copy_host)));
$why = implode(' | ', SiteCopyRunner::host_refusals($src, $src_host));
check(strpos($why, 'own host') !== false && strpos($why, 'site container engine') !== false,
	'the site\'s own host, on Docker, does not', $why);
$copy_host_agent->set('mgn_agent_primitives', 'host_report,site_quiet_host');
$copy_host_agent->save();
$why = implode(' | ', SiteCopyRunner::host_refusals($src, $copy_host));
check(strpos($why, 'site_install') !== false && strpos($why, 'discard_copy') !== false, 'a host agent without the words is named', $why);
$copy_host_agent->set('mgn_agent_primitives', 'host_report,hold_container,site_install,site_quiet_host,provision_certificate,discard_copy');
$copy_host_agent->save();
$carries = false;
$why = implode(' | ', SiteCopyRunner::host_refusals($src, $copy_host));
check(strpos($why, 'Update the site first') !== false, 'a release without the host\'s half of the quiet state is refused', $why);
$carries = true;
// A release this management node holds no archive of (it does not publish, and
// upgraded to it before it kept the archives it applies) is said so (B55).
$carrier = SiteCopyRunner::$release_carrier;
SiteCopyRunner::$release_carrier = null;
$src_version = (string)$src->get('mgn_joinery_version');
$src->set('mgn_joinery_version', '0.0.1');
$why = implode(' | ', SiteCopyRunner::host_refusals($src, $copy_host));
check(!SiteCopyRunner::holds_release('0.0.1') && strpos($why, 'holds no archive of release 0.0.1') !== false
	&& strpos($why, 'Update the site first') === false,
	'a release this management node holds no archive of is refused as that, not as one to update', $why);
$src->set('mgn_joinery_version', $src_version);
SiteCopyRunner::$release_carrier = $carrier;
$copy_host_agent->set('mgn_last_host_report', json_encode(array('site_engine' => 'podman', 'containers' => array(array('name' => 'schsite')))));
$copy_host_agent->save();
$why = implode(' | ', SiteCopyRunner::host_refusals($src, $copy_host));
check(strpos($why, 'container named schsite') !== false, 'a host already running a container of the site\'s name is refused', $why);
$copy_host_agent->set('mgn_last_host_report', json_encode(array('site_engine' => 'podman', 'containers' => array())));
$copy_host_agent->save();
$src_host_agent->set('mgn_agent_primitives', 'host_report,hold_container');
$src_host_agent->save();
$why = implode(' | ', SiteCopyRunner::source_refusals($src, true, SiteCopy::FROM_SOURCE, true));
check(strpos($why, 'Its host') !== false && strpos($why, 'site_quiet_host') !== false,
	'the site\'s own host must be able to freeze it from outside', $why);
$src_host_agent->set('mgn_agent_primitives', 'host_report,hold_container,site_certificate_stage,site_quiet_host,provision_certificate');
$src_host_agent->save();
$src->set('mgn_last_status_data', json_encode(array('backup_recovery_state' => 'unknown')));
$src->set('mgn_backup_recovery_fpr', null);
$src->save();
$why = implode(' | ', SiteCopyRunner::host_refusals($src, $copy_host));
check(strpos($why, 'backs it up once more') !== false, 'a site whose backups cannot run is refused: the final copy backs it up', $why . ' :: ' . json_encode(RecoveryKeyFleet::node_state($src)));
$src->set('mgn_last_status_data', json_encode(array('backup_recovery_state' => 'proven')));
$src->set('mgn_backup_recovery_fpr', $recovery_fpr);
$src->save();

// ---------------------------------------------------------------------------
section('Start: the copy\'s row on its host, and its install queued there');

$copy = SiteCopyRunner::start_on_host($src, (int)$copy_host->key, 1);
harness_register_row('scp_site_copies', 'scp_site_copy_id', $copy->key);
$cnode = SiteCopyRunner::copy_row($copy, true);
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $cnode->key);
check($copy->on_host() && $copy->source_alive() && $copy->runs_from_backups() && $copy->status() === SiteCopy::STATUS_WAITING,
	'a copy onto a host of a running site, its runs from backups, waiting', $copy->status() . ' ' . $copy->get('scp_halt_reason'));
check($cnode->get('mgn_container_name') === 'schsite' && (int)$cnode->get('mgn_mgh_managed_host_id') === (int)$copy_host->key
	&& $cnode->get('mgn_host') === $H_COPY && $cnode->get('mgn_install_state') === 'copy'
	&& (int)$cnode->get('mgn_copy_of_node_id') === (int)$src->key && (int)$cnode->get('mgn_port') >= 8080,
	'its row is a dormant container on the copy\'s host, with a port of its own', json_encode(array($cnode->get('mgn_port'), $cnode->get('mgn_host'))));
$install = new ManagementJob((int)$copy->get('scp_install_job_id'), TRUE);
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $install->key);
$cmd = json_decode((string)$install->get('mjb_commands'), true);
check((int)$install->get('mjb_mgn_managed_node_id') === (int)$copy_host_agent->key && ($cmd['primitive'] ?? '') === 'site_install',
	'the install is the host\'s own agent\'s job', json_encode($cmd));
check(($cmd['params'] ?? null) == array('name' => 'schsite', 'domain' => 'sch.example.org', 'port' => (int)$cnode->get('mgn_port'),
		'release' => $release, 'copy_of' => (int)$src->key, 'copy_of_key' => $source_key, 'memory' => '384m'),
	'at the site\'s release, as a copy of it, with the site container\'s own memory limit', json_encode($cmd['params'] ?? null));

// A join that comes before the install has finished is left alone.
$mk_join = function (string $ip, string $key, string $fpr) use ($tag) {
	$r = new AgentJoinRequest(NULL);
	foreach (array('ajr_claimed_name' => 'schsite', 'ajr_public_key' => $key, 'ajr_fingerprint' => $fpr,
		'ajr_source_ip' => $ip, 'ajr_status' => 'pending', 'ajr_container' => true) as $k => $v) {
		$r->set($k, $v);
	}
	$r->save();
	$r->load();
	harness_register_row('ajr_agent_join_requests', 'ajr_agent_join_request_id', $r->key);
	return $r;
};
$stranger = $mk_join('203.0.113.9', base64_encode(random_bytes(32)), $copy_fpr);
SiteCopyRunner::advance($copy);
$copy->load();
$cnode->load();
check(trim((string)$cnode->get('mgn_agent_public_key')) === '' && $copy->status() === SiteCopy::STATUS_WAITING,
	'nothing is let in while the install runs');

// ---------------------------------------------------------------------------
section('The install: its port and its agent\'s key, then its join');

$install->set('mjb_status', 'completed');
$install->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
$install->set('mjb_output', $envelope('site_install', array('output' => $install_output(9099, $copy_fpr), 'output_bytes' => 100)));
$install->save();
$wrong_key = $mk_join($H_COPY, base64_encode(random_bytes(32)), 'ffffffffffffffff');
SiteCopyRunner::advance($copy);
$copy->load();
$cnode->load();
check((int)$cnode->get('mgn_port') === 9099, 'the port the install says it took is the copy\'s', (string)$cnode->get('mgn_port'));
check($copy->get('scp_expected_site_key') === $copy_fpr, 'the key its install showed is recorded', (string)$copy->get('scp_expected_site_key'));
check(trim((string)$cnode->get('mgn_agent_public_key')) === '',
	'neither a join with the right key from a stranger\'s address, nor one from the host with another key, is let in');
$threw = '';
try { SiteCopyRunner::approve_join($copy, $stranger); } catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'does not come from the host') !== false, 'and the owner cannot approve one that does not come from the host', $threw);
$real = $mk_join($H_COPY, $copy_key, $copy_fpr);
SiteCopyRunner::advance($copy);
$cnode->load();
check($cnode->get('mgn_agent_public_key') === $copy_key, 'the join from the host, with the key its install showed, is let in on its own');

// It reports its words; the run is the copy from backups.
$cnode->set('mgn_agent_primitives', 'host_report,copy_look,copy_take_key,copy_take_vouch,copy_stage,copy_restore,site_census,take_node_id,site_quiet');
$cnode->set('mgn_agent_version', '1.78.0');
$cnode->set('mgn_last_host_report', json_encode(array('disk' => array('avail_bytes' => 50 * 1073741824),
	'memory' => array('total_bytes' => 402653184))));
$cnode->save();

$finish = function ($job, string $status, ?array $result, string $error = '') {
	$job->set('mjb_status', $status);
	$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
	$job->set('mjb_error_message', $error);
	$job->set('mjb_result', $result === null ? null : json_encode($result));
	$job->save();
};
$jobs_on = array();
$drive = function (SiteCopy $copy, array $statuses, array $by_op) use ($finish, $envelope, &$jobs_on) {
	$ops = array();
	for ($guard = 0; $guard < 40 && in_array($copy->status(), $statuses, true); $guard++) {
		SiteCopyRunner::advance($copy);
		$copy->load();
		$current = null;
		foreach ($copy->steps() as $s) {
			if ($s['verdict'] === 'running' && !empty($s['job_id'])) {
				$current = $s;
				break;
			}
		}
		if (!$current) {
			continue;
		}
		$job = new ManagementJob((int)$current['job_id'], TRUE);
		harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $job->key);
		$key = $current['op'] . (!empty($current['arg']) ? ':' . $current['arg'] : '') . '@' . $current['on'];
		$ops[] = $key;
		$jobs_on[$key] = array((int)$job->get('mjb_mgn_managed_node_id'), json_decode((string)$job->get('mjb_commands'), true));
		$r = $by_op[$key] ?? array('completed', array('done' => true));
		if ($current['op'] === 'take_node_id' && $r[0] === 'completed') {
			$job->set('mjb_status', 'completed');
			$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
			$job->set('mjb_output', $envelope('take_node_id', array('staged' => true, 'node_id' => (int)$copy->get('scp_source_node_id'))));
			$job->save();
			JobResultProcessor::process($job);
			$job->load();
			JobResultProcessor::complete_take_node_id($job);
		} elseif (in_array($current['op'], array('copy_look', 'copy_take_key', 'hold_container'), true)) {
			$job->set('mjb_status', $r[0]);
			$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
			$job->set('mjb_result', null);
			$job->set('mjb_output', $envelope($current['op'], $r[1]));
			$job->save();
		} else {
			$finish($job, $r[0], $r[1], $r[2] ?? '');
		}
		$job->load();
		SiteCopyRunner::job_finished($job);
		$copy->load();
	}
	return $ops;
};

$ops = $drive($copy, array(SiteCopy::STATUS_WAITING, SiteCopy::STATUS_COPYING), array(
	'copy_look@copy'     => array('completed', array('look_path' => $look)),
	'copy_take_key@copy' => array('completed', array('chain_id' => $chain_id, 'manifest_sha256' => hash('sha256', $manifest),
		'recovery_fingerprint' => $recovery_fpr)),
	'site_census@copy'   => array('completed', $census(80)),
));
check($ops === array('host_report@copy', 'copy_look@copy', 'copy_take_key@copy', 'copy_stage@copy', 'copy_restore@copy', 'site_census@copy'),
	'the run is a copy from backups, every step on the copy', json_encode($ops));
check($copy->status() === SiteCopy::STATUS_DORMANT, 'and ends dormant', $copy->status() . ' ' . $copy->get('scp_halt_reason'));
check(count($staged_with) === 1 && ($staged_with[0]['vouched_seq'] ?? null) === 0,
	'it stages the run whose key its owner opened (B20)', json_encode($staged_with));

// ---------------------------------------------------------------------------
section('The switch-over: the site frozen, inside and out, and a final copy that matches');

$methods = SiteCopyRunner::switch_methods($copy);
check(strpos(implode(' ', $methods[SiteCopyRunner::METHOD_IP_SWAP]), 'shared host') !== false,
	'a container on a shared host is never swapped', implode(' ', $methods[SiteCopyRunner::METHOD_IP_SWAP]));
check(($methods[SiteCopyRunner::METHOD_MANUAL] ?? null) === array(), 'the owner may move the address with their own DNS change');
check(SiteCopyRunner::switch_refusals($copy) === array(), 'it can switch over', implode(' | ', SiteCopyRunner::switch_refusals($copy)));

SiteCopyRunner::begin_switch($copy, SiteCopyRunner::METHOD_MANUAL, null, 1);
$copy->load();
$switch = $copy->switch_record();
check(($switch['hosts']['copy']['host_node_id'] ?? 0) === (int)$copy_host_agent->key
	&& ($switch['hosts']['source']['host_node_id'] ?? 0) === (int)$src_host_agent->key
	&& ($switch['hold']['name'] ?? '') === 'schsite',
	'both hosts and the old container are recorded before the rows swap', json_encode($switch['hosts'] ?? null));
$freeze = array(
	'backup_run@source'  => array('completed', array('backup_status' => 'success')),
	'copy_vouch@source'  => array('completed', array('vouched' => true, 'vouch' => '{"body":"v","signature":"s"}', 'chain_id' => $chain_id)),
	'site_census@source' => array('completed', $census(81)),
	'site_census@copy'   => array('completed', $census(80)),
);
$ops = $drive($copy, array(SiteCopy::STATUS_FREEZING), $freeze);
check($ops === array('site_quiet:on@source', 'site_quiet_host:on@source_host', 'backup_run@source', 'copy_vouch@source',
		'copy_take_vouch@copy', 'copy_stage@copy', 'copy_restore@copy', 'site_census@source', 'site_census@copy'),
	'it freezes itself (its maintenance page up), then its host holds it off the network, and the final copy runs', json_encode($ops));
check(count($staged_with) === 2 && !array_key_exists('vouched_seq', $staged_with[1]),
	'the final copy stages the newest run, which the frozen source vouched for just before', json_encode($staged_with[1] ?? null));
check($jobs_on['site_quiet_host:on@source_host'][0] === (int)$src_host_agent->key
	&& ($jobs_on['site_quiet_host:on@source_host'][1]['params'] ?? null) == array('action' => 'on', 'name' => 'schsite', 'reason' => 'switchover'),
	'on the site\'s own host, for a switch-over', json_encode($jobs_on['site_quiet_host:on@source_host']));
check($copy->status() === SiteCopy::STATUS_HALTED && strpos((string)$copy->get('scp_halt_reason'), 'final census') !== false,
	'a row written after the last backup makes the final census differ: nothing moves', $copy->status() . ' ' . $copy->get('scp_halt_reason'));

// The way back from there: the site runs again, its own quiet, then its host's.
SiteCopyRunner::go_back($copy, null);
$copy->load();
$ops = $drive($copy, array(SiteCopy::STATUS_RETURNING), array());
check($ops === array('site_quiet_host:off@source_host', 'site_quiet:off@source'),
	'going back before the node moves: its host lets its network go behind its 503, then it runs again', json_encode($ops));
$src->load();
check($copy->status() === SiteCopy::STATUS_HALTED && trim((string)$src->get('mgn_install_state')) === '',
	'the site runs as before, and its copy needs copying again', $copy->status() . ' / ' . $src->get('mgn_install_state'));

// Again, with nothing written meanwhile.
SiteCopyRunner::copy_again($copy, 1);
$copy->load();
$drive($copy, array(SiteCopy::STATUS_COPYING), array(
	'copy_look@copy'     => array('completed', array('look_path' => $look)),
	'copy_take_key@copy' => array('completed', array('chain_id' => $chain_id, 'manifest_sha256' => hash('sha256', $manifest),
		'recovery_fingerprint' => $recovery_fpr)),
	'site_census@copy'   => array('completed', $census(80)),
));
SiteCopyRunner::begin_switch($copy, SiteCopyRunner::METHOD_MANUAL, null, 1);
$copy->load();
$freeze['site_census@source'] = array('completed', $census(80));
$drive($copy, array(SiteCopy::STATUS_FREEZING), $freeze);
check($copy->status() === SiteCopy::STATUS_READY, 'the final censuses match: ready', $copy->status() . ' ' . $copy->get('scp_halt_reason'));

// ---------------------------------------------------------------------------
section('The start: the copy takes the node, its host lets it go and issues it a certificate');

ProxiedOriginMove::$sleeper = function ($s) {};
ProxiedOriginMove::$asker = function ($url) use ($look) {
	return array('status' => 303, 'set_cookie' => 'joinery_look=' . substr($look, 15) . '; Path=/', 'error' => '');
};
SiteCopyRunner::move_address($copy, null, 1);
$copy->load();
$ops = $drive($copy, array(SiteCopy::STATUS_STARTING), array(
	'hold_container:stop@host' => array('completed', array('output' => json_encode(array('container' => 'schsite', 'action' => 'stop',
		'done' => true, 'held' => true, 'state' => 'exited', 'restart' => 'no')))),
));
check($ops === array('take_node_id@copy', 'site_quiet:off@source', 'site_quiet_host:off@copy_host', 'provision_certificate@source',
		'hold_container:stop@host'),
	'the node id, the copy\'s own quiet, its host\'s, its certificate, and the old container held', json_encode($ops));
check($jobs_on['site_quiet_host:off@copy_host'][0] === (int)$copy_host_agent->key
	&& ($jobs_on['site_quiet_host:off@copy_host'][1]['params'] ?? null) == array('action' => 'off', 'name' => 'schsite'),
	'the copy\'s host lifts its quiet', json_encode($jobs_on['site_quiet_host:off@copy_host']));
check($jobs_on['provision_certificate@source'][0] === (int)$copy_host_agent->key,
	'its certificate is issued on its host, not inside it', (string)$jobs_on['provision_certificate@source'][0]);
check($jobs_on['hold_container:stop@host'][0] === (int)$src_host_agent->key, 'the old container is held on the site\'s old host');
$src->load();
check($copy->status() === SiteCopy::STATUS_SWITCHED && $src->get('mgn_agent_public_key') === $copy_key
	&& (int)$src->get('mgn_mgh_managed_host_id') === (int)$copy_host->key && (int)$src->get('mgn_port') === 9099,
	'the site\'s row is the copy\'s container now: its host and its port moved with it',
	$copy->status() . ' ' . $copy->get('scp_halt_reason') . ' ' . $src->get('mgn_mgh_managed_host_id') . ' ' . $src->get('mgn_port'));
// The way back stays open after it has taken the node (site_copy_anything_to_anything D7).
SiteCopyRunner::go_back($copy, null);
$copy->load();
$back = array_map(function ($st) { return $st['op'] . (($st['arg'] ?? '') !== '' ? ':' . $st['arg'] : '') . '@' . $st['on']; }, $copy->steps());
check($copy->status() === SiteCopy::STATUS_RETURNING && $back === array('site_quiet:on@source', 'site_quiet_host:on@copy_host',
	'go_back@source', 'hold_container:start@host', 'site_quiet_host:off@source_host', 'site_quiet:off@source', 'hold_container:stop@copy_host'),
	'once it has taken the node, the way back is still open: the copy quieted inside and out, the rows back, the old container '
	. 'started, the site let go of its freeze, and the copy\'s container held stopped on its host', json_encode($back));

// ---------------------------------------------------------------------------
section('Discard: the copy removed from its host');

// A second site on the old host, copied onto the copy's host and discarded.
$src2 = $mk_node('src2', array('mgn_web_root' => '/var/www/html/sch2site/public_html', 'mgn_site_url' => 'https://sch2.example.org',
	'mgn_host' => $H_SRC, 'mgn_container_name' => 'sch2site', 'mgn_mgh_managed_host_id' => (int)$src_host->key,
	'mgn_agent_public_key' => base64_encode(random_bytes(32)), 'mgn_agent_version' => '1.78.0',
	'mgn_agent_primitives' => 'copy_vouch,site_census,site_quiet,backup_run,check_status',
	'mgn_joinery_version' => $release, 'mgn_backup_recovery_fpr' => $recovery_fpr, 'mgn_agent_server_manager' => 'inactive',
	'mgn_last_status_data' => json_encode(array('backup_recovery_state' => 'proven'))));
sm_test_node_space($src2);
$copy2 = SiteCopyRunner::start_on_host($src2, (int)$copy_host->key, 1);
harness_register_row('scp_site_copies', 'scp_site_copy_id', $copy2->key);
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', (int)$copy2->get('scp_copy_node_id'));
$i2 = new ManagementJob((int)$copy2->get('scp_install_job_id'), TRUE);
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $i2->key);
$threw = '';
try { SiteCopyRunner::discard($copy2); } catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'being installed') !== false, 'a copy is not discarded while its host installs it', $threw);
$i2->set('mjb_status', 'failed');
$i2->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
$i2->set('mjb_error_message', 'install.sh exited 1');
$i2->save();
SiteCopyRunner::advance($copy2);
$copy2->load();
check($copy2->status() === SiteCopy::STATUS_HALTED && strpos((string)$copy2->get('scp_halt_reason'), 'install on its host failed') !== false,
	'an install that failed stops the copy and says so', (string)$copy2->get('scp_halt_reason'));
SiteCopyRunner::discard($copy2);
$copy2->load();
$removal = new ManagementJob((int)($copy2->switch_record()['discard_job_id'] ?? 0), TRUE);
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $removal->key);
$rcmd = json_decode((string)$removal->get('mjb_commands'), true);
check($copy2->status() === SiteCopy::STATUS_DISCARDED && (int)$removal->get('mjb_mgn_managed_node_id') === (int)$copy_host_agent->key
	&& ($rcmd['primitive'] ?? '') === 'discard_copy' && ($rcmd['params'] ?? null) == array('name' => 'sch2site'),
	'discarding it asks its host to remove the dormant copy', json_encode($rcmd));

DbConnector::get_instance()->get_db_link()->query('SELECT pg_advisory_unlock(' . SiteCopyRunner::LOCK_KEY . ')');
harness_finish();
