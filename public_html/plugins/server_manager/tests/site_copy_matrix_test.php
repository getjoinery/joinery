<?php
/** @joinery-test
 * name: site_copy_matrix
 * tier: db
 * env: any
 * needs: []
 */

/**
 * site_copy_matrix (specs/site_copy_anything_to_anything.md WP2): the end-state
 * table is the code's table. For a fixture node of each source kind and each
 * target kind, the ways the address can move offered before the copy is made
 * (SiteCopyMethods, D6), and the preflight's refusals (D5, D8).
 *
 *   Source ↓ / Target →     Linode here      Hetzner here     Brought      Host
 *   own server, alive       P S D M          P D M            P D M        P D M
 *   container, alive        P D M            P D M            P D M        P D M
 *   management node, alive  refused at preflight, every target
 *   dead site (any kind)    P S D M          P D M            P D M        P D M
 *
 *   P proxy, S IP swap (same provider, account and region), D DNS records, M by hand.
 *
 * Also pinned, though they are not cells of the table: a bare-metal source whose
 * machine serves anything else (its own test twin aside), a copy from backups
 * of a site whose agent key is not on record, and alive or dead is the
 * source's, whatever the target (on a host too).
 *
 * @version 1.1 - a site whose agent was never heard from is not copied as a running one (B101)
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/lib/node_space_fixture.php');
harness_boot();

$tag = bin2hex(random_bytes(3));
// The domain's DNS is at a host that proxies, and that this node can write.
SiteCopyMethods::$dns_host = function ($d) { return 'cloudflare'; };
harness_defer(function () { SiteCopyMethods::$dns_host = null; });

$mk_node = function (string $suffix, array $set) use ($tag) {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'HarnessTest SCM ' . $suffix);
	$n->set('mgn_slug', 'harnessscm-' . $suffix . '-' . $tag);
	$n->set('mgn_uptime_enabled', false);
	foreach ($set as $k => $v) {
		$n->set($k, $v);
	}
	$n->save();
	$n->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $n->key);
	return $n;
};
$oca = new OperatorCloudAccount(NULL);
$oca->set('oca_provider', 'linode');
$oca->set('oca_method', OperatorCloudAccount::METHOD_KEY);
$oca->set('oca_account_name', 'SCM account ' . $tag);
$oca->save();
$oca->load();
harness_register_row('oca_operator_cloud_accounts', 'oca_operator_cloud_account_id', $oca->key);
$provision = function (ManagedNode $n, string $provider, string $region) use ($oca, $tag) {
	static $i = 0;
	$i++;
	$p = new CustomerCloudProvision(NULL);
	foreach (array('cvp_origin' => 'admin', 'cvp_usr_user_id' => 1, 'cvp_domain' => 'scm.example.org', 'cvp_slug' => 'harnessscm-p' . $i . '-' . $tag,
			'cvp_status' => 'done', 'cvp_docker_mode' => 'bare-metal', 'cvp_provider' => $provider, 'cvp_hosting_mode' => 'operator',
			'cvp_oca_operator_cloud_account_id' => (int)$oca->key, 'cvp_instance_id' => 'i' . $i, 'cvp_region' => $region,
			'cvp_mgn_managed_node_id' => (int)$n->key) as $k => $v) {
		$p->set($k, $v);
	}
	$p->save();
	harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $p->key);
	return $p;
};
$base = array('mgn_site_url' => 'https://scm.example.org', 'mgn_agent_public_key' => base64_encode(random_bytes(32)),
	'mgn_agent_version' => JobCommandBuilder::FORWARD_MIN_AGENT_VERSION,
	'mgn_agent_primitives' => 'backup_run,copy_export,site_census,site_quiet,copy_vouch,check_status',
	'mgn_agent_last_poll' => gmdate('Y-m-d H:i:s'), 'mgn_joinery_version' => JobCommandBuilder::BROKER_MIN_CORE_VERSION,
	'mgn_last_status_data' => json_encode(array('backup_recovery_state' => 'proven')), 'mgn_backup_recovery_fpr' => str_repeat('cd', 32),
	'mgn_agent_server_manager' => 'inactive', 'mgn_last_host_report' => json_encode(array('sites' => array())));

// The sources. A container's host has an agent with the words that freeze it and hold it.
$hnode = $mk_node('hostagent', array('mgn_host' => '192.0.2.150', 'mgn_agent_public_key' => base64_encode(random_bytes(32)),
	'mgn_agent_version' => JobCommandBuilder::FORWARD_MIN_AGENT_VERSION, 'mgn_agent_primitives' => 'host_report,site_certificate_stage,site_quiet_host,hold_container'));
$mh = new ManagedHost(NULL);
foreach (array('mgh_slug' => 'harnessscm-h-' . $tag, 'mgh_name' => 'HarnessTest SCM host', 'mgh_host' => '192.0.2.150',
		'mgh_mgn_managed_node_id' => (int)$hnode->key) as $k => $v) {
	$mh->set($k, $v);
}
$mh->save();
harness_register_row('mgh_managed_hosts', 'mgh_managed_host_id', $mh->key);
$own = $mk_node('own', array_merge($base, array('mgn_host' => '192.0.2.151', 'mgn_web_root' => '/var/www/html/scmown/public_html')));
$provision($own, 'linode', 'us-east');
$box = $mk_node('box', array_merge($base, array('mgn_host' => '192.0.2.150', 'mgn_web_root' => '/var/www/html/scmbox/public_html',
	'mgn_container_name' => 'scmbox', 'mgn_mgh_managed_host_id' => (int)$mh->key)));
$mgmt = $mk_node('mgmt', array_merge($base, array('mgn_host' => '192.0.2.152', 'mgn_web_root' => '/var/www/html/scmmgmt/public_html',
	'mgn_agent_server_manager' => 'active')));
foreach (array($own, $box, $mgmt) as $n) {
	sm_test_node_space($n);
}

$targets = array(
	'Linode here, same account and region' => array('kind' => SiteCopyMethods::TARGET_MADE, 'provider' => 'linode', 'account' => $oca->choice_key(), 'region' => 'us-east'),
	'Linode here, another region'          => array('kind' => SiteCopyMethods::TARGET_MADE, 'provider' => 'linode', 'account' => $oca->choice_key(), 'region' => 'eu-west'),
	'Hetzner here'                         => array('kind' => SiteCopyMethods::TARGET_MADE, 'provider' => 'hetzner', 'account' => 'oca:999999', 'region' => 'fsn1'),
	'a brought server'                     => array('kind' => SiteCopyMethods::TARGET_BROUGHT),
	'a container on an engine host'        => array('kind' => SiteCopyMethods::TARGET_HOST),
);
$offered = function (ManagedNode $source, array $target): string {
	$letters = array(SiteCopyRunner::METHOD_PROXIED => 'P', SiteCopyRunner::METHOD_IP_SWAP => 'S',
		SiteCopyRunner::METHOD_DNS => 'D', SiteCopyRunner::METHOD_MANUAL => 'M');
	$out = '';
	foreach (SiteCopyMethods::for_target($source, $target) as $method => $why) {
		$out .= $why ? '' : $letters[$method];
	}
	return $out;
};

// ---------------------------------------------------------------------------
section('The ways the address can move, cell by cell');

$expect = array(
	'own server' => array('PSDM', 'PDM', 'PDM', 'PDM', 'PDM'),
	'container'  => array('PDM', 'PDM', 'PDM', 'PDM', 'PDM'),
);
foreach (array('own server' => $own, 'container' => $box) as $what => $source) {
	$i = 0;
	foreach ($targets as $where => $target) {
		$got = $offered($source, $target);
		check($got === $expect[$what][$i], "{$what} onto {$where}: {$expect[$what][$i]}", $got . ' ' . json_encode(SiteCopyMethods::for_target($source, $target)));
		$i++;
	}
}
$why = implode(' ', SiteCopyMethods::for_target($own, $targets['Linode here, another region'])[SiteCopyRunner::METHOD_IP_SWAP]);
check(strpos($why, 'one region') !== false, 'the swap refused across regions says so', $why);
$why = implode(' ', SiteCopyMethods::for_target($own, $targets['Hetzner here'])[SiteCopyRunner::METHOD_IP_SWAP]);
check(strpos($why, 'one provider') !== false && strpos($why, 'another cloud account') !== false,
	'the swap refused across providers and accounts says both', $why);
// An own server at Hetzner swaps with a Hetzner server of its project and location (D3).
$hz = new OperatorCloudAccount(NULL);
$hz->set('oca_provider', 'hetzner');
$hz->set('oca_method', OperatorCloudAccount::METHOD_KEY);
$hz->set('oca_account_name', 'SCM project ' . $tag);
$hz->save();
$hz->load();
harness_register_row('oca_operator_cloud_accounts', 'oca_operator_cloud_account_id', $hz->key);
$hzown = $mk_node('hzown', array_merge($base, array('mgn_host' => '192.0.2.153', 'mgn_web_root' => '/var/www/html/scmhz/public_html')));
$hp = $provision($hzown, 'hetzner', 'fsn1');
$hp->set('cvp_oca_operator_cloud_account_id', (int)$hz->key);
$hp->save();
check($offered($hzown, array('kind' => SiteCopyMethods::TARGET_MADE, 'provider' => 'hetzner', 'account' => $hz->choice_key(), 'region' => 'fsn1')) === 'PSDM',
	'own server at Hetzner onto Hetzner here, same project and location: PSDM');
check($offered($hzown, $targets['Linode here, same account and region']) === 'PDM', 'own server at Hetzner onto Linode: no swap across providers');
SiteCopyMethods::$dns_host = function ($d) { return ''; };
$m = SiteCopyMethods::for_target($own, $targets['a brought server']);
check($m[SiteCopyRunner::METHOD_PROXIED] && $m[SiteCopyRunner::METHOD_DNS] && $m[SiteCopyRunner::METHOD_MANUAL] === array(),
	'a domain at a host with no driver: by hand only, every target');
SiteCopyMethods::$dns_host = function ($d) { return 'cloudflare'; };

// ---------------------------------------------------------------------------
section('Alive or dead is the source\'s, whatever the target');

check(SiteCopyRunner::source_refusals($own, true, SiteCopy::FROM_SOURCE) === array(), 'an own server, alive, copies',
	implode(' | ', SiteCopyRunner::source_refusals($own, true, SiteCopy::FROM_SOURCE)));
check(SiteCopyRunner::source_refusals($box, true, SiteCopy::FROM_SOURCE) === array()
	&& SiteCopyRunner::source_refusals($box, true, SiteCopy::FROM_SOURCE, true) === array(),
	'a container, alive, copies onto a server or a host, frozen through its host (D4)',
	implode(' | ', SiteCopyRunner::source_refusals($box, true, SiteCopy::FROM_SOURCE)));
foreach (array(false, true) as $on_host) {
	$why = implode(' | ', SiteCopyRunner::source_refusals($mgmt, true, SiteCopy::FROM_SOURCE, $on_host));
	check(strpos($why, 'management node') !== false && strpos($why, 'backups') !== false,
		'another management node, alive, is refused at preflight' . ($on_host ? ' onto a host too: it is never frozen' : ''), $why);
	check(SiteCopyRunner::source_refusals($mgmt, true, SiteCopy::FROM_BACKUPS, $on_host) === array(),
		'and copies from its backups' . ($on_host ? ' onto a host' : ''), implode(' | ', SiteCopyRunner::source_refusals($mgmt, true, SiteCopy::FROM_BACKUPS, $on_host)));
}
$silent = new ManagedNode($own->key, TRUE);
$silent->set('mgn_agent_last_poll', gmdate('Y-m-d H:i:s', time() - 3 * 86400));
$why = implode(' | ', SiteCopyRunner::source_refusals($silent, true, SiteCopy::FROM_SOURCE));
check(strpos($why, 'has not been heard from') !== false && strpos($why, 'backups') !== false,
	'a site whose agent is silent is not copied as a running one, and is told to copy from its backups', $why);
check(SiteCopyRunner::source_refusals($silent, true, SiteCopy::FROM_BACKUPS) === array(), 'from its backups it is, dead');
// An agent never heard from is not one heard just now (B101).
$silent->set('mgn_agent_last_poll', null);
$why = implode(' | ', SiteCopyRunner::source_refusals($silent, true, SiteCopy::FROM_SOURCE));
check(strpos($why, 'has not been heard from at all') !== false, 'nor is a site whose agent was never heard from', $why);
$keyless = new ManagedNode($own->key, TRUE);
$keyless->set('mgn_agent_public_key', null);
$why = implode(' | ', SiteCopyRunner::source_refusals($keyless, true, SiteCopy::FROM_BACKUPS));
check(strpos($why, 'agent\'s key') !== false, 'a copy from backups needs the source\'s agent key on record before a server is made', $why);
$dead_box = new ManagedNode($box->key, TRUE);
$dead_box->set('mgn_agent_last_poll', null);
check(SiteCopyRunner::source_refusals($dead_box, true, SiteCopy::FROM_BACKUPS, true) === array(), 'a dead container copies onto a host, from its backups');

// ---------------------------------------------------------------------------
section('A server of its own that serves anything else (D8)');

$shared = new ManagedNode($own->key, TRUE);
$shared->set('mgn_last_host_report', json_encode(array('containers' => array(array('name' => 'other')))));
$why = implode(' | ', SiteCopyRunner::source_refusals($shared, true, SiteCopy::FROM_SOURCE));
check(strpos($why, 'site container') !== false && strpos($why, 'whole machine') !== false, 'a machine running containers is refused', $why);
$shared->set('mgn_last_host_report', json_encode(array('sites' => array('scmown', 'scmown_test'))));
check(SiteCopyRunner::source_refusals($shared, true, SiteCopy::FROM_SOURCE) === array(), 'its own test twin is not another site',
	implode(' | ', SiteCopyRunner::source_refusals($shared, true, SiteCopy::FROM_SOURCE)));
$shared->set('mgn_last_host_report', json_encode(array('sites' => array('scmown', 'neighbour'))));
$why = implode(' | ', SiteCopyRunner::source_refusals($shared, true, SiteCopy::FROM_BACKUPS));
check(strpos($why, 'neighbour') !== false, 'a machine that also serves another site is refused, from its backups too: its power-off is machine-wide', $why);
$shared->set('mgn_last_host_report', null);
$why = implode(' | ', SiteCopyRunner::source_refusals($shared, true, SiteCopy::FROM_SOURCE));
check(strpos($why, 'Run a status check') !== false, 'a running machine whose report does not list its sites is asked for one', $why);
check(strpos(implode(' | ', SiteCopyRunner::source_refusals($shared, true, SiteCopy::FROM_BACKUPS)), 'Run a status check') === false,
	'a dead one is not: its last report is all there is');
$shared->set('mgn_last_host_report', json_encode(array('sites' => array())));
$mk_node('sibling', array('mgn_host' => '192.0.2.151', 'mgn_web_root' => '/var/www/html/scmsibling/public_html'));
$why = implode(' | ', SiteCopyRunner::source_refusals($shared, true, SiteCopy::FROM_SOURCE));
check(strpos($why, 'scmsibling') !== false, 'a node on the same address is another site on the machine', $why);

harness_finish();
