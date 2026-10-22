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
 *    and their TTLs back, and the copy stops believing the old server;
 *  - what still names the old server's address (SiteCopyAddressFacts): the
 *    SPF entries covering it, listed before the switch and kept by it, and
 *    the copy's reverse DNS set once the move settles on a server made here;
 *  - a manual switch's way back: the records the owner points back, named
 *    before going back and, after, while DNS still names the copy.
 *
 * @version 1.6 - a container site's DNS move takes the AAAA naming its host's IPv6 (C7's B47)
 * @version 1.5 - holds the copy lock for the run, so the live tick never refuses one of its actions
 * @version 1.4 - reverse DNS at settle names the copy's IPv6 too, each address checked against its own record (C5's B33)
 * @version 1.3 - a manual switch's way back names the records to point back (C4's B28)
 * @version 1.2 - a way back before the copy took the node asks it no untrust (B13)
 * @version 1.2 - a DNS host that rounds a TTL up: the plan records what it holds, and a later attempt keeps the
 *                originals (B12)
 * @version 1.1 - the IP-bound list and the reverse DNS at settle
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/lib/node_space_fixture.php');
require_once(__DIR__ . '/../../../tests/lib/dns_fixtures.php');
harness_boot();

// The task that advances the live copies on this database takes the same lock
// for each tick; held for the run, the tick skips while these copies are driven,
// instead of an action here refusing ('being moved along right now').
DbConnector::get_instance()->get_db_link()->query('SELECT pg_advisory_lock(' . SiteCopyRunner::LOCK_KEY . ')');

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
	// $refuse: a record id whose writes the provider refuses.
	public static $refuse = '';
	// $min_ttl: the shortest TTL the host keeps; a shorter one is rounded up to it (Linode keeps 120 for 60).
	public static $min_ttl = 0;
	public function updateRecord(string $zone, DnsRecord $live, DnsRecord $desired): void {
		if ((string)$live->provider_id === self::$refuse) {
			throw new Exception('the provider refused the write');
		}
		self::$writes[] = array('id' => $live->provider_id, 'value' => $desired->value, 'ttl' => $desired->ttl);
		self::$records[$live->provider_id]['value'] = $desired->value;
		self::$records[$live->provider_id]['ttl'] = $desired->ttl === null ? null : max((int)$desired->ttl, self::$min_ttl);
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
	// $fail_after_off: the provider powers the server off and then answers an error.
	public $fail_after_off = false;
	public function shutdownInstance(string $instance_id): void {
		$this->shutdowns++;
		$this->status = 'offline';
		if ($this->fail_after_off) {
			throw new CloudComputeException('the provider answered 500');
		}
	}
	public function bootInstance(string $instance_id): void { $this->status = 'running'; }
	public function getTransfer(): array { return array(); }
	public $rdns = array();
	// $refuse_rdns: addresses the provider answers an error for.
	public $refuse_rdns = array();
	public function setReverseDns(string $instance_id, string $ip, string $hostname): array {
		if (in_array($ip, $this->refuse_rdns, true)) {
			throw new CloudComputeException('the provider answered 422');
		}
		$this->rdns[] = array($instance_id, $ip, $hostname);
		return array('ip' => $ip, 'rdns' => $hostname);
	}
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
// $down: resolvers that cannot be asked.
$resolve = array();
$down = array();
PublicResolvers::$asker = function ($resolver, $name, $type) use (&$resolve, &$down) {
	if (in_array($resolver, $down, true)) {
		return null;
	}
	$values = $resolve[strtolower($name) . ' ' . $type] ?? array();
	$answer = array();
	foreach ($values as $v) {
		$answer[] = array('type' => $type === 'A' ? 1 : ($type === 'TXT' ? 16 : 28), 'data' => $v, 'ttl' => 60);
	}
	return array('rcode' => 0, 'answers' => $answer);
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
		'mgn_backup_recovery_fpr' => str_repeat('ab', 32), 'mgn_agent_server_manager' => 'inactive',
		'mgn_last_host_report' => json_encode(array('sites' => array('scd' . $n)))));
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
	if ($n === 'a') {
		// This copy's server was made here, so its reverse DNS is set from here.
		$cp = new CustomerCloudProvision(NULL);
		foreach (array('cvp_origin' => 'admin', 'cvp_usr_user_id' => 1, 'cvp_domain' => $domain, 'cvp_slug' => 'harnessscd-cp' . $n . '-' . $tag,
				'cvp_status' => 'done', 'cvp_docker_mode' => 'bare-metal', 'cvp_provider' => 'linode', 'cvp_hosting_mode' => 'operator',
				'cvp_instance_id' => '7200' . $n, 'cvp_instance_ip' => $T4, 'cvp_instance_ipv6' => $T6, 'cvp_region' => 'us-central',
				'cvp_mgn_managed_node_id' => (int)$cnode->key) as $k => $v) {
			$cp->set($k, $v);
		}
		$cp->save();
		harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $cp->key);
	}
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

