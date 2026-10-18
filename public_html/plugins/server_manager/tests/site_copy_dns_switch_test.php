<?php
/** @joinery-test
 * name: site_copy_dns_switch
 * tier: db
 * env: dev-only
 * needs: []
 */

/**
 * site_copy_dns_switch (specs/site_copy_anything_to_anything.md WP3, D1): the
 * switch by the site's own DNS records, against a fake DNS host and fake
 * public resolvers held in this file.
 *
 *  - the ways known before the copy: DNS offered where the domain's host has
 *    a driver, the proxy only where it proxies, manual always;
 *  - the plan: every unproxied A and AAAA record naming the site's server, with
 *    the copy's address of its family and its old TTL; planning writes nothing;
 *  - the switch: TTLs lowered before anything is frozen; the copy proven at its
 *    own address before any record moves; then the records, the copy taking
 *    the node, its machine believing the old server, the old server
 *    forwarding;
 *  - the watch: Keep refused while it runs; it settles only once every
 *    resolver names the copy and one old TTL has passed; then the copy stops
 *    believing the old server and the old server is powered off;
 *  - Keep puts the TTLs back; the way back during the watch puts the records
 *    and their TTLs back, and the copy stops believing the old server.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/lib/node_space_fixture.php');
harness_boot();

/** A DNS host: one zone, records in memory, every write logged. */
class ScdFakeDns extends DnsDriverBase {
	public static $records = array();
	public static $writes = array();
	public static function getKey(): string { return 'scdfakedns'; }
	public static function getLabel(): string { return 'SCD fake DNS'; }
	public static function credentialFields(): array { return array('token' => array('label' => 'Token', 'secret' => true)); }
	public function zoneFor(string $domain): ?string { return 'example.org'; }
	public function listRecords(string $zone): array {
		$out = array();
		foreach (self::$records as $id => $r) {
			$rec = new DnsRecord($r['type'], $r['name'], $r['value'], $r['ttl']);
			$rec->provider_id = $id;
			$out[] = $rec;
		}
		return $out;
	}
	public function createRecord(string $zone, DnsRecord $record): void { throw new Exception('no creates here'); }
	public function updateRecord(string $zone, DnsRecord $live, DnsRecord $desired): void {
		self::$writes[] = array('id' => $live->provider_id, 'value' => $desired->value, 'ttl' => $desired->ttl);
		self::$records[$live->provider_id]['value'] = $desired->value;
		self::$records[$live->provider_id]['ttl'] = $desired->ttl;
	}
	public function deleteRecord(string $zone, DnsRecord $live): void { throw new Exception('no deletes here'); }
}
DnsDriverRegistry::reset();
harness_defer(function () { DnsDriverRegistry::reset(); });

/** A provider that powers the old server off. */
class ScdFakeCloud implements CloudComputeProvider {
	public $status = 'running';
	public $shutdowns = 0;
	public function createInstance(array $opts): array { throw new CloudComputeException('no'); }
	public function getInstance(string $instance_id): array { return array('id' => $instance_id, 'status' => $this->status, 'ip' => '', 'label' => '', 'disk_encryption' => null); }
	public function rebuildInstance(string $instance_id, array $opts): array { throw new CloudComputeException('no'); }
	public function deleteInstance(string $instance_id): void {}
	public function shutdownInstance(string $instance_id): void { $this->shutdowns++; $this->status = 'offline'; }
	public function bootInstance(string $instance_id): void { $this->status = 'running'; }
	public function getTransfer(): array { return array(); }
	public function setReverseDns(string $instance_id, string $ip, string $hostname): array { return array(); }
}
$cloud = new ScdFakeCloud();
IpSwapMove::$driver_for = function ($p) use ($cloud) { return $cloud; };
harness_defer(function () { IpSwapMove::$driver_for = null; });

$tag = bin2hex(random_bytes(3));
$S4 = '203.0.113.71';
$S6 = '2001:db8:71::1';
$T4 = '198.51.100.72';
$T6 = '2001:db8:72::1';
$look = '/.joinery-look/0123456789abcdef0123456789abcdef';
$domain = 'scd.example.org';

