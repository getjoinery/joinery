<?php
/** @joinery-test
 * name: starter_purchase
 * tier: db
 * env: any
 * needs: []
 */
/**
 * Buying Starter: the buyer picks a plan on the configure page, and the plan
 * decides the product, the price and where the paid site lands
 * (specs/starter_tier.md, Launch scope step 2).
 *
 *  - **Starter is offered only while it can be built:** its product is on sale
 *    AND a starter host has room. A paid starter site with nowhere to land is a
 *    refund.
 *  - **The draft records its plan as its landing**, and the payment button
 *    posts at that plan's product.
 *  - **A line whose draft is of the other plan is refused before the charge**,
 *    and so is a Starter line once the starter hosts are full.
 *  - **Payment activates a starter draft as a hosted container site:** status
 *    ready, operator mode, landing container, mail owed, no instance and no
 *    data-root encryption of its own (the host's disk is the host's).
 *  - **A paid product of the wrong plan is not built:** the activation refuses
 *    and a person is alerted.
 *
 * Fixture rows are registered with the harness and removed after the run.
 *
 * Run: php plugins/server_manager/tests/starter_purchase_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/store/includes/FulfillmentRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/fulfillment_providers/CustomerCloudFulfillment.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/requirements/ManagedSiteRequirement.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ManagedSiteDraft.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provisions_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_hosts_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/product_versions_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_item_requirements_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));

class SpFulfillment extends CustomerCloudFulfillment {
	public static $alerts = array();
	protected function alert_activation_problem(User $user, OrderItem $order_item, string $reason): void {
		self::$alerts[] = array('order_item' => (int)$order_item->key, 'reason' => $reason);
	}
}

$run = substr(md5(uniqid('sp', true)), 0, 6);

function sp_product(string $name, string $link, array $fields) {
	$product = new Product(NULL);
	$product->set('pro_name', $name);
	$product->set('pro_link', $link);
	$product->set('pro_is_active', true);
	foreach ($fields as $k => $v) { if ($k[0] !== '_') { $product->set($k, $v); } }
	$product->save();
	$product->load();
	harness_register_row('pro_products', 'pro_product_id', $product->key);
	$version = new ProductVersion(NULL);
	$version->set('prv_pro_product_id', $product->key);
	$version->set('prv_version_name', 'Hosting');
	$version->set('prv_version_price', $fields['_price'] ?? '4.99');
	$version->set('prv_price_type', 'month');
	$version->set('prv_status', 1);
	$version->save();
	$version->load();
	harness_register_row('prv_product_versions', 'prv_product_version_id', $version->key);
	return array($product, $version);
}

function sp_post(string $plan, string $domain): array {
	return array('plan' => $plan, 'cvp_domain_source' => 'own', 'cvp_domain' => $domain,
		'cvp_sitename' => '', 'cvp_buyer_email' => 'admin@example.com');
}

function sp_paid_line($buyer, $product, $version, array $form_data): array {
	$order = new Order(NULL);
	$order->set('ord_usr_user_id', $buyer->key);
	$order->set('ord_status', Order::STATUS_PAID);
	$order->save();
	$order->load();
	harness_register_row('ord_orders', 'ord_order_id', $order->key);
	$item = new OrderItem(NULL);
	$item->set('odi_ord_order_id', $order->key);
	$item->set('odi_pro_product_id', $product->key);
	$item->set('odi_prv_product_version_id', $version->key);
	$item->set('odi_usr_user_id', $buyer->key);
	$item->set('odi_product_info', base64_encode(serialize($form_data)));
	$item->set('odi_price', $version->get('prv_version_price'));
	$item->set('odi_status', OrderItem::STATUS_PAID);
	$item->save();
	$item->load();
	harness_register_row('odi_order_items', 'odi_order_item_id', $item->key);
	$item->save_cart_data($form_data);
	foreach (new MultiOrderItemRequirement(array('order_item_id' => $item->key)) as $row) {
		harness_register_row('oir_order_item_requirements', 'oir_order_item_requirement_id', $row->key);
	}
	return array($order, $item);
}

function sp_line_data($draft, $product): array {
	$req = new ManagedSiteRequirement();
	$req->validate(array('managed_site' => (int)$draft->key), $product);
	list($data) = $req->process(array('managed_site' => (int)$draft->key), $product, null, null);
	return $data;
}

function sp_register($row) {
	harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $row->key);
	return $row;
}

list($starter, $starter_version) = sp_product('Starter hosting [test]', 'sp-starter-' . $run, array(
	'pro_fulfillment_provider' => 'customer_cloud', 'pro_fulfillment_ref' => 2, 'pro_max_cart_count' => 1));
list($managed, $managed_version) = sp_product('Managed hosting [test]', 'sp-managed-' . $run, array(
	'pro_fulfillment_provider' => 'customer_cloud', 'pro_fulfillment_ref' => 1, 'pro_max_cart_count' => 1,
	'_price' => '9.99'));

harness_set_setting_mem('server_manager_hosted_regions', '');
harness_set_setting_mem('server_manager_customer_cloud_region', 'us-east');
harness_set_setting_mem('data_root_encryption_offered', '1');

$buyer = make_user('SpBuyer' . $run);
$_SESSION['loggedin'] = 1;
$_SESSION['usr_user_id'] = (int)$buyer->key;
$_SESSION['permission'] = 0;

$host = new ManagedHost(NULL);
$host->set('mgh_slug', 'sp-starter-host-' . $run);
$host->set('mgh_name', 'HarnessTest starter host ' . $run);
$host->set('mgh_host', '203.0.113.' . random_int(2, 250));
$host->set('mgh_tier', 'starter');
$host->set('mgh_max_sites', 12);
$host->set('mgh_provisioning_enabled', true);
$host->save();
$host->load();
harness_register_row('mgh_managed_hosts', 'mgh_managed_host_id', $host->key);

// ---------------------------------------------------------------------------
section('Starter is offered only while it can be built');

$offered = ManagedSiteDraft::offered_plans();
check(isset($offered['starter']) && isset($offered['managed']), 'with a starter host free, both plans are offered',
	implode(',', array_keys($offered)));
check(array_key_first($offered) === 'starter', 'cheapest first: Starter leads');
check(strpos(ManagedSiteDraft::plans_sentence(), 'Starter $4.99 a month') === 0,
	'the plans sentence names Starter first, with its price', ManagedSiteDraft::plans_sentence());

$host->set('mgh_provisioning_enabled', false);
$host->save();
$no_room = ManagedSiteDraft::offered_plans();
check(!isset($no_room['starter']) && isset($no_room['managed']),
	'with no starter host taking sites, only Managed is offered', implode(',', array_keys($no_room)));
$host->set('mgh_provisioning_enabled', true);
$host->save();

// ---------------------------------------------------------------------------
section('The draft records its plan, and pays for that plan\'s product');

$r = ManagedSiteDraft::save_and_freeze(null, sp_post('starter', 'sp-starter-' . $run . '.com'), $buyer);
check($r['errors'] === array(), 'a Starter setup freezes', implode(' | ', $r['errors']));
$sdraft = $r['row'] ? sp_register($r['row']) : null;
check($sdraft && $sdraft->is_container() && (string)$sdraft->get('cvp_status') === 'pending_payment',
	'its row lands in a container, at pending_payment');
check($sdraft && ManagedSiteDraft::plan_of($sdraft) === 'starter', 'and reads back as the Starter plan');
check($sdraft && (int)ManagedSiteDraft::product_for_row($sdraft)->key === (int)$starter->key,
	'its product is the Starter product');
$form = $sdraft ? ManagedSiteDraft::payment_form($sdraft, ManagedSiteDraft::product_for_row($sdraft)) : '';
check(strpos($form, 'value="' . (int)$starter->key . '"') !== false, 'the payment button posts at the Starter product');

$r = ManagedSiteDraft::save_and_freeze(null, sp_post('managed', 'sp-managed-' . $run . '.com'), $buyer);
$mdraft = $r['row'] ? sp_register($r['row']) : null;
check($mdraft && !$mdraft->is_container() && ManagedSiteDraft::plan_of($mdraft) === 'managed',
	'a Managed setup lands on an instance of its own');

$r = ManagedSiteDraft::save_and_freeze(null, sp_post('shelf', 'sp-shelf-' . $run . '.com'), $buyer);
check(!empty($r['errors']) && $r['row'] === null, 'a plan that is not on sale is refused', implode(' | ', $r['errors']));

$host->set('mgh_provisioning_enabled', false);
$host->save();
$r = ManagedSiteDraft::save_and_freeze(null, sp_post('starter', 'sp-full-' . $run . '.com'), $buyer);
check($r['row'] === null && strpos(implode(' ', $r['errors']), 'Starter is not available right now') !== false,
	'Starter chosen while its servers are full is refused with a sentence, never swapped for Managed', implode(' | ', $r['errors']));
$host->set('mgh_provisioning_enabled', true);
$host->save();

// The product page offers and accepts only a draft of its own plan.
$req = new ManagedSiteRequirement();
$errs = $req->validate(array('managed_site' => (int)$mdraft->key), $starter);
check(!empty($errs), 'a Managed draft is refused on the Starter product page', implode(' | ', $errs));
check(ManagedSiteRequirement::newest_draft_for_user((int)$buyer->key, 'container') !== null
		&& (int)ManagedSiteRequirement::newest_draft_for_user((int)$buyer->key, 'container')->key === (int)$sdraft->key,
	'and the Starter page offers the buyer\'s Starter draft, not their newer Managed one');

// ---------------------------------------------------------------------------
section('The wrong plan, or a full starter fleet, is refused before the charge');

$provider = new SpFulfillment();
$sdata = sp_line_data($sdraft, $starter);
check($provider->checkAvailability($starter, 2, 1, $sdata) === null, 'a Starter draft on the Starter product passes');
check($provider->checkAvailability($managed, 1, 1, $sdata) === CustomerCloudFulfillment::WRONG_PLAN_SENTENCE,
	'a Starter draft on the Managed product is refused');
$mdata = sp_line_data($mdraft, $managed);
check($provider->checkAvailability($starter, 2, 1, $mdata) === CustomerCloudFulfillment::WRONG_PLAN_SENTENCE,
	'a Managed draft on the Starter product is refused');

$host->set('mgh_provisioning_enabled', false);
$host->save();
check($provider->checkAvailability($starter, 2, 1, $sdata) === CustomerCloudFulfillment::NO_ROOM_SENTENCE,
	'a Starter line is refused while no starter host has room');
$host->set('mgh_provisioning_enabled', true);
$host->save();

// ---------------------------------------------------------------------------
section('Payment activates a starter draft as a hosted container site');

list($order, $item) = sp_paid_line($buyer, $starter, $starter_version, $sdata);
SpFulfillment::$alerts = array();
$f = $provider->fulfill($buyer, $starter, $item, $order, 2);
check((int)($f['ref_id'] ?? 0) === (int)$sdraft->key, 'fulfill activates the Starter draft', var_export($f, true));
$sdraft->load();
check((string)$sdraft->get('cvp_status') === 'ready', 'it is ready to build');
check((string)$sdraft->get('cvp_hosting_mode') === 'operator', 'on our side: operator mode');
check($sdraft->is_container() && trim((string)$sdraft->get('cvp_instance_id')) === '',
	'still a container site, with no instance id');
check((string)$sdraft->get('cvp_mail_state') === 'pending', 'its mail is owed from birth');
check(!$sdraft->get('cvp_data_root_encrypted'),
	'no data-root encryption of its own, even where it is offered: the disk is its host\'s');
check(SpFulfillment::$alerts === array(), 'nobody is alerted', json_encode(SpFulfillment::$alerts));
check((int)$sdraft->get('cvp_mgh_managed_host_id') === (int)$host->key,
	'its place on a starter host is reserved at payment, so the host counts it taken from now');

// A Managed draft paid as Starter (a line that slipped past the check) is not built.
list($order2, $item2) = sp_paid_line($buyer, $starter, $starter_version, $mdata);
SpFulfillment::$alerts = array();
$f2 = $provider->fulfill($buyer, $starter, $item2, $order2, 2);
check(($f2['ref_id'] ?? null) === null, 'a Managed draft paid through the Starter product is not activated');
$mdraft->load();
check((string)$mdraft->get('cvp_status') === 'pending_payment', 'the draft is left as it was');
check(count(SpFulfillment::$alerts) === 1 && strpos(SpFulfillment::$alerts[0]['reason'], 'other one') !== false,
	'and a person is alerted with the reason', json_encode(SpFulfillment::$alerts));

harness_finish();
