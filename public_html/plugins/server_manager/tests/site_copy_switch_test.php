<?php
/** @joinery-test
 * name: site_copy_switch
 * tier: db
 * env: dev-only
 * needs: []
 */

/**
 * site_copy_switch (specs/site_copy.md WP7a, B44): the switch-over by a
 * proxied origin change, and the way back.
 *
 * The Cloudflare driver runs for real against a fake Cloudflare API held in
 * this file, so its proxied listing and its PATCH are what is tested. Agent
 * jobs are finished by hand, as site_copy_runner does; the proof through the
 * proxy is a stand-in that answers as the copy or as the frozen site.
 *
 * @version 1.1 - a container site moves only its own names
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/dns/drivers/CloudflareDnsDriver.php'));

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

$tag = bin2hex(random_bytes(3));
$source_key = base64_encode(random_bytes(32));
$copy_key = base64_encode(random_bytes(32));
$look = '/.joinery-look/0123456789abcdef0123456789abcdef';
$S4 = '192.0.2.63';
$T4 = '198.51.100.70';
$T6 = '2001:db8::70';

// ── A fake Cloudflare: one zone, records in memory, every write logged ──────
$cf = array(
	'records' => array(),
	'writes'  => array(),
	'fail_patch_of' => '',
);
$cf_reset = function (array $records) use (&$cf) {
	$cf['records'] = array();
	foreach ($records as $i => $r) {
		$cf['records']['r' . $i] = array_merge(array('id' => 'r' . $i, 'ttl' => 1), $r);
	}
	$cf['writes'] = array();
	$cf['fail_patch_of'] = '';
};
$cf_handler = function (RequestInterface $req, array $opts) use (&$cf) {
	$path = $req->getUri()->getPath();
	$json = function ($body, int $status = 200) {
		return Create::promiseFor(new Response($status, array('Content-Type' => 'application/json'), json_encode($body)));
	};
	if ($req->getMethod() === 'GET' && preg_match('#/zones$#', $path)) {
		return $json(array('success' => true, 'result' => array(array('id' => 'z1', 'name' => 'example.org',
			'name_servers' => array('ada.ns.cloudflare.com'))), 'result_info' => array('total_pages' => 1)));
	}
	if ($req->getMethod() === 'GET' && preg_match('#/zones/z1/dns_records$#', $path)) {
		return $json(array('success' => true, 'result' => array_values($cf['records']), 'result_info' => array('total_pages' => 1)));
	}
	if ($req->getMethod() === 'PATCH' && preg_match('#/zones/z1/dns_records/([a-z0-9]+)$#', $path, $m)) {
		$body = json_decode((string)$req->getBody(), true);
		$cf['writes'][] = array('id' => $m[1], 'body' => $body);
		if ($cf['fail_patch_of'] === $m[1]) {
			return Create::rejectionFor(new GuzzleHttp\Exception\ServerException('500', $req,
				new Response(500, array(), '{"success":false,"errors":[{"message":"boom"}]}')));
		}
		$cf['records'][$m[1]]['content'] = $body['content'];
		return $json(array('success' => true, 'result' => $cf['records'][$m[1]]));
	}
	return $json(array('success' => false), 404);
};
$driver = function () use ($cf_handler) {
	return new CloudflareDnsDriver(array('api_token' => 'test-token'),
		new Client(array('handler' => HandlerStack::create($cf_handler), 'http_errors' => true)));
};
$site_records = function () use ($S4) {
	return array(
		array('type' => 'A', 'name' => 'scp.example.org', 'content' => $S4, 'proxied' => true),
		array('type' => 'A', 'name' => 'www.scp.example.org', 'content' => $S4, 'proxied' => true),
		array('type' => 'A', 'name' => 'other.example.org', 'content' => '203.0.113.9', 'proxied' => false),
		array('type' => 'TXT', 'name' => 'scp.example.org', 'content' => 'v=spf1 -all'),
	);
};

// ── The proof: the proxy answers as whichever machine the record names ─────
$asks = array();
ProxiedOriginMove::$sleeper = function ($s) {};
$proxy_reaches = function (bool $copy) use (&$asks, $look) {
	ProxiedOriginMove::$asker = function ($url) use (&$asks, $copy, $look) {
		$asks[] = $url;
		return $copy
			? array('status' => 303, 'set_cookie' => 'joinery_look=' . substr($look, 15) . '; Path=/', 'error' => '')
			: array('status' => 503, 'set_cookie' => '', 'error' => '');
	};
};

// ── Fixtures ───────────────────────────────────────────────────────────────
$mk_node = function (string $suffix, array $set) use ($tag) {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'HarnessTest SCW ' . $suffix);
	$n->set('mgn_slug', 'harnessscw-' . $suffix . '-' . $tag);
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
$census = function (int $rows) {
	return array('measured' => true, 'census' => array('version' => SiteCensus::VERSION,
		'tables' => array('usr_users' => 3, 'rql_request_logs' => $rows),
		'files' => array('uploads' => array('files' => 4, 'dirs' => 1, 'bytes' => 400, 'digest' => 'd1')),
		'secrets' => array('canary' => SecretBox::OPEN_OK, 'dead' => 0, 'present' => 3),
		'offloaded' => array('total' => 0, 'sampled' => 0, 'answered' => 0)));
};

$src = $mk_node('src', array('mgn_web_root' => '/var/www/html/scwsite/public_html', 'mgn_site_url' => 'https://scp.example.org',
	'mgn_host' => $S4, 'mgn_agent_public_key' => $source_key, 'mgn_agent_version' => '1.53.0',
	'mgn_agent_primitives' => 'copy_export,copy_vouch,site_census,site_quiet,backup_run,check_status',
	'mgn_joinery_version' => '0.8.453', 'mgn_last_status_data' => json_encode(array('backup_recovery_state' => 'proven')),
	'mgn_backup_recovery_fpr' => str_repeat('ab', 32), 'mgn_agent_server_manager' => 'inactive'));
if (!JobCommandBuilder::get_target($src)) {
	harness_skip('the switch-over', 'no enabled backup target on this management node');
	harness_finish();
	return;
}
$cnode = $mk_node('copy', array('mgn_web_root' => '/var/www/html/scwsite/public_html', 'mgn_site_url' => 'https://scp.example.org',
	'mgn_host' => $T4, 'mgn_agent_public_key' => $copy_key, 'mgn_agent_version' => '1.53.0',
	'mgn_agent_primitives' => 'host_report,copy_import,copy_take_vouch,copy_stage,copy_restore,site_census,take_node_id,site_quiet',
	'mgn_install_state' => 'copy', 'mgn_copy_of_node_id' => (int)$src->key));
// The copy's agent reported its IPv6 address when it joined.
$ajr = new AgentJoinRequest(NULL);
foreach (array('ajr_claimed_name' => 'scwsite', 'ajr_public_key' => $copy_key,
	'ajr_fingerprint' => AgentJoinRequest::fingerprint(base64_decode($copy_key)), 'ajr_source_ip' => $T4,
	'ajr_addresses' => $T4 . ',' . $T6, 'ajr_status' => 'approved', 'ajr_mgn_managed_node_id' => (int)$cnode->key) as $k => $v) {
	$ajr->set($k, $v);
}
$ajr->save();
harness_register_row('ajr_agent_join_requests', 'ajr_agent_join_request_id', $ajr->key);

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
	foreach (array('scp_source_node_id' => (int)$src->key, 'scp_copy_node_id' => (int)$cnode->key, 'scp_release' => '0.8.453',
		'scp_status' => SiteCopy::STATUS_DORMANT, 'scp_chain_id' => $chain_id, 'scp_look_path' => $look,
		'scp_last_copied_time' => gmdate('Y-m-d H:i:s')) as $k => $v) {
		$c->set($k, $v);
	}
	$c->save();
	harness_register_row('scp_site_copies', 'scp_site_copy_id', $c->key);
	return $c;
};

/**
 * Move a phase to its end: finish each queued step's job with what that op
 * returns, as the node's result would, until the copy leaves $status. A
 * take_node_id result goes the way the agent endpoint takes it: recorded,
 * answered with the swap, then the copy moves on.
 */
