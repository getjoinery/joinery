<?php
/** @joinery-test
 * name: data_root_layers
 * tier: db
 * env: any
 * needs: []
 */

/**
 * data_root_layers (specs/site_copy_final_test.md E2, E4): one layer of
 * encryption, never two, counted and kept.
 *
 *  - the count, read from both sides: ours from the host report, the
 *    provider's from the provision; unknown while either is;
 *  - a container's layers are its host's;
 *  - two is a warning incident on any machine but a management node, which
 *    never copies itself;
 *  - the provider's layer is recorded from its listing on a provision that has
 *    none, and never over one that has;
 *  - keeping the provider's layer when encrypting in place is a management
 *    node's alone; anywhere else the refusal points at a copy.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$tag = bin2hex(random_bytes(3));
$mk_node = function (string $suffix, array $set) use ($tag) {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'HarnessTest DRL ' . $suffix);
	$n->set('mgn_slug', 'harnessdrl-' . $suffix . '-' . $tag);
	$n->set('mgn_uptime_enabled', false);
	$n->set('mgn_host', '192.0.2.' . (100 + crc32($suffix) % 50));
	foreach ($set as $k => $v) {
		$n->set($k, $v);
	}
	$n->save();
	$n->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $n->key);
	return $n;
};
$provision = function (ManagedNode $n, ?string $layer, string $instance) use ($tag) {
	$p = new CustomerCloudProvision(NULL);
	foreach (array('cvp_origin' => 'admin', 'cvp_usr_user_id' => 1, 'cvp_domain' => 'drl-' . $instance . '.example.org', 'cvp_slug' => 'drl-' . $instance . '-' . $tag,
			'cvp_status' => 'done', 'cvp_docker_mode' => 'bare-metal', 'cvp_provider' => 'linode', 'cvp_hosting_mode' => 'operator',
			'cvp_instance_id' => $instance . $tag, 'cvp_provider_disk_encryption' => $layer, 'cvp_mgn_managed_node_id' => (int)$n->key) as $k => $v) {
		$p->set($k, $v);
	}
	$p->save();
	$p->load();
	harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $p->key);
	return $p;
};
$ours = json_encode(array('data_root_encryption' => array('backing' => 'luks-file', 'open' => true)));
$plain = json_encode(array('data_root_encryption' => 'none'));
$site = array('mgn_web_root' => '/var/www/html/drl/public_html', 'mgn_site_url' => 'https://drl.example.org');

// ---------------------------------------------------------------------------
section('The count');

$two = $mk_node('two', array_merge($site, array('mgn_last_host_report' => $ours)));
$provision($two, 'enabled', 'a');
$l = DataRootLayers::of($two);
check($l['count'] === 2 && $l['ours'] === true && $l['provider'] === 'enabled' && strpos($l['line'], 'Encryption layers: 2') === 0,
	'ours and the provider\'s: two', json_encode($l));
$one = $mk_node('one', array_merge($site, array('mgn_last_host_report' => $ours)));
$provision($one, 'disabled', 'b');
check(DataRootLayers::of($one)['count'] === 1, 'ours, the provider\'s off: one');
$linode = $mk_node('linode', array_merge($site, array('mgn_last_host_report' => $plain)));
$provision($linode, 'enabled', 'c');
check(DataRootLayers::of($linode)['count'] === 1, 'the provider\'s alone: one');
$brought = $mk_node('brought', array_merge($site, array('mgn_last_host_report' => $plain)));
$l = DataRootLayers::of($brought);
check($l['count'] === null && $l['ours'] === false && $l['provider'] === 'unknown' && strpos($l['line'], 'not known') !== false
	&& strpos($l['line'], 'ours: none') !== false, 'a brought server: none of ours, the provider unknown, the count not known', json_encode($l));

$hnode = $mk_node('host', array('mgn_host' => '192.0.2.160', 'mgn_last_host_report' => $ours));
$provision($hnode, 'disabled', 'h');
$mh = new ManagedHost(NULL);
foreach (array('mgh_slug' => 'harnessdrl-h-' . $tag, 'mgh_name' => 'HarnessTest DRL host', 'mgh_host' => '192.0.2.160',
		'mgh_mgn_managed_node_id' => (int)$hnode->key) as $k => $v) {
	$mh->set($k, $v);
}
$mh->save();
harness_register_row('mgh_managed_hosts', 'mgh_managed_host_id', $mh->key);
$box = $mk_node('box', array_merge($site, array('mgn_container_name' => 'drlbox', 'mgn_mgh_managed_host_id' => (int)$mh->key,
	'mgn_last_host_report' => $plain)));
$l = DataRootLayers::of($box);
check($l['count'] === 1 && $l['ours'] === true && strpos($l['line'], 'its host\'s') !== false, 'a container\'s layers are its host\'s', json_encode($l));

// ---------------------------------------------------------------------------
section('Two is a warning, except on a management node');

$src = new IncidentSourceTwoLayers();
$inc = $src->evaluate($two);
check($inc !== null && $inc['severity'] === IncidentRecord::SEVERITY_WARNING && strpos($inc['detail']['Fix'], 'Copy tab') !== false,
	'two layers on a site\'s server: a warning, with the copy as its fix', json_encode($inc));
check($src->evaluate($one) === null && $src->evaluate($linode) === null && $src->evaluate($brought) === null && $src->evaluate($box) === null,
	'one, or not known, or a container: nothing');
$mgmt = $mk_node('mgmt', array_merge($site, array('mgn_last_host_report' => $ours, 'mgn_agent_server_manager' => 'active')));
$provision($mgmt, 'enabled', 'm');
check(DataRootLayers::of($mgmt)['count'] === 2 && $src->evaluate($mgmt) === null, 'a management node keeps both until it is replaced: nothing');

// ---------------------------------------------------------------------------
section('The provider\'s layer, recorded from its listing');

$unrecorded = $mk_node('unrecorded', array_merge($site, array('mgn_last_host_report' => $ours)));
$p = $provision($unrecorded, null, 'u');
check(DataRootLayers::of($unrecorded)['count'] === null, 'never recorded: not known');
check(DataRootLayers::record_provider_layer('linode', 'u' . $tag, 'enabled') === 1, 'the listing records it');
check(DataRootLayers::of($unrecorded)['count'] === 2 && $src->evaluate($unrecorded) !== null, 'and then it counts, and warns');
check(DataRootLayers::record_provider_layer('linode', 'u' . $tag, 'disabled') === 0
	&& (string)(new CustomerCloudProvision($p->key, TRUE))->get('cvp_provider_disk_encryption') === 'enabled',
	'a recorded layer is never written over');
check(DataRootLayers::record_provider_layer('hetzner', 'u' . $tag, 'enabled') === 0, 'nor one of another provider\'s instance of that id');

// ---------------------------------------------------------------------------
section('Keeping the provider\'s layer in place is a management node\'s alone (E2)');

check(DataRootEncryptionOffer::may_keep_provider_layer($mgmt) && !DataRootEncryptionOffer::may_keep_provider_layer($linode),
	'a management node may keep both; a site\'s server may not');
harness_set_setting_mem('data_root_encryption_offered', '1');
$threw = '';
try { DataRootEncryptionOffer::queue($linode, true, 1); } catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'Copy the site onto a new server') !== false && strpos($threw, 'only for a management node') !== false,
	'asked to keep both on a site\'s server: refused, pointing at a copy', $threw);
$threw = '';
try { DataRootEncryptionOffer::queue($mgmt, true, 1); } catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'only for a management node') === false && strpos($threw, 'provider\'s disk encryption') === false,
	'on a management node the provider\'s layer is no reason to refuse (its other preconditions may be)', $threw);
harness_set_setting_mem('data_root_encryption_offered', '0');

harness_finish();