// A site in a container: its own row knows only the IPv4 its agent joined over;
// the IPv6 is on its Docker host's record (C7's B47, where only the A moved).
$dhost = $mk_node('dockhost', array('mgn_host' => $S4));
$dhp = new CustomerCloudProvision(NULL);
foreach (array('cvp_origin' => 'admin', 'cvp_usr_user_id' => 1, 'cvp_domain' => $S4, 'cvp_slug' => 'harnessscd-dh-' . $tag,
		'cvp_status' => 'done', 'cvp_docker_mode' => 'bare-metal', 'cvp_install_mode' => 'adopted', 'cvp_provider' => 'linode',
		'cvp_hosting_mode' => 'operator', 'cvp_instance_id' => '7300', 'cvp_instance_ip' => $S4, 'cvp_instance_ipv6' => $S6,
		'cvp_region' => 'us-east', 'cvp_mgn_managed_node_id' => (int)$dhost->key) as $k => $v) {
	$dhp->set($k, $v);
}
$dhp->save();
harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $dhp->key);
$dmgh = new ManagedHost(NULL);
foreach (array('mgh_name' => 'HarnessTest SCD dock ' . $tag, 'mgh_slug' => 'harnessscd-dock-' . $tag, 'mgh_host' => $S4,
		'mgh_mgn_managed_node_id' => (int)$dhost->key) as $k => $v) {
	$dmgh->set($k, $v);
}
$dmgh->save();
$dmgh->load();
harness_register_row('mgh_managed_hosts', 'mgh_managed_host_id', $dmgh->key);
$dsite = $mk_node('docksite', array('mgn_host' => $S4, 'mgn_container_name' => 'scddock', 'mgn_mgh_managed_host_id' => (int)$dmgh->key,
	'mgn_site_url' => 'https://' . $domain, 'mgn_web_root' => '/var/www/html/scddock/public_html'));
$reset_zone();
$dplan = DnsRecordMove::plan($dns(), $domain, $dsite, $cnode);
$dby = array();
foreach ($dplan['records'] as $r) {
	$dby[$r['name'] . ' ' . $r['type']] = $r;
}
check(isset($dby[$domain . ' AAAA']) && $dby[$domain . ' AAAA']['to'] === $T6 && isset($dby[$domain . ' A']) && !isset($dby['other.example.org A']),
	'a container site moves the AAAA naming its host\'s IPv6 with its A, though its own row knows only the IPv4', json_encode($dplan['records']));

// ---------------------------------------------------------------------------
section('What still names the old server\'s address');

$resolve[$domain . ' TXT'] = array('v=spf1 ip4:' . $S4 . ' ip4:192.0.2.0/24 ip6:2001:db8:71::/48 include:_spf.example.net -all',
	'note ip4:203.0.113.0/24');
check(SiteCopyAddressFacts::spf_entries($domain, array($S4, $S6)) === array('ip4:' . $S4, 'ip6:2001:db8:71::/48'),
	'the SPF entries covering the old server\'s addresses, an address or a block, and no other record',
	json_encode(SiteCopyAddressFacts::spf_entries($domain, array($S4, $S6))));