SiteCopyMethods::$dns_host = function ($d) { return 'scdfakedns'; };
harness_defer(function () { SiteCopyMethods::$dns_host = null; });
ProxiedOriginMove::$sleeper = function ($s) {};
// The copy answers its look path at its own address, under the site's name.
ProxiedOriginMove::$asker = function ($url, $at = '') use ($look, $T4) {
	return $at === $T4 ? array('status' => 303, 'set_cookie' => 'joinery_look=' . substr($look, 15) . '; Path=/', 'error' => '')
		: array('status' => 503, 'set_cookie' => '', 'error' => '');
};
harness_defer(function () { ProxiedOriginMove::$asker = null; ProxiedOriginMove::$sleeper = null; });
// The public resolvers: whatever $resolve names for a name and type.
$resolve = array();
PublicResolvers::$asker = function ($url) use (&$resolve) {
	parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
	$values = $resolve[strtolower($q['name']) . ' ' . $q['type']] ?? array();
	$answer = array();
	foreach ($values as $v) {
		$answer[] = array('type' => $q['type'] === 'A' ? 1 : 28, 'data' => $v, 'TTL' => 60);
	}
	return array('Status' => 0, 'Answer' => $answer);
};
harness_defer(function () { PublicResolvers::$asker = null; });
SiteCopyRunner::$worker_starter = function ($id) {};
harness_defer(function () { SiteCopyRunner::$worker_starter = null; });

$reset_zone = function () use ($domain, $S4, $S6) {
	ScdFakeDns::$records = array(
		'r1' => array('type' => 'A', 'name' => $domain, 'value' => $S4, 'ttl' => 3600),
		'r2' => array('type' => 'A', 'name' => 'www.' . $domain, 'value' => $S4, 'ttl' => 300),
		'r3' => array('type' => 'AAAA', 'name' => $domain, 'value' => $S6, 'ttl' => 3600),
		'r4' => array('type' => 'A', 'name' => 'other.example.org', 'value' => '192.0.2.200', 'ttl' => 3600),
		'r5' => array('type' => 'TXT', 'name' => $domain, 'value' => 'v=spf1 ip4:' . $S4 . ' -all', 'ttl' => 3600),
	);
	ScdFakeDns::$writes = array();
};
$dns = function () { return new ScdFakeDns(array('token' => 'x')); };

// ── Fixtures ───────────────────────────────────────────────────────────────
$mk_node = function (string $suffix, array $set) use ($tag) {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'HarnessTest SCD ' . $suffix);
	$n->set('mgn_slug', 'harnessscd-' . $suffix . '-' . $tag);
	$n->set('mgn_uptime_enabled', false);
	foreach ($set as $k => $v) {
		$n->set($k, $v);
	}
	$n->save();
	$n->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $n->key);
	return $n;
};
$finish = function ($job, string $status, ?array $result) {
	$job->set('mjb_status', $status);
	$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
	$job->set('mjb_result', $result === null ? null : json_encode($result));
	$job->save();
};
$census = function () {
	return array('measured' => true, 'census' => array('version' => SiteCensus::VERSION,
		'tables' => array('usr_users' => 3), 'files' => array(),
		'secrets' => array('canary' => SecretBox::OPEN_OK, 'dead' => 0, 'present' => 3),
		'offloaded' => array('total' => 0, 'sampled' => 0, 'answered' => 0)));
};
$chain_id = 'chain-' . gmdate('Ymd_His', time() - 7200);
SiteCopyRunner::$chain_lister = function ($node) use ($chain_id) {
	return array('chains' => array(array('chain_id' => $chain_id, 'profile' => BackupProfile::MANAGER, 'bytes' => 7000,
		'runs' => array(array('seq' => 0, 'time' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600), 'artifacts' => array('files' => 6000, 'db' => 100))))),
		'objects' => array(), 'error' => null);
};
SiteCopyRunner::$stage_builder = function ($node, $chain) {
	return array('primitive' => 'copy_stage', 'params' => array('chain_id' => $chain['chain_id'], 'manifest_url' => 'https://x.invalid/m',
		'artifact_urls' => array()));
};
harness_defer(function () { SiteCopyRunner::$chain_lister = null; SiteCopyRunner::$stage_builder = null; });
JobCommandBuilder::set_shelf_listing_for_tests(array());