$drive = function (SiteCopy $copy, string $status, array $by_op) use ($finish, $src) {
	$ops = array();
	for ($guard = 0; $guard < 20 && $copy->status() === $status; $guard++) {
		SiteCopyRunner::advance($copy);
		$copy->load();
		$current = null;
		foreach ($copy->steps() as $s) {
			if ($s['verdict'] === 'running') {
				$current = $s;
				break;
			}
		}
		if (!$current) {
			continue;
		}
		$job = new ManagementJob((int)$current['job_id'], TRUE);
		harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $job->key);
		$ops[] = $job->get('mjb_job_type') . (!empty($current['arg']) ? ':' . $current['arg'] : '') . '@' . $current['on'];
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
	return $ops;
};
$freeze_results = function () use ($census, $chain_id) {
	return array(
		'backup_run@source'  => array('completed', array('backup_status' => 'success')),
		'copy_vouch@source'  => array('completed', array('vouched' => true, 'vouch' => '{"body":"v","signature":"s"}',
			'chain_id' => $chain_id)),
		'site_census@source' => array('completed', $census(80)),
		'site_census@copy'   => array('completed', $census(80)),
	);
};

// ---------------------------------------------------------------------------
section('The records a proxied move takes');

$cf_reset($site_records());
$plan = ProxiedOriginMove::plan($driver(), 'scp.example.org', $src, $cnode);
check($plan['zone'] === 'example.org' && count($plan['records']) === 2
	&& $plan['records'][0]['from'] === $S4 && $plan['records'][0]['to'] === $T4,
	'every proxied A record naming the site\'s server moves to the copy\'s address; others are not touched',
	json_encode($plan));
