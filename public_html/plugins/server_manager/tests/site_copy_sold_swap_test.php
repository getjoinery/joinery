<?php
/** @joinery-test
 * name: site_copy_sold_swap
 * tier: db
 * env: any
 * needs: []
 */
/**
 * A sold site moved by a site copy keeps its purchase (specs/hosting_paid_launch.md
 * B5, R17).
 *
 * At a switch-over provision rows follow their machines, so a sold site's
 * provision used to land on the old machine's row, and removing the old
 * container removed the purchase with it: the billing row, the relay slot,
 * the mail account. A sold provision now stays with the site, and only its
 * machine columns move:
 *
 *  - **A Starter site copied onto another starter host** (the copy has no
 *    provision): the sold provision stays on the site's row and takes the new
 *    host and port. The way back puts the old ones back.
 *  - **A Starter site copied onto a server of its own** (the copy has one):
 *    the two trade machine columns, so each still describes the machine on
 *    its own row.
 *  - **Which host**: a sold Starter site may go to another starter host; a
 *    sold site on a server of its own never onto a host, where its purchase
 *    would follow the old server.
 *
 * Every row is made inside one transaction and rolled back.
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$db = DbConnector::get_instance()->get_db_link();
$db->beginTransaction();
$tag = substr(bin2hex(random_bytes(4)), 0, 8);

function scs_host($n, $tag) {
	$h = new ManagedHost(NULL);
	$h->set('mgh_slug', 'harnesstest-scs-' . $n . '-' . $tag);
	$h->set('mgh_name', 'HarnessTest scs ' . $n . ' ' . $tag);
	$h->set('mgh_host', '192.0.2.' . (70 + $n));
	$h->prepare();
	$h->save();
	$h->load();
	return $h;
}
function scs_node(array $f) {
	$n = new ManagedNode(NULL);
	$s = bin2hex(random_bytes(3));
	$n->set('mgn_name', 'HarnessTest scs ' . $s);
	$n->set('mgn_slug', 'harnesstest-scs-' . $s);
	$n->set('mgn_ssh_user', 'root');
	foreach ($f as $k => $v) { $n->set($k, $v); }
	$n->save();
	$n->load();
	return $n;
}
function scs_sold($node, $host, $tag, $item = 2147480002) {
	$p = new CustomerCloudProvision(NULL);
	foreach (array('cvp_origin' => 'order', 'cvp_external_order_item_id' => $item, 'cvp_usr_user_id' => 1,
		'cvp_domain' => 'scs-' . $tag . '.example.org', 'cvp_slug' => 'harnessscs-' . bin2hex(random_bytes(3)),
		'cvp_status' => 'done', 'cvp_hosting_mode' => 'operator', 'cvp_landing' => 'container',
		'cvp_docker_mode' => 'docker', 'cvp_mgh_managed_host_id' => (int)$host->key, 'cvp_port' => 8101,
		'cvp_mgn_managed_node_id' => (int)$node->key) as $k => $v) {
		$p->set($k, $v);
	}
	$p->save();
	$p->load();
	return $p;
}

$h1 = scs_host(1, $tag);
$h2 = scs_host(2, $tag);

section('A Starter site copied onto another starter host keeps its purchase');
$site = scs_node(array('mgn_host' => '192.0.2.71', 'mgn_container_name' => 'scssite', 'mgn_mgh_managed_host_id' => $h1->key,
	'mgn_port' => 8101, 'mgn_agent_public_key' => base64_encode(str_repeat("\x21", 32)), 'mgn_install_state' => 'switching'));
$copy = scs_node(array('mgn_host' => '192.0.2.72', 'mgn_container_name' => 'scssite', 'mgn_mgh_managed_host_id' => $h2->key,
	'mgn_port' => 8204, 'mgn_agent_public_key' => base64_encode(str_repeat("\x22", 32)), 'mgn_install_state' => 'copy',
	'mgn_copy_of_node_id' => $site->key));
$sold = scs_sold($site, $h1, $tag);
check($sold->is_sold() && $sold->is_container(), 'the fixture is a sold Starter site');
SiteCopySwap::take_over($site, $copy);
$site->load(); $copy->load(); $sold->load();
check((int)$site->get('mgn_mgh_managed_host_id') === (int)$h2->key && $copy->get('mgn_install_state') === 'retired',
	'the site\'s row holds the new container, the copy\'s row the old one');
check((int)$sold->get('cvp_mgn_managed_node_id') === (int)$site->key, 'the sold provision stays on the site\'s row, not the old container\'s');
check((int)$sold->get('cvp_mgh_managed_host_id') === (int)$h2->key && (int)$sold->get('cvp_port') === 8204,
	'and names the host and port the site now runs on', json_encode(array($sold->get('cvp_mgh_managed_host_id'), $sold->get('cvp_port'))));
$old_removed = new MultiCustomerCloudProvision(array('node_id' => (int)$copy->key, 'deleted' => false));
check(count($old_removed) === 0, 'so the old container\'s row holds no purchase its removal would take');
SiteCopySwap::go_back($site, $copy);
$site->load(); $copy->load(); $sold->load();
check((int)$sold->get('cvp_mgn_managed_node_id') === (int)$site->key && (int)$sold->get('cvp_mgh_managed_host_id') === (int)$h1->key
	&& (int)$sold->get('cvp_port') === 8101, 'the way back puts the old host and port back on it');

section('A Starter site copied onto a server of its own trades machine columns with the copy\'s provision');
$site2 = scs_node(array('mgn_host' => '192.0.2.73', 'mgn_container_name' => 'scssite2', 'mgn_mgh_managed_host_id' => $h1->key,
	'mgn_port' => 8102, 'mgn_agent_public_key' => base64_encode(str_repeat("\x23", 32)), 'mgn_install_state' => 'switching'));
$vps = scs_node(array('mgn_host' => '192.0.2.74', 'mgn_agent_public_key' => base64_encode(str_repeat("\x24", 32)),
	'mgn_install_state' => 'copy', 'mgn_copy_of_node_id' => $site2->key));
$sold2 = scs_sold($site2, $h1, $tag . 'b', 2147480003);
$sold2->set('cvp_port', 8102);
$sold2->save();
$copy_prov = new CustomerCloudProvision(NULL);
foreach (array('cvp_origin' => 'admin', 'cvp_usr_user_id' => 1, 'cvp_domain' => 'scs-' . $tag . 'b.example.org',
	'cvp_slug' => 'harnessscs-copy-' . bin2hex(random_bytes(3)), 'cvp_status' => 'done', 'cvp_hosting_mode' => 'customer',
	'cvp_landing' => 'instance', 'cvp_docker_mode' => 'bare-metal', 'cvp_install_mode' => 'copy',
	'cvp_instance_id' => '9990001', 'cvp_instance_ip' => '192.0.2.74', 'cvp_region' => 'us-east',
	'cvp_source_node_id' => (int)$site2->key, 'cvp_release' => '0.8.617',
	'cvp_mgn_managed_node_id' => (int)$vps->key) as $k => $v) {
	$copy_prov->set($k, $v);
}
$copy_prov->save();
$copy_prov->load();
SiteCopySwap::take_over($site2, $vps);
$sold2->load(); $copy_prov->load();
check((int)$sold2->get('cvp_mgn_managed_node_id') === (int)$site2->key && (int)$copy_prov->get('cvp_mgn_managed_node_id') === (int)$vps->key,
	'neither provision changes row');
check((string)$sold2->get('cvp_landing') === 'instance' && (string)$sold2->get('cvp_instance_id') === '9990001'
	&& (string)$sold2->get('cvp_origin') === 'order' && (int)$sold2->get('cvp_external_order_item_id') === 2147480003,
	'the sold one keeps its order and now names the new server');
check((string)$copy_prov->get('cvp_landing') === 'container' && (int)$copy_prov->get('cvp_mgh_managed_host_id') === (int)$h1->key
	&& !$copy_prov->get('cvp_instance_id') && (string)$copy_prov->get('cvp_install_mode') === 'copy',
	'the copy\'s names the old container its row now holds');
check((string)$sold2->get('cvp_docker_mode') === 'bare-metal' && (string)$copy_prov->get('cvp_docker_mode') === 'bare-metal',
	'the sold one runs as the copy\'s server was installed (bare metal); the copy\'s stays bare metal, as its copy install was');
$site2->load(); $vps->load();
SiteCopySwap::go_back($site2, $vps);
$sold2->load(); $copy_prov->load();
check((string)$sold2->get('cvp_landing') === 'container' && (int)$sold2->get('cvp_mgh_managed_host_id') === (int)$h1->key
	&& (string)$sold2->get('cvp_docker_mode') === 'docker' && !$sold2->get('cvp_instance_id')
	&& (string)$copy_prov->get('cvp_instance_id') === '9990001',
	'the way back trades them back: the sold one is the docker container again, the copy\'s the server');

section('Which host a sold site may be copied onto');
$host_agent = scs_node(array('mgn_host' => '192.0.2.75', 'mgn_agent_public_key' => base64_encode(str_repeat("\x25", 32))));
$starter = scs_host(3, $tag);
$starter->set('mgh_tier', 'starter');
$starter->set('mgh_mgn_managed_node_id', $host_agent->key);
$starter->save();
$reasons = function ($source) use ($starter) { return implode(' | ', SiteCopyRunner::host_refusals($source, $starter)); };
check(strpos($reasons($site), 'It is a starter host') === false, 'a sold Starter site may go to another starter host', $reasons($site));
$managed = scs_node(array('mgn_host' => '192.0.2.77'));
$mp = new CustomerCloudProvision(NULL);
foreach (array('cvp_origin' => 'order', 'cvp_external_order_item_id' => 2147480004, 'cvp_usr_user_id' => 1,
	'cvp_domain' => 'scs-' . $tag . 'm.example.org', 'cvp_slug' => 'harnessscs-m-' . bin2hex(random_bytes(3)),
	'cvp_status' => 'done', 'cvp_hosting_mode' => 'customer', 'cvp_landing' => 'instance', 'cvp_docker_mode' => 'docker',
	'cvp_instance_id' => '9990002', 'cvp_mgn_managed_node_id' => (int)$managed->key) as $k => $v) { $mp->set($k, $v); }
$mp->save();
check(strpos($reasons($managed), 'sold Managed site') !== false, 'a sold site on a server of its own is never copied onto a host', $reasons($managed));
$plain = scs_node(array('mgn_host' => '192.0.2.76'));
check(strpos($reasons($plain), 'It is a starter host') !== false, 'an operator\'s own site is still refused a starter host');

$db->rollBack();
harness_finish();
