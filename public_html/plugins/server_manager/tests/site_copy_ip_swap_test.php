<?php
/** @joinery-test
 * name: site_copy_ip_swap
 * tier: db
 * env: dev-only
 * needs: []
 */

/**
 * site_copy_ip_swap (specs/site_copy.md WP12): the switch-over by swapping
 * the two servers' IPv4 addresses at Linode, its undo when the copy does not
 * answer, and the way back after the switch.
 *
 * The Linode driver's new calls run for real against a fake Linode API (the
 * request shapes); the switch-over runs against a fake provider held in this
 * file, whose machines take a call or two to change state, as real ones do.
 * Agent jobs are finished by hand, as site_copy_switch does.
 *
 * @version 1.6 - its sources sit at JobCommandBuilder::COPY_SOURCE_MIN_VERSION, the copy floor (B115)
 * @version 1.5 - B70: a kept swap's unnamed IPv6 is named alone with Set Reverse DNS
 * @version 1.4 - B45: the copy's IPv6 is named in reverse DNS when the AAAA gives it, at the switch or at Keep, and
 *                the Copy tab says what to do when it is not; the AAAA refusal leads with pointing it at the copy
 * @version 1.3 - holds the copy lock for the run, so the live tick never refuses one of its actions
 * @version 1.2 - B58/B62: a copy enrolled here is offered the swap before its next host report names the holder;
 *               a row for another machine's identity, or one not yet enrolled, is not proof
 * @version 1.1 - the source's backups are on a test target of its own (lib/node_space_fixture.php): since a node backs
 *               up only through a storage space, the suite had been skipping;
 *               the source on the broker's release floor
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/lib/node_space_fixture.php');
harness_boot();

// The task that advances the live copies on this database takes the same lock
// for each tick; held for the run, the tick skips while these copies are driven,
// instead of an action here refusing ('being moved along right now').
DbConnector::get_instance()->get_db_link()->query('SELECT pg_advisory_lock(' . SiteCopyRunner::LOCK_KEY . ')');

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

$tag = bin2hex(random_bytes(3));
$source_key = base64_encode(random_bytes(32));
$copy_key = base64_encode(random_bytes(32));
$look = '/.joinery-look/0123456789abcdef0123456789abcdef';
$S4 = '192.0.2.81';
$T4 = '198.51.100.82';

// ---------------------------------------------------------------------------
section('The Linode driver: what the swap asks of the API');

$seen = array();
$routes = array();
$linode = function () use (&$seen, &$routes) {
	$handler = function (RequestInterface $req, array $opts) use (&$seen, &$routes) {
		$key = $req->getMethod() . ' ' . preg_replace('#^/v4/#', '', $req->getUri()->getPath());
		$seen[] = array('key' => $key, 'body' => json_decode((string)$req->getBody(), true));
		$r = $routes[$key] ?? array(200, array('data' => array()));
		if ($r[0] >= 400) {
			return Create::rejectionFor(new GuzzleHttp\Exception\ClientException('err', $req,
				new Response($r[0], array(), json_encode($r[1]))));
		}
		return Create::promiseFor(new Response($r[0], array('Content-Type' => 'application/json'), json_encode($r[1])));
	};
	return new LinodeComputeDriver('test-token', new Client(array('base_uri' => LinodeComputeDriver::API_BASE,
		'handler' => HandlerStack::create($handler), 'http_errors' => true)));
};

$routes = array(
	'GET linode/instances/11'                    => array(200, array('id' => 11, 'status' => 'running', 'region' => 'us-east',
		'ipv4' => array($S4), 'ipv6' => '2600:3c03::f03c:1/128', 'label' => 's', 'interface_generation' => 'legacy_config')),
	'GET linode/instances/11/ips'                => array(200, array('ipv4' => array('public' => array(array('address' => $S4))),
		'ipv6' => array('slaac' => array('address' => '2600:3c03::f03c:1')))),
	'GET linode/instances/11/configs'            => array(200, array('data' => array(array('helpers' => array('network' => true))))),
	'GET linode/instances/12'                    => array(200, array('id' => 12, 'status' => 'running', 'region' => 'us-east',
		'ipv4' => array($T4), 'label' => 't', 'interface_generation' => 'linode')),
	'GET linode/instances/12/ips'                => array(200, array('ipv4' => array('public' => array(array('address' => $T4))))),
	'GET linode/instances/12/interfaces/settings' => array(200, array('network_helper' => false)),
	'GET networking/ips/' . $S4                  => array(200, array('address' => $S4, 'linode_id' => 11, 'rdns' => 'mail.example.org')),
	'GET linode/instances/11/firewalls'          => array(200, array('data' => array(array('id' => 7, 'label' => 'web-only')))),
);
$d = $linode();
$r = $d->addressReport('11');
check($r['ipv4_public'] === array($S4) && $r['network_helper'] === true && $r['ipv6'] === '2600:3c03::f03c:1',
	'a legacy instance reports its public IPv4, its IPv6 and Network Helper from its configuration profiles', json_encode($r));
$r = $d->addressReport('12');
check($r['network_helper'] === false, 'a Linode-interfaces instance reports Network Helper from its interface settings', json_encode($r));
check($r['interface_generation'] === 'linode' && $d->addressReport('11')['interface_generation'] === 'legacy_config',
	'each instance reports its interface generation, which a swap reads (B8)', json_encode($r));
$seen = array();
$routes['POST linode/instances'] = array(200, array('id' => 13, 'status' => 'provisioning', 'region' => 'us-east', 'label' => 'n'));
$d->createInstance(array('label' => 'n', 'region' => 'us-east', 'type' => 'g6-nanode-1', 'image' => 'linode/ubuntu26.04', 'root_pass' => 'x-Aa1-long-enough-pass'));
$create = null;
foreach ($seen as $call) { if ($call['key'] === 'POST linode/instances') { $create = $call['body']; } }
check(is_array($create) && ($create['interface_generation'] ?? '') === 'legacy_config',
	'a new instance asks for configuration-profile interfaces, never the account\'s default (B8)', json_encode($create));
$a = $d->ipAddress($S4);
check($a['instance_id'] === '11' && $a['rdns'] === 'mail.example.org', 'an address reports the instance holding it and its reverse DNS');
check($d->instanceFirewalls('11') === array('7' => 'web-only'), 'the firewalls attached to an instance, by id');
$seen = array();
$d->assignIpv4('us-east', array($S4 => '12', $T4 => '11'));
check($seen[0]['key'] === 'POST networking/ips/assign'
	&& $seen[0]['body'] === array('region' => 'us-east', 'assignments' => array(
		array('address' => $S4, 'linode_id' => 12), array('address' => $T4, 'linode_id' => 11))),
	'the swap is one assign call with both addresses, in the region', json_encode($seen));
$seen = array();
$d->rebootInstance('12');
check($seen[0]['key'] === 'POST linode/instances/12/reboot', 'a reboot is the instance\'s reboot call');

// ---------------------------------------------------------------------------
// A fake provider for the switch-over: two machines, their addresses, and
// state changes that take a call to happen.

class IpSwapTestDriver implements CloudComputeProvider, CloudAddressSwap {
	public $status = array();
	public $owner = array();     // address => instance id
	public $rdns = array();      // address => name
	public $region = array();
	public $helper = array();
	public $firewalls = array();
	public $v6 = array();
	public $generation = array();   // instance => interface generation (B8)
	public $calls = array();
	public $drop_rdns = false;
	public $refuse_v6_rdns = 0;  // an HTTP status the provider answers an IPv6 reverse DNS call with
	private $pending = array();  // instance => status it reaches on the next read

	public function getInstance(string $id): array {
		// One read in the state the call left it in, then the next.
		$out = array('id' => $id, 'status' => $this->status[$id], 'ip' => '', 'label' => '', 'region' => $this->region[$id]);
		if (isset($this->pending[$id])) {
			$this->status[$id] = $this->pending[$id];
			unset($this->pending[$id]);
		}
		return $out;
	}
	public function shutdownInstance(string $id): void {
		$this->calls[] = "shutdown {$id}";
		$this->status[$id] = 'shutting_down';
		$this->pending[$id] = 'offline';
	}
	public function bootInstance(string $id): void {
		$this->calls[] = "boot {$id}";
		$this->status[$id] = 'booting';
		$this->pending[$id] = 'running';
	}
	public function rebootInstance(string $id): void {
		$this->calls[] = "reboot {$id}";
		$this->status[$id] = 'rebooting';
		$this->pending[$id] = 'running';
	}
	public function addressReport(string $id): array {
		$v4 = array();
		foreach ($this->owner as $ip => $inst) {
			if ((string)$inst === (string)$id) {
				$v4[] = $ip;
			}
		}
		return array('id' => $id, 'status' => $this->status[$id], 'region' => $this->region[$id], 'label' => '',
			'ipv4_public' => $v4, 'ipv6' => $this->v6[$id] ?? '', 'network_helper' => $this->helper[$id] ?? true,
			'interface_generation' => $this->generation[$id] ?? 'legacy_config');
	}
	public function ipAddress(string $address): array {
		return array('address' => $address, 'instance_id' => (string)($this->owner[$address] ?? ''), 'rdns' => $this->rdns[$address] ?? '');
	}
	public function assignIpv4(string $region, array $assignments): void {
		$this->calls[] = 'assign ' . json_encode($assignments);
		foreach ($assignments as $ip => $inst) {
			$this->owner[$ip] = (string)$inst;
			if ($this->drop_rdns) {
				$this->rdns[$ip] = $ip . '.ip.linodeusercontent.com';
			}
		}
	}
	public function setReverseDns(string $id, string $ip, string $hostname): array {
		if ($this->refuse_v6_rdns && strpos($ip, ':') !== false) {
			throw new CloudComputeException('unauthorized', $this->refuse_v6_rdns);
		}
		$this->calls[] = "rdns {$ip} {$hostname}";
		$this->rdns[$ip] = $hostname;
		return array('ip' => $ip, 'rdns' => $hostname);
	}
	public function instanceFirewalls(string $id): array { return $this->firewalls[$id] ?? array(); }
	public function createInstance(array $opts): array { throw new CloudComputeException('not here'); }
	public function rebuildInstance(string $id, array $opts): array { throw new CloudComputeException('not here'); }
	public function deleteInstance(string $id): void { throw new CloudComputeException('never: the platform deletes no instance'); }
	public function getTransfer(): array { return array(); }
}

/** A provider that moves an address only between stopped servers (Hetzner). */
class IpSwapTestStoppedDriver extends IpSwapTestDriver implements CloudSwapWhileStopped {
	public function assignIpv4(string $region, array $assignments): void {
		foreach ($assignments as $ip => $inst) {
			foreach (array((string)$inst, (string)($this->owner[$ip] ?? '')) as $id) {
				if ($id !== '' && $this->status[$id] !== 'offline') {
					throw new CloudComputeException("server {$id} is {$this->status[$id]}");
				}
			}
		}
		parent::assignIpv4($region, $assignments);
	}
}