$words = 'copy_export,copy_vouch,site_census,site_quiet,backup_run,check_status';
$copy_words = 'host_report,copy_import,copy_take_vouch,copy_stage,copy_restore,site_census,take_node_id,site_quiet,trust_forwarder';
$pair = function (string $n) use ($mk_node, $domain, $S4, $S6, $T4, $T6, $words, $copy_words, $chain_id, $look, $tag) {
	$src = $mk_node('src' . $n, array('mgn_web_root' => '/var/www/html/scd' . $n . '/public_html', 'mgn_site_url' => 'https://' . $domain,
		'mgn_host' => $S4, 'mgn_agent_public_key' => base64_encode(random_bytes(32)), 'mgn_agent_version' => JobCommandBuilder::FORWARD_MIN_AGENT_VERSION,
		'mgn_agent_primitives' => $words, 'mgn_agent_last_poll' => gmdate('Y-m-d H:i:s'),
		'mgn_joinery_version' => JobCommandBuilder::BROKER_MIN_CORE_VERSION,
		'mgn_last_status_data' => json_encode(array('backup_recovery_state' => 'proven')),
		'mgn_backup_recovery_fpr' => str_repeat('ab', 32), 'mgn_agent_server_manager' => 'inactive'));
	sm_test_node_space($src);
	$sp = new CustomerCloudProvision(NULL);
	foreach (array('cvp_origin' => 'admin', 'cvp_usr_user_id' => 1, 'cvp_domain' => $domain, 'cvp_slug' => 'harnessscd-sp' . $n . '-' . $tag,
			'cvp_status' => 'done', 'cvp_docker_mode' => 'bare-metal', 'cvp_provider' => 'linode', 'cvp_hosting_mode' => 'operator',
			'cvp_instance_id' => '7100' . $n, 'cvp_instance_ip' => $S4, 'cvp_instance_ipv6' => $S6, 'cvp_region' => 'us-east',
			'cvp_mgn_managed_node_id' => (int)$src->key) as $k => $v) {
		$sp->set($k, $v);
	}
	$sp->save();
	harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $sp->key);
	$copy_key = base64_encode(random_bytes(32));
	$cnode = $mk_node('copy' . $n, array('mgn_web_root' => '/var/www/html/scd' . $n . '/public_html', 'mgn_site_url' => 'https://' . $domain,
		'mgn_host' => $T4, 'mgn_agent_public_key' => $copy_key, 'mgn_agent_version' => JobCommandBuilder::FORWARD_MIN_AGENT_VERSION,
		'mgn_agent_primitives' => $copy_words, 'mgn_install_state' => 'copy', 'mgn_copy_of_node_id' => (int)$src->key));
	$ajr = new AgentJoinRequest(NULL);
	foreach (array('ajr_claimed_name' => 'scd' . $n, 'ajr_public_key' => $copy_key, 'ajr_fingerprint' => AgentJoinRequest::fingerprint(base64_decode($copy_key)),
			'ajr_source_ip' => $T4, 'ajr_addresses' => $T4 . ',' . $T6, 'ajr_status' => 'approved', 'ajr_mgn_managed_node_id' => (int)$cnode->key) as $k => $v) {
		$ajr->set($k, $v);
	}
	$ajr->save();
	harness_register_row('ajr_agent_join_requests', 'ajr_agent_join_request_id', $ajr->key);
	$c = new SiteCopy(NULL);
	foreach (array('scp_source_node_id' => (int)$src->key, 'scp_copy_node_id' => (int)$cnode->key,
			'scp_release' => JobCommandBuilder::BROKER_MIN_CORE_VERSION, 'scp_status' => SiteCopy::STATUS_DORMANT, 'scp_chain_id' => $chain_id,
			'scp_look_path' => $look, 'scp_last_copied_time' => gmdate('Y-m-d H:i:s')) as $k => $v) {
		$c->set($k, $v);
	}
	$c->save();
	harness_register_row('scp_site_copies', 'scp_site_copy_id', $c->key);
	return array($src, $cnode, $c);
};

