<?php
/** @joinery-test
 * name: site_removal
 * tier: db
 * env: any
 * needs: []
 * covers: [plugins/server_manager/includes/SiteRemoval.php, plugins/server_manager/data/customer_cloud_provisions_class.php, plugins/server_manager/includes/InstanceTransferFinish.php]
 */
/**
 * A buyer takes a site on their own server off Your Sites (walkthrough Q36):
 * a built one is let go (agent forgotten, node disabled, the row gone), an
 * order that never built and cost nothing is removed, and neither a site we
 * host nor a never-built order that was paid for is offered.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$run = getmypid() . '-' . random_int(1000, 9999);
$buyer = make_user('SiteRemoval');

function srm_line($buyer, string $price) {
	$order = new Order(NULL);
	$order->set('ord_usr_user_id', $buyer->key);
	$order->save(); $order->load();
	harness_register_row('ord_orders', 'ord_order_id', $order->key);
	$line = new OrderItem(NULL);
	$line->set('odi_ord_order_id', $order->key);
	$line->set('odi_usr_user_id', $buyer->key);
	$line->set('odi_pro_product_id', 999999003);
	$line->set('odi_price', $price);
	$line->set('odi_is_subscription', false);
	$line->set('odi_status', OrderItem::STATUS_PAID);
	$line->save(); $line->load();
	harness_register_row('odi_order_items', 'odi_order_item_id', $line->key);
	return $line;
}

function srm_site($buyer, string $name, string $status, string $mode, array $fields = array()) {
	// A row past payment carries its paid line; a free one unless the test names another.
	if ($status !== 'pending_payment' && !isset($fields['cvp_external_order_item_id'])) {
		$fields['cvp_external_order_item_id'] = (int)srm_line($buyer, '0.00')->key;
	}
	$prov = new CustomerCloudProvision(NULL);
	$prov->set('cvp_origin', 'buyer');
	$prov->set('cvp_usr_user_id', $buyer->key);
	$prov->set('cvp_domain', $name . '.example.com');
	$prov->set('cvp_slug', $name);
	$prov->set('cvp_status', $status);
	$prov->set('cvp_hosting_mode', $mode);
	foreach ($fields as $k => $v) { $prov->set($k, $v); }
	$prov->save();
	$prov->load();
	harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $prov->key);
	return $prov;
}

function srm_node(string $slug) {
	$node = new ManagedNode(NULL);
	$node->set('mgn_name', 'HarnessTest removal ' . $slug);
	$node->set('mgn_slug', $slug);
	$node->set('mgn_host', '203.0.113.' . random_int(2, 250));
	$node->set('mgn_ssh_user', 'root');
	$node->set('mgn_web_root', '/var/www/html/' . $slug . '/public_html');
	$node->set('mgn_uptime_enabled', true);
	$node->set('mgn_enabled', true);
	$node->set('mgn_agent_public_key', 'srm-agent-' . $slug);
	$node->set('mgn_agent_version', AgentVocabulary::FLOOR);
	$node->save();
	$node->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node->key);
	return $node;
}

section('What is offered');
$hosted = srm_site($buyer, 'srm-hosted-' . $run, 'done', 'operator');
check(SiteRemoval::offered($hosted) === '', 'a site we host is not removed here: its subscription is cancelled instead');
$draft = srm_site($buyer, 'srm-draft-' . $run, 'pending_payment', 'customer');
check(SiteRemoval::offered($draft) === '', 'an unpaid setup has its own Delete');
$waiting = srm_site($buyer, 'srm-wait-' . $run, 'pending_connect', 'customer');
check(SiteRemoval::offered($waiting) === SiteRemoval::KIND_REMOVE, 'an order that never built and cost nothing can be removed');
$building = srm_site($buyer, 'srm-build-' . $run, 'installing', 'customer');
check(SiteRemoval::offered($building) === '', 'a site mid-build is left alone');

$line = srm_line($buyer, '15.98');
$paid_for = srm_site($buyer, 'srm-paid-' . $run, 'failed', 'customer', array('cvp_external_order_item_id' => (int)$line->key));
check(SiteRemoval::paid($paid_for) === 15.98 && SiteRemoval::offered($paid_for) === '',
	'a never-built order that was paid for is not removed here: the money goes back first', (string)SiteRemoval::paid($paid_for));

section('An order that never built is removed');
$result = SiteRemoval::remove($waiting);
$waiting->load();
check($result['kind'] === SiteRemoval::KIND_REMOVE && !empty($waiting->get('cvp_delete_time')), 'it leaves the page');
$threw = false;
try { SiteRemoval::remove($hosted); } catch (RuntimeException $e) { $threw = true; }
check($threw, 'a site that is not offered is refused');

section('A built site on the buyer\'s own server is let go');
$node = srm_node('srm-own-' . $run);
$own = srm_site($buyer, 'srm-own-' . $run, 'done', 'customer', array('cvp_mgn_managed_node_id' => (int)$node->key));
check(SiteRemoval::offered($own) === SiteRemoval::KIND_STOP, 'a built site on their own server offers Stop managing and remove');
check(strpos(SiteRemoval::question($own), 'Your server keeps running') !== false
	&& strpos(SiteRemoval::question($own), 'add it from this page') !== false, 'the question says the server is untouched and how to come back');
$result = SiteRemoval::remove($own);
$own->load();
$node = new ManagedNode((int)$node->key, TRUE);
check($result['kind'] === SiteRemoval::KIND_STOP && !empty($own->get('cvp_delete_time')), 'the site leaves the page');
check(!$node->get('mgn_enabled') && trim((string)$node->get('mgn_agent_public_key')) === '' && !$node->get('mgn_uptime_enabled'),
	'its agent is forgotten, its checks are off and the node is disabled');

section('A bundle bought later for the site\'s address is the site\'s bundle');
// Bought from the buyer's connected sites after the build: the purchase is on
// the service rows, not on the site's order (walkthrough finding 164).
$node3 = srm_node('srm-later-' . $run);
$later = srm_site($buyer, 'srm-later-' . $run, 'done', 'customer', array('cvp_mgn_managed_node_id' => (int)$node3->key));
check($later->bundle_order_item_id() === 0 && strpos(SiteRemoval::question($later), 'subscription is cancelled') === false,
	'a site with no bundle has none, and the question says nothing of one');
$bundle = srm_line($buyer, '2.99');
$tenant = new ServiceTenant(NULL);
$tenant->set('svt_usr_user_id', (int)$buyer->key);
$tenant->set('svt_service', ServiceTenant::SERVICE_MAIL);
$tenant->set('svt_state', ServiceTenant::STATE_ACTIVE);
$tenant->set('svt_paid_until', '2036-01-01 00:00:00');
$tenant->set('svt_slug', 'srm' . str_replace('-', '', $run));
$tenant->set('svt_host', (string)$later->get('cvp_domain'));
$tenant->set('svt_odi_order_item_id', (int)$bundle->key);
$tenant->save();
harness_register_row('svt_service_tenants', 'svt_service_tenant_id', $tenant->key);
check($later->bundle_order_item_id() === (int)$bundle->key, 'the line that pays for its service rows is found');
check(strpos(SiteRemoval::question($later), 'subscription is cancelled') !== false, 'and the question says it is cancelled with the site');
$with = srm_site($buyer, 'srm-with-' . $run, 'done', 'customer', array('cvp_services_order_item_id' => 424242));
check($with->bundle_order_item_id() === 424242, 'a bundle bought with the site is the one on its order');
check($hosted->bundle_order_item_id() === 0, 'a site we host has no bundle line');

section('As the signed-in buyer, on the rule the page checks');
// A member may not write a node or a provision; the page checks the site is
// theirs and writes on that rule (profile_sites_logic remove_site).
$node2 = srm_node('srm-mine-' . $run);
$mine = srm_site($buyer, 'srm-mine-' . $run, 'done', 'customer', array('cvp_mgn_managed_node_id' => (int)$node2->key));
$_SESSION['loggedin'] = 1; $_SESSION['usr_user_id'] = (int)$buyer->key; $_SESSION['permission'] = 0;
$_SERVER['REQUEST_METHOD'] = 'POST';
$refused = false;
try { SiteRemoval::remove(new CustomerCloudProvision((int)$mine->key, TRUE)); } catch (Throwable $e) { $refused = true; }
check($refused, 'outside that rule a member\'s write is refused');
$mine2 = new CustomerCloudProvision((int)$mine->key, TRUE);
$done = SystemBase::rule_checked_write(function () use ($mine2) { return SiteRemoval::remove($mine2); });
$mine2->load();
check($done['kind'] === SiteRemoval::KIND_STOP && !empty($mine2->get('cvp_delete_time'))
	&& !(new ManagedNode((int)$node2->key, TRUE))->get('mgn_enabled'), 'on it, the buyer\'s own site is let go');
unset($_SERVER['REQUEST_METHOD']);
$_SESSION = array();

harness_finish();