check(empty($cf['writes']), 'and planning writes nothing');

$cases = array(
	'an unproxied record naming the server' => array(array('type' => 'A', 'name' => 'mail.example.org', 'content' => $S4,
		'proxied' => false), 'not proxied'),
	'the site\'s name pointing elsewhere' => array(array('type' => 'A', 'name' => 'scp.example.org', 'content' => '203.0.113.50',
		'proxied' => true), 'not an address this management node knows'),
);
foreach ($cases as $what => $c) {
	$cf_reset(array_merge($site_records(), array($c[0])));
	$threw = '';
	try { ProxiedOriginMove::plan($driver(), 'scp.example.org', $src, $cnode); } catch (Exception $e) { $threw = $e->getMessage(); }
	check(strpos($threw, $c[1]) !== false, 'refused: ' . $what, $threw);
}
// An AAAA naming the source, when the copy reported IPv6, moves to it.
$src_prov = new CustomerCloudProvision(NULL);
foreach (array('cvp_origin' => 'admin', 'cvp_usr_user_id' => 1, 'cvp_domain' => 'scp.example.org',
	'cvp_slug' => 'harnessscw-sprov-' . $tag, 'cvp_status' => 'done', 'cvp_docker_mode' => 'bare-metal',
	'cvp_instance_ip' => $S4, 'cvp_instance_ipv6' => '2001:db8::63', 'cvp_mgn_managed_node_id' => (int)$src->key) as $k => $v) {
	$src_prov->set($k, $v);
}
$src_prov->save();
harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $src_prov->key);
$cf_reset(array_merge($site_records(), array(array('type' => 'AAAA', 'name' => 'scp.example.org',
	'content' => '2001:DB8:0:0::63', 'proxied' => true))));
$plan = ProxiedOriginMove::plan($driver(), 'scp.example.org', $src, $cnode);
$aaaa = array_values(array_filter($plan['records'], function ($r) { return $r['type'] === 'AAAA'; }));
check(count($aaaa) === 1 && $aaaa[0]['to'] === $T6, 'an AAAA naming the server (compared as an address, not as text) moves to the '
	. 'copy\'s IPv6 address, from its join', json_encode($plan['records']));
$ajr->set('ajr_addresses', $T4);
$ajr->save();
$threw = '';
try { ProxiedOriginMove::plan($driver(), 'scp.example.org', $src, $cnode); } catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'no IPv6 address for the copy') !== false, 'with no IPv6 address for the copy, the AAAA refuses the move', $threw);
$ajr->set('ajr_addresses', $T4 . ',' . $T6);
$ajr->save();