/** Finish each queued step's job as the node would, until the copy leaves $status. */
$drive = function (SiteCopy $copy, string $status) use ($finish, $census, $chain_id) {
	$ops = array();
	$jobs = array();
	for ($guard = 0; $guard < 30 && $copy->status() === $status; $guard++) {
		SiteCopyRunner::advance($copy);
		$copy->load();
		$current = null;
		foreach ($copy->steps() as $s) {
			if ($s['verdict'] === 'running') {
				$current = $s;
				break;
			}
		}
		if (!$current || empty($current['job_id'])) {
			continue;
		}
		$job = new ManagementJob((int)$current['job_id'], TRUE);
		harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $job->key);
		$key = $current['op'] . (!empty($current['arg']) ? ':' . $current['arg'] : '') . '@' . $current['on'];
		$ops[] = $key;
		$jobs[$key] = $job;
		if ($current['op'] === 'take_node_id') {
			$src_id = (int)$copy->get('scp_source_node_id');
			$job->set('mjb_status', 'completed');
			$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
			$job->set('mjb_output', "=== [Step 1/1] take_node_id ===\n" . json_encode(array('api_version' => '1.0',
				'data' => array('staged' => true, 'node_id' => $src_id))));
			$job->save();
			JobResultProcessor::process($job);
			$job->load();
			JobResultProcessor::complete_take_node_id($job);
		} else {
			$r = array('done' => true);
			if ($current['op'] === 'backup_run') { $r = array('backup_status' => 'success'); }
			if ($current['op'] === 'copy_vouch') { $r = array('vouched' => true, 'vouch' => '{"body":"v","signature":"s"}', 'chain_id' => $chain_id); }
			if ($current['op'] === 'site_census') { $r = $census(); }
			$finish($job, 'completed', $r);
		}
		$job->load();
		SiteCopyRunner::job_finished($job);
		$copy->load();
	}
	return array($ops, $jobs);
};
$params = function ($job) {
	$p = $job->get('mjb_parameters');
	$p = is_string($p) ? json_decode($p, true) : $p;
	return (array)($p['params'] ?? $p);
};

// ---------------------------------------------------------------------------
section('The ways the address can move, known before the copy');

list($src, $cnode, $copy) = $pair('a');
$m = SiteCopyMethods::for_target($src, array('kind' => SiteCopyMethods::TARGET_BROUGHT));
check($m[SiteCopyRunner::METHOD_DNS] === array() && $m[SiteCopyRunner::METHOD_MANUAL] === array()
	&& $m[SiteCopyRunner::METHOD_PROXIED] !== array() && $m[SiteCopyRunner::METHOD_IP_SWAP] !== array(),
	'onto a brought server: by DNS records and by hand; not through a proxy (this host does not proxy), not by swap', json_encode($m));
SiteCopyMethods::$dns_host = function ($d) { return ''; };
$m = SiteCopyMethods::for_target($src, array('kind' => SiteCopyMethods::TARGET_HOST));
check($m[SiteCopyRunner::METHOD_DNS] !== array() && strpos(implode(' ', $m[SiteCopyRunner::METHOD_DNS]), 'by hand') !== false
	&& $m[SiteCopyRunner::METHOD_MANUAL] === array(), 'a domain at a host with no driver: DNS refused, saying to switch by hand; manual still offered');
SiteCopyMethods::$dns_host = function ($d) { return 'scdfakedns'; };

// ---------------------------------------------------------------------------
section('The plan');

$reset_zone();
$plan = DnsRecordMove::plan($dns(), $domain, $src, $cnode);
$by = array();
foreach ($plan['records'] as $r) {
	$by[$r['name'] . ' ' . $r['type']] = $r;
}
check(count($plan['records']) === 3 && $by[$domain . ' A']['to'] === $T4 && $by['www.' . $domain . ' A']['ttl_was'] === 300
	&& $by[$domain . ' AAAA']['to'] === $T6 && !isset($by['other.example.org A']),
	'every A and AAAA naming the site\'s server, with the copy\'s address of its family and its old TTL; others untouched', json_encode($plan));