check(SiteCopyAddressFacts::covers('203.0.113.0/25', $S4) && !SiteCopyAddressFacts::covers('203.0.113.128/25', $S4)
	&& !SiteCopyAddressFacts::covers('203.0.113.70', $S4) && !SiteCopyAddressFacts::covers('2001:db8::/32', $S4)
	&& SiteCopyAddressFacts::covers('2001:db8:70::/46', $S6),
	'a block covers what its prefix covers, of its own family only');
$facts = SiteCopyAddressFacts::for_switch($src, $domain, SiteCopyRunner::METHOD_DNS, $cnode);
$text = implode("\n", $facts);
check(strpos($text, 'names ip4:' . $S4) !== false && strpos($text, 'names ip6:2001:db8:71::/48') !== false
	&& strpos($text, 'this management node sets the copy\'s address') !== false && strpos($text, $S4 . ', ' . $S6) !== false,
	'by DNS: each SPF entry, reverse DNS set from here (the copy\'s server was made here), and the allow-lists naming both addresses', $text);
$text = implode("\n", SiteCopyAddressFacts::for_switch($src, $domain, SiteCopyRunner::METHOD_MANUAL, $mk_node('brought', array('mgn_host' => '198.51.100.99'))));
check(strpos($text, 'at its provider') !== false, 'by hand onto a server brought here: its reverse DNS is the owner\'s to set', $text);
$text = implode("\n", SiteCopyAddressFacts::for_switch($src, $domain, SiteCopyRunner::METHOD_PROXIED, $cnode));
check(strpos($text, 'names ip4:') !== false && strpos($text, 'Reverse DNS') === false,
	'through the proxy: the SPF entries, and no reverse DNS (the name resolves to the proxy)', $text);
check(strpos(implode(' ', SiteCopyAddressFacts::for_switch($src, $domain, SiteCopyRunner::METHOD_DNS, $cnode, true)), 'its host\'s') !== false,
	'onto a host: the reverse DNS is the host\'s');
check(SiteCopyAddressFacts::for_switch($src, $domain, SiteCopyRunner::METHOD_IP_SWAP, $cnode) === array(),
	'an IP swap moves the address itself: nothing listed');

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
check(strpos(implode(' ', (array)($sw['address_facts'] ?? array())), 'names ip4:' . $S4) !== false,
	'the switch-over keeps the list of what still names the old address', json_encode($sw['address_facts'] ?? null));
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
check($cloud->rdns === array(array('7200a', $T4, $domain), array('7200a', $T6, $domain))
	&& strpos((string)($sw['rdns_note'] ?? ''), $T4 . ' and ' . $T6 . ' now answer ' . $domain) !== false,
	'once settled, the copy\'s server (made here) answers the site\'s name in reverse DNS, at its IPv4 and at the IPv6 its AAAA moved to (B33)',
	json_encode(array($cloud->rdns, $sw['rdns_note'] ?? null)));

// ---------------------------------------------------------------------------
section('Reverse DNS names each address its own record gives');

$rdns_node = new ManagedNode((int)$copy->get('scp_source_node_id'), TRUE);
$cloud->rdns = array();
$r = NodeReverseDns::set($rdns_node, $domain, $cloud, array($T4));
check($cloud->rdns === array(array('7200a', $T4, $domain)) && isset($r['not_set'][$T6])
	&& strpos(NodeReverseDns::summary($r), $T6 . ' keeps its reverse DNS: ' . $domain . ' has no AAAA record giving it') !== false,
	'a name with only an A record: the IPv4 is named, the IPv6 is left and the result says why', json_encode(array($cloud->rdns, $r)));