// A container site shares its server's address with the other sites there.
// Their records name the same address, proxied or not, and are not its own.
$siblings = array_merge($site_records(), array(
	array('type' => 'A', 'name' => 'demo.example.org', 'content' => $S4, 'proxied' => true),
	array('type' => 'A', 'name' => 'developers.example.org', 'content' => $S4, 'proxied' => false),
));
$cf_reset($siblings);
$threw = '';
try { ProxiedOriginMove::plan($driver(), 'scp.example.org', $src, $cnode); } catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'developers.example.org') !== false, 'a bare-metal site\'s server is its own: another unproxied name '
	. 'on its address refuses the move', $threw);
$in_container = new ManagedNode($src->key, TRUE);
$in_container->set('mgn_container_name', 'scpsite');
$cf_reset($siblings);
$plan = ProxiedOriginMove::plan($driver(), 'scp.example.org', $in_container, $cnode);
$names = array_map(function ($r) { return $r['name']; }, $plan['records']);
sort($names);
check($names === array('scp.example.org', 'www.scp.example.org'), 'a container site moves only its own name and www; the '
	. 'other sites on its shared server keep their records, proxied or not', json_encode($plan['records']));

// ---------------------------------------------------------------------------
section('The driver: a move keeps the record proxied, and a failed write is undone');

$cf_reset($site_records());
$plan = ProxiedOriginMove::plan($driver(), 'scp.example.org', $src, $cnode);
ProxiedOriginMove::move($driver(), $plan);
check($cf['records']['r0']['content'] === $T4 && $cf['records']['r1']['content'] === $T4 && $cf['records']['r0']['proxied'] === true,
	'both records point at the copy, still proxied');
check($cf['writes'][0]['body'] === array('content' => $T4), 'each write sends the address alone (a PATCH): proxy and TTL stay',
	json_encode($cf['writes'][0]));
$left = ProxiedOriginMove::move_back($driver(), $plan);
check($left === array() && $cf['records']['r0']['content'] === $S4 && $cf['records']['r1']['content'] === $S4,
	'moving back points them at the server again');

$cf_reset($site_records());
$cf['fail_patch_of'] = 'r1';
$threw = '';
try { ProxiedOriginMove::move($driver(), $plan); } catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'put back') !== false && $cf['records']['r0']['content'] === $S4,
	'when the second write fails, the first is put back, and the message says so', $threw);

$cf_reset($site_records());
$cf['records']['r1']['content'] = '203.0.113.77';
ProxiedOriginMove::move($driver(), array('zone' => 'example.org', 'records' => array($plan['records'][0])));
$left = ProxiedOriginMove::move_back($driver(), $plan);
check(count($left) === 1 && strpos($left[0], '203.0.113.77') !== false && $cf['records']['r1']['content'] === '203.0.113.77',
	'moving back leaves alone a record someone has since pointed elsewhere, and names it', implode(' ', $left));
$threw = '';
try {
	$r = new DnsRecord('A', 'other.example.org', '203.0.113.9');
	$r->provider_id = 'r2';
	$r->proxied = false;
	$driver()->setProxiedOrigin('example.org', $r, $T4);
} catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'only a proxied') !== false, 'the driver never moves an unproxied record', $threw);

// ---------------------------------------------------------------------------
section('Switch over: the records are checked before anything freezes');

$copy = $mk_copy($cnode);
check(SiteCopyRunner::switch_refusals($copy) === array(), 'a dormant, current copy with the words can switch over',
	implode(' | ', SiteCopyRunner::switch_refusals($copy)));
$cf_reset(array_merge($site_records(), array(array('type' => 'A', 'name' => 'mail.example.org', 'content' => $S4, 'proxied' => false))));
$threw = '';
try { SiteCopyRunner::begin_switch($copy, SiteCopyRunner::METHOD_PROXIED, $driver(), null); } catch (Exception $e) { $threw = $e->getMessage(); }
$src->load(); $copy->load();
check(strpos($threw, 'Nothing was frozen') !== false && trim((string)$src->get('mgn_install_state')) === ''
	&& $copy->status() === SiteCopy::STATUS_DORMANT, 'an unproxied record stops the switch-over before the site is frozen', $threw);

