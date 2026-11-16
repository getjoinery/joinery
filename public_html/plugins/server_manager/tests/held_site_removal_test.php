<?php
/** @joinery-test
 * name: held_site_removal
 * tier: db
 * env: any
 * needs: []
 */
/**
 * Removing a held Starter site, by a person (specs/hosting_paid_launch.md B1).
 *
 *  - **The keep-period's end marks a held Starter site ready to remove**, and
 *    a site never backed up here (no storage space) ends it with nothing to
 *    empty rather than never.
 *  - **A late payment takes the mark away.**
 *  - **Permanently Delete Site picks decommission_held_site** for a marked
 *    site, with no approval from the site and whatever release it was on;
 *    an unmarked one keeps asking for the site's approval, and a host agent
 *    without the word refuses rather than falling back.
 *  - **When the host verifies the site gone**, its unpaid subscription is
 *    cancelled, its mail account and open suspension are closed and its node
 *    goes, as for any removal.
 *
 * Every row is made inside one transaction and rolled back.
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$db = DbConnector::get_instance()->get_db_link();
$db->beginTransaction();
$suffix = substr(bin2hex(random_bytes(4)), 0, 8);

function hsr_node(array $fields) {
	$node = new ManagedNode(NULL);
	$s = bin2hex(random_bytes(3));
	$node->set('mgn_name', 'HarnessTest held ' . $s);
	$node->set('mgn_slug', 'harnesstest-held-' . $s);
	$node->set('mgn_host', '192.0.2.20');
	$node->set('mgn_ssh_user', 'root');
	foreach ($fields as $k => $v) { $node->set($k, $v); }
	$node->save();
	$node->load();
	return $node;
}

// A starter host with a paired agent that knows the held word.
$host_node = hsr_node(array(
	'mgn_agent_public_key' => base64_encode(str_repeat("\x0d", 32)),
	'mgn_agent_version'    => '1.123.0',
	'mgn_agent_primitives' => 'check_status,decommission_site,decommission_moved_site,decommission_held_site',
));
$host = new ManagedHost(NULL);
$host->set('mgh_slug', 'harnesstest-held-host-' . $suffix);
$host->set('mgh_name', 'HarnessTest held host ' . $suffix);
$host->set('mgh_host', '192.0.2.20');
$host->set('mgh_mgn_managed_node_id', $host_node->key);
$host->prepare();
$host->save();
$host->load();

$site_name = 'hsr' . $suffix;
$site = hsr_node(array(
	'mgn_container_name'      => $site_name,
	'mgn_web_root'            => '/var/www/html/' . $site_name . '/public_html',
	'mgn_mgh_managed_host_id' => $host->key,
	'mgn_joinery_version'     => '0.8.100',   // far below the approval panel: a held site needs none
	'mgn_last_status_check'   => gmdate('Y-m-d H:i:s', time() - 70 * 86400),
));

$provision = new CustomerCloudProvision(NULL);
$provision->set('cvp_origin', 'order');
$provision->set('cvp_external_order_item_id', 2147480001);
$provision->set('cvp_usr_user_id', 990000 + random_int(0, 9999));
$provision->set('cvp_domain', $site_name . '.example.com');
$provision->set('cvp_slug', $site_name);
$provision->set('cvp_hosting_mode', 'operator');
$provision->set('cvp_landing', 'container');
$provision->set('cvp_status', 'done');
$provision->set('cvp_buyer_email', $site_name . '@example.com');
$provision->set('cvp_mgn_managed_node_id', $site->key);
$provision->set('cvp_mgh_managed_host_id', $host->key);
$provision->save();
$provision->load();

$trial = new HostedTrial(NULL);
$trial->set('htr_cvp_customer_cloud_provision_id', (int)$provision->key);
$trial->set('htr_state', HostedTrial::STATE_SHUTDOWN);
$trial->set('htr_payment_failed_time', gmdate('Y-m-d H:i:s', time() - 91 * 86400));
$trial->set('htr_shutdown_time', gmdate('Y-m-d H:i:s', time() - 61 * 86400));
$trial->set('htr_shelf_ends_time', gmdate('Y-m-d H:i:s', time() - 3600));
$trial->prepare();
$trial->save();
$trial->load();

section('The keep-period\'s end marks a held Starter site ready to remove');
check(!JobCommandBuilder::decommission_is_held($site), 'before the prune it is not ready');
$before = '';
try { JobCommandBuilder::build_decommission_node($site); } catch (Exception $e) { $before = $e->getMessage(); }
check(strpos($before, 'cannot render the removal approval') !== false,
	'an unmarked held site falls to the ordinary word, which needs an approval it cannot give', $before);
$watch = new HostedTrialWatch();
$prune = new ReflectionMethod('HostedTrialWatch', 'prune_shelf_if_due');
$changed = $prune->invoke($watch, $provision, $trial);
$trial->load();
check($changed === 1 && trim((string)$trial->get('htr_remove_ready_time')) !== '' && $trial->get('htr_shelf_ends_time') === null,
	'a site with no backup storage ends its keep-period with nothing to empty, and is marked ready');
check(JobCommandBuilder::decommission_is_held($site), 'the marked site is a held removal');

section('Permanently Delete Site takes the host\'s hold for a marked site');
$env = JobCommandBuilder::build_decommission_node($site);
check(($env['primitive'] ?? '') === 'decommission_held_site' && ($env['params'] ?? null) === array('site' => $site_name),
	'it routes as decommission_held_site with only the site name, at a core far below the approval panel', json_encode($env));

$bare_host_node = hsr_node(array(
	'mgn_agent_public_key' => base64_encode(str_repeat("\x0e", 32)),
	'mgn_agent_version'    => '1.122.0',
	'mgn_agent_primitives' => 'check_status,decommission_site',
));
$host->set('mgh_mgn_managed_node_id', $bare_host_node->key);
$host->save();
$msg = '';
try { JobCommandBuilder::build_decommission_node($site); } catch (Exception $e) { $msg = $e->getMessage(); }
check(strpos($msg, 'decommission_held_site') !== false && strpos($msg, 'held after its hosting ended') !== false,
	'a host agent without the word refuses; it never falls back to an approval the stopped site cannot give', $msg);
$host->set('mgh_mgn_managed_node_id', $host_node->key);
$host->save();

section('A late payment takes the mark away');
$clear = new ReflectionMethod('HostedTrialSignals', 'clear_grace');
$clear->invoke(null, $trial);
$trial->load();
check($trial->get('htr_remove_ready_time') === null && (string)$trial->get('htr_state') === HostedTrial::STATE_SHUTDOWN,
	'the row stays shut down, its ready mark cleared');
check(!JobCommandBuilder::decommission_is_held($site), 'and the site is no longer a held removal');
$after = '';
try { JobCommandBuilder::build_decommission_node($site); } catch (Exception $e) { $after = $e->getMessage(); }
check(strpos($after, 'cannot render the removal approval') !== false,
	'and Permanently Delete Site goes back to the ordinary word', $after);
$trial->set('htr_remove_ready_time', gmdate('Y-m-d H:i:s'));
$trial->save();

section('Only a sold Starter site in shutdown counts');
$provision->set('cvp_origin', 'admin');
$provision->save();
check(!JobCommandBuilder::decommission_is_held($site), 'an admin\'s own site (not sold) is not a held removal');
$provision->set('cvp_origin', 'order');
$provision->save();
$trial->set('htr_state', HostedTrial::STATE_GRACE);
$trial->save();
check(!JobCommandBuilder::decommission_is_held($site), 'a row still in grace is not');
$trial->set('htr_state', HostedTrial::STATE_SHUTDOWN);
$trial->save();
check(JobCommandBuilder::decommission_is_held($site), 'back in shutdown with its mark, it is again');

section('The mail account goes with the site');
$provision->set('cvp_smtp2go_subaccount_id', 'sub-' . $suffix);
$provision->save();
$closed = array();
$fake = new class($closed) {
	public $closed;
	public function __construct(&$c) { $this->closed = &$c; }
	public function subaccountState(string $id): string { return 'active'; }
	public function closeSubaccount(string $id): void { $this->closed[] = $id; }
};
$notes = HostedHeldRemoval::finish($site, function () use ($fake) { return $fake; });
$provision->load();
check($closed === array('sub-' . $suffix) && $provision->get('cvp_smtp2go_subaccount_id') === null,
	'its SMTP2GO subaccount is closed and forgotten', json_encode($notes));
$failing = new class {
	public function subaccountState(string $id): string { throw new RuntimeException('unreachable'); }
	public function closeSubaccount(string $id): void {}
};
$provision->set('cvp_smtp2go_subaccount_id', 'sub2-' . $suffix);
$provision->save();
$notes = HostedHeldRemoval::finish($site, function () use ($failing) { return $failing; });
$provision->load();
check(strpos(implode(' ', $notes), 'was not closed') !== false && $provision->get('cvp_smtp2go_subaccount_id') === 'sub2-' . $suffix,
	'a close that fails says so and keeps the id for a person', json_encode($notes));
$provision->set('cvp_smtp2go_subaccount_id', null);
$provision->save();

section('An unpaid subscription is cancelled with the site');
$product = new Product(NULL);
$product->set('pro_name', 'HSR hosting ' . $suffix);
$product->set('pro_link', 'hsr-hosting-' . $suffix);
$product->save();
$order = new Order(NULL);
$order->set('ord_usr_user_id', (int)$provision->get('cvp_usr_user_id'));
$order->set('ord_status', Order::STATUS_PAID);
$order->set('ord_total_cost', 4.99);
$order->save();
$item = new OrderItem(NULL);
$item->set('odi_ord_order_id', $order->key);
$item->set('odi_pro_product_id', $product->key);
$item->set('odi_usr_user_id', (int)$provision->get('cvp_usr_user_id'));
$item->set('odi_price', 4.99);
$item->set('odi_status', OrderItem::STATUS_PAID);
$item->set('odi_is_subscription', true);
$item->set('odi_subscription_status', 'past_due');
$item->save();
$provision->set('cvp_external_order_item_id', (int)$item->key);
$provision->save();
$broken = function ($i) { throw new RuntimeException('processor down'); };
$notes = HostedHeldRemoval::finish($site, function () { return null; }, $broken);
$item->load();
check(strpos(implode(' ', $notes), 'Cancel it by hand') !== false && !$item->get('odi_subscription_cancelled_time'),
	'a cancel that fails says to cancel by hand and leaves the line as it is', json_encode($notes));
$asked = array();
$notes = HostedHeldRemoval::finish($site, function () { return null; }, function ($i) use (&$asked) { $asked[] = (int)$i->key; });
$item->load();
check($asked === array((int)$item->key) && $item->get('odi_subscription_cancelled_time') && (string)$item->get('odi_subscription_status') === 'canceled',
	'a past_due subscription still open is cancelled at its processor and marked cancelled', json_encode($notes));
$asked = array();
HostedHeldRemoval::finish($site, function () { return null; }, function ($i) use (&$asked) { $asked[] = (int)$i->key; });
check($asked === array(), 'one already cancelled is left alone');
check(JobCommandBuilder::held_removal_accepted_date($trial) === gmdate('Y-m-d', strtotime((string)$trial->get('htr_shutdown_time') . ' UTC') + 55 * 86400 + 86399),
	'the day its host accepts the removal is 55 days after the hold');

section('When the host verifies it gone, its suspension closes and its node goes');
$sus = new HostedSuspension(NULL);
$sus->set('hsu_cvp_customer_cloud_provision_id', (int)$provision->key);
$sus->set('hsu_reason', 'harness abuse case');
$sus->save();
$sus->load();
$job = ManagementJob::createFromBuild($host_node->key, 'decommission_node', $env,
	array('victim_node_id' => (int)$site->key, 'site' => $site_name, 'held_removal' => '1'), 1);
$job->set('mjb_status', 'completed');
$job->set('mjb_output', json_encode(array('status' => 'ok', 'data' => array('output' => "removing\nDECOMMISSION_VERIFIED\n"))));
$job->save();
JobResultProcessor::process($job);
$job->load();
$result = json_decode((string)$job->get('mjb_result'), true);
$site->load();
$sus->load();
check(!empty($result['decommissioned']) && $site->get('mgn_delete_time') && $site->get('mgn_site_removed_time'),
	'the node is soft-deleted and stamped removed', json_encode($result));
check($sus->get('hsu_unsuspend_time') !== null && HostedSuspension::open_for($provision->key) === null,
	'its open suspension is closed, so Unsuspend is refused from now on');
check(strpos(json_encode($result['also'] ?? array()), 'suspension was closed') !== false,
	'the job\'s notes say so', json_encode($result['also'] ?? null));
$provision->load();
check((bool)$provision->get('cvp_delete_time'), 'its provision goes with the node, freeing its place and its relay slot');

$db->rollBack();
harness_finish();