$fake = new IpSwapTestDriver();
$fake_reset = function () use ($fake, $S4, $T4) {
	$fake->status = array('11' => 'running', '12' => 'running');
	$fake->owner = array($S4 => '11', $T4 => '12');
	$fake->rdns = array($S4 => 'mail.example.org', $T4 => '');
	$fake->region = array('11' => 'us-east', '12' => 'us-east');
	$fake->helper = array('11' => true, '12' => true);
	$fake->firewalls = array('11' => array('7' => 'web-only'), '12' => array('7' => 'web-only'));
	$fake->v6 = array('11' => '2600:3c03::1', '12' => '2600:3c03::2');
	$fake->generation = array();
	$fake->calls = array();
	$fake->drop_rdns = false;
};
$fake_reset();
IpSwapMove::$driver_for = function ($p) use ($fake) { return $fake; };
$aaaa = array();
IpSwapMove::$aaaa_lookup = function ($domain) use (&$aaaa) { return $aaaa; };
SiteCopyRunner::$worker_starter = function ($id) {};

// The site's name answers as the copy once the copy holds the address and
// has restarted on it.
ProxiedOriginMove::$sleeper = function ($s) {};
$copy_answers = true;
ProxiedOriginMove::$asker = function ($url) use ($fake, $S4, $look, &$copy_answers) {
	$at_copy = $fake->owner[$S4] === '12' && $fake->status['12'] === 'running';
	return ($at_copy && $copy_answers)
		? array('status' => 303, 'set_cookie' => 'joinery_look=' . substr($look, 15) . '; Path=/', 'error' => '')
		: array('status' => 0, 'set_cookie' => '', 'error' => 'connection refused');
};