check(ScdFakeDns::$writes === array(), 'planning writes nothing');

// ---------------------------------------------------------------------------
section('The switch: TTLs first, then the freeze');

$reset_zone();
SiteCopyRunner::begin_switch($copy, SiteCopyRunner::METHOD_DNS, $dns(), 1);
$copy->load();
$ttls = array_map(function ($r) { return $r['ttl']; }, ScdFakeDns::$records);
check($ttls['r1'] === 60 && $ttls['r2'] === 60 && $ttls['r3'] === 60 && $ttls['r4'] === 3600 && ScdFakeDns::$records['r1']['value'] === $S4,
	'the planned records\' TTLs are lowered before anything is frozen, their values unchanged', json_encode(ScdFakeDns::$records));
$sw = $copy->switch_record();
check($copy->status() === SiteCopy::STATUS_FREEZING && $sw['method'] === 'dns' && $sw['forward_to'] === $T4 && $sw['forward_from'] === $S4
	&& isset($sw['power']['source']), 'frozen: it forwards to the copy\'s address, from its own, and its server is powered off once settled',
	json_encode($sw));
$drive($copy, SiteCopy::STATUS_FREEZING);
check($copy->status() === SiteCopy::STATUS_READY, 'the final copy matches', $copy->status() . ' ' . $copy->get('scp_halt_reason'));

// ---------------------------------------------------------------------------
section('The move: the copy proven at its own address, then the records');

ScdFakeDns::$writes = array();
SiteCopyRunner::move_address($copy, $dns(), 1);
$copy->load();
check(ScdFakeDns::$records['r1']['value'] === $T4 && ScdFakeDns::$records['r2']['value'] === $T4 && ScdFakeDns::$records['r3']['value'] === $T6
	&& ScdFakeDns::$records['r1']['ttl'] === 60 && ScdFakeDns::$records['r4']['value'] === '192.0.2.200',
	'the records name the copy, still at a minute\'s TTL', json_encode(ScdFakeDns::$records));
list($ops, $jobs) = $drive($copy, SiteCopy::STATUS_STARTING);
check($ops === array('take_node_id@copy', 'site_quiet:off@source', 'trust_forwarder:trust@source', 'site_quiet:forward@copy'),
	'the copy takes the node and starts, its machine believes the old server, then the old server forwards', json_encode($ops));
$src->load();
$tp = $params($jobs['trust_forwarder:trust@source']);
$fp = $params($jobs['site_quiet:forward@copy']);
check(($tp['action'] ?? '') === 'trust' && ($tp['address'] ?? '') === $S4 && (int)($tp['hours'] ?? 0) === SiteCopyRunner::FORWARDER_TRUST_HOURS
	&& (int)$jobs['trust_forwarder:trust@source']->get('mjb_mgn_managed_node_id') === (int)$src->key,
	'the copy\'s machine (the site\'s row now) believes the old server\'s address for a bounded time', json_encode($tp));
check(($fp['action'] ?? '') === 'forward' && ($fp['to'] ?? '') === $T4
	&& (int)$jobs['site_quiet:forward@copy']->get('mjb_mgn_managed_node_id') === (int)$cnode->key,
	'the old server (the retired row now) forwards to the copy\'s address', json_encode($fp));
$sw = $copy->switch_record();
check($copy->status() === SiteCopy::STATUS_SWITCHED && ($sw['watch']['state'] ?? '') === 'watching', 'switched, and watching', json_encode($sw['watch'] ?? null));

// ---------------------------------------------------------------------------
section('The watch');

