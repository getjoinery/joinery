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
 *  - **The billing page** shows each subscription in its own section, and a cycle
 *    change acts only on a subscription of the buyer's own, to a cycle it offers (B12).
 *  - **One Stripe subscription can pay for two lines**: a change finds the item
 *    that bills its own line (StripeHelper::subscription_item_for_line).
 *
 * Every row is made inside one transaction and rolled back.
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/store/logic/change_tier_logic.php'));
require_once(PathHelper::getIncludePath('plugins/store/logic/billing_logic.php'));

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
	$p->set('pro_link', str_replace(' ', '-', strtolower($name)) . '-' . $suffix);
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

section('The billing page shows each subscription, and changes only the one it names');
$billing = billing_logic(array());
$listed = array_map(function ($sec) { return (int)$sec['item']->key; }, $billing->data['subscriptions'] ?? array());
check(in_array((int)$tier_line->key, $listed, true) && in_array((int)$hosting_line->key, $listed, true) && count($listed) === 2,
	'both the tier and the hosting subscription get a section', json_encode($listed));
check(!empty($billing->data['has_tier_subscription']), 'the change-tier link is offered for the tier subscription');
$other = make_user_row('tso2' . $suffix);
$_SESSION['usr_user_id'] = (int)$other->key;
$theirs = billing_logic(array());
check(($theirs->data['subscriptions'] ?? null) === array() && empty($theirs->data['has_tier_subscription']),
	'another buyer sees none of them');
$refused = billing_logic(array('action' => 'change_billing_cycle', 'odi_order_item_id' => (int)$tier_line->key, 'new_version_id' => 1));
check(strpos((string)($refused->data['error_message'] ?? ''), 'your own Stripe subscriptions') !== false,
	'a cycle change naming someone else\'s subscription is refused', json_encode($refused->data['error_message'] ?? null));
$_SESSION['usr_user_id'] = $uid;
$bad_version = billing_logic(array('action' => 'change_billing_cycle', 'odi_order_item_id' => (int)$hosting_line->key, 'new_version_id' => 1));
check(strpos((string)($bad_version->data['error_message'] ?? ''), 'your own Stripe subscriptions') !== false
	|| strpos((string)($bad_version->data['error_message'] ?? ''), 'Invalid billing option') !== false,
	'a cycle the page does not offer that line is refused', json_encode($bad_version->data['error_message'] ?? null));

section('One Stripe subscription paying for two lines: each line finds its own item');
$sh = (new ReflectionClass('StripeHelper'))->newInstanceWithoutConstructor();
$sh->test_mode = false;
$db->exec("UPDATE pro_products SET pro_stripe_product_id = 'prod_tso_tier_" . $suffix . "' WHERE pro_product_id = " . (int)$tier_product->key);
$db->exec("UPDATE pro_products SET pro_stripe_product_id = 'prod_tso_host_" . $suffix . "' WHERE pro_product_id = " . (int)$hosting_product->key);
$tier_line = new OrderItem((int)$tier_line->key, TRUE);
$hosting_line = new OrderItem((int)$hosting_line->key, TRUE);
$item = function ($id, $product) { return (object)array('id' => $id, 'price' => (object)array('id' => 'price_x_' . $id, 'product' => $product)); };
$two = (object)array('items' => (object)array('data' => array(
	$item('si_tier', 'prod_tso_tier_' . $suffix),
	$item('si_host', (object)array('id' => 'prod_tso_host_' . $suffix)),
)));
check($sh->subscription_item_for_line($two, $hosting_line)->id === 'si_host' && $sh->subscription_item_for_line($two, $tier_line)->id === 'si_tier',
	'the hosting line finds the hosting item (first or second, string or expanded product), the tier line the tier item');
$none = (object)array('items' => (object)array('data' => array($item('si_a', 'prod_other'), $item('si_b', 'prod_other2'))));
$why = '';
try { $sh->subscription_item_for_line($none, $tier_line); } catch (Exception $e) { $why = $e->getMessage(); }
check(strpos($why, 'no item for this plan') !== false, 'no matching item refuses rather than taking the first', $why);
$dup = (object)array('items' => (object)array('data' => array($item('si_a', 'prod_tso_tier_' . $suffix), $item('si_b', 'prod_tso_tier_' . $suffix))));
$why = '';
try { $sh->subscription_item_for_line($dup, $tier_line); } catch (Exception $e) { $why = $e->getMessage(); }
check(strpos($why, 'more than once') !== false, 'two matching items refuse', $why);
$one = (object)array('items' => (object)array('data' => array($item('si_only', 'prod_renamed'))));
check($sh->subscription_item_for_line($one, $tier_line)->id === 'si_only', 'a one-item subscription is that item');

section('A tier change replaces the tier line only: the hosting line bought beside it stays current');
$sub = 'sub_tso_cart_' . $suffix;
$cart = new Order(NULL);
$cart->set('ord_usr_user_id', $uid);
$cart->set('ord_status', Order::STATUS_PAID);
$cart->set('ord_total_cost', 10);
$cart->save();
$line_on = function ($order, $product) use ($uid, $sub) {
	$i = new OrderItem(NULL);
	$i->set('odi_ord_order_id', $order->key);
	$i->set('odi_usr_user_id', $uid);
	$i->set('odi_pro_product_id', $product->key);
	$i->set('odi_price', 5);
	$i->set('odi_status', OrderItem::STATUS_PAID);
	$i->set('odi_is_subscription', true);
	$i->set('odi_stripe_subscription_id', $sub);
	$i->set('odi_subscription_status', 'active');
	$i->save();
	return (int)$i->key;
};
$cart_tier = $line_on($cart, $tier_product);
$cart_host = $line_on($cart, $hosting_product);
$ids_now = function () use ($sub) {
	return array_map(function ($l) { return (int)$l->key; }, StripeHelper::subscription_current_lines($sub));
};
check($ids_now() === array($cart_host, $cart_tier), 'one cart: both lines are current');
$change = new Order(NULL);
$change->set('ord_usr_user_id', $uid);
$change->set('ord_status', Order::STATUS_PAID);
$change->set('ord_total_cost', 5);
$change->save();
$new_tier = $line_on($change, $tier_product);
check($ids_now() === array($new_tier, $cart_host), 'after a tier change: the new tier line and the hosting line, not the replaced tier line',
	json_encode($ids_now()));
$change2 = new Order(NULL);
$change2->set('ord_usr_user_id', $uid);
$change2->set('ord_status', Order::STATUS_PAID);
$change2->set('ord_total_cost', 5);
$change2->save();
$newer_tier = $line_on($change2, $tier_product);
check($ids_now() === array($newer_tier, $cart_host), 'and after a second change, still the hosting line');

$_SESSION = array();
$db->rollBack();
harness_finish();
