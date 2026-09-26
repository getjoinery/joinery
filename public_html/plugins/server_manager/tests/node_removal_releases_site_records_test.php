<?php
/** @joinery-test
 * name: node_removal_releases_site_records
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Removing a node ends the dashboard's work on its site.
 *
 * test380s was removed from the dashboard and its Linode deleted, and the
 * provisioning task went on working on it every fifteen minutes: waiting for a
 * certificate, asking the removed node to prepare mail for its domain, and
 * holding a hosted trial that would run out and try to power off a machine
 * already gone. This pins what removal does instead:
 *
 *  - the provisioning record and its hosted trial are removed;
 *  - a domain still being bought or wired up is parked for a person, never
 *    deleted (the buyer is its registrant); an active one is left as it is;
 *  - a subscription still billing is named, never touched;
 *  - another site's records are untouched, and asking again does nothing;
 *  - the domain stage parks a row whose node was removed before this existed,
 *    and the domain watch sends a removed node no notice;
 *  - sm_008 releases a provision left live by a removal before this existed.
 *
 * Run: php plugins/server_manager/tests/node_removal_releases_site_records_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionManagedDomains.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ManagedDomainWatch.php'));

$suffix = getmypid() . '-' . random_int(1000, 9999);
$buyer = make_user('NodeRemoval');

function nr_node(string $name) {
	$node = new ManagedNode(NULL);
	$node->set('mgn_name', $name);
	$node->set('mgn_slug', $name);
	$node->set('mgn_host', '198.51.100.40');
	$node->set('mgn_ssh_user', 'root');
	$node->set('mgn_container_name', 'nrtest');
	$node->set('mgn_enabled', true);
	$node->set('mgn_agent_public_key', 'nr-agent-key-' . $name);
	$node->set('mgn_agent_version', AgentVocabulary::FLOOR);
	$node->set('mgn_agent_primitives', 'managed_domain_prepare,managed_domain_notice');
	$node->prepare();
	$node->save();
	$node->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node->key);
	return $node;
}

function nr_provision($buyer, $node, string $domain, int $order_item_id = 0, bool $register = true) {
	$prov = new CustomerCloudProvision(NULL);
	$prov->set('cvp_origin', 'admin');
	$prov->set('cvp_usr_user_id', $buyer->key);
	$prov->set('cvp_domain', $domain);
	$prov->set('cvp_slug', substr(preg_replace('/[^a-z0-9]+/', '-', $domain), 0, 50));
	$prov->set('cvp_status', 'done');
	$prov->set('cvp_hosting_mode', 'operator');
	$prov->set('cvp_mgn_managed_node_id', $node->key);
	if ($order_item_id) { $prov->set('cvp_external_order_item_id', $order_item_id); }
	$prov->save();
	$prov->load();
	if ($register) { harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $prov->key); }
	return $prov;
}

function nr_trial($prov, bool $register = true) {
	$trial = new HostedTrial(NULL);
	$trial->set('htr_cvp_customer_cloud_provision_id', (int)$prov->key);
	$trial->set('htr_state', HostedTrial::STATE_TRIAL);
	$trial->set('htr_trial_ends_time', gmdate('Y-m-d H:i:s', time() + 20 * 86400));
	$trial->save();
	$trial->load();
	if ($register) { harness_register_row('htr_hosted_trials', 'htr_hosted_trial_id', $trial->key); }
	return $trial;
}

function nr_domain($buyer, $node, string $domain, string $status) {
	$row = new RegisteredDomain(NULL);
	$row->set('rdm_registrar', 'namecheap');
	$row->set('rdm_domain', $domain);
	$row->set('rdm_usr_user_id', $buyer->key);
	$row->set('rdm_buyer_email', $buyer->get('usr_email'));
	$row->set('rdm_status', $status);
	$row->set('rdm_mgn_managed_node_id', $node->key);
	$row->prepare();
	$row->save();
	$row->load();
	harness_register_row('rdm_registered_domains', 'rdm_registered_domain_id', $row->key);
	return $row;
}

/** A hosting line billed as a live subscription. */
function nr_subscription($buyer): array {
	$order = new Order(NULL);
	$order->set('ord_usr_user_id', $buyer->key);
	$order->save();
	$order->load();
	harness_register_row('ord_orders', 'ord_order_id', $order->key);
	$item = new OrderItem(NULL);
	$item->set('odi_ord_order_id', $order->key);
	$item->set('odi_pro_product_id', 999999001);
	$item->set('odi_usr_user_id', $buyer->key);
	$item->set('odi_price', '15.00');
	$item->set('odi_status', OrderItem::STATUS_PAID);
	$item->set('odi_is_subscription', true);
	$item->set('odi_subscription_status', 'active');
	$item->save();
	$item->load();
	harness_register_row('odi_order_items', 'odi_order_item_id', $item->key);
	return array((int)$order->key, (int)$item->key);
}

