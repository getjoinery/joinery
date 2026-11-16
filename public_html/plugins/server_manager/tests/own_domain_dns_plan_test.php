<?php
/** @joinery-test
 * name: own_domain_dns_plan
 * tier: db
 * env: any
 * needs: []
 * covers: [plugins/server_manager/logic/profile_sites_logic.php, includes/dns/DnsPublishBox.php, includes/oauth/consumers/DnsPublishConsumer.php]
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
	'TXT _dmarc.' . $domain . ' v=DMARC1; p=none',
);
sort($want);
check($got === $want, 'A and AAAA from the server, both email records, and DMARC while there is none', json_encode($got));

section('DMARC already published is left alone');
DnsResolver::setBackend(new FakeDnsBackend(array(
	'_dmarc.' . $domain . '|' . DNS_TXT => array(array('txt' => 'v=DMARC1; p=reject')),
)));
$plan = profile_sites_dns_plan($provision);
$types = array();
foreach ($plan ? $plan->getRecords() : array() as $r) {
	$types[] = $r->type;
}
check(!in_array('TXT', $types, true) && count($types) === 4, 'no DMARC record in the plan', json_encode($types));

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
$provision->set('cvp_delete_time', gmdate('Y-m-d H:i:s'));
$provision->save();
$node->set('mgn_delete_time', gmdate('Y-m-d H:i:s'));
$node->save();

harness_finish();
