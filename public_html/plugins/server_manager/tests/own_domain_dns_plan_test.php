<?php
/** @joinery-test
 * name: own_domain_dns_plan
 * tier: db
 * env: any
 * needs: []
 * covers: [plugins/server_manager/logic/profile_sites_logic.php, plugins/server_manager/includes/provisioning/ProvisionBuyerDns.php, includes/dns/DnsPublishBox.php, includes/oauth/consumers/DnsPublishConsumer.php]
 */
/**
 * A hosting buyer who brought their own domain can have its records written
 * for them from their sites page ("Add these records for me"). The plan the
 * box writes is every record the site asks for: the address records in both
 * families where the server has both, the email records, and DMARC while the
 * domain has none. The optional IPv6 line is listed while the name lacks it.
 * A name we registered gets nothing (we publish it ourselves), and neither
 * does a site not built yet.
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('tests/lib/dns_fixtures.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/logic/profile_sites_logic.php'));
require_once(PathHelper::getIncludePath('includes/dns/DnsPublishBox.php'));

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$domain = 'odp-' . $suffix . '.example.com';

$node = new ManagedNode(NULL);
$node->set('mgn_name', 'HarnessTest own-domain ' . $suffix);
$node->set('mgn_slug', 'odp-' . $suffix);
$node->set('mgn_host', '203.0.113.' . random_int(2, 250));
$node->set('mgn_ssh_user', 'root');
$node->set('mgn_web_root', '/var/www/html/odp' . $suffix . '/public_html');
$node->set('mgn_container_name', 'odp' . $suffix);
$node->set('mgn_port', 8080);
$node->set('mgn_uptime_enabled', false);
$node->save();
$node->load();
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', (int)$node->key);
$v4 = (string)$node->get('mgn_host');
$v6 = '2001:db8::' . random_int(16, 4000);

$provision = new CustomerCloudProvision(NULL);
$provision->set('cvp_origin', 'admin');
$provision->set('cvp_usr_user_id', 990000 + random_int(0, 9999));
$provision->set('cvp_domain', $domain);
$provision->set('cvp_slug', 'odp-' . $suffix);
$provision->set('cvp_hosting_mode', 'operator');
$provision->set('cvp_buyer_email', 'odp-' . $suffix . '@example.com');
$provision->set('cvp_status', 'done');
$provision->set('cvp_domain_source', 'own');
$provision->set('cvp_mgn_managed_node_id', (int)$node->key);
$provision->set('cvp_instance_id', 'odp-' . $suffix);
$provision->set('cvp_instance_ip', $v4);
$provision->set('cvp_instance_ipv6', $v6);
$provision->set('cvp_mail_records', json_encode(array(
	array('type' => 'CNAME', 'name' => 's77._domainkey.' . $domain, 'value' => 'dkim.smtp2go.net', 'note' => 'DKIM'),
	array('type' => 'CNAME', 'name' => 'em77.' . $domain, 'value' => 'return.smtp2go.net', 'note' => 'Return path'),
)));
$provision->save();
$provision->load();
// Registered after the node, so teardown (newest first) removes it before the node it points at.
harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', (int)$provision->key);

section('The list is held until it is whole');
DnsResolver::setBackend(new FakeDnsBackend(array()));
check(ProvisionBuyerDns::applies($provision) && !ProvisionBuyerDns::listed($provision),
	'a built site on its buyer\'s own domain has a list, not shown yet');
check(profile_sites_dns_plan($provision) === null && profile_sites_dns_record($provision) === null
	&& profile_sites_mail_records($provision) === array() && profile_sites_dmarc_record($provision) === null
	&& profile_sites_aaaa_record($provision) === null,
	'no record is listed before the site has said its part: one trip to the registrar');
check(ProvisionBuyerDns::others_settled($provision), 'the sending records are known, so the site can be asked');
$provision->set('cvp_mail_records', null);
$provision->set('cvp_mail_state', 'pending');
check(!ProvisionBuyerDns::others_settled($provision), 'a site whose sending records are still coming is not asked yet');
$provision->set('cvp_mail_state', 'failed');
check(ProvisionBuyerDns::others_settled($provision), 'a mail leg that ended does not hold the list');
$provision->set('cvp_mail_state', null);
$provision->set('cvp_mail_records', json_encode(array(
	array('type' => 'CNAME', 'name' => 's77._domainkey.' . $domain, 'value' => 'dkim.smtp2go.net', 'note' => 'DKIM'),
	array('type' => 'CNAME', 'name' => 'em77.' . $domain, 'value' => 'return.smtp2go.net', 'note' => 'Return path'),
)));

section('The site is asked what its domain needs, and its answer completes the list');
/** The leg with the node's answer supplied, so no agent is needed. */
class OdpBuyerDns extends ProvisionBuyerDns {
	public $job = null;
	protected function latest_job(int $node_id, string $domain): ?array { return $this->job; }
}
$provision->save();
$leg = new OdpBuyerDns();
$leg->job = array('mjb_management_job_id' => 0, 'mjb_status' => 'running', 'mjb_create_time' => gmdate('Y-m-d H:i:s'),
	'mjb_completed_time' => null, 'mjb_result' => null);