// ── Fixtures ───────────────────────────────────────────────────────────────
$mk_node = function (string $suffix, array $set) use ($tag) {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'HarnessTest SCI ' . $suffix);
	$n->set('mgn_slug', 'harnessscI-' . $suffix . '-' . $tag);
	$n->set('mgn_uptime_enabled', false);
	foreach ($set as $k => $v) {
		$n->set($k, $v);
	}
	$n->save();
	$n->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $n->key);
	return $n;
};
$mk_prov = function (string $suffix, ManagedNode $node, string $instance, string $ip) use ($tag) {
	$p = new CustomerCloudProvision(NULL);
	foreach (array('cvp_origin' => 'admin', 'cvp_usr_user_id' => 1, 'cvp_domain' => 'sci.example.org',
		'cvp_slug' => 'harnesssci-' . $suffix . '-' . $tag, 'cvp_status' => 'done', 'cvp_docker_mode' => 'bare-metal',
		'cvp_hosting_mode' => 'operator', 'cvp_provider' => 'linode', 'cvp_region' => 'us-east',
		'cvp_instance_id' => $instance, 'cvp_instance_ip' => $ip, 'cvp_mgn_managed_node_id' => (int)$node->key) as $k => $v) {
		$p->set($k, $v);
	}
	$p->save();
	$p->load();
	harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $p->key);
	return $p;
};
$finish = function ($job, string $status, ?array $result, string $error = '') {
	$job->set('mjb_status', $status);
	$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
	$job->set('mjb_error_message', $error);
	$job->set('mjb_result', $result === null ? null : json_encode($result));
	$job->save();
};
$census = function (int $rows) {
	return array('measured' => true, 'census' => array('version' => SiteCensus::VERSION,
		'tables' => array('usr_users' => 3, 'rql_request_logs' => $rows),
		'files' => array('uploads' => array('files' => 4, 'dirs' => 1, 'bytes' => 400, 'digest' => 'd1')),
		'secrets' => array('canary' => SecretBox::OPEN_OK, 'dead' => 0, 'present' => 3),
		'offloaded' => array('total' => 0, 'sampled' => 0, 'answered' => 0)));
};

$src = $mk_node('src', array('mgn_web_root' => '/var/www/html/scisite/public_html', 'mgn_site_url' => 'https://sci.example.org',
	'mgn_host' => $S4, 'mgn_agent_public_key' => $source_key, 'mgn_agent_version' => '1.54.0', 'mgn_agent_last_poll' => gmdate('Y-m-d H:i:s'),
	'mgn_agent_primitives' => 'copy_export,copy_vouch,site_census,site_quiet,backup_run,check_status',
	'mgn_joinery_version' => JobCommandBuilder::COPY_SOURCE_MIN_VERSION, 'mgn_last_status_data' => json_encode(array('backup_recovery_state' => 'proven')),
	'mgn_backup_recovery_fpr' => str_repeat('ab', 32), 'mgn_agent_server_manager' => 'inactive',
	'mgn_last_host_report' => json_encode(array('sites' => array('scisite')))));
// Its backups, on a target of the test's own.
sm_test_node_space($src);
$cnode = $mk_node('copy', array('mgn_web_root' => '/var/www/html/scisite/public_html', 'mgn_site_url' => 'https://sci.example.org',
	'mgn_host' => $T4, 'mgn_agent_public_key' => $copy_key, 'mgn_agent_version' => '1.54.0',
	'mgn_agent_primitives' => 'host_report,copy_import,copy_take_vouch,copy_stage,copy_restore,site_census,take_node_id,site_quiet',
	'mgn_install_state' => 'copy', 'mgn_copy_of_node_id' => (int)$src->key));