function nr_fresh($obj) {
	$class = get_class($obj);
	return new $class((int)$obj->key, TRUE);
}

function nr_mentions(array $notes, string $needle): bool {
	foreach ($notes as $note) {
		if (strpos($note, $needle) !== false) { return true; }
	}
	return false;
}

// ---------------------------------------------------------------------------
// One site with everything its hosting order made, and a neighbour.
// ---------------------------------------------------------------------------

$site = nr_node('nr-site-' . $suffix);
list($order_id, $item_id) = nr_subscription($buyer);
$prov = nr_provision($buyer, $site, 'nr-site-' . $suffix . '.example.com', $item_id);
$trial = nr_trial($prov);
$pending = nr_domain($buyer, $site, 'nr-pending-' . $suffix . '.com', RegisteredDomain::STATUS_PENDING);
$wiring = nr_domain($buyer, $site, 'nr-wiring-' . $suffix . '.com', RegisteredDomain::STATUS_REGISTERED);
$active = nr_domain($buyer, $site, 'nr-active-' . $suffix . '.com', RegisteredDomain::STATUS_ACTIVE);

$neighbour = nr_node('nr-neighbour-' . $suffix);
$n_prov = nr_provision($buyer, $neighbour, 'nr-neighbour-' . $suffix . '.example.com');
$n_trial = nr_trial($n_prov);
$n_domain = nr_domain($buyer, $neighbour, 'nr-neighbour-' . $suffix . '.com', RegisteredDomain::STATUS_REGISTERED);

$site->soft_delete();
$notes = $site->removal_notes();

section('The provisioning record and its hosted trial go with the node');
check((string)nr_fresh($site)->get('mgn_delete_time') !== '', 'the node is removed');
check((string)nr_fresh($prov)->get('cvp_delete_time') !== '', 'its provisioning record is removed');
check((string)nr_fresh($trial)->get('htr_delete_time') !== '', 'its hosted trial is removed');
check(nr_mentions($notes, 'The provisioning record for ' . $prov->get('cvp_domain') . ' and its hosted trial were removed.'),
	'and the removal says so', implode(' | ', $notes));

section('A domain still being bought or wired up is parked, never deleted');
$p = nr_fresh($pending);
$w = nr_fresh($wiring);
check($p->get('rdm_status') === RegisteredDomain::STATUS_FAILED && (string)$p->get('rdm_delete_time') === '',
	'a name not yet bought is parked, and kept');
check(strpos((string)$p->get('rdm_error'), 'Not bought: its site nr-site-' . $suffix . ' was removed from the dashboard on ' . gmdate('Y-m-d')) === 0,
	'with the reason, and what a person decides', (string)$p->get('rdm_error'));
check($w->get('rdm_status') === RegisteredDomain::STATUS_FAILED && (string)$w->get('rdm_delete_time') === '',
	'a name bought but not wired up is parked, and kept');
check(strpos((string)$w->get('rdm_error'), 'The buyer still owns it.') !== false, 'with the reason', (string)$w->get('rdm_error'));
check(nr_mentions($notes, $wiring->get('rdm_domain') . ' is kept for its buyer and parked on the Domains page.')
	&& nr_mentions($notes, $pending->get('rdm_domain') . ' is kept'), 'and the removal names both', implode(' | ', $notes));

section('An active domain needs no server and is left as it is');
$a = nr_fresh($active);
check($a->get('rdm_status') === RegisteredDomain::STATUS_ACTIVE && (string)$a->get('rdm_error') === ''
	&& (string)$a->get('rdm_delete_time') === '', 'still active, still the buyer\'s');
check(!nr_mentions($notes, $active->get('rdm_domain')), 'and not mentioned');

section('A subscription still billing is named, never touched');
$item = new OrderItem($item_id, TRUE);
check($item->get('odi_subscription_status') === 'active' && (string)$item->get('odi_subscription_cancelled_time') === '',
	'the subscription is left as it was');