$leg->run(array('provision_id' => (int)$provision->key));
$provision->load();
check(!ProvisionBuyerDns::listed($provision), 'a site that has been asked and not answered keeps the list held');
$leg->job = array('mjb_management_job_id' => 0, 'mjb_status' => 'completed', 'mjb_create_time' => gmdate('Y-m-d H:i:s'),
	'mjb_completed_time' => gmdate('Y-m-d H:i:s'), 'mjb_result' => json_encode(array('answered' => true, 'ok' => false,
		'records' => array(), 'error' => 'not yet')));
$leg->run(array('provision_id' => (int)$provision->key));
$provision->load();
check(!ProvisionBuyerDns::listed($provision), 'a refusal is not an answer');
$leg->job['mjb_result'] = json_encode(array('answered' => true, 'ok' => true, 'dkim_ready' => false, 'records' => array(
	array('type' => 'SRV', 'name' => '_joinery._tcp.' . $domain, 'value' => '0 5 443 ' . $domain, 'priority' => null, 'note' => 'Direct'))));
$ran = $leg->run(array('provision_id' => (int)$provision->key));
$provision->load();
check(ProvisionBuyerDns::listed($provision) && count((array)ProvisionBuyerDns::site_records($provision)) === 1
	&& $ran['status'] === 'success', 'the answer is kept on the row and the list is shown', json_encode($ran));
$ran = $leg->run(array('provision_id' => (int)$provision->key));
check($ran['status'] === 'skipped', 'a completed list is not asked about again', json_encode($ran));

// A site without the mailbox plugin can never answer: its refusal is the answer.
$kept = $provision->get('cvp_site_dns_records');
$provision->set('cvp_site_dns_records', null);
$provision->set('cvp_site_dns_time', null);
$provision->save();
$leg->job = array('mjb_management_job_id' => 0, 'mjb_status' => 'failed', 'mjb_create_time' => gmdate('Y-m-d H:i:s'),
	'mjb_completed_time' => gmdate('Y-m-d H:i:s'), 'mjb_result' => json_encode(array('answered' => false)),
	'mjb_error_message' => 'Refused by the node: primitive "managed_domain_prepare" refused: no script from '
		. 'public_html/plugins/mailbox can be verified before running as root: it ships no signed release manifest');
$ran = $leg->run(array('provision_id' => (int)$provision->key));
$provision->load();
check(ProvisionBuyerDns::listed($provision) && ProvisionBuyerDns::site_records($provision) === array(),
	'a site with no mailbox plugin is listed with no records of its own', json_encode($ran));
$ran = $leg->run(array('provision_id' => (int)$provision->key));
check($ran['status'] === 'skipped', 'and is not asked again', json_encode($ran));
$leg->job['mjb_error_message'] = 'Refused by the node: some other refusal';
$provision->set('cvp_site_dns_records', null);
$provision->set('cvp_site_dns_time', null);
$provision->save();
$leg->run(array('provision_id' => (int)$provision->key));
$provision->load();
check(!ProvisionBuyerDns::listed($provision) || ProvisionBuyerDns::site_records($provision) === null,
	'any other failure is not an answer');