$sprov = $mk_prov('s', $src, '11', $S4);
$cprov = $mk_prov('c', $cnode, '12', $T4);

$chain_id = 'chain-' . gmdate('Ymd_His', time() - 7200);
$listing = array('chains' => array(array('chain_id' => $chain_id, 'profile' => BackupProfile::MANAGER, 'bytes' => 70000000,
	'runs' => array(array('seq' => 0, 'time' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
		'artifacts' => array('files' => 69000000, 'db' => 140000))))), 'objects' => array(), 'error' => null);
SiteCopyRunner::$chain_lister = function ($node) use (&$listing) { return $listing; };
SiteCopyRunner::$stage_builder = function ($node, $chain) {
	return array('primitive' => 'copy_stage', 'params' => array('chain_id' => $chain['chain_id'],
		'manifest_url' => 'https://x.invalid/m', 'artifact_urls' => array()));
};
JobCommandBuilder::set_shelf_listing_for_tests(array());

$mk_copy = function (ManagedNode $cnode) use ($src, $chain_id, $look) {
	$c = new SiteCopy(NULL);
	foreach (array('scp_source_node_id' => (int)$src->key, 'scp_copy_node_id' => (int)$cnode->key, 'scp_release' => JobCommandBuilder::COPY_SOURCE_MIN_VERSION,
		'scp_status' => SiteCopy::STATUS_DORMANT, 'scp_chain_id' => $chain_id, 'scp_look_path' => $look,
		'scp_last_copied_time' => gmdate('Y-m-d H:i:s')) as $k => $v) {
		$c->set($k, $v);
	}
	$c->save();
	harness_register_row('scp_site_copies', 'scp_site_copy_id', $c->key);
	return $c;
};

/**
 * Move a phase on: advance, and finish each queued job as the node would.
 * Local steps take no job; advancing again is what moves them (the worker's
 * part), so the loop just keeps going.
 */
$drive = function (SiteCopy $copy, array $statuses, array $by_op = array()) use ($finish, $src, $census, $chain_id) {
	$defaults = array(
		'backup_run@source'  => array('completed', array('backup_status' => 'success')),
		'copy_vouch@source'  => array('completed', array('vouched' => true, 'vouch' => '{"body":"v","signature":"s"}', 'chain_id' => $chain_id)),
		'site_census@source' => array('completed', $census(80)),
		'site_census@copy'   => array('completed', $census(80)),
	);
	$by_op = array_merge($defaults, $by_op);
	for ($guard = 0; $guard < 60 && in_array($copy->status(), $statuses, true); $guard++) {
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
		$r = $by_op[$key] ?? array('completed', array('done' => true));
		if ($current['op'] === 'take_node_id' && $r[0] === 'completed') {
			$job->set('mjb_status', 'completed');
			$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
			$job->set('mjb_output', "=== [Step 1/1] take_node_id ===\n" . json_encode(array('api_version' => '1.0',
				'data' => array('staged' => true, 'node_id' => (int)$src->key))));
			$job->save();
			JobResultProcessor::process($job);
			$job->load();
			JobResultProcessor::complete_take_node_id($job);
		} else {
			$finish($job, $r[0], $r[1], $r[2] ?? '');
		}
		$job->load();
		SiteCopyRunner::job_finished($job);
		$copy->load();
	}
};
$ops_of = function (SiteCopy $copy) {
	return array_map(function ($s) { return $s['op'] . (!empty($s['arg']) ? ':' . $s['arg'] : '') . '=' . $s['verdict']; }, $copy->steps());
};

// ---------------------------------------------------------------------------
section('What the swap needs, checked before anything changes');

$copy = $mk_copy($cnode);
check(empty(SiteCopyRunner::switch_methods($copy)[SiteCopyRunner::METHOD_IP_SWAP]),
	'two operator servers in one region can swap, from this management node\'s own records');

$cprov->set('cvp_region', 'eu-central');
$cprov->save();
$m = SiteCopyRunner::switch_methods($copy)[SiteCopyRunner::METHOD_IP_SWAP];
check($m && strpos(implode(' ', $m), 'one region') !== false, 'servers in two regions cannot', implode(' ', $m));
$cprov->set('cvp_region', 'us-east');
$cprov->save();

$refuse = function (string $what, callable $set, string $needle) use ($copy, $src, $cnode, $fake_reset, &$aaaa) {
	$fake_reset();
	$aaaa = array();
	$set();
	$threw = '';
	try {
		SiteCopyRunner::begin_switch($copy, SiteCopyRunner::METHOD_IP_SWAP, null, null);
	} catch (Exception $e) {
		$threw = $e->getMessage();
	}
	$copy->load();
	$src->load();
	check(strpos($threw, $needle) !== false && $copy->status() === SiteCopy::STATUS_DORMANT
		&& trim((string)$src->get('mgn_install_state')) === '', $what . ': refused, and nothing is frozen', $threw);
};
$refuse('Network Helper off on the copy', function () use ($fake) { $fake->helper['12'] = false; }, 'Network Helper is off for the copy');
$refuse('the copy\'s server on Linode Interfaces (B8)', function () use ($fake) { $fake->generation['12'] = 'linode'; }, 'The copy\'s server uses Linode Interfaces');
$refuse('a second public IPv4 on the site\'s server', function () use ($fake) { $fake->owner['192.0.2.99'] = '11'; }, 'exactly one');
$refuse('a firewall on the site\'s server the copy lacks', function () use ($fake) { $fake->firewalls['12'] = array(); }, 'web-only');
$refuse('an AAAA record naming the site\'s server\'s IPv6', function () use (&$aaaa) { $aaaa = array('2600:3c03::1'); },
	'IPv6 addresses do not move. Point it at the copy\'s IPv6 (2600:3c03::2) before switching, or remove it');
