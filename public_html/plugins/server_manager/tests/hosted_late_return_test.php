<?php
/** @joinery-test
 * name: hosted_late_return
 * tier: db
 * env: any
 * needs: []
 */
/**
 * Bringing a held Starter site back after a late payment
 * (specs/hosting_paid_launch.md O3, HostedLateReturn).
 *
 *  - **Refused** until a subscription pays for it, and while it is suspended.
 *  - **Its own line active again**: the container is asked to start, and
 *    nothing else changes until its host answers that it runs; then the
 *    hosting row is subscribed with every date and the removal mark cleared,
 *    backups follow the fleet's defaults and uptime checks are on. A start
 *    that did not take changes nothing, and Bring back is offered again.
 *  - **Its own line cancelled, a new one bought**: the site moves to the one
 *    active subscription to the same product that no other site is on.
 *
 * Every row is made inside one transaction and rolled back.
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$db = DbConnector::get_instance()->get_db_link();
$db->beginTransaction();
$t = substr(bin2hex(random_bytes(4)), 0, 8);
$buyer = 990000 + random_int(0, 9999);

$host_node = new ManagedNode(NULL);
foreach (array('mgn_name' => 'HarnessTest hlr host ' . $t, 'mgn_slug' => 'harnesstest-hlr-host-' . $t, 'mgn_host' => '192.0.2.80',
	'mgn_ssh_user' => 'root', 'mgn_agent_public_key' => base64_encode(str_repeat("\x31", 32))) as $k => $v) { $host_node->set($k, $v); }
$host_node->save();
$host = new ManagedHost(NULL);
$host->set('mgh_slug', 'harnesstest-hlr-' . $t);
$host->set('mgh_name', 'HarnessTest hlr ' . $t);
$host->set('mgh_host', '192.0.2.80');
$host->set('mgh_mgn_managed_node_id', $host_node->key);
$host->prepare();
$host->save();
$node = new ManagedNode(NULL);
foreach (array('mgn_name' => 'HarnessTest hlr ' . $t, 'mgn_slug' => 'harnesstest-hlr-' . $t, 'mgn_host' => '192.0.2.80',
	'mgn_ssh_user' => 'root', 'mgn_container_name' => 'hlr' . $t, 'mgn_mgh_managed_host_id' => $host->key,
	'mgn_uptime_enabled' => false, 'mgn_backup_policy' => json_encode(array('enabled' => false))) as $k => $v) { $node->set($k, $v); }
$node->save();
$node->load();

$product = new Product(NULL);
$product->set('pro_name', 'HLR Starter ' . $t);
$product->set('pro_link', 'hlr-starter-' . $t);
$product->save();
$line = function ($status, $cancelled = false) use ($product, $buyer) {
	$o = new Order(NULL);
	$o->set('ord_usr_user_id', $buyer);
	$o->set('ord_status', Order::STATUS_PAID);
	$o->set('ord_total_cost', 4.99);
	$o->save();
	$i = new OrderItem(NULL);
	$i->set('odi_ord_order_id', $o->key);
	$i->set('odi_usr_user_id', $buyer);
	$i->set('odi_pro_product_id', $product->key);
	$i->set('odi_price', 4.99);
	$i->set('odi_status', OrderItem::STATUS_PAID);
	$i->set('odi_is_subscription', true);
	$i->set('odi_subscription_status', $status);
	if ($cancelled) { $i->set('odi_subscription_cancelled_time', gmdate('Y-m-d H:i:s')); }
	$i->save();
	$i->load();
	return $i;
};
$own = $line('past_due');

$provision = new CustomerCloudProvision(NULL);
foreach (array('cvp_origin' => 'order', 'cvp_external_order_item_id' => (int)$own->key, 'cvp_usr_user_id' => $buyer,
	'cvp_domain' => 'hlr' . $t . '.example.com', 'cvp_slug' => 'hlr' . $t, 'cvp_hosting_mode' => 'operator',
	'cvp_landing' => 'container', 'cvp_status' => 'done', 'cvp_mgn_managed_node_id' => $node->key,
	'cvp_mgh_managed_host_id' => $host->key) as $k => $v) { $provision->set($k, $v); }
$provision->save();
$provision->load();
$trial = new HostedTrial(NULL);
foreach (array('htr_cvp_customer_cloud_provision_id' => (int)$provision->key, 'htr_state' => HostedTrial::STATE_SHUTDOWN,
	'htr_payment_failed_time' => gmdate('Y-m-d H:i:s', time() - 40 * 86400), 'htr_shutdown_time' => gmdate('Y-m-d H:i:s', time() - 10 * 86400),
	'htr_shelf_ends_time' => gmdate('Y-m-d H:i:s', time() + 50 * 86400), 'htr_remove_ready_time' => gmdate('Y-m-d H:i:s')) as $k => $v) { $trial->set($k, $v); }
$trial->prepare();
$trial->save();
$trial->load();

section('Refused until a subscription pays for it, while suspended, and while its removal runs');
check(strpos(HostedLateReturn::refusal($node), 'No active subscription') !== false, 'its own line still past_due: refused');
$own->set('odi_subscription_status', 'active');
$own->save();
$sus = new HostedSuspension(NULL);
$sus->set('hsu_cvp_customer_cloud_provision_id', (int)$provision->key);
$sus->set('hsu_reason', 'harness');
$sus->save();
check(strpos(HostedLateReturn::refusal($node), 'Unsuspend') !== false, 'suspended: refused, naming Unsuspend');
$sus->set('hsu_unsuspend_time', gmdate('Y-m-d H:i:s'));
$sus->save();
$rm = new ManagementJob(NULL);
$rm->set('mjb_mgn_managed_node_id', (int)$host_node->key);
$rm->set('mjb_job_type', 'decommission_node');
$rm->set('mjb_commands', '[]');
$rm->set('mjb_status', 'pending');
$rm->set('mjb_parameters', json_encode(array('victim_node_id' => (int)$node->key)));
$rm->save();
check(strpos(HostedLateReturn::refusal($node), 'removal is under way') !== false, 'a removal pending on its host: refused');
$rm->set('mjb_status', 'cancelled');
$rm->save();
check(HostedLateReturn::refusal($node) === '', 'its own line active again: offered');

section('Brought back on its own line: the row changes only once its host has started it');
$started = array();
$steps = HostedLateReturn::bring_back($node, 1, function ($h, $name) use (&$started) { $started[] = array((int)$h->key, $name); });
$trial->load(); $node->load();
check($started === array(array((int)$host_node->key, 'hlr' . $t)), 'its host is asked to start its container', json_encode($started));
check((string)$trial->get('htr_state') === HostedTrial::STATE_SHUTDOWN && !$node->get('mgn_uptime_enabled'),
	'nothing else changes yet: still shut down, uptime still off');
$fake_job = function ($status, $result) use ($host_node, $provision) {
	$j = new ManagementJob(NULL);
	$j->set('mjb_mgn_managed_node_id', (int)$host_node->key);
	$j->set('mjb_job_type', 'hold_container');
	$j->set('mjb_commands', '[]');
	$j->set('mjb_status', $status);
	$j->set('mjb_parameters', json_encode(array('provision_id' => (int)$provision->key, 'late_return' => '1')));
	if ($result !== null) { $j->set('mjb_result', json_encode($result)); }
	$j->save();
	return $j;
};
$j1 = $fake_job('running', null);
check(strpos(HostedLateReturn::refusal($node), 'starting it already') !== false, 'while the start waits, Bring back is not offered again');
check(HostedLateReturn::settle($provision, $trial) === 0, 'and the watch changes nothing');
check(!JobCommandBuilder::decommission_is_held($node), 'nor is it offered for removal while its host starts it');
$trial->set('htr_shelf_ends_time', gmdate('Y-m-d H:i:s', time() - 60));
$trial->save();
$watch = new HostedTrialWatch();
$wm = new ReflectionMethod('HostedTrialWatch', 'watch');
$ran = $wm->invoke($watch, $provision);
$trial->load();
check($ran === 0 && trim((string)$trial->get('htr_shelf_ends_time')) !== '',
	'and its backups are not pruned while its host starts it, though its backup date has passed');
$j1->set('mjb_status', 'completed');
$j1->set('mjb_result', json_encode(array('action' => 'start', 'done' => false)));
$j1->save();
check(HostedLateReturn::settle($provision, $trial) === 0 && HostedLateReturn::start_outcome($provision) === false
	&& HostedLateReturn::refusal($node) === '', 'a start that did not take changes nothing, and Bring back is offered again');
$fake_job('completed', array('action' => 'start', 'done' => true));
check(HostedLateReturn::settle($provision, $trial) === 1, 'once the host says it runs, the watch settles it');
$trial->load(); $node->load();
check((string)$trial->get('htr_state') === HostedTrial::STATE_SUBSCRIBED && $trial->get('htr_remove_ready_time') === null
	&& $trial->get('htr_shelf_ends_time') === null && $trial->get('htr_grace_ends_time') === null,
	'its hosting is subscribed, every date and the removal mark cleared');
check($node->get('mgn_backup_policy') === null && (bool)$node->get('mgn_uptime_enabled'),
	'backups follow the fleet\'s defaults again and uptime checks are on');
$why = '';
try { HostedLateReturn::bring_back($node, 1, function () {}); } catch (DisplayableUserException $e) { $why = $e->getMessage(); }
check(strpos($why, 'not shut down') !== false, 'asked again, it refuses: nothing to bring back', $why);

section('Its own line cancelled, a new one bought: it moves to the new line');
$trial->set('htr_state', HostedTrial::STATE_SHUTDOWN);
$trial->set('htr_shutdown_time', gmdate('Y-m-d H:i:s', time() + 5));
$trial->save();
check(HostedLateReturn::settle($provision, $trial) === 0, 'a start from before this shutdown says nothing about it');
$own->set('odi_subscription_cancelled_time', gmdate('Y-m-d H:i:s'));
$own->set('odi_subscription_status', 'canceled');
$own->save();
$fresh = $line('active');
$second = $line('active');
check(strpos(HostedLateReturn::refusal($node), 'No active subscription') !== false,
	'two candidate lines: refused rather than guessing');
$second->set('odi_subscription_cancelled_time', gmdate('Y-m-d H:i:s'));
$second->save();
check(HostedLateReturn::refusal($node) === '', 'one candidate line: offered');
$steps = HostedLateReturn::bring_back($node, 1, function () {});
$provision->load(); $trial->load();
check((int)$provision->get('cvp_external_order_item_id') === (int)$fresh->key && (int)$trial->get('htr_external_order_item_id') === (int)$fresh->key,
	'the site and its hosting row now name the new line', json_encode($steps));

$db->rollBack();
harness_finish();
