<?php
/** @joinery-test
 * name: site_copy_from_backups
 * tier: db
 * env: dev-only
 * needs: []
 */

/**
 * site_copy_from_backups (specs/site_copy.md WP10): a copy made from a dead
 * site's backups alone. Nothing is asked of the source; the chain's key comes
 * from the owner's recovery key on the copy's own page (the agent's
 * copy_take_key, tested in the agent); the census is judged alone; and the
 * switch-over powers the old server off, moves the address and starts the
 * copy, with no way back once it has taken the node.
 *
 * Also the copy's page handoff (CopyKeyHandoff): the shapes it accepts, and
 * that it does nothing with no request staged.
 *
 * @version 1.2 - a container source's old container is stopped and held on its host, last; the site's Copy tab names
 *                 it until it and its certificate are gone (cleanup_left)
 * @version 1.1 - a container site and a management node from backups; the backups release floor
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$tag = bin2hex(random_bytes(3));
$source_key = base64_encode(random_bytes(32));
$copy_key = base64_encode(random_bytes(32));
$look = '/.joinery-look/' . str_repeat('5a', 16);
$S4 = '192.0.2.91';
$T4 = '198.51.100.92';

// The owner's recovery key, and a chain whose data key is sealed to it.
$recovery = sodium_crypto_box_keypair();
$recovery_pub = sodium_crypto_box_publickey($recovery);
$recovery_fpr = hash('sha256', $recovery_pub);
$data_key = base64_encode(random_bytes(32));
$chain_id = 'chain-' . gmdate('Ymd_His', time() - 9 * 86400);
$manifest = json_encode(array('version' => 1, 'chain_id' => $chain_id, 'slug' => 'x',
	'envelope' => array('version' => 1, 'recipients' => array(
		array('kind' => 'recovery', 'fingerprint' => $recovery_fpr, 'sealed' => base64_encode(sodium_crypto_box_seal($data_key, $recovery_pub))),
		array('kind' => 'site', 'fingerprint' => str_repeat('0', 64), 'sealed' => base64_encode('not this one')),
	)),
	'runs' => array(array('seq' => 0, 'time' => gmdate('Y-m-d\TH:i:s\Z', time() - 8 * 86400)))));
SiteCopyRunner::$manifest_reader = function ($source, $chain) use (&$manifest) { return $manifest; };

$mk_node = function (string $suffix, array $set) use ($tag) {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'HarnessTest SCB ' . $suffix);
	$n->set('mgn_slug', 'harnessscb-' . $suffix . '-' . $tag);
	$n->set('mgn_uptime_enabled', false);
	foreach ($set as $k => $v) {
		$n->set($k, $v);
	}
	$n->save();
	$n->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $n->key);
	return $n;
};
$finish = function ($job, string $status, ?array $result, string $error = '') {
	$job->set('mjb_status', $status);
	$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
	$job->set('mjb_error_message', $error);
	$job->set('mjb_result', $result === null ? null : json_encode($result));
	$job->save();
};
$census = function (int $dead) {
	return array('measured' => true, 'census' => array('version' => SiteCensus::VERSION,
		'tables' => array('usr_users' => 3), 'files' => array(),
		'secrets' => array('canary' => SecretBox::OPEN_OK, 'dead' => $dead, 'present' => 3),
		'offloaded' => array('total' => 0, 'sampled' => 0, 'answered' => 0)));
};

// The source: its server is dead. Its agent reports none of the copy words
// and has not been heard from; only its row, its backups and its recovery
// key's fingerprint are left.
$src = $mk_node('src', array('mgn_web_root' => '/var/www/html/scbsite/public_html', 'mgn_site_url' => 'https://scb.example.org',
	'mgn_host' => $S4, 'mgn_agent_public_key' => $source_key, 'mgn_agent_version' => '1.40.0', 'mgn_agent_primitives' => 'check_status',
	'mgn_joinery_version' => JobCommandBuilder::COPY_FROM_BACKUPS_MIN_VERSION, 'mgn_backup_recovery_fpr' => $recovery_fpr,
	'mgn_agent_server_manager' => 'inactive'));
if (!JobCommandBuilder::get_target($src)) {
	harness_skip('a copy from backups', 'no enabled backup target on this management node');
	harness_finish();
	return;
}
$listing = array('chains' => array(array('chain_id' => $chain_id, 'profile' => BackupProfile::MANAGER, 'bytes' => 70000000,
	'runs' => array(array('seq' => 0, 'time' => gmdate('Y-m-d\TH:i:s\Z', time() - 8 * 86400),
		'artifacts' => array('files' => 69000000, 'db' => 140000))))), 'objects' => array(), 'error' => null);
SiteCopyRunner::$chain_lister = function ($node) use (&$listing) { return $listing; };
SiteCopyRunner::$stage_builder = function ($node, $chain) {
	return array('primitive' => 'copy_stage', 'params' => array('chain_id' => $chain['chain_id'],
		'manifest_url' => 'https://x.invalid/m', 'artifact_urls' => array()));
};
SiteCopyRunner::$worker_starter = function ($id) {};
JobCommandBuilder::set_shelf_listing_for_tests(array());

// ---------------------------------------------------------------------------
section('Preflight: the backups, not the source\'s agent');

$live_why = SiteCopyRunner::source_refusals($src, true, SiteCopy::FROM_SOURCE);
check((bool)$live_why, 'from the running site, the dead source is refused (its agent lacks the copy words)', implode(' ', $live_why));
check(SiteCopyRunner::source_refusals($src, true, SiteCopy::FROM_BACKUPS) === array(),
	'from its backups, it is not: nothing is asked of its agent');
foreach (array(
	'a container site'  => array('mgn_container_name', 'scbsite'),
	'a management node' => array('mgn_agent_server_manager', 'active'),
) as $what => $c) {
	$n = new ManagedNode($src->key, TRUE);
	$n->set($c[0], $c[1]);
	check(SiteCopyRunner::source_refusals($n, true, SiteCopy::FROM_BACKUPS) === array(), $what . ' is copied from its backups',
		implode(' | ', SiteCopyRunner::source_refusals($n, true, SiteCopy::FROM_BACKUPS)));
	$live = implode(' | ', SiteCopyRunner::source_refusals($n, true, SiteCopy::FROM_SOURCE));
	check(strpos($live, $c[0] === 'mgn_container_name' ? 'container site' : 'management node') !== false,
		'but not from the running site: ' . $what, $live);
}
$n = new ManagedNode($src->key, TRUE);
$n->set('mgn_site_url', rtrim((string)LibraryFunctions::get_absolute_url(), '/'));
$why = implode(' | ', SiteCopyRunner::source_refusals($n, true, SiteCopy::FROM_BACKUPS));
check(strpos($why, 'this management node') !== false, 'this management node itself is never copied, from backups either', $why);
$n = new ManagedNode($src->key, TRUE);
$n->set('mgn_joinery_version', JobCommandBuilder::COPY_SOURCE_MIN_VERSION);
$why = implode(' | ', SiteCopyRunner::source_refusals($n, true, SiteCopy::FROM_BACKUPS));
check(strpos($why, 'needs ' . JobCommandBuilder::COPY_FROM_BACKUPS_MIN_VERSION) !== false,
	'a release without the copy\'s key page is refused for a copy from backups', $why);
$n = new ManagedNode($src->key, TRUE);
$n->set('mgn_container_name', 'scbsite');
check(IpSwapMove::provision_of($n) === null && strpos(implode(' ', IpSwapMove::record_refusals($n, $src)), 'container') !== false,
	'a container site\'s shared server is never powered off or swapped');
$threw = '';
try { SiteCopyRunner::newest_chain($src); } catch (Exception $e) { $threw = $e->getMessage(); }
check($threw !== '', 'a live copy needs a backup under a day old; this one is eight days old');
$chain = SiteCopyRunner::newest_chain($src, SiteCopyRunner::chain_age_limit(SiteCopy::FROM_BACKUPS));
check($chain['chain_id'] === $chain_id, 'a copy from backups takes the newest chain at any age');

$copy = SiteCopyRunner::start_own_server($src, 1, SiteCopy::FROM_BACKUPS);
harness_register_row('scp_site_copies', 'scp_site_copy_id', $copy->key);
$copy->load();
check($copy->from_backups() && $copy->status() === SiteCopy::STATUS_WAITING, 'the copy is recorded as from backups, waiting for its server');

// The copy's server joins.
$cnode = $mk_node('copy', array('mgn_web_root' => '/var/www/html/scbsite/public_html', 'mgn_site_url' => 'https://scb.example.org',
	'mgn_host' => $T4, 'mgn_agent_public_key' => $copy_key, 'mgn_agent_version' => '1.54.0',
	'mgn_agent_primitives' => 'host_report,copy_look,copy_take_key,copy_stage,copy_restore,site_census,take_node_id,site_quiet,provision_certificate',
	'mgn_install_state' => 'copy', 'mgn_copy_of_node_id' => (int)$src->key,
	'mgn_last_host_report' => json_encode(array('disk' => array('avail_bytes' => 50 * 1024 * 1024 * 1024),
		'memory' => array('total_bytes' => 2 * 1024 * 1024 * 1024)))));
$copy->set('scp_copy_node_id', (int)$cnode->key);
$copy->save();

// ---------------------------------------------------------------------------
section('The run: no step on the source, and the key from the owner');

$take_key_params = null;
$drive = function (SiteCopy $copy, array $statuses, array $by_op) use ($finish, $src, &$take_key_params) {
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
		if ($current['op'] === 'copy_take_key') {
			$p = $job->get('mjb_parameters');
			$p = is_string($p) ? json_decode($p, true) : $p;
			$take_key_params = $p;
		}
		$r = $by_op[$key] ?? array('completed', array('done' => true));
		if ($current['op'] === 'take_node_id' && $r[0] === 'completed') {
			$job->set('mjb_status', 'completed');
			$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
			$job->set('mjb_output', "=== [Step 1/1] take_node_id ===\n" . json_encode(array('api_version' => '1.0',
				'data' => array('staged' => true, 'node_id' => (int)$copy->get('scp_source_node_id')))));
			$job->save();
			JobResultProcessor::process($job);
			$job->load();
			JobResultProcessor::complete_take_node_id($job);
		} elseif (in_array($current['op'], array('copy_look', 'copy_take_key', 'hold_container'), true)) {
			// As the node posts it: the word's data in the output, for this
			// management node's own processor to read.
			$job->set('mjb_status', $r[0]);
			$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
			$job->set('mjb_result', null);
			$job->set('mjb_output', "=== [Step 1/1] {$current['op']} ===\n" . json_encode(array('api_version' => '1.0', 'data' => $r[1])));
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

$run_results = array(
	'copy_look@copy'     => array('completed', array('look_path' => $look)),
	'copy_take_key@copy' => array('completed', array('chain_id' => $chain_id, 'manifest_sha256' => hash('sha256', $manifest),
		'recovery_fingerprint' => $recovery_fpr)),
	'site_census@copy'   => array('completed', $census(1)),
);
$ops = $drive($copy, array(SiteCopy::STATUS_WAITING, SiteCopy::STATUS_COPYING), $run_results);
check($ops === array('host_report@copy', 'copy_look@copy', 'copy_take_key@copy', 'copy_stage@copy', 'copy_restore@copy', 'site_census@copy'),
	'six steps, every one on the copy', json_encode($ops));
$params = is_array($take_key_params) ? ($take_key_params['params'] ?? $take_key_params) : array();
check(($params['chain_id'] ?? '') === $chain_id && ($params['manifest_sha256'] ?? '') === hash('sha256', $manifest)
	&& ($params['recovery_fingerprint'] ?? '') === $recovery_fpr,
	'the copy is asked for the chain, its manifest\'s hash and the recovery key\'s fingerprint', json_encode($params));
$sealed = base64_decode((string)($params['recovery_sealed'] ?? ''));
check($sealed !== '' && sodium_crypto_box_seal_open($sealed, $recovery) === $data_key,
	'and is handed the data key as sealed to the recovery key: ciphertext only this management node cannot open');
check(strpos(json_encode($params), $data_key) === false, 'the data key itself is nowhere in the job');
check($copy->get('scp_look_path') === $look, 'the copy\'s look path is recorded, for the owner to reach its own page');
check($copy->status() === SiteCopy::STATUS_HALTED && strpos((string)$copy->get('scp_halt_reason'), 'census') !== false,
	'a dead sealed secret on the copy stops it: its census is judged alone', $copy->status() . ' ' . $copy->get('scp_halt_reason'));

SiteCopyRunner::copy_again($copy, 1);
$copy->load();
$run_results['site_census@copy'] = array('completed', $census(0));
$drive($copy, array(SiteCopy::STATUS_COPYING), $run_results);
check($copy->status() === SiteCopy::STATUS_DORMANT && !empty($copy->census()['match']),
	'a clean census: the copy is dormant and current as of the backup', $copy->status() . ' ' . $copy->get('scp_halt_reason'));

$manifest_was = $manifest;
$manifest = str_replace($recovery_fpr, str_repeat('c', 64), $manifest);
$threw = '';
try { SiteCopyRunner::key_request($src, $chain_id); } catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'recovery key') !== false, 'a chain sealed to another recovery key than the site last reported is named, not asked for', $threw);
$manifest = $manifest_was;

// ---------------------------------------------------------------------------
section('The switch-over: the old server off, the address moved by the owner');

$methods = SiteCopyRunner::switch_methods($copy);
check(array_key_exists(SiteCopyRunner::METHOD_MANUAL, $methods) && $methods[SiteCopyRunner::METHOD_MANUAL] === array(),
	'from backups, the owner may move the address with their own DNS change');
check(SiteCopyRunner::switch_refusals($copy) === array(), 'the dead source asks no switch-over words',
	implode(' ', SiteCopyRunner::switch_refusals($copy)));

// The old server was made here, so the switch-over powers it off.
$sprov = new CustomerCloudProvision(NULL);
foreach (array('cvp_origin' => 'admin', 'cvp_usr_user_id' => 1, 'cvp_domain' => 'scb.example.org',
	'cvp_slug' => 'harnessscb-s-' . $tag, 'cvp_status' => 'done', 'cvp_docker_mode' => 'bare-metal', 'cvp_hosting_mode' => 'operator',
	'cvp_provider' => 'linode', 'cvp_region' => 'us-east', 'cvp_instance_id' => '31', 'cvp_instance_ip' => $S4,
	'cvp_mgn_managed_node_id' => (int)$src->key) as $k => $v) {
	$sprov->set($k, $v);
}
$sprov->save();
harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $sprov->key);
$powered = array('status' => 'running', 'calls' => array());
IpSwapMove::$driver_for = function ($p) use (&$powered) {
	return new class($powered) implements CloudComputeProvider, CloudAddressSwap {
		private $st;
		public function __construct(&$st) { $this->st = &$st; }
		public function getInstance(string $id): array {
			$out = array('id' => $id, 'status' => $this->st['status'], 'ip' => '', 'label' => '', 'region' => 'us-east');
			if ($this->st['status'] === 'shutting_down') { $this->st['status'] = 'offline'; }
			return $out;
		}
		public function shutdownInstance(string $id): void { $this->st['calls'][] = "shutdown {$id}"; $this->st['status'] = 'shutting_down'; }
		public function bootInstance(string $id): void { $this->st['calls'][] = "boot {$id}"; }
		public function rebootInstance(string $id): void { $this->st['calls'][] = "reboot {$id}"; }
		public function addressReport(string $id): array { return array(); }
		public function ipAddress(string $a): array { return array(); }
		public function assignIpv4(string $r, array $a): void { $this->st['calls'][] = 'assign'; }
		public function instanceFirewalls(string $id): array { return array(); }
		public function setReverseDns(string $id, string $ip, string $h): array { return array(); }
		public function createInstance(array $o): array { return array(); }
		public function rebuildInstance(string $id, array $o): array { return array(); }
		public function deleteInstance(string $id): void { $this->st['calls'][] = 'delete'; }
		public function getTransfer(): array { return array(); }
	};
};

SiteCopyRunner::begin_switch($copy, SiteCopyRunner::METHOD_MANUAL, null, 1);
$copy->load();
$drive($copy, array(SiteCopy::STATUS_FREEZING), array());
$src->load();
check($copy->status() === SiteCopy::STATUS_READY && $powered['status'] === 'offline' && $powered['calls'] === array('shutdown 31'),
	'no freeze and no final copy: the old server is powered off, and the copy is ready', $copy->status() . ' ' . json_encode($powered));
check(trim((string)$src->get('mgn_install_state')) === 'switching', 'the source row is switching');

ProxiedOriginMove::$sleeper = function ($s) {};
ProxiedOriginMove::$asker = function ($url) use ($look) {
	return array('status' => 303, 'set_cookie' => 'joinery_look=' . substr($look, 15) . '; Path=/', 'error' => '');
};
SiteCopyRunner::move_address($copy, null, 1);
$copy->load();
$start_ops = $drive($copy, array(SiteCopy::STATUS_STARTING), array());
check($start_ops === array('take_node_id@copy', 'site_quiet:off@source', 'provision_certificate@source'),
	'the copy takes the node, starts, and then gets its certificate: none travelled from the dead server', json_encode($start_ops));
$src->load();
check($copy->status() === SiteCopy::STATUS_SWITCHED && $src->get('mgn_agent_public_key') === $copy_key,
	'the owner\'s DNS change is proven by the copy\'s look path at the site\'s name, and the copy becomes the node',
	$copy->status() . ' ' . $copy->get('scp_halt_reason'));
$threw = '';
try { SiteCopyRunner::go_back($copy, null); } catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'nothing to go back to') !== false, 'there is no way back once the copy has taken the node: the old site is dead', $threw);
SiteCopyRunner::finish($copy);
$copy->load();
check($copy->status() === SiteCopy::STATUS_FINISHED, 'keeping the switch-over finishes it');
check(!in_array('delete', $powered['calls'], true), 'no server was deleted');

// ---------------------------------------------------------------------------
section('A container\'s old container: stopped on its host once the copy has taken over');

// A container site on a shared server, whose host has an agent of its own.
$hnode = $mk_node('host', array('mgn_host' => '192.0.2.95', 'mgn_agent_public_key' => base64_encode(random_bytes(32)),
	'mgn_agent_version' => '1.59.0', 'mgn_agent_primitives' => 'host_report,restart_container'));
$mh = new ManagedHost(NULL);
foreach (array('mgh_slug' => 'harnessscb-h-' . $tag, 'mgh_name' => 'HarnessTest SCB host', 'mgh_host' => '192.0.2.95',
	'mgh_mgn_managed_node_id' => (int)$hnode->key) as $k => $v) {
	$mh->set($k, $v);
}
$mh->save();
harness_register_row('mgh_managed_hosts', 'mgh_managed_host_id', $mh->key);
$csrc = $mk_node('csrc', array('mgn_web_root' => '/var/www/html/scbtwo/public_html', 'mgn_site_url' => 'https://scb2.example.org',
	'mgn_host' => '192.0.2.95', 'mgn_container_name' => 'scbtwo', 'mgn_mgh_managed_host_id' => (int)$mh->key,
	'mgn_agent_public_key' => base64_encode(random_bytes(32)), 'mgn_agent_version' => '1.40.0', 'mgn_agent_primitives' => 'check_status',
	'mgn_joinery_version' => JobCommandBuilder::COPY_FROM_BACKUPS_MIN_VERSION, 'mgn_backup_recovery_fpr' => $recovery_fpr,
	'mgn_agent_server_manager' => 'inactive'));
$ccopy = SiteCopyRunner::start_own_server($csrc, 1, SiteCopy::FROM_BACKUPS);
harness_register_row('scp_site_copies', 'scp_site_copy_id', $ccopy->key);
$ccopy_key = base64_encode(random_bytes(32));
$ccnode = $mk_node('ccopy', array('mgn_web_root' => '/var/www/html/scbtwo/public_html', 'mgn_site_url' => 'https://scb2.example.org',
	'mgn_host' => '198.51.100.96', 'mgn_agent_public_key' => $ccopy_key, 'mgn_agent_version' => '1.54.0',
	'mgn_agent_primitives' => 'host_report,copy_look,copy_take_key,copy_stage,copy_restore,site_census,take_node_id,site_quiet,provision_certificate',
	'mgn_install_state' => 'copy', 'mgn_copy_of_node_id' => (int)$csrc->key,
	'mgn_last_host_report' => json_encode(array('disk' => array('avail_bytes' => 50 * 1024 * 1024 * 1024),
		'memory' => array('total_bytes' => 2 * 1024 * 1024 * 1024)))));
$ccopy->set('scp_copy_node_id', (int)$ccnode->key);
$ccopy->save();
$ccopy->load();
$drive($ccopy, array(SiteCopy::STATUS_WAITING, SiteCopy::STATUS_COPYING), $run_results);
check($ccopy->status() === SiteCopy::STATUS_DORMANT, 'the container site\'s copy is dormant', $ccopy->status() . ' ' . $ccopy->get('scp_halt_reason'));

$why = implode(' | ', SiteCopyRunner::switch_refusals($ccopy));
check(strpos($why, 'old container\'s host') !== false && strpos($why, 'hold_container') !== false,
	'a host whose agent cannot hold the old container stopped refuses the switch-over, and says which word', $why);
$threw = '';
try { JobCommandBuilder::build_hold_container($hnode, 'scbtwo', 'stop'); } catch (Exception $e) { $threw = $e->getMessage(); }
check($threw !== '', 'and no hold job is built for it');
$hnode->set('mgn_agent_primitives', 'host_report,restart_container,hold_container');
$hnode->save();
check(SiteCopyRunner::switch_refusals($ccopy) === array(), 'with the word, the switch-over may start',
	implode(' | ', SiteCopyRunner::switch_refusals($ccopy)));
foreach (array(array('scbtwo', 'rm'), array('Scbtwo', 'stop'), array('a;b', 'start')) as $bad) {
	$threw = '';
	try { JobCommandBuilder::build_hold_container($hnode, $bad[0], $bad[1]); } catch (Exception $e) { $threw = $e->getMessage(); }
	check($threw !== '', 'a hold is built only for stop or start of a site name, not ' . implode(' ', $bad));
}

SiteCopyRunner::begin_switch($ccopy, SiteCopyRunner::METHOD_MANUAL, null, 1);
$ccopy->load();
$hold = $ccopy->switch_record()['hold'] ?? null;
check($ccopy->status() === SiteCopy::STATUS_READY && is_array($hold) && count($hold) === 2
	&& (int)($hold['host_node_id'] ?? 0) === (int)$hnode->key && ($hold['name'] ?? '') === 'scbtwo',
	'the switch-over records the old container and its host, and nothing is stopped yet: the site still runs there',
	$ccopy->status() . ' ' . json_encode($hold));

SiteCopyRunner::move_address($ccopy, null, 1);
$ccopy->load();
$not_stopped = array('completed', array('output' => json_encode(array('container' => 'scbtwo', 'action' => 'stop',
	'done' => false, 'held' => true, 'state' => 'running', 'restart' => 'no'))));
$ops = $drive($ccopy, array(SiteCopy::STATUS_STARTING), array('hold_container:stop@host' => $not_stopped));
check($ops === array('take_node_id@copy', 'site_quiet:off@source', 'provision_certificate@source', 'hold_container:stop@host'),
	'the old container is stopped last: the copy takes the node, starts and gets its certificate first', json_encode($ops));
check($ccopy->status() === SiteCopy::STATUS_HALTED && strpos((string)$ccopy->get('scp_halt_reason'), 'not stopped (it is running)') !== false,
	'an old container its host did not stop halts the start, and says so', $ccopy->status() . ' ' . $ccopy->get('scp_halt_reason'));
$hold_job = null;
foreach ($ccopy->steps() as $st) {
	if ($st['op'] === 'hold_container' && !empty($st['job_id'])) {
		$hold_job = new ManagementJob((int)$st['job_id'], TRUE);
	}
}
$hp = $hold_job ? $hold_job->get('mjb_parameters') : null;
$hp = is_string($hp) ? json_decode($hp, true) : $hp;
check($hold_job && (int)$hold_job->get('mjb_mgn_managed_node_id') === (int)$hnode->key
	&& ($hp['params'] ?? $hp)['action'] === 'stop' && ($hp['params'] ?? $hp)['name'] === 'scbtwo',
	'the hold is the host\'s job, naming the old container: the container\'s own agent is inside it', json_encode($hp));

SiteCopyRunner::retry_start($ccopy);
$ccopy->load();
$stopped = array('completed', array('output' => json_encode(array('container' => 'scbtwo', 'action' => 'stop',
	'done' => true, 'held' => true, 'state' => 'exited', 'restart' => 'no'))));
$ops = $drive($ccopy, array(SiteCopy::STATUS_STARTING), array('hold_container:stop@host' => $stopped));
check($ccopy->status() === SiteCopy::STATUS_SWITCHED && end($ops) === 'hold_container:stop@host',
	'tried again, the old container is stopped and held, and the switch-over is done', $ccopy->status() . ' ' . json_encode($ops));
SiteCopyRunner::finish($ccopy);

// The site's Copy tab names the old container until it is removed and its
// host holds no certificate of it (B5), however long ago the switch-over was.
$left = SiteCopyRunner::cleanup_left((int)$csrc->key);
$old_row = SiteCopyRunner::copy_row($ccopy, false);
check(count($left) === 1 && (int)$left[0]['node']->key === (int)$old_row->key && $left[0]['removed'] === false,
	'a kept switch-over names its old container, not yet removed', json_encode(array_map(function ($l) {
		return array((int)$l['node']->key, $l['removed'], $l['certificates']); }, $left)));
$old_row->set('mgn_moved_check_state', 'absent');
$old_row->save();
$hnode->set('mgn_last_status_data', json_encode(array('ssl_certificates' => array(array('name' => 'scb2.example.org'),
	array('name' => 'scb2.example.org-0001'), array('name' => 'other.example.org')))));
$hnode->save();
$left = SiteCopyRunner::cleanup_left((int)$csrc->key);
check(count($left) === 1 && $left[0]['removed'] === true && $left[0]['certificates'] === array('scb2.example.org', 'scb2.example.org-0001'),
	'removed, it is named for the certificates its host still holds for its domain, and only those',
	json_encode($left ? $left[0]['certificates'] : null));
$ccopy->set('scp_update_time', gmdate('Y-m-d H:i:s', time() - 40 * 86400));
$ccopy->save();
check(count(SiteCopyRunner::cleanup_left((int)$csrc->key)) === 1, 'and still after forty days: unfinished cleanup does not age out');
$hnode->set('mgn_last_status_data', json_encode(array('ssl_certificates' => array(array('name' => 'other.example.org')))));
$hnode->save();
check(SiteCopyRunner::cleanup_left((int)$csrc->key) === array(), 'removed, with no certificate left, nothing is named');

// ---------------------------------------------------------------------------
section('The copy\'s page handoff');

$keep = array();
foreach (array(CopyKeyHandoff::REQUEST_SETTING, CopyKeyHandoff::ANSWER_SETTING) as $name) {
	$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
	$q->execute(array($name));
	$keep[$name] = (string)$q->fetchColumn();
}
Setting::put(CopyKeyHandoff::REQUEST_SETTING, '');
Setting::put(CopyKeyHandoff::ANSWER_SETTING, '');
check(CopyKeyHandoff::pending() === null, 'with nothing staged the page has nothing to show');
$threw = '';
try { CopyKeyHandoff::answer(5, base64_encode(random_bytes(32)), base64_encode(random_bytes(32))); } catch (Exception $e) { $threw = $e->getMessage(); }
check($threw !== '', 'and takes no answer');

Setting::put(CopyKeyHandoff::REQUEST_SETTING, json_encode(array('job_id' => 5, 'chain_id' => $chain_id, 'site' => 'scb.example.org',
	'manifest_sha256' => str_repeat('ab', 32), 'recovery_fingerprint' => $recovery_fpr,
	'ephemeral_public' => base64_encode(random_bytes(32)), 'issued_time' => gmdate('Y-m-d H:i:s'),
	'expires_time' => gmdate('Y-m-d H:i:s', time() + 3600), 'last_error' => 'that key does not open it')));
$p = CopyKeyHandoff::pending();
check($p && $p['job_id'] === 5 && $p['last_error'] === 'that key does not open it' && !$p['answered'],
	'a staged request shows, with the last answer\'s reason');
$threw = '';
try { CopyKeyHandoff::answer(5, 'not base64!', base64_encode(random_bytes(32))); } catch (Exception $e) { $threw = $e->getMessage(); }
check($threw !== '', 'an answer that is not 32 bytes is refused');
$threw = '';
try { CopyKeyHandoff::answer(6, base64_encode(random_bytes(32)), base64_encode(random_bytes(32))); } catch (Exception $e) { $threw = $e->getMessage(); }
check($threw !== '', 'an answer for another job is refused');
CopyKeyHandoff::answer(5, base64_encode(random_bytes(32)), base64_encode($recovery_pub));
$p = CopyKeyHandoff::pending();
$ans = json_decode((string)DbConnector::get_instance()->get_db_link()->query("SELECT stg_value FROM stg_settings WHERE stg_name = 'copy_key_answer'")->fetchColumn(), true);
check($p['answered'] && (int)$ans['job_id'] === 5 && $ans['public_key'] === base64_encode($recovery_pub) && !isset($ans['declined']),
	'an answer is handed to the agent, and the page says it is with it');
$threw = '';
try { CopyKeyHandoff::decline(5); } catch (Exception $e) { $threw = $e->getMessage(); }
check($threw !== '', 'a second answer is refused while the first is with the agent');
Setting::put(CopyKeyHandoff::ANSWER_SETTING, '');
CopyKeyHandoff::decline(5);
$ans = json_decode((string)DbConnector::get_instance()->get_db_link()->query("SELECT stg_value FROM stg_settings WHERE stg_name = 'copy_key_answer'")->fetchColumn(), true);
check(!empty($ans['declined']), 'a decline is handed to the agent');
foreach ($keep as $name => $value) {
	Setting::put($name, $value);
}

SiteCopyRunner::$chain_lister = null;
SiteCopyRunner::$stage_builder = null;
SiteCopyRunner::$manifest_reader = null;
SiteCopyRunner::$worker_starter = null;
IpSwapMove::$driver_for = null;
ProxiedOriginMove::$asker = null;
ProxiedOriginMove::$sleeper = null;
JobCommandBuilder::set_shelf_listing_for_tests(null);
harness_finish();