// B58: the swap restarts the copy, and an encrypted data root no escrow holder
// opens would wait there for its recovery key.
$cnode_report = function (string $holder) use ($cnode) {
	$cnode->set('mgn_last_host_report', json_encode(array('data_root_encryption' => array('backing' => 'luks-file', 'open' => true,
		'holder' => $holder, 'slot' => $holder === '' ? 'unknown' : 0))));
	$cnode->save();
};
$refuse('the copy\'s encrypted data root with no escrow holder (B58)', function () use ($cnode_report) { $cnode_report(''); },
	'waiting for its recovery key');
$m = SiteCopyRunner::switch_methods($copy)[SiteCopyRunner::METHOD_IP_SWAP];
check($m && strpos(implode(' ', $m), 'no management node holds its unlock key') !== false, 'and the page says why the swap is not offered');
$cnode_report('https://mgr.example.org');
check(empty(SiteCopyRunner::switch_methods($copy)[SiteCopyRunner::METHOD_IP_SWAP]), 'once a holder opens it, the swap is offered again');
// B62: enrolled here since its last report, which still names no holder.
$c_identity = base64_encode(random_bytes(32));
$cnode->set('mgn_last_host_report', json_encode(array('data_root_encryption' => array('backing' => 'luks-file', 'open' => true,
	'identity' => $c_identity, 'holder' => '', 'slot' => 'unknown'))));
$cnode->save();
$c_row = new DataRootEscrow(NULL);
$c_row->set('dre_identity_key', base64_encode(random_bytes(32)));
$c_row->set('dre_status', 'enrolled');
$c_row->set('dre_hostname', 'HarnessTest ip swap copy');
$c_row->set('dre_mgn_managed_node_id', $cnode->key);
$c_row->save();
harness_register_row('dre_data_root_escrows', 'dre_data_root_escrow_id', $c_row->key);
check(!IpSwapMove::opens_by_itself($cnode), 'a row held here for another machine\'s identity is no proof it opens (B62)');
$c_row->set('dre_identity_key', $c_identity);
$c_row->set('dre_status', 'known');
$c_row->save();
check(!IpSwapMove::opens_by_itself($cnode), 'nor is its own row before it enrols');
$c_row->set('dre_status', 'enrolled');
$c_row->save();
check(IpSwapMove::opens_by_itself($cnode) && empty(SiteCopyRunner::switch_methods($copy)[SiteCopyRunner::METHOD_IP_SWAP]),
	'enrolled here, it opens by itself and the swap is offered before its next host report names the holder (B62)');
$c_row->permanent_delete();
$cnode->set('mgn_last_host_report', null);
$cnode->save();
$fake_reset();
$aaaa = array();
check($fake->calls === array(), 'checking calls nothing that changes a server');

// ---------------------------------------------------------------------------
section('Switching over by IP swap');

SiteCopyRunner::begin_switch($copy, SiteCopyRunner::METHOD_IP_SWAP, null, null);
$copy->load();
$sw = $copy->switch_record();
check(($sw['method'] ?? '') === 'ip_swap' && ($sw['swap']['source']['ip'] ?? '') === $S4 && ($sw['swap']['copy']['ip'] ?? '') === $T4
	&& ($sw['swap']['source']['rdns'] ?? '') === 'mail.example.org' && ($sw['swap']['domain'] ?? '') === 'sci.example.org',
	'the plan records each machine, its address, its reverse DNS, and the site\'s name');
$drive($copy, array(SiteCopy::STATUS_FREEZING));
check($copy->status() === SiteCopy::STATUS_READY, 'the final copy runs as for any switch-over, and the copy is ready', $copy->status());
check($fake->calls === array(), 'the servers are untouched until the move');

SiteCopyRunner::move_address($copy, null, null);
$copy->load();
$drive($copy, array(SiteCopy::STATUS_STARTING));
$src->load();
$cnode->load();
$sprov->load();
$cprov->load();
check($copy->status() === SiteCopy::STATUS_SWITCHED, 'the copy takes the address, answers at the site\'s name, takes the node and starts',
	$copy->status() . ' ' . $copy->get('scp_halt_reason') . ' ' . implode(' ', $ops_of($copy)));
check($fake->owner[$S4] === '12' && $fake->owner[$T4] === '11', 'the site\'s address is on the copy\'s machine, and the copy\'s on the old one');
check($fake->status['11'] === 'offline' && $fake->status['12'] === 'running', 'the old server is off, and the copy\'s runs');
check($fake->rdns[$S4] === 'mail.example.org', 'the site\'s address kept its reverse DNS');
check(!preg_grep('/^rdns 2600:/', $fake->calls) && (string)($copy->switch_record()['rdns_note'] ?? '')
	=== 'Reverse DNS for 2600:3c03::2 was not set: no AAAA record of sci.example.org names it. Point the AAAA at it, then '
	. 'keep the switch-over, which tries again.',
	'with no AAAA record giving the copy\'s IPv6, its reverse DNS is left alone, and the Copy tab says what to do (B45)',
	json_encode($fake->calls) . ' ' . ($copy->switch_record()['rdns_note'] ?? ''));
check(array_slice($fake->calls, 0, 3) === array('shutdown 11', 'assign {"' . $S4 . '":"12","' . $T4 . '":"11"}', 'reboot 12'),
	'in order: the old server off, the swap, the copy restarted on its new address', json_encode($fake->calls));