check(nr_mentions($notes, 'Its hosting subscription (order ' . $order_id . ') is still active and still bills the buyer'),
	'and the removal names it, by its order', implode(' | ', $notes));

section('Another site\'s records are untouched');
check((string)nr_fresh($n_prov)->get('cvp_delete_time') === '' && (string)nr_fresh($n_trial)->get('htr_delete_time') === '',
	'the neighbour keeps its provisioning record and trial');
check(nr_fresh($n_domain)->get('rdm_status') === RegisteredDomain::STATUS_REGISTERED, 'and its domain carries on');

section('Asked again, nothing more happens');
$again = nr_fresh($site);
check($again->release_site_records() === array(), 'a second release finds nothing live to act on');

// ---------------------------------------------------------------------------
section('The domain stage parks a row whose node was removed before this existed');

// The state a removal left before ManagedNode 1.27: node gone, row still live.
$old_site = nr_node('nr-old-' . $suffix);
$stranded = nr_domain($buyer, $old_site, 'nr-stranded-' . $suffix . '.com', RegisteredDomain::STATUS_REGISTERED);
DbConnector::get_instance()->get_db_link()->prepare(
	'UPDATE mgn_managed_nodes SET mgn_delete_time = now() WHERE mgn_managed_node_id = ?')->execute(array($old_site->key));

$advance = new ReflectionMethod('ProvisionManagedDomains', 'advance');
$advance->setAccessible(true);
$stage = new ProvisionManagedDomains();
check($advance->invoke($stage, nr_fresh($stranded)) === 1, 'the stage takes one step: parking it');
$s = nr_fresh($stranded);
check($s->get('rdm_status') === RegisteredDomain::STATUS_FAILED
	&& strpos((string)$s->get('rdm_error'), 'nr-old-' . $suffix . ' was removed from the dashboard') !== false,
	'parked with the reason', (string)$s->get('rdm_error'));

$jobs = DbConnector::get_instance()->get_db_link()->prepare(
	"SELECT count(*) FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = 'managed_domain_prepare'");
$jobs->execute(array($old_site->key));
check((int)$jobs->fetchColumn() === 0, 'and the removed node is never asked to prepare mail');

// Retry on the Domains page puts a parked row back in the queue.
$s->set('rdm_status', RegisteredDomain::STATUS_REGISTERED);
$s->set('rdm_error', null);
$s->save();
$advance->invoke($stage, nr_fresh($s));
check(nr_fresh($s)->get('rdm_status') === RegisteredDomain::STATUS_FAILED, 'retried while its site is still gone, it parks again');

// ---------------------------------------------------------------------------
section('The domain watch sends a removed node no notice');

$converge = new ReflectionMethod('ManagedDomainWatch', 'converge_notice');
$converge->setAccessible(true);
check($converge->invoke(new ManagedDomainWatch(), nr_fresh($active)) === 0, 'nothing moves for the removed site\'s active domain');
$notice = DbConnector::get_instance()->get_db_link()->prepare(
	"SELECT count(*) FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = 'managed_domain_notice'");
$notice->execute(array($site->key));
check((int)$notice->fetchColumn() === 0, 'and no notice job is filed for it');

// ---------------------------------------------------------------------------
section('sm_008 releases a provision a removal before this left live');

// Inside a transaction that is rolled back: the migration acts on every such
// provision in the database, and this suite changes only rows it made. The
// rows made in it go with the rollback, so none is registered for teardown.
$migration = null;
foreach (require(PathHelper::getIncludePath('plugins/server_manager/migrations/migrations.php')) as $m) {
	if ($m['id'] === 'sm_008_release_removed_nodes_provisions') { $migration = $m; }
}
check($migration !== null, 'the migration is declared');
$dblink = DbConnector::get_instance()->get_db_link();
$dblink->beginTransaction();
try {
	$left = nr_provision($buyer, $old_site, 'nr-left-' . $suffix . '.example.com', 0, false);
	$left_trial = nr_trial($left, false);
	$kept = nr_provision($buyer, $neighbour, 'nr-kept-' . $suffix . '.example.com', 0, false);
	$migration['up'](DbConnector::get_instance());
	check((string)nr_fresh($left)->get('cvp_delete_time') !== '' && (string)nr_fresh($left_trial)->get('htr_delete_time') !== '',
		'the removed node\'s provision and trial are removed');
	check((string)nr_fresh($kept)->get('cvp_delete_time') === '', 'a live node\'s provision is not');
} finally {
	$dblink->rollBack();
}

harness_finish();