$provision->set('cvp_site_dns_records', $kept);
$provision->set('cvp_site_dns_time', gmdate('Y-m-d H:i:s'));
$provision->save();
$provision->set('cvp_domain_source', 'register');
check(ProvisionBuyerDns::applies($provision), 'what decides it is the registration row, not what the buyer chose');
$provision->set('cvp_domain_source', 'own');
$provision->set('cvp_hosting_mode', 'customer');
check(!ProvisionBuyerDns::applies($provision) && ProvisionBuyerDns::listed($provision),
	'a site we did not build has no list of ours to hold');
$provision->set('cvp_hosting_mode', 'operator');

section('The site\'s own records join the list, Joinery Direct among them');
$direct_key = 'v=joinery1; k=k1; p=AAAA';
$provision->set('cvp_site_dns_records', json_encode(array(
	array('type' => 'A', 'name' => $domain, 'value' => '198.51.100.9', 'priority' => null, 'note' => ''),
	array('type' => 'TXT', 'name' => $domain, 'value' => 'v=spf1 mx ~all', 'priority' => null, 'note' => ''),
	array('type' => 'TXT', 'name' => 'mail._domainkey.' . $domain, 'value' => 'v=DKIM1; p=x', 'priority' => null, 'note' => ''),
	array('type' => 'TXT', 'name' => '_dmarc.' . $domain, 'value' => 'v=DMARC1; p=quarantine', 'priority' => null, 'note' => ''),
	array('type' => 'CNAME', 'name' => 'em77.' . $domain, 'value' => 'elsewhere.example', 'priority' => null, 'note' => ''),
	array('type' => 'SRV', 'name' => '_joinery._tcp.' . $domain, 'value' => '0 5 443 ' . $domain, 'priority' => null, 'note' => 'Direct'),
	array('type' => 'TXT', 'name' => '_joinery-key.' . $domain, 'value' => $direct_key, 'priority' => null, 'note' => 'Direct key'),
)));
$provision->set('cvp_site_dns_time', gmdate('Y-m-d H:i:s'));
check(ProvisionBuyerDns::listed($provision), 'the list is shown once the site has answered');
$site_names = array();
foreach (ProvisionBuyerDns::site_records_for_buyer($provision, profile_sites_plane_mail_records($provision)) as $r) {
	$site_names[] = $r['type'] . ' ' . $r['name'];
}
check($site_names === array('SRV _joinery._tcp.' . $domain, 'TXT _joinery-key.' . $domain),
	'the site\'s address, signing key, SPF and DMARC give way to ours where we set up sending; a name we list is ours',
	json_encode($site_names));
$listed = array();
foreach (profile_sites_mail_records($provision) as $r) {
	$listed[] = $r['type'] . ' ' . $r['name'];
}
check(in_array('SRV _joinery._tcp.' . $domain, $listed, true) && in_array('TXT _joinery-key.' . $domain, $listed, true)
	&& in_array('CNAME em77.' . $domain, $listed, true), 'the buyer sees the sending and the Direct records together',
	json_encode($listed));
DnsResolver::setBackend(new FakeDnsBackend(array(
	'_joinery._tcp.' . $domain . '|' . DNS_SRV => array(array('target' => $domain, 'port' => 443, 'pri' => 0, 'weight' => 5)),
	'_joinery-key.' . $domain . '|' . DNS_TXT => array(array('txt' => $direct_key)),
)));
$listed = array();
foreach (profile_sites_mail_records($provision) as $r) {
	$listed[] = $r['type'];
}
check($listed === array('CNAME', 'CNAME'), 'a Direct record that answers in DNS is no longer listed', json_encode($listed));
$provision->set('cvp_mail_records', null);
$own = array();
foreach (ProvisionBuyerDns::site_records_for_buyer($provision, array()) as $r) {
	$own[] = $r['type'] . ' ' . $r['name'];
}
check(count($own) === 6 && !in_array('A ' . $domain, $own, true),
	'a site that sends its own mail keeps every record it named but its address', json_encode($own));