check($cprov->get('cvp_instance_ip') === $S4 && $sprov->get('cvp_instance_ip') === $T4,
	'each provision records the address its machine holds now');
check($src->get('mgn_host') === $S4 && $src->get('mgn_agent_public_key') === $copy_key,
	'the node is the copy\'s machine, at the site\'s address');
check($cnode->get('mgn_host') === $T4 && trim((string)$cnode->get('mgn_install_state')) === 'retired',
	'the retired row is the old machine, at the address it holds now');

// ---------------------------------------------------------------------------
section('The way back after the switch');

// B63/B64: the owner pointed the site's AAAA at the copy, and this holder
// holds the key of the copy's machine: on the site's node while the copy is
// the site, on the copy row once the rows swap back.
$aaaa = array('2600:3c03::2');
$back_row = new DataRootEscrow(NULL);
$back_row->set('dre_identity_key', base64_encode(random_bytes(32)));
$back_row->set('dre_status', 'enrolled');
$back_row->set('dre_hostname', 'HarnessTest ip swap back');
$back_row->set('dre_mgn_managed_node_id', $src->key);
$back_row->save();
$old_row = new DataRootEscrow(NULL);
$old_row->set('dre_identity_key', base64_encode(random_bytes(32)));
$old_row->set('dre_status', 'enrolled');
$old_row->set('dre_hostname', 'HarnessTest ip swap old');
$old_row->set('dre_mgn_managed_node_id', $cnode->key);
$old_row->save();
harness_register_row('dre_data_root_escrows', 'dre_data_root_escrow_id', $old_row->key);
harness_register_row('dre_data_root_escrows', 'dre_data_root_escrow_id', $back_row->key);
SiteCopyRunner::go_back($copy, null);
$copy->load();
$drive($copy, array(SiteCopy::STATUS_RETURNING));
$src->load();
$sprov->load();
$cprov->load();
check($copy->status() === SiteCopy::STATUS_DISCARDED, 'the copy is discarded and the site runs on its own server again',
	$copy->status() . ' ' . $copy->get('scp_halt_reason') . ' ' . implode(' ', $ops_of($copy)));
check($fake->owner[$S4] === '11' && $fake->owner[$T4] === '12', 'each address is back on its own machine');
check($fake->status['11'] === 'running', 'the old server runs again');
check($src->get('mgn_agent_public_key') === $source_key && $src->get('mgn_host') === $S4
	&& $sprov->get('cvp_instance_ip') === $S4 && $cprov->get('cvp_instance_ip') === $T4,
	'the node is the old machine at its own address, and the provisions say so');
check(trim((string)$src->get('mgn_install_state')) === '', 'the site is no longer switching');
$ended = SiteCopy::recently_ended_for_source((int)$src->key);
$notes = $ended ? implode(' ', SiteCopyRunner::went_back_notes($ended->switch_record())) : '';
check($ended && (int)$ended->key === (int)$copy->key && strpos($notes, 'AAAA record for the copy\'s IPv6 address 2600:3c03::2') !== false,
	'discarded, the copy still carries what going back noted, for the ended-copy banner (B63)', $notes);
// The owner points the record at the site's own server: the banner stops naming it (B66).
$aaaa = array('2600:3c03::1');
$notes = implode(' ', SiteCopyRunner::went_back_notes($ended->switch_record()));
check(strpos($notes, 'AAAA') === false && !empty($ended->switch_record()['aaaa_left']),
	'once the AAAA record no longer names the copy, the banner no longer says it does (B66)', $notes);
$aaaa = array('2600:3c03::2');
$back_row = new DataRootEscrow($back_row->key, TRUE);
$old_row = new DataRootEscrow($old_row->key, TRUE);
check((string)$back_row->get('dre_status') === 'retired' && (int)$back_row->get('dre_mgn_managed_node_id') === (int)$cnode->key,
	'and the key held for the discarded copy\'s machine is retired (B64)',
	(string)$back_row->get('dre_status') . ' node ' . $back_row->get('dre_mgn_managed_node_id'));
check((string)$old_row->get('dre_status') === 'enrolled' && (int)$old_row->get('dre_mgn_managed_node_id') === (int)$src->key,
	'while the site\'s own machine, back on its node, keeps its key', (string)$old_row->get('dre_status'));
$aaaa = array();

// ---------------------------------------------------------------------------
section('A copy that does not answer: the swap is undone');

$cnode2 = $mk_node('copy2', array('mgn_web_root' => '/var/www/html/scisite/public_html', 'mgn_site_url' => 'https://sci.example.org',
	'mgn_host' => $T4, 'mgn_agent_public_key' => base64_encode(random_bytes(32)), 'mgn_agent_version' => '1.54.0',
	'mgn_agent_primitives' => 'host_report,copy_import,copy_take_vouch,copy_stage,copy_restore,site_census,take_node_id,site_quiet',
	'mgn_install_state' => 'copy', 'mgn_copy_of_node_id' => (int)$src->key));
