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
 *    node's alone; anywhere else the refusal points at a copy;
 *  - what to do while the provider's layer is on is one answer: a copy, into
 *    the owner's account once their key is proven where it is not (B114);
 *  - the machine is told the provider's layer when it reports another, so its
 *    own data root script refuses as the node page does (B114).
 *
 * @version 1.2 - the node page's Encrypt button stays off where the one-layer rule always refuses (B148)
 * @version 1.1 - the one fix while the provider's layer is on, and the machine told the layer (B114)
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
check(!DataRootEncryptionOffer::layer_permits($linode) && !DataRootEncryptionOffer::layer_permits($brought)
	&& DataRootEncryptionOffer::layer_permits($one) && DataRootEncryptionOffer::layer_permits($mgmt),
	'the Encrypt button can be live only where the provider\'s layer is off, or on a management node (B148)');
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

// ---------------------------------------------------------------------------
section('While the provider\'s layer is on: one answer, a copy (B114)');

$customer = function (ManagedNode $n) {
	$n->set('mgn_cloud_account', CloudAccounts::CUSTOMER);
	$n->save();
	foreach (new MultiCustomerCloudProvision(array('node_id' => (int)$n->key, 'deleted' => false)) as $p) {
		$p->set('cvp_hosting_mode', 'customer');
		$p->save();
	}
};
$fix = DataRootEncryptionOffer::provider_layer_fix($linode);
check(strpos($fix, 'Copy the site onto a new server from its Copy tab: it is born encrypted by us with the provider\'s layer off.') === 0,
	'in one of our accounts: a copy, born with ours', $fix);
$keyed = $mk_node('keyed', array_merge($site, array('mgn_last_host_report' => $plain, 'mgn_backup_recovery_public_key' => base64_encode(random_bytes(32)))));
$provision($keyed, 'enabled', 'k');
$customer($keyed);
$fix = DataRootEncryptionOffer::provider_layer_fix($keyed);
check(strpos($fix, 'in its owner\'s account') !== false && strpos($fix, 'sealed to its owner\'s recovery key') !== false
	&& strpos($fix, 'Prove') === false, 'in its owner\'s account with a proven key: a copy into that account, sealed to it', $fix);
$keyless = $mk_node('keyless', array_merge($site, array('mgn_last_host_report' => $plain)));
$provision($keyless, 'enabled', 'n');
$customer($keyless);
$fix = DataRootEncryptionOffer::provider_layer_fix($keyless);
check(strpos($fix, 'Prove this site\'s recovery key on its own Backups page first') === 0 && strpos($fix, 'no copy') !== false,
	'without one: prove the key first, since no copy runs before it', $fix);
$note = function (ManagedNode $n) {
	foreach (DataRootEncryptionOffer::preconditions($n) as $p) {
		if ($p['label'] === 'In one of our accounts') { return $p['note']; }
	}
	return '';
};
check(strpos($note($keyless), 'where its provider already encrypts its disk') !== false && strpos($note($keyless), 'own Backups page') === false,
	'the checklist never sends an owner\'s machine with the provider\'s layer on to its own Backups page', $note($keyless));
$owner_off = $mk_node('owneroff', array_merge($site, array('mgn_last_host_report' => $plain)));
$provision($owner_off, 'disabled', 'o');
$customer($owner_off);
check(strpos($note($owner_off), 'encrypted from its own Backups page') !== false, 'with the provider\'s layer off it does: one layer either way', $note($owner_off));
harness_set_setting_mem('data_root_encryption_offered', '1');
$threw = '';
try { DataRootEncryptionOffer::queue($keyless, false, 1); } catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'Prove this site\'s recovery key') !== false && strpos($threw, 'own Backups page, sealed') === false
	&& substr_count($threw, 'copy the site onto a new server') + substr_count($threw, 'Copy the site onto a new server') === 1,
	'the refusal says one thing: prove the key, then a copy', $threw);
harness_set_setting_mem('data_root_encryption_offered', '0');

// ---------------------------------------------------------------------------
section('The machine is told the provider\'s layer (B114)');

$words = array('mgn_agent_public_key' => base64_encode(random_bytes(32)), 'mgn_agent_version' => '1.96.0',
	'mgn_agent_primitives' => 'host_report,data_root_provider_layer');