$cloud->rdns = array();
$r = NodeReverseDns::set($rdns_node, $domain, $cloud, array($T4, '2001:0db8:0072:0000:0000:0000:0000:0001'));
check(count($cloud->rdns) === 2 && $r['set'] === array($T4, $T6), 'an AAAA spelled another way is the same address', json_encode($r));
$cloud->rdns = array();
$cloud->refuse_rdns = array($T6);
$r = NodeReverseDns::set($rdns_node, $domain, $cloud, array($T4, $T6));
$cloud->refuse_rdns = array();
check($r['set'] === array($T4) && strpos((string)($r['not_set'][$T6] ?? ''), 'the provider refused it') !== false,
	'the provider refusing only the IPv6: the IPv4 is still named, and the refusal is reported, not thrown', json_encode($r));
$threw = '';
try { NodeReverseDns::set($rdns_node, $domain, $cloud, array($S4, $T6)); } catch (NodeReverseDnsException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'Create the A record first') !== false && strpos($threw, $S4) !== false,
	'a name whose A still gives another address: refused, naming what it resolves to', $threw);

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

// Each later pair takes the site's addresses: the one before leaves the dashboard.
$retire = function (ManagedNode ...$nodes) {
	foreach ($nodes as $n) {
		$n->load();
		if (!$n->get('mgn_delete_time')) {
			$n->soft_delete();
		}
	}
};
$op_list = function (SiteCopy $copy) {
	return array_map(function ($s) { return $s['op'] . (($s['arg'] ?? '') !== '' ? ':' . $s['arg'] : '') . '@' . $s['on']; }, $copy->steps());
};

// ---------------------------------------------------------------------------
section('The resolvers are asked in wire format');

$q = PublicResolvers::query('WWW.Example.org.', 28);
check(bin2hex($q) === '000001000001000000000000' . '03777777076578616d706c65036f726700' . '001c0001',
	'a query: id 0, recursion desired, one question, the name lower-cased', bin2hex($q));
check(PublicResolvers::query('a..b', 1) === null && PublicResolvers::query(str_repeat('a', 64) . '.org', 1) === null, 'no query for a name that cannot be one');
// A response: the question, a CNAME, then an A at the name the CNAME points to (by compression pointer).
$msg = pack('nnnnnn', 0, 0x8180, 1, 2, 0, 0) . "\3www\7example\3org\0" . pack('nn', 1, 1)
	. "\xc0\x0c" . pack('nnNn', 5, 1, 300, 6) . "\3web\xc0\x10"
	. "\xc0\x2d" . pack('nnNn', 1, 1, 120, 4) . inet_pton('198.51.100.9');
$r = PublicResolvers::parse($msg);
check($r !== null && $r['rcode'] === 0 && count($r['answers']) === 1 && $r['answers'][0]['data'] === '198.51.100.9' && $r['answers'][0]['ttl'] === 120,
	'a response read: the A record behind a CNAME, with its TTL', json_encode($r));
check(PublicResolvers::parse(substr($msg, 0, 40)) === null, 'a cut-off response reads as none');
check(PublicResolvers::parse(pack('nnnnnn', 0, 0x8183, 0, 0, 0, 0))['rcode'] === 3, 'NXDOMAIN reads as an answer of none');
// A TXT answer split into two character-strings, as a long SPF record is.
$txt = "\x0fv=spf1 ip4:1.2.\x083.4 -all";
$msg = pack('nnnnnn', 0, 0x8180, 1, 1, 0, 0) . "\7example\3org\0" . pack('nn', 16, 1)
	. "\xc0\x0c" . pack('nnNn', 16, 1, 300, strlen($txt)) . $txt;
$r = PublicResolvers::parse($msg);
check($r !== null && count($r['answers']) === 1 && $r['answers'][0]['type'] === 16 && $r['answers'][0]['data'] === 'v=spf1 ip4:1.2.3.4 -all',
	'a TXT record read: its strings joined, as written', json_encode($r));
$bad = "\x40v=spf1";
$msg = pack('nnnnnn', 0, 0x8180, 1, 1, 0, 0) . "\7example\3org\0" . pack('nn', 16, 1)
	. "\xc0\x0c" . pack('nnNn', 16, 1, 300, strlen($bad)) . $bad;
check(PublicResolvers::parse($msg)['answers'] === array(), 'a TXT string longer than its record reads as nothing');

// ---------------------------------------------------------------------------
section('The watch ends');