// The discard above took the first copy row's provision with it; this copy
// row is the same machine's.
$cprov = $mk_prov('c2', $cnode2, '12', $T4);
$fake_reset();
$fake->drop_rdns = true;
// The owner pointed the site's AAAA record at the copy's IPv6 before switching.
$aaaa = array('2600:3c03::2');
$copy2 = $mk_copy($cnode2);
SiteCopyRunner::begin_switch($copy2, SiteCopyRunner::METHOD_IP_SWAP, null, null);
$copy2->load();
$drive($copy2, array(SiteCopy::STATUS_FREEZING));
$copy_answers = false;
SiteCopyRunner::move_address($copy2, null, null);
$copy2->load();
// The probe gives up after its minutes: wind its clock back instead of waiting.
for ($i = 0; $i < 40 && $copy2->status() === SiteCopy::STATUS_STARTING; $i++) {
	SiteCopyRunner::advance($copy2);
	$copy2->load();
	$steps = $copy2->steps();
	foreach ($steps as $k => $s) {
		if ($s['op'] === 'probe' && $s['verdict'] === 'running') {
			$steps[$k]['state']['started'] = time() - 3600;
			$copy2->set_steps($steps);
			$copy2->save();
		}
	}
}
$sw = $copy2->switch_record();
check(in_array('rdns ' . $S4 . ' mail.example.org', $fake->calls, true),
	'a reverse DNS the provider dropped in the move is set back', json_encode($fake->calls));
$drive($copy2, array(SiteCopy::STATUS_UNDOING));
$src->load();
check($copy2->status() === SiteCopy::STATUS_READY && strpos((string)($copy2->switch_record()['move_failure'] ?? ''), 'probe') === 0,
	'the copy did not answer, so the move is undone and the copy is ready again, saying why',
	$copy2->status() . ' ' . json_encode($copy2->switch_record()['move_failure'] ?? null) . ' ' . implode(' ', $ops_of($copy2)));
check($fake->owner[$S4] === '11' && $fake->status['11'] === 'running' && $fake->status['12'] === 'running',
	'each address is back, the old server runs (still frozen), and the copy runs on its own address');
check(trim((string)$src->get('mgn_install_state')) === 'switching' && ($copy2->switch_record()['address_at'] ?? '') === 'source',
	'the site is still frozen, its address at its own server');
$notes = implode(' ', SiteCopyRunner::went_back_notes($copy2->switch_record()));
check(strpos($notes, 'AAAA record for the copy\'s IPv6 address 2600:3c03::2') !== false && strpos($notes, '2600:3c03::1') !== false,
	'the undo says the AAAA record still names the copy\'s IPv6, and where to point it back (B61)', $notes);
$aaaa = array();
SiteCopyRunner::go_back($copy2, null);
$copy2->load();
$drive($copy2, array(SiteCopy::STATUS_RETURNING));
$src->load();
check($copy2->status() === SiteCopy::STATUS_DORMANT && trim((string)$src->get('mgn_install_state')) === '',
	'going back from there: the site runs, and the copy stays dormant', $copy2->status() . ' ' . $copy2->get('scp_halt_reason'));

// ---------------------------------------------------------------------------
section('A provider that moves an address only between stopped servers');

SiteCopyRunner::discard($copy2);
$stopped = new IpSwapTestStoppedDriver();
$fake_reset();
foreach (get_object_vars($fake) as $k => $v) {
	$stopped->$k = $v;
}
IpSwapMove::$driver_for = function ($p) use ($stopped) { return $stopped; };
ProxiedOriginMove::$asker = function ($url) use ($stopped, $S4, $look) {
	return ($stopped->owner[$S4] === '12' && $stopped->status['12'] === 'running')
		? array('status' => 303, 'set_cookie' => 'joinery_look=' . substr($look, 15) . '; Path=/', 'error' => '')
		: array('status' => 0, 'set_cookie' => '', 'error' => 'connection refused');
};
$cnode3 = $mk_node('copy3', array('mgn_web_root' => '/var/www/html/scisite/public_html', 'mgn_site_url' => 'https://sci.example.org',
	'mgn_host' => $T4, 'mgn_agent_public_key' => base64_encode(random_bytes(32)), 'mgn_agent_version' => '1.54.0',
	'mgn_agent_primitives' => 'host_report,copy_import,copy_take_vouch,copy_stage,copy_restore,site_census,take_node_id,site_quiet',
	'mgn_install_state' => 'copy', 'mgn_copy_of_node_id' => (int)$src->key));
$mk_prov('c3', $cnode3, '12', $T4);
$copy3 = $mk_copy($cnode3);
// The owner points the site's AAAA at the copy's IPv6 before switching, as
// the plan asks: the one address no swap moves (B45, found on Hetzner).
$aaaa = array('2600:3c03::2');
SiteCopyRunner::begin_switch($copy3, SiteCopyRunner::METHOD_IP_SWAP, null, null);
$copy3->load();
check(!empty($copy3->switch_record()['swap']['both_off']), 'the plan says the copy is powered off for the swap too');
$drive($copy3, array(SiteCopy::STATUS_FREEZING));
SiteCopyRunner::move_address($copy3, null, null);
$copy3->load();
$drive($copy3, array(SiteCopy::STATUS_STARTING));
check($copy3->status() === SiteCopy::STATUS_SWITCHED && array_slice($stopped->calls, 0, 4) === array('shutdown 11', 'shutdown 12',
	'assign {"' . $S4 . '":"12","' . $T4 . '":"11"}', 'boot 12'),
	'both servers off, the swap, then the copy on again at its new address', $copy3->status() . ' ' . json_encode($stopped->calls));
check(($stopped->rdns['2600:3c03::2'] ?? '') === 'sci.example.org' && in_array('rdns 2600:3c03::2 sci.example.org', $stopped->calls, true),
	'switched, the copy\'s IPv6, which the AAAA gives, answers the site\'s name in reverse DNS (B45)', json_encode($stopped->calls));