$provision->set('cvp_mail_records', json_encode(array(
	array('type' => 'CNAME', 'name' => 's77._domainkey.' . $domain, 'value' => 'dkim.smtp2go.net', 'note' => 'DKIM'),
	array('type' => 'CNAME', 'name' => 'em77.' . $domain, 'value' => 'return.smtp2go.net', 'note' => 'Return path'),
)));

section('The plan is every record the site asks for');
DnsResolver::setBackend(new FakeDnsBackend(array()));
$plan = profile_sites_dns_plan($provision);
$got = array();
foreach ($plan ? $plan->getRecords() : array() as $r) {
	$got[] = $r->type . ' ' . $r->name . ' ' . $r->value;
}
sort($got);
$want = array(
	'A ' . $domain . ' ' . $v4,
	'AAAA ' . $domain . ' ' . $v6,
	'CNAME em77.' . $domain . ' return.smtp2go.net',
	'CNAME s77._domainkey.' . $domain . ' dkim.smtp2go.net',
	'SRV _joinery._tcp.' . $domain . ' 0 5 443 ' . $domain,
	'TXT _joinery-key.' . $domain . ' ' . $direct_key,
	'TXT _dmarc.' . $domain . ' v=DMARC1; p=none',
);
sort($want);
check($got === $want, 'A and AAAA from the server, both email records, the Direct records, and DMARC while there is none', json_encode($got));

section('DMARC already published is left alone');
DnsResolver::setBackend(new FakeDnsBackend(array(
	'_dmarc.' . $domain . '|' . DNS_TXT => array(array('txt' => 'v=DMARC1; p=reject')),
)));
$plan = profile_sites_dns_plan($provision);
$types = array();
foreach ($plan ? $plan->getRecords() : array() as $r) {
	$types[] = $r->type;
}
$names = array();
foreach ($plan ? $plan->getRecords() : array() as $r) {
	$names[] = $r->name;
}
check(!in_array('_dmarc.' . $domain, $names, true) && count($types) === 6, 'no DMARC record in the plan', json_encode($names));

section('The optional IPv6 line shows while the name lacks it');
DnsResolver::setBackend(new FakeDnsBackend(array()));
$line = profile_sites_aaaa_record($provision);
check($line !== null && $line['type'] === 'AAAA' && $line['value'] === $v6, 'listed with the server\'s IPv6 address',
	json_encode($line));
DnsResolver::setBackend(new FakeDnsBackend(array(
	$domain . '|' . DNS_AAAA => array(array('ipv6' => $v6)),
)));
check(profile_sites_aaaa_record($provision) === null, 'gone once the name answers with it');

section('Nothing for a site that is not built, or not hosted by us');
$provision->set('cvp_status', 'booting');
check(profile_sites_dns_plan($provision) === null && profile_sites_aaaa_record($provision) === null,
	'a site still booting has no plan and no IPv6 line');
$provision->set('cvp_status', 'done');
$provision->set('cvp_hosting_mode', 'customer');
check(profile_sites_dns_plan($provision) === null, 'a site on the buyer\'s own cloud account is not ours to publish');
$provision->set('cvp_hosting_mode', 'operator');

section('A page may set the box\'s floor for the visitor\'s own domain');
$handle = new ReflectionMethod('DnsPublishBox', 'handle');
$params = $handle->getParameters();
check(count($params) === 4 && $params[3]->getName() === 'min_level' && $params[3]->isDefaultValueAvailable()
	&& $params[3]->getDefaultValue() === null, 'handle() takes min_level, defaulting to the config:write floor');
$consumer_src = (string)file_get_contents(PathHelper::getIncludePath('includes/oauth/consumers/DnsPublishConsumer.php'));
check(strpos($consumer_src, "\$payload['min_level']") !== false,
	'the sign-in callback holds the grant to the floor the flow carried');

DnsResolver::setBackend(null);

harness_finish();