$told = function (ManagedNode $n) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare("SELECT mjb_management_job_id, mjb_commands->'params'->>'layer' FROM mjb_management_jobs
		WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = 'data_root_provider_layer' ORDER BY 1");
	$q->execute(array((int)$n->key));
	$out = array();
	foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) {
		harness_register_row('mjb_management_jobs', 'mjb_management_job_id', (int)$r[0]);
		$out[] = $r[1];
	}
	return $out;
};
$report = function (?string $layer, string $script = '1.32') {
	$r = array('data_root_script' => array('installed_version' => $script, 'release_version' => $script, 'consumers' => array()));
	if ($layer !== null) { $r['data_root_provider_layer'] = $layer; }
	return $r;
};
$tell = $mk_node('tell', array_merge($site, $words, array('mgn_last_host_report' => $plain)));
$provision($tell, 'enabled', 't');
check(DataRootLayers::push_provider_layer($tell, $report('unknown')) && $told($tell) === array('enabled'),
	'a machine that has recorded nothing is told what this side knows: enabled', json_encode($told($tell)));
check(!DataRootLayers::push_provider_layer($tell, $report('unknown')) && count($told($tell)) === 1,
	'not twice within the hour, while its report still says otherwise');
check(!DataRootLayers::push_provider_layer($tell, $report('disabled')) && count($told($tell)) === 1,
	'nor when it says disabled within that hour: the same value is the one sent');
$agree = $mk_node('agree', array_merge($site, $words, array('mgn_last_host_report' => $plain)));
$provision($agree, 'disabled', 'g');
check(!DataRootLayers::push_provider_layer($agree, $report('disabled')) && $told($agree) === array(), 'one that already says it is told nothing');
check(DataRootLayers::push_provider_layer($agree, $report('enabled')) && $told($agree) === array('disabled'),
	'one that says otherwise is told again', json_encode($told($agree)));
$oldscript = $mk_node('oldscript', array_merge($site, $words, array('mgn_last_host_report' => $plain)));
$provision($oldscript, 'enabled', 's');
check(!DataRootLayers::push_provider_layer($oldscript, $report('unknown', '1.31')) && $told($oldscript) === array(),
	'a release whose data root script cannot take it (before 1.32): nothing');
check(!DataRootLayers::push_provider_layer($oldscript, array('data_root_script' => 'none', 'data_root_provider_layer' => 'unknown'))
	&& $told($oldscript) === array(), 'no data root yet: nothing, until it has one');
check(!DataRootLayers::push_provider_layer($oldscript, $report(null)) && $told($oldscript) === array(),
	'a host report from before the key: nothing');
$noword = $mk_node('noword', array_merge($site, $words, array('mgn_agent_primitives' => 'host_report', 'mgn_last_host_report' => $plain)));
$provision($noword, 'enabled', 'w');
check(!DataRootLayers::push_provider_layer($noword, $report('unknown')) && $told($noword) === array(), 'an agent without the word: nothing');
$unknown = $mk_node('unknownlayer', array_merge($site, $words, array('mgn_last_host_report' => $plain)));
check(!DataRootLayers::push_provider_layer($unknown, $report('enabled')) && $told($unknown) === array(),
	'where this side does not know the layer, nothing: unknown is never sent');
foreach ($words as $k => $v) { $box->set($k, $v); }
$box->save();
check(!DataRootLayers::push_provider_layer($box, $report('unknown')) && $told($box) === array(), 'a container: nothing (its host is the machine)');
$cmd = JobCommandBuilder::build_data_root_provider_layer($tell, 'disabled');
check($cmd === array('primitive' => 'data_root_provider_layer', 'params' => array('layer' => 'disabled')), 'the word carries the one value', json_encode($cmd));
foreach (array('unknown', 'on', '') as $bad) {
	$threw = '';
	try { JobCommandBuilder::build_data_root_provider_layer($tell, $bad); } catch (Exception $e) { $threw = $e->getMessage(); }
	check($threw !== '', "a layer of '{$bad}' is refused here", $threw);
}
$threw = '';
try { JobCommandBuilder::build_data_root_provider_layer($box, 'enabled'); } catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'container') !== false, 'and a container node is refused by name', $threw);
check(in_array('data_root_provider_layer', JobResultProcessor::processable_types(), true),
	'its job asks for the next host report as it ends, as the other data root words do');
$kept = JobResultProcessor::sanitise_host_report(array('generated_at' => gmdate('c'), 'data_root_provider_layer' => 'enabled'));
$odd = JobResultProcessor::sanitise_host_report(array('generated_at' => gmdate('c'), 'data_root_provider_layer' => '<b>on</b>'));
$older = JobResultProcessor::sanitise_host_report(array('generated_at' => gmdate('c')));
check(($kept['data_root_provider_layer'] ?? null) === 'enabled' && ($odd['data_root_provider_layer'] ?? null) === 'unknown'
	&& array_key_exists('data_root_provider_layer', $older) && $older['data_root_provider_layer'] === null,
	'the host report keeps it: enabled or disabled as said, anything else unknown, null from an older node');

harness_finish();