check($stopped->rdns[$S4] === 'mail.example.org' && !preg_grep('/^rdns ' . preg_quote($S4, '/') . ' /', $stopped->calls),
	'and the moved IPv4 keeps the reverse DNS it brought with it', json_encode($stopped->calls));
check((string)($copy3->switch_record()['rdns_note'] ?? '') === 'Reverse DNS: 2600:3c03::2 now answers sci.example.org.',
	'the Copy tab says so', (string)($copy3->switch_record()['rdns_note'] ?? ''));
$aaaa = array();
$stopped->calls = array();
SiteCopyRunner::go_back($copy3, null);
$copy3->load();
$drive($copy3, array(SiteCopy::STATUS_RETURNING));
check($stopped->owner[$S4] === '11' && in_array('shutdown 12', $stopped->calls, true)
	&& array_search('shutdown 12', $stopped->calls) < array_search('assign {"' . $S4 . '":"11","' . $T4 . '":"12"}', $stopped->calls),
	'the way back powers the copy off before swapping back', json_encode($stopped->calls));

// ---------------------------------------------------------------------------
section('An AAAA pointed at the copy after the switch: Keep names the IPv6');

$fake_reset();
foreach (get_object_vars($fake) as $k => $v) {
	$stopped->$k = $v;
}
$cnode4 = $mk_node('copy4', array('mgn_web_root' => '/var/www/html/scisite/public_html', 'mgn_site_url' => 'https://sci.example.org',
	'mgn_host' => $T4, 'mgn_agent_public_key' => base64_encode(random_bytes(32)), 'mgn_agent_version' => '1.54.0',
	'mgn_agent_primitives' => 'host_report,copy_import,copy_take_vouch,copy_stage,copy_restore,site_census,take_node_id,site_quiet',
	'mgn_install_state' => 'copy', 'mgn_copy_of_node_id' => (int)$src->key));
$mk_prov('c4', $cnode4, '12', $T4);
$copy4 = $mk_copy($cnode4);
// The owner removed the site's AAAA to get past the plan's refusal.
$aaaa = array();
SiteCopyRunner::begin_switch($copy4, SiteCopyRunner::METHOD_IP_SWAP, null, null);
$copy4->load();
$drive($copy4, array(SiteCopy::STATUS_FREEZING));
SiteCopyRunner::move_address($copy4, null, null);
$copy4->load();
$drive($copy4, array(SiteCopy::STATUS_STARTING));
$note4 = (string)($copy4->switch_record()['rdns_note'] ?? '');
check($copy4->status() === SiteCopy::STATUS_SWITCHED && !preg_grep('/^rdns 2600:/', $stopped->calls)
	&& strpos($note4, 'keep the switch-over, which tries again') !== false,
	'switched with no AAAA, the copy\'s IPv6 is not named, and the Copy tab says Keep tries again',
	$copy4->status() . ' ' . $copy4->get('scp_halt_reason') . ' ' . $note4);
check(strpos(IpSwapMove::name_copy_ipv6((array)$copy4->switch_record()['swap'], true),
	'use Set Reverse DNS on the Overview tab with sci.example.org for 2600:3c03::2 only') !== false,
	'kept with still no AAAA naming it, the line says to name the IPv6 alone with Set Reverse DNS (B70)');
// Then the owner points the AAAA at the copy's IPv6, and keeps the switch-over.
$aaaa = array('2600:3c03::2');
SiteCopyRunner::finish($copy4);
$copy4->load();
$note4 = (string)($copy4->switch_record()['rdns_note'] ?? '');
check($copy4->status() === SiteCopy::STATUS_FINISHED && ($stopped->rdns['2600:3c03::2'] ?? '') === 'sci.example.org'
	&& $note4 === 'Reverse DNS: 2600:3c03::2 now answers sci.example.org.' && IpSwapMove::copy_ipv6_named($note4),
	'keeping it names the copy\'s IPv6 then, and says so', $copy4->status() . ' ' . $note4 . ' ' . json_encode($stopped->calls));
check($stopped->rdns[$S4] === 'mail.example.org' && !preg_grep('/^rdns ' . preg_quote($S4, '/') . ' /', $stopped->calls),
	'and the moved IPv4 keeps its reverse DNS', json_encode($stopped->calls));
// A provider refusing the credential: the line says what to fix, not only
// what the provider answered.
$stopped->refuse_v6_rdns = 401;
$note4 = IpSwapMove::name_copy_ipv6((array)$copy4->switch_record()['swap'], true);
$stopped->refuse_v6_rdns = 0;
check(strpos($note4, 'Reverse DNS was not set for 2600:3c03::2: The provider refused the credential for ') === 0
	&& strpos($note4, 'Replace it on the Hosting tab of Provisioning Setup') !== false && !IpSwapMove::copy_ipv6_named($note4),
	'a provider refusing the credential: the line says where to replace it (B70 N1)', $note4);
$aaaa = array();

SiteCopyRunner::$chain_lister = null;
SiteCopyRunner::$stage_builder = null;
SiteCopyRunner::$worker_starter = null;
IpSwapMove::$driver_for = null;
IpSwapMove::$aaaa_lookup = null;
ProxiedOriginMove::$asker = null;
ProxiedOriginMove::$sleeper = null;
JobCommandBuilder::set_shelf_listing_for_tests(null);
DbConnector::get_instance()->get_db_link()->query('SELECT pg_advisory_unlock(' . SiteCopyRunner::LOCK_KEY . ')');
harness_finish();