$src->set('mgn_agent_primitives', 'copy_export,site_census,check_status');
$src->save();
check(strpos(implode(' | ', SiteCopyRunner::switch_refusals($copy)), 'copy_vouch') !== false,
	'a site whose agent lacks the switch-over words cannot switch over, naming them');
$src->set('mgn_agent_primitives', 'copy_export,copy_vouch,site_census,site_quiet,backup_run,check_status');
$src->save();

// ---------------------------------------------------------------------------
section('The final copy, and the way back before anything moved');

$cf_reset($site_records());
SiteCopyRunner::begin_switch($copy, SiteCopyRunner::METHOD_PROXIED, $driver(), null);
$src->load(); $copy->load();
check(trim((string)$src->get('mgn_install_state')) === 'switching' && !$src->is_operational()
	&& $copy->status() === SiteCopy::STATUS_FREEZING, 'the site is frozen on the dashboard too: no automation touches it');
check($copy->steps()[0]['op'] === 'site_quiet' && $copy->steps()[0]['arg'] === 'on' && $copy->steps()[0]['verdict'] === 'running',
	'freezing the site is queued at once');
$q = new ManagementJob((int)$copy->steps()[0]['job_id'], TRUE);   // registered for cleanup by $drive
check((int)$q->get('mjb_mgn_managed_node_id') === (int)$src->key && (string)$q->get('mjb_job_type') === 'site_quiet'
	&& (json_decode((string)$q->get('mjb_commands'), true)['params'] ?? null) === array('action' => 'on'), 'on the site itself',
	(string)$q->get('mjb_commands'));

// A census that differs stops the switch-over before the address moves.
$results = $freeze_results();
$results['site_census@copy'] = array('completed', $census(79));
$ops = $drive($copy, SiteCopy::STATUS_FREEZING, $results);
check($ops === array('site_quiet:on@source', 'backup_run@source', 'copy_vouch@source', 'copy_take_vouch@copy', 'copy_stage@copy',
	'copy_restore@copy', 'site_census@source', 'site_census@copy'), 'the final copy runs in order, each job on its own machine',
	implode(', ', $ops));
check($copy->status() === SiteCopy::STATUS_HALTED && strpos((string)$copy->get('scp_halt_reason'), 'final census') !== false,
	'a census that differs at all, with the site frozen, stops it: nothing moves', (string)$copy->get('scp_halt_reason'));
$bj = null;
foreach ($copy->steps() as $s) {
	if ($s['op'] === 'backup_run') { $bj = new ManagementJob((int)$s['job_id'], TRUE); }
}
$bp = json_decode((string)$bj->get('mjb_commands'), true)['params'] ?? array();
check(($bp['full_interval_days'] ?? 0) === SiteCopyRunner::FINAL_RUN_FULL_INTERVAL_DAYS && ($bp['mode'] ?? '') === 'chain'
	&& ($bp['type'] ?? '') === 'project',
	'the final backup extends the chain: a chain run on the longest interval', (string)$bj->get('mjb_commands'));
$tv = null;
foreach ($copy->steps() as $s) {
	if ($s['op'] === 'copy_take_vouch') { $tv = new ManagementJob((int)$s['job_id'], TRUE); }
}
check((json_decode((string)$tv->get('mjb_commands'), true)['params']['vouch'] ?? '') === '{"body":"v","signature":"s"}',
	'the copy is handed the vouch the site made, as it came', (string)$tv->get('mjb_commands'));
check(SiteCopyRunner::in_switch_over($copy), 'stopped with the site frozen is still a switch-over');
$threw = '';
try { SiteCopyRunner::discard($copy); } catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'Go back') !== false, 'a copy in a switch-over cannot be discarded: go back first', $threw);