$threw = '';
try { SiteCopyRunner::finish($copy, $dns()); } catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'still moving') !== false, 'Keep is refused while the address is moving', $threw);
$resolve = array($domain . ' A' => array($S4), 'www.' . $domain . ' A' => array($S4), $domain . ' AAAA' => array($S6));
SiteCopyRunner::advance_watch($copy);
$copy->load();
$w = $copy->switch_record()['watch'];
check($w['state'] === 'watching' && empty($w['agree']) && $w['waiting_on'] !== '', 'resolvers naming the old server: still watching', json_encode($w));
$resolve = array($domain . ' A' => array($T4), 'www.' . $domain . ' A' => array($T4), $domain . ' AAAA' => array($T6));
SiteCopyRunner::advance_watch($copy);
$copy->load();
$w = $copy->switch_record()['watch'];
check($w['state'] === 'watching' && !empty($w['agree']), 'every resolver naming the copy, an old TTL not yet passed: still watching', json_encode($w));
$sw = $copy->switch_record();
$sw['watch']['moved_time'] = gmdate('Y-m-d H:i:s', time() - 7200);
$copy->set_switch_record($sw);
$copy->save();
SiteCopyRunner::advance_watch($copy);
$copy->load();
check($copy->status() === SiteCopy::STATUS_SETTLING, 'agreed and an old TTL past: the old server is let go', $copy->status());
list($ops, $jobs) = $drive($copy, SiteCopy::STATUS_SETTLING);
for ($i = 0; $i < 5 && $copy->status() === SiteCopy::STATUS_SETTLING; $i++) {
	SiteCopyRunner::advance($copy);
	$copy->load();
}
$sw = $copy->switch_record();
check(in_array('trust_forwarder:untrust@source', $ops, true) && $cloud->shutdowns === 1 && $copy->status() === SiteCopy::STATUS_SWITCHED
	&& ($sw['watch']['state'] ?? '') === 'settled' && !empty($sw['source_off']),
	'the copy stops believing the old server, and the old server is powered off', json_encode(array($ops, $cloud->shutdowns, $copy->status(), $sw['watch'] ?? null)));

// ---------------------------------------------------------------------------
section('Keep puts the TTLs back');

$threw = '';
try { SiteCopyRunner::finish($copy, null); } catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'DNS token') !== false, 'Keep asks for the token, to put the TTLs back', $threw);
SiteCopyRunner::finish($copy, $dns());
$copy->load();
check($copy->status() === SiteCopy::STATUS_FINISHED && ScdFakeDns::$records['r1']['ttl'] === 3600 && ScdFakeDns::$records['r2']['ttl'] === 300
	&& ScdFakeDns::$records['r1']['value'] === $T4, 'kept: each record\'s old TTL again, at the copy\'s address', json_encode(ScdFakeDns::$records));

// ---------------------------------------------------------------------------
section('The way back during the watch');

$cloud->status = 'running';
$cloud->shutdowns = 0;
list($src, $cnode, $copy) = $pair('b');
$reset_zone();
SiteCopyRunner::begin_switch($copy, SiteCopyRunner::METHOD_DNS, $dns(), 1);
$copy->load();
$drive($copy, SiteCopy::STATUS_FREEZING);
SiteCopyRunner::move_address($copy, $dns(), 1);
$copy->load();
$drive($copy, SiteCopy::STATUS_STARTING);
check($copy->status() === SiteCopy::STATUS_SWITCHED, 'a second site switched and watching', $copy->status() . ' ' . $copy->get('scp_halt_reason'));
$threw = '';
try { SiteCopyRunner::go_back($copy, null); } catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'DNS token') !== false, 'going back asks for the token: the records name the copy', $threw);
SiteCopyRunner::go_back($copy, $dns());
$copy->load();
check(ScdFakeDns::$records['r1']['value'] === $S4 && ScdFakeDns::$records['r3']['value'] === $S6 && ScdFakeDns::$records['r1']['ttl'] === 3600,
	'the records name the old server again, at their old TTLs', json_encode(ScdFakeDns::$records));
$back = array_map(function ($s) { return $s['op'] . (($s['arg'] ?? '') !== '' ? ':' . $s['arg'] : '') . '@' . $s['on']; }, $copy->steps());
check($back === array('site_quiet:on@source', 'go_back@source', 'trust_forwarder:untrust@copy', 'site_quiet:off@source'),
	'the copy quieted, the rows back, the copy stops believing the old server, and the site runs again (which stops its forward)',
	json_encode($back));
check(($copy->switch_record()['watch']['state'] ?? '') === 'ended', 'and the watch ends');

harness_finish();