$plan_one = array('records' => array(array('name' => 'w.' . $domain, 'type' => 'A', 'from' => $S4, 'to' => $T4, 'ttl_was' => 300)));
$moved = gmdate('Y-m-d H:i:s', time() - 3600);
$resolve = array('w.' . $domain . ' A' => array($T4, '192.0.2.200'));
$r = DnsRecordMove::settled($plan_one, $moved);
check($r['settled'] && !$r['by_time'], 'another address the name carries, which the plan did not move, does not hold the watch up', json_encode($r));
$resolve = array('w.' . $domain . ' A' => array($T4, $S4));
check(!DnsRecordMove::settled($plan_one, $moved)['settled'], 'a resolver still naming the old address beside the new: not settled');
$resolve = array('w.' . $domain . ' A' => array($T4));
$down = array('Quad9');
$r = DnsRecordMove::settled($plan_one, $moved);
check(!$r['settled'] && !$r['agree'], 'one resolver that cannot be asked: not settled yet', json_encode($r));
$r = DnsRecordMove::settled($plan_one, gmdate('Y-m-d H:i:s', time() - DnsRecordMove::MAX_WAIT - 60));
check($r['settled'] && $r['by_time'], 'past MAX_WAIT it settles whatever the resolvers say, and says so', json_encode($r));
$down = array();
check(DnsRecordMove::MAX_WAIT < SiteCopyRunner::FORWARDER_TRUST_HOURS * 3600, 'the watch ends before the copy stops believing the old server');
$kept = DnsRecordMove::keep_earlier_ttls(array(array('id' => 'r1', 'ttl_was' => 60), array('id' => 'r2', 'ttl_was' => 300)),
	array(array('id' => 'r1', 'ttl_was' => 3600), array('id' => 'r2', 'ttl_was' => 900)));
check($kept[0]['ttl_was'] === 3600 && $kept[1]['ttl_was'] === 300,
	'a TTL an earlier attempt lowered and never put back keeps its original; one at any other value is the zone\'s', json_encode($kept));

// ---------------------------------------------------------------------------
section('Going back before the move puts the TTLs back');

$retire($src, $cnode);
list($src, $cnode, $copy) = $pair('c');
$reset_zone();
SiteCopyRunner::begin_switch($copy, SiteCopyRunner::METHOD_DNS, $dns(), 1);
$copy->load();
check(ScdFakeDns::$records['r1']['ttl'] === 60 && !empty($copy->switch_record()['ttl_lowered']), 'the TTLs lowered at the start');
$threw = '';
try { SiteCopyRunner::go_back($copy, null); } catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'TTL') !== false, 'going back before the move asks for the token too: the TTLs go back', $threw);
SiteCopyRunner::go_back($copy, $dns());
$copy->load();
check(ScdFakeDns::$records['r1']['ttl'] === 3600 && ScdFakeDns::$records['r2']['ttl'] === 300 && ScdFakeDns::$records['r1']['value'] === $S4
	&& empty($copy->switch_record()['ttl_lowered']), 'each record\'s old TTL again, still at the site\'s server', json_encode(ScdFakeDns::$records));
list($ops) = $drive($copy, SiteCopy::STATUS_RETURNING);
$copy->load();
check($ops === array('site_quiet:off@source'),
	'nothing asks the copy to stop believing a server it was never told to believe (a dormant copy refuses it): the site just starts',
	json_encode(array($ops, $copy->status(), $copy->get('scp_halt_reason'))));

// ---------------------------------------------------------------------------
section('An old agent: no forward, and the copy is still proven at its own address');

$retire($src, $cnode);
list($src, $cnode, $copy) = $pair('d');
$src->set('mgn_agent_version', '1.83.0');
$src->save();
$reset_zone();
SiteCopyRunner::begin_switch($copy, SiteCopyRunner::METHOD_DNS, $dns(), 1);
$copy->load();
$sw = $copy->switch_record();
check(($sw['forward_to'] ?? 'x') === '' && strpos((string)($sw['no_forward'] ?? ''), '1.83.0') !== false && ($sw['probe_at'] ?? '') === $T4,
	'nothing forwards, saying why, and the copy\'s address is kept for its proof', json_encode($sw));