SiteCopyRunner::go_back($copy, null);
$copy->load();
check($copy->status() === SiteCopy::STATUS_RETURNING && count($copy->steps()) === 1
	&& $copy->steps()[0]['op'] === 'site_quiet' && $copy->steps()[0]['arg'] === 'off',
	'going back before the address moved needs no token: the site is only unfrozen');
$drive($copy, SiteCopy::STATUS_RETURNING, array());
$src->load();
check(trim((string)$src->get('mgn_install_state')) === '' && $src->is_operational()
	&& $copy->status() === SiteCopy::STATUS_HALTED && empty($cf['writes']),
	'the site runs again; the copy, whose final copy did not finish, waits for a copy again; no record was touched',
	(string)$copy->get('scp_halt_reason'));

// ---------------------------------------------------------------------------
section('A new chain at the final backup stops the switch-over');

$copy->set('scp_status', SiteCopy::STATUS_DORMANT);
$copy->save();
SiteCopyRunner::begin_switch($copy, SiteCopyRunner::METHOD_PROXIED, $driver(), null);
$copy->load();
$rolled = $listing;
$rolled['chains'][0]['chain_id'] = 'chain-' . gmdate('Ymd_His');
SiteCopyRunner::$chain_lister = function ($node) use ($rolled) { return $rolled; };
$drive($copy, SiteCopy::STATUS_FREEZING, $freeze_results());
check($copy->status() === SiteCopy::STATUS_HALTED && strpos((string)$copy->get('scp_halt_reason'), 'new backup chain') !== false,
	'a final backup that started a new chain stops before the vouch: the copy holds no key for it', (string)$copy->get('scp_halt_reason'));
SiteCopyRunner::$chain_lister = function ($node) use (&$listing) { return $listing; };
SiteCopyRunner::go_back($copy, null);
$drive($copy, SiteCopy::STATUS_RETURNING, array());

// ---------------------------------------------------------------------------
section('Move the address: unproven, it moves back');

$copy->set('scp_status', SiteCopy::STATUS_DORMANT);
$copy->save();
SiteCopyRunner::begin_switch($copy, SiteCopyRunner::METHOD_PROXIED, $driver(), null);
$copy->load();
$drive($copy, SiteCopy::STATUS_FREEZING, $freeze_results());
check($copy->status() === SiteCopy::STATUS_READY && !empty($copy->switch_record()['final_copied_time']),
	'the final census matches exactly: ready to move the address', (string)$copy->get('scp_halt_reason'));
check(!empty($copy->census()['match']), 'and the match is kept for the page');

$proxy_reaches(false);
$asks = array();
$threw = '';
try { SiteCopyRunner::move_address($copy, $driver(), null); } catch (Exception $e) { $threw = $e->getMessage(); }
$copy->load();
check(strpos($threw, 'moved back') !== false && $cf['records']['r0']['content'] === $S4 && $cf['records']['r1']['content'] === $S4
	&& $copy->status() === SiteCopy::STATUS_READY && $copy->switch_record()['address_at'] === 'source',
	'when the proxy never answers as the copy, the records go back and the site stays frozen, ready', $threw);
check(count($asks) > 1 && strpos($asks[0], 'https://scp.example.org' . $look . '?switch_probe=') === 0
	&& $asks[0] !== $asks[1], 'the proof asks the site\'s name for the path only the copy answers, never the same URL twice');

// ---------------------------------------------------------------------------
section('Move the address, and the copy starts as the site');

$proxy_reaches(true);
SiteCopyRunner::move_address($copy, $driver(), null);
$copy->load();
check($cf['records']['r0']['content'] === $T4 && $copy->status() === SiteCopy::STATUS_STARTING
	&& $copy->switch_record()['address_at'] === 'copy' && !empty($copy->switch_record()['proof']['proven']),
	'the records point at the copy, the proxy reached it, and the copy is starting');
$ops = $drive($copy, SiteCopy::STATUS_STARTING, array());
$src->load(); $cnode->load();
check($ops === array('take_node_id@copy', 'site_quiet:off@source'), 'it takes the node id, then the site runs on the node',
	implode(', ', $ops));
