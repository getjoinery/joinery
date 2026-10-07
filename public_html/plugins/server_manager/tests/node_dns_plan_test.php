<?php
/** @joinery-test
 * name: node_dns_plan
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The records that point a site's name at its machine (NodeDnsPlan), on
 * throwaway rows: an A record for the machine's IPv4 and an AAAA record for
 * its IPv6, each where the machine has one.
 *
 *   - a cloud-born node: both, from what its provision recorded;
 *   - a site on a shared host (specs/multi_tenant_docker_hosts.md S23): no
 *     provision of its own, so its host's node's provision answers for it;
 *   - a node known only by its connection address: the one family that is;
 *   - a hostname as the connection address is never resolved;
 *   - publicIp() stays the IPv4 where there is one, else the IPv6.
 *
 * Run: php plugins/server_manager/tests/node_dns_plan_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$suffix = bin2hex(random_bytes(3));
$owner = make_user('NodeDnsPlan');
$mk_node = function (string $tag, array $fields = array()) use ($suffix): ManagedNode {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'HarnessTest dnsplan ' . $tag . ' ' . $suffix);
	$n->set('mgn_slug', 'harnessdns-' . $tag . '-' . $suffix);
	$n->set('mgn_ssh_user', 'root');
	$n->set('mgn_enabled', true);
	foreach ($fields as $k => $v) { $n->set($k, $v); }
	$n->save();
	$n->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $n->key);
	return $n;
};
$mk_provision = function (ManagedNode $node, string $ip, string $ipv6) use ($owner, $suffix): void {
	$p = new CustomerCloudProvision(NULL);
	$p->set('cvp_origin', 'admin');
	$p->set('cvp_usr_user_id', $owner->key);
	$p->set('cvp_domain', 'harnessdns-' . $node->key . '.example');
	$p->set('cvp_slug', 'harnessdns-' . $node->key);
	$p->set('cvp_status', 'done');
	$p->set('cvp_instance_id', '77' . $node->key);
	$p->set('cvp_instance_ip', $ip);
	$p->set('cvp_instance_ipv6', $ipv6);
	$p->set('cvp_mgn_managed_node_id', $node->key);
	$p->save();
	$p->load();
	harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $p->key);
};
/** The plan's records as "TYPE name -> value", in order. */
$records = function ($plan): array {
	if ($plan === null) { return array(); }
	$out = array();
	foreach ($plan->getRecords() as $r) { $out[] = $r->type . ' ' . $r->name . ' -> ' . $r->value; }
	return $out;
};

section('A cloud-born node: an A and an AAAA record, from its provision');

$cloud = $mk_node('cloud', array('mgn_host' => '203.0.113.10', 'mgn_site_url' => 'https://dnsplan-cloud-' . $suffix . '.example'));
$mk_provision($cloud, '203.0.113.10', '2001:db8:10::1');
$got = $records(NodeDnsPlan::forNode($cloud));
check($got === array(
		'A dnsplan-cloud-' . $suffix . '.example -> 203.0.113.10',
		'AAAA dnsplan-cloud-' . $suffix . '.example -> 2001:db8:10::1'),
	'the site name points at both of the machine\'s addresses', implode(' | ', $got));
check(NodeDnsPlan::publicIp($cloud) === '203.0.113.10', 'publicIp is the IPv4');

section('A site on a shared host: its host\'s addresses');

$host_node = $mk_node('host', array('mgn_host' => '203.0.113.20'));
$mk_provision($host_node, '203.0.113.20', '2001:DB8:20:0::0001');
$mgh = new ManagedHost(NULL);
$mgh->set('mgh_name', 'HarnessTest dnsplan host ' . $suffix);
$mgh->set('mgh_slug', 'harnessdns-' . $suffix);
$mgh->set('mgh_host', '203.0.113.20');
$mgh->set('mgh_mgn_managed_node_id', (int)$host_node->key);
$mgh->save();
$mgh->load();
harness_register_row('mgh_managed_hosts', 'mgh_managed_host_id', $mgh->key);
$site = $mk_node('site', array('mgn_host' => '203.0.113.20', 'mgn_mgh_managed_host_id' => (int)$mgh->key,
	'mgn_container_name' => 'harnessdns' . $suffix, 'mgn_site_url' => 'https://dnsplan-site-' . $suffix . '.example'));
$got = $records(NodeDnsPlan::forNode($site));
check($got === array(
		'A dnsplan-site-' . $suffix . '.example -> 203.0.113.20',
		'AAAA dnsplan-site-' . $suffix . '.example -> 2001:db8:20::1'),
	'a site with no provision of its own takes its host\'s IPv4 and IPv6, the IPv6 in its short form',
	implode(' | ', $got));

section('A node known only by its connection address');

$v4 = $mk_node('v4', array('mgn_host' => '203.0.113.30', 'mgn_site_url' => 'dnsplan-v4-' . $suffix . '.example'));
check($records(NodeDnsPlan::forNode($v4)) === array('A dnsplan-v4-' . $suffix . '.example -> 203.0.113.30'),
	'an IPv4 connection address: an A record and no AAAA', implode(' | ', $records(NodeDnsPlan::forNode($v4))));
$v6 = $mk_node('v6', array('mgn_host' => '2001:db8:30::5', 'mgn_site_url' => 'https://dnsplan-v6-' . $suffix . '.example'));
check($records(NodeDnsPlan::forNode($v6)) === array('AAAA dnsplan-v6-' . $suffix . '.example -> 2001:db8:30::5'),
	'an IPv6 connection address: an AAAA record and no A', implode(' | ', $records(NodeDnsPlan::forNode($v6))));
check(NodeDnsPlan::publicIp($v6) === '2001:db8:30::5', 'publicIp is the IPv6 where there is no IPv4');
$named = $mk_node('named', array('mgn_host' => 'box.example.net', 'mgn_site_url' => 'https://dnsplan-named-' . $suffix . '.example'));
check(NodeDnsPlan::forNode($named) === null && NodeDnsPlan::publicIp($named) === '',
	'a hostname as the connection address is never resolved: nothing to publish');
$no_site = $mk_node('nosite', array('mgn_host' => '203.0.113.40'));
check(NodeDnsPlan::forNode($no_site) === null, 'a node with no site name has nothing to publish');

harness_finish();