$drive($copy, SiteCopy::STATUS_FREEZING);
SiteCopyRunner::move_address($copy, $dns(), 1);
$copy->load();
check(ScdFakeDns::$records['r1']['value'] === $T4 && ($copy->switch_record()['proof']['proven'] ?? false),
	'proven at the copy\'s own address (the name still resolves to the frozen site), and the records moved', json_encode($copy->switch_record()['proof'] ?? null));
list($ops, ) = $drive($copy, SiteCopy::STATUS_STARTING);
check(!in_array('trust_forwarder:trust@source', $ops, true) && !in_array('site_quiet:forward@copy', $ops, true), 'and nothing is told to forward', json_encode($ops));
$retire($src, $cnode);

// ---------------------------------------------------------------------------
section('An old server neither powered off nor held stops forwarding; a settle that stops');

list($src, $cnode, $copy) = $pair('e');
foreach (new MultiCustomerCloudProvision(array('cvp_mgn_managed_node_id' => (int)$src->key, 'deleted' => false)) as $sp) {
	$sp->soft_delete();
}
$reset_zone();
SiteCopyRunner::begin_switch($copy, SiteCopyRunner::METHOD_DNS, $dns(), 1);
$copy->load();
check(!isset($copy->switch_record()['power']), 'a server this management node did not create: nothing to power off');
$drive($copy, SiteCopy::STATUS_FREEZING);
SiteCopyRunner::move_address($copy, $dns(), 1);
$copy->load();
$drive($copy, SiteCopy::STATUS_STARTING);
$resolve = array($domain . ' A' => array($T4), 'www.' . $domain . ' A' => array($T4), $domain . ' AAAA' => array($T6));
$sw = $copy->switch_record();
$sw['watch']['moved_time'] = gmdate('Y-m-d H:i:s', time() - 7200);
$copy->set_switch_record($sw);
$copy->save();
SiteCopyRunner::advance_watch($copy);
$copy->load();
check($op_list($copy) === array('trust_forwarder:untrust@source', 'site_quiet:on@copy'),
	'settled: the copy stops believing it, and the old server is quiet again, its maintenance page in place of the forward', json_encode($op_list($copy)));
// The old server's agent does not answer: the settle stops.
for ($i = 0; $i < 5 && $copy->status() === SiteCopy::STATUS_SETTLING; $i++) {
	SiteCopyRunner::advance($copy);
	$copy->load();
	foreach ($copy->steps() as $st) {
		if ($st['verdict'] === 'running' && !empty($st['job_id'])) {
			$job = new ManagementJob((int)$st['job_id'], TRUE);
			harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $job->key);
			$finish($job, 'failed', null);
			$job->set('mjb_error_message', 'the agent did not answer');
			$job->save();
			SiteCopyRunner::job_finished($job);
			$copy->load();
			break;
		}
	}
}
check($copy->status() === SiteCopy::STATUS_HALTED && ($copy->switch_record()['phase'] ?? '') === 'settle', 'the settle stopped',
	$copy->status() . ' ' . $copy->get('scp_halt_reason'));
SiteCopyRunner::retry_settle($copy);
$copy->load();
check($copy->status() === SiteCopy::STATUS_SETTLING && $op_list($copy) === array('trust_forwarder:untrust@source', 'site_quiet:on@copy'),
	'tried again: every settle step laid again', json_encode(array($copy->status(), $op_list($copy))));
$drive($copy, SiteCopy::STATUS_SETTLING);
check($copy->status() === SiteCopy::STATUS_SWITCHED, 'and the old server let go', $copy->status() . ' ' . $copy->get('scp_halt_reason'));
// A settle that stops can also be kept as it is.
$sw = $copy->switch_record();
$sw['phase'] = 'settle';
$copy->set_switch_record($sw);
$copy->set('scp_status', SiteCopy::STATUS_HALTED);
$copy->save();
SiteCopyRunner::finish($copy, $dns());
$copy->load();
check($copy->status() === SiteCopy::STATUS_FINISHED && ScdFakeDns::$records['r1']['ttl'] === 3600, 'a settle that stopped can be kept, its TTLs put back');

