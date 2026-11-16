<?php
/** @joinery-test
 * name: tier_subscription_only
 * tier: db
 * env: any
 * needs: []
 */
/**
 * Tier billing reads only tier subscriptions (hosting_paid_launch B10).
 *
 * A getjoinery buyer can hold a hosting subscription beside a tier one. The
 * tier side (change tier, the app store's exclusivity rule, revoking the tier
 * when a subscription ends) must act on the tier's own line and never on the
 * hosting line, even when the hosting line is the newer one.
 *
 *  - **MultiOrderItem grants_tier** keeps only lines whose product grants a tier.
 *  - **change_tier_logic** picks the tier subscription, not the newer hosting one.
 *  - **getActiveSubscriptionSource / userHasActiveSubscription** ignore a
 *    hosting subscription, so it never blocks a tier bought in an app.
 *  - **handleSubscriptionExpired** leaves the tier alone when the hosting line ends,
 *    and revokes it when the tier's line ends.
 *
 * Every row is made inside one transaction and rolled back.
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/store/logic/change_tier_logic.php'));

$db = DbConnector::get_instance()->get_db_link();
$db->beginTransaction();
$suffix = substr(bin2hex(random_bytes(4)), 0, 8);

harness_set_setting_mem('products_active', '1');
harness_set_setting_mem('subscriptions_active', '1');

$user = make_user_row('tso' . $suffix);
$uid = (int)$user->key;

$tier = new SubscriptionTier(NULL);
$tier->set('sbt_tier_level', 950);
$tier->set('sbt_name', 'tso_' . $suffix);
$tier->set('sbt_display_name', 'TSO ' . $suffix);
$tier->set('sbt_is_active', true);
$tier->save();
$tier->load();

$make_product = function (string $name, $tier_id) use ($suffix) {
	$p = new Product(NULL);
	$p->set('pro_name', $name . ' ' . $suffix);
	$p->set('pro_link', strtolower($name) . '-' . $suffix);
	if ($tier_id) {
		$p->set('pro_sbt_subscription_tier_id', (int)$tier_id);
	}
	$p->save();
	return $p;
};
$tier_product = $make_product('TSO tier', $tier->key);
$hosting_product = $make_product('TSO hosting', null);

$make_line = function ($product, string $stripe_sub) use ($uid) {
	$order = new Order(NULL);
	$order->set('ord_usr_user_id', $uid);
	$order->set('ord_status', Order::STATUS_PAID);
	$order->set('ord_payment_method', 'stripe');
	$order->set('ord_total_cost', 5);
	$order->save();
	$item = new OrderItem(NULL);
	$item->set('odi_ord_order_id', $order->key);
	$item->set('odi_usr_user_id', $uid);
	$item->set('odi_pro_product_id', $product->key);
	$item->set('odi_price', 5);
	$item->set('odi_status', OrderItem::STATUS_PAID);
	$item->set('odi_is_subscription', true);
	$item->set('odi_stripe_subscription_id', $stripe_sub);
	$item->set('odi_subscription_status', 'active');
	$item->set('odi_subscription_period_end', gmdate('Y-m-d H:i:s', time() + 20 * 86400));
	$item->save();
	return $item;
};

section('A buyer with only a hosting subscription has no tier subscription');
$hosting_line = $make_line($hosting_product, 'sub_tso_hosting_' . $suffix);
$ids = function (array $opts) use ($uid) {
	$out = array();
	foreach (new MultiOrderItem(array_merge(array('user_id' => $uid), $opts)) as $i) { $out[] = (int)$i->key; }
	return $out;
};
check($ids(array('is_current_subscription' => true)) === array((int)$hosting_line->key), 'the hosting line is a current subscription');
check($ids(array('is_current_subscription' => true, 'grants_tier' => true)) === array(), 'but not a tier subscription');
check(TierBilling::getActiveSubscriptionSource($uid) === null, 'no active tier source, so nothing to conflict with');
check(TierBilling::sourceConflict($uid, 'app_store') === null, 'a tier bought in an app is not refused because of hosting');
check(!TierBilling::userHasActiveSubscription($uid), 'userHasActiveSubscription reads no tier subscription');

section('The tier line, older than the hosting line, is the one change tier acts on');
$db->exec('DELETE FROM odi_order_items WHERE odi_order_item_id = ' . (int)$hosting_line->key);
$tier_line = $make_line($tier_product, 'sub_tso_tier_' . $suffix);
$hosting_line = $make_line($hosting_product, 'sub_tso_hosting2_' . $suffix);
$tier->addUser($uid, 'purchase', 'order', (int)$tier_line->get('odi_ord_order_id'));
check((int)$hosting_line->key > (int)$tier_line->key, 'the hosting line is the newer one');
check($ids(array('is_current_subscription' => true, 'grants_tier' => true)) === array((int)$tier_line->key), 'grants_tier keeps only the tier line');
check(TierBilling::getActiveSubscriptionSource($uid) === 'stripe' && TierBilling::userHasActiveSubscription($uid), 'the tier line is the active tier subscription');

section('Edges: a product filter beside grants_tier, a retired tier product, a product gone for good');
check($ids(array('odi_pro_product_id' => (int)$tier_product->key, 'grants_tier' => true)) === array((int)$tier_line->key)
	&& $ids(array('odi_pro_product_id' => (int)$hosting_product->key, 'grants_tier' => true)) === array(),
	'grants_tier and a product filter both apply');
$db->exec("UPDATE pro_products SET pro_delete_time = now() WHERE pro_product_id = " . (int)$tier_product->key);
check($ids(array('is_current_subscription' => true, 'grants_tier' => true)) === array((int)$tier_line->key)
	&& TierBilling::lineGrantsTier($tier_line), 'a soft-deleted tier product\'s line is still a tier line');
$db->exec("UPDATE pro_products SET pro_delete_time = NULL WHERE pro_product_id = " . (int)$tier_product->key);
$ghost = new OrderItem(NULL);
$ghost->set('odi_pro_product_id', 2147480000);
check(TierBilling::lineGrantsTier($ghost) === false, 'a line whose product is gone for good grants no tier, without throwing');

section('A hosting subscription ending leaves the tier; the tier\'s own line ending revokes it');
check(TierBilling::lineGrantsTier($tier_line) && !TierBilling::lineGrantsTier($hosting_line), 'lineGrantsTier tells the two apart');
TierBilling::handleSubscriptionExpired($uid, $hosting_line);
$t = SubscriptionTier::GetUserTier($uid);
check($t && (int)$t->key === (int)$tier->key, 'the hosting line ending leaves the buyer in their tier');
TierBilling::handleSubscriptionExpired($uid, $tier_line);
check(SubscriptionTier::GetUserTier($uid) === null, 'the tier line ending revokes the tier');

section('Change tier, signed in as the buyer, acts on the tier line');
$_SESSION['usr_user_id'] = $uid;
$_SESSION['loggedin'] = true;
SessionControl::get_instance();
$result = change_tier_logic(array());
$picked = $result->data['current_subscription'] ?? null;
check($picked && (int)$picked->key === (int)$tier_line->key, 'change_tier_logic picks the tier subscription, not the newer hosting one',
	$picked ? 'picked #' . $picked->key : 'none (' . json_encode(array_keys((array)$result->data)) . ')');

$_SESSION = array();
$db->rollBack();
harness_finish();