check($copy->status() === SiteCopy::STATUS_SWITCHED && (string)$src->get('mgn_agent_public_key') === $copy_key
	&& (string)$src->get('mgn_host') === $T4 && (string)$cnode->get('mgn_agent_public_key') === $source_key
	&& $cnode->get('mgn_install_state') === 'retired', 'switched: the node holds the copy\'s machine, the old one is retired',
	(string)$copy->get('scp_halt_reason'));
$sq = null;
foreach ($copy->steps() as $s) {
	if ($s['op'] === 'site_quiet') { $sq = new ManagementJob((int)$s['job_id'], TRUE); }
}
check((int)$sq->get('mjb_mgn_managed_node_id') === (int)$src->key, 'site_quiet off goes to the node, which is now the copy\'s machine');

// ---------------------------------------------------------------------------
section('The way back after the switch');

$threw = '';
try { SiteCopyRunner::go_back($copy, null); } catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'DNS token') !== false, 'with the address at the copy, going back needs the token', $threw);
SiteCopyRunner::go_back($copy, $driver());
$copy->load();
check($cf['records']['r0']['content'] === $S4 && $cf['records']['r1']['content'] === $S4,
	'the address moves back first');
$ops = $drive($copy, SiteCopy::STATUS_RETURNING, array());
check($ops === array('site_quiet:on@source', 'site_quiet:off@source'),
	'the copy\'s machine is quieted while it is still the node, the rows swap back, and the site runs on its own server',
	implode(', ', $ops));
$src->load(); $cnode->load();
check((string)$src->get('mgn_agent_public_key') === $source_key && trim((string)$src->get('mgn_install_state')) === ''
	&& $src->is_operational(), 'the node holds its own machine again, working');
check($copy->status() === SiteCopy::STATUS_DISCARDED && $cnode->get('mgn_delete_time'),
	'and the copy is discarded: its agent took the site\'s node id and can no longer reach this management node');
check(SiteCopy::recently_ended_for_source((int)$src->key) !== null, 'the page still names its server for deleting');

// ---------------------------------------------------------------------------
section('Keep the switch-over');

$copy_key2 = base64_encode(random_bytes(32));
$cnode2 = $mk_node('copy2', array('mgn_web_root' => '/var/www/html/scwsite/public_html', 'mgn_site_url' => 'https://scp.example.org',
	'mgn_host' => $T4, 'mgn_agent_public_key' => $copy_key2, 'mgn_agent_version' => '1.53.0',
	'mgn_agent_primitives' => 'host_report,copy_import,copy_take_vouch,copy_stage,copy_restore,site_census,take_node_id,site_quiet',
	'mgn_install_state' => 'copy', 'mgn_copy_of_node_id' => (int)$src->key));
$cnode = $cnode2;
$copy2 = $mk_copy($cnode2);
$cf_reset($site_records());
SiteCopyRunner::begin_switch($copy2, SiteCopyRunner::METHOD_PROXIED, $driver(), null);
$copy2->load();
$drive($copy2, SiteCopy::STATUS_FREEZING, $freeze_results());
SiteCopyRunner::move_address($copy2, $driver(), null);
$copy2->load();
$drive($copy2, SiteCopy::STATUS_STARTING, array());
check($copy2->status() === SiteCopy::STATUS_SWITCHED, 'a second switch-over reaches switched', (string)$copy2->get('scp_halt_reason'));
$server = SiteCopyRunner::finish($copy2);
$copy2->load(); $cnode2->load(); $src->load();
check($copy2->status() === SiteCopy::STATUS_FINISHED && $cnode2->get('mgn_delete_time') && strpos($server, $S4) !== false,
	'keeping it removes the old server\'s row and names that server (the old address) for deleting', $server);
check((string)$src->get('mgn_agent_public_key') === $copy_key2 && $src->is_operational() && SiteCopy::live_for_source((int)$src->key) === null,
	'the node runs on the new machine, and the site can be copied again');

ProxiedOriginMove::$asker = null;
ProxiedOriginMove::$sleeper = null;
SiteCopyRunner::$chain_lister = null;
SiteCopyRunner::$stage_builder = null;
JobCommandBuilder::set_shelf_listing_for_tests(null);
harness_finish();