// ---------------------------------------------------------------------------
section('A settle that stops inside the power-off, then the way back');

$retire($src, $cnode);
$cloud->status = 'running';
$cloud->shutdowns = 0;
list($src, $cnode, $copy) = $pair('f');
$reset_zone();
SiteCopyRunner::begin_switch($copy, SiteCopyRunner::METHOD_DNS, $dns(), 1);
$copy->load();
$drive($copy, SiteCopy::STATUS_FREEZING);
SiteCopyRunner::move_address($copy, $dns(), 1);
$copy->load();
$drive($copy, SiteCopy::STATUS_STARTING);
$resolve = array($domain . ' A' => array($T4), 'www.' . $domain . ' A' => array($T4), $domain . ' AAAA' => array($T6));
$sw = $copy->switch_record();
$sw['watch']['moved_time'] = gmdate('Y-m-d H:i:s', time() - 7200);
$copy->set_switch_record($sw);
$copy->save();
SiteCopyRunner::advance_watch($copy);
$copy->load();
$cloud->fail_after_off = true;
$drive($copy, SiteCopy::STATUS_SETTLING);
$cloud->fail_after_off = false;
$sw = $copy->switch_record();
check($copy->status() === SiteCopy::STATUS_HALTED && ($sw['phase'] ?? '') === 'settle' && empty($sw['source_off']) && $cloud->status === 'offline',
	'the old server went off, and the step that asked stopped without knowing it', json_encode(array($copy->status(), $sw['phase'] ?? null, $op_list($copy))));
SiteCopyRunner::go_back($copy, $dns());
$copy->load();
$back = $op_list($copy);
check(in_array('power_on:source@source', $back, true)
	&& array_search('power_on:source@source', $back) < array_search('site_quiet:off@source', $back),
	'going back powers the old server on before its site leaves quiet', json_encode($back));

// ---------------------------------------------------------------------------
section('A TTL the provider refuses to lower: the ones lowered go back');

$retire($src, $cnode);
list($src, $cnode, $copy) = $pair('g');
$reset_zone();
ScdFakeDns::$refuse = 'r3';
$threw = '';
try { SiteCopyRunner::begin_switch($copy, SiteCopyRunner::METHOD_DNS, $dns(), 1); } catch (Exception $e) { $threw = $e->getMessage(); }
ScdFakeDns::$refuse = '';
$copy->load();
$src->load();
check(strpos($threw, 'Nothing was frozen') !== false && strpos($threw, 'were put back') !== false
	&& ScdFakeDns::$records['r1']['ttl'] === 3600 && ScdFakeDns::$records['r2']['ttl'] === 300
	&& trim((string)$src->get('mgn_install_state')) === '' && $copy->status() === SiteCopy::STATUS_DORMANT,
	'nothing frozen, and every TTL that reached a minute is back at its original', $threw . ' ' . json_encode(ScdFakeDns::$records));

// ---------------------------------------------------------------------------
section('A DNS host that rounds a TTL up: the plan says what it holds (B12)');

$retire($src, $cnode);
list($src, $cnode, $copy) = $pair('h');
$reset_zone();
ScdFakeDns::$min_ttl = 120;
SiteCopyRunner::begin_switch($copy, SiteCopyRunner::METHOD_DNS, $dns(), 1);
$copy->load();
$recs = array();
foreach ((array)($copy->switch_record()['records'] ?? array()) as $r) {
	$recs[$r['id']] = $r;
}
check(ScdFakeDns::$records['r1']['ttl'] === 120 && ($recs['r1']['ttl_held'] ?? null) === 120 && ($recs['r2']['ttl_held'] ?? null) === 120
	&& ($recs['r1']['ttl_was'] ?? null) === 3600 && DnsRecordMove::held_ttl($recs['r1']) === 120,
	'each record carries the TTL the host keeps while it moves, read back, beside its original', json_encode($recs));
$again = DnsRecordMove::keep_earlier_ttls(array(array('id' => 'r1', 'ttl_was' => 120), array('id' => 'r2', 'ttl_was' => 120)),
	array_values($recs));
check($again[0]['ttl_was'] === 3600 && $again[1]['ttl_was'] === 300,
	'a later attempt that finds them still at the held TTL keeps the originals', json_encode($again));
$older = DnsRecordMove::keep_earlier_ttls(array(array('id' => 'r1', 'ttl_was' => 120)), array(array('id' => 'r1', 'ttl_was' => 3600)));
check($older[0]['ttl_was'] === 120,
	'beside a plan that recorded no held TTL, only the asked one counts as lowered: 120 is the zone\'s', json_encode($older));
SiteCopyRunner::go_back($copy, $dns());
$copy->load();
ScdFakeDns::$min_ttl = 0;
check(ScdFakeDns::$records['r1']['ttl'] === 3600 && ScdFakeDns::$records['r2']['ttl'] === 300 && ScdFakeDns::$records['r3']['ttl'] === 3600,
	'going back puts each original back', json_encode(ScdFakeDns::$records));
$retire($src, $cnode);

section('A manual switch\'s way back names the records to point back (B28)');
$man = array('method' => SiteCopyRunner::METHOD_MANUAL, 'records' => array(
	array('id' => '', 'type' => 'A', 'name' => 'site.example.org', 'from' => '192.0.2.10', 'to' => '198.51.100.20', 'ttl_was' => null),
	array('id' => '', 'type' => 'AAAA', 'name' => 'site.example.org', 'from' => '2001:db8::10', 'to' => '2001:db8::20', 'ttl_was' => null),
));
$lines = SiteCopyRunner::manual_records_back($man);
check($lines === array('site.example.org A from 198.51.100.20 to 192.0.2.10', 'site.example.org AAAA from 2001:db8::20 to 2001:db8::10'),
	'before going back, each record is named with the copy\'s address and the site\'s own', json_encode($lines));
check(SiteCopyRunner::manual_records_back(array('method' => SiteCopyRunner::METHOD_DNS) + $man) === array(),
	'a switch by DNS records names none: going back changes them itself');
$zone = array('site.example.org|' . DNS_A => array(array('ip' => '198.51.100.20')),
	'site.example.org|' . DNS_AAAA => array(array('ipv6' => '2001:0db8:0:0:0:0:0:10')));
DnsResolver::setBackend(new FakeDnsBackend($zone));
harness_defer(function () { DnsResolver::clearBackend(); });
$went = $man + array('address_at' => 'source', 'moved_back_time' => '2026-10-21 13:30:00');
$notes = implode(' ', SiteCopyRunner::went_back_notes($went));
check(strpos($notes, 'site.example.org A from 198.51.100.20 to 192.0.2.10') !== false && strpos($notes, 'AAAA') === false,
	'after going back, the note names the record DNS still points at the copy, and not one already pointed back', $notes);
check(strpos(implode(' ', SiteCopyRunner::went_back_notes($man + array('address_at' => 'copy', 'moved_back_time' => '2026-10-21 13:30:00'))),
	'DNS still names the copy') === false, 'moved to the copy again, it says nothing about pointing back');
$zone['site.example.org|' . DNS_A] = array(array('ip' => '192.0.2.10'));
DnsResolver::setBackend(new FakeDnsBackend($zone));
check(SiteCopyRunner::went_back_notes($went) === array(), 'once the owner has pointed both back, the note is gone');
$zone['site.example.org|' . DNS_A] = false;
DnsResolver::setBackend(new FakeDnsBackend($zone));
check(SiteCopyRunner::went_back_notes($went) === array(), 'a lookup that fails names nothing either way');
DnsResolver::clearBackend();

DbConnector::get_instance()->get_db_link()->query('SELECT pg_advisory_unlock(' . SiteCopyRunner::LOCK_KEY . ')');
harness_finish();
