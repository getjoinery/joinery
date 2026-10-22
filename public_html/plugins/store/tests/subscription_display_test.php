<?php
/** @joinery-test
 * name: subscription_display
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * What a buyer sees of a subscription, and the end time it rests on.
 *
 * - B91: a subscription cancelled to end at the period end is still the
 *   buyer's until then. odi_subscription_cancelled_time is when it ENDS (as the
 *   change-tier page writes it), so the Stripe sync stores Stripe's cancel_at,
 *   not canceled_at (the moment the cancel was asked for), and the
 *   subscriptions page lists 'current' subscriptions, not only uncancelled ones.
 * - B92: the price shows the plan's own period and the site's currency, never
 *   a hardcoded '$X/month'.
 *
 * Run: php tests/run.php test-db --filter=subscription_display
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(PathHelper::getIncludePath('plugins/store/includes/StripeHelper.php'));

$owner = new User(NULL);
$owner->set('usr_first_name', 'Sub');
$owner->set('usr_last_name', 'Display');
$owner->set('usr_email', harness_fixture_email('subdisplay'));
$owner->set('usr_password', User::GeneratePassword(bin2hex(random_bytes(8))));
$owner->save();
harness_register_user($owner);

$product = new Product(NULL);
$product->set('pro_name', 'Subscription display ' . substr(md5((string)microtime(true)), 0, 6));
$product->set('pro_created_by', $owner->key);
$product->set('pro_link', 'sub-display-' . substr(md5((string)microtime(true)), 0, 8));
$product->save();
harness_register_row('pro_products', 'pro_product_id', $product->key);

$version = new ProductVersion(NULL);
$version->set('prv_pro_product_id', $product->key);
$version->set('prv_version_name', 'Yearly');
$version->set('prv_version_price', '29.99');
$version->set('prv_price_type', 'year');
$version->set('prv_status', 1);
$version->save();
harness_register_row('prv_product_versions', 'prv_product_version_id', $version->key);

$order = new Order(NULL);
$order->set('ord_usr_user_id', $owner->key);
$order->set('ord_status', Order::STATUS_PAID);
$order->set('ord_total_cost', '29.99');
$order->save();
harness_register_row('ord_orders', 'ord_order_id', $order->key);

$item = new OrderItem(NULL);
$item->set('odi_ord_order_id', $order->key);
$item->set('odi_usr_user_id', $owner->key);
$item->set('odi_pro_product_id', $product->key);
$item->set('odi_prv_product_version_id', $version->key);
$item->set('odi_price', '29.99');
$item->set('odi_status', OrderItem::STATUS_PAID);
$item->set('odi_is_subscription', true);
$item->set('odi_subscription_status', 'active');
$item->save();
harness_register_row('odi_order_items', 'odi_order_item_id', $item->key);

section('B92: the price reads in the plan\'s own period');

$label = $item->subscription_price_label();
$symbol = CurrencyHelper::symbol(Globalvars::get_instance()->get_setting('site_currency')) ?? '$';
check($label === $symbol . '29.99/year', 'a yearly plan reads ' . $symbol . '29.99/year', $label);

section('B91: a cancel at period end ends at the period end');

$stripe = new StripeHelper();
$period_end = time() + 30 * 86400;
$asked = time() - 60;
$stripe->apply_subscription_to_order_item($item, array(
	'status' => 'active', 'cancel_at_period_end' => true, 'cancel_at' => $period_end,
	'canceled_at' => $asked, 'current_period_end' => $period_end,
));
$item = new OrderItem($item->key, TRUE);
check((string)$item->get('odi_subscription_cancelled_time') === gmdate('Y-m-d H:i:s', $period_end),
	'the stored end is cancel_at, not the moment it was asked for', (string)$item->get('odi_subscription_cancelled_time'));
check((bool)$item->get('odi_subscription_cancel_at_period_end'), 'and it is marked as ending at the period end');

$ids = function ($filter) use ($owner) {
	$out = array();
	foreach (new MultiOrderItem(array('user_id' => $owner->key, $filter => true)) as $o) { $out[] = (int)$o->key; }
	return $out;
};
check(in_array((int)$item->key, $ids('is_current_subscription'), true), 'it is still a current subscription');

require_once(PathHelper::getIncludePath('plugins/store/logic/subscriptions_logic.php'));
$_SESSION = array('usr_user_id' => $owner->key, 'loggedin' => true, 'permission' => 0);
harness_set_setting_mem('products_active', '1');
harness_set_setting_mem('subscriptions_active', '1');
$page = subscriptions_logic(array());
$listed = array();
foreach ($page->data['active_subscriptions'] as $o) { $listed[] = (int)$o->key; }
check(in_array((int)$item->key, $listed, true), 'and the subscriptions page lists it', json_encode($listed));

section('A cancel on a chosen date ends on that date');

$chosen = time() + 10 * 86400;
$stripe->apply_subscription_to_order_item($item, array(
	'status' => 'active', 'cancel_at_period_end' => false, 'cancel_at' => $chosen,
	'canceled_at' => $asked, 'current_period_end' => $period_end,
));
$item = new OrderItem($item->key, TRUE);
check((string)$item->get('odi_subscription_cancelled_time') === gmdate('Y-m-d H:i:s', $chosen),
	'cancel_at wins over canceled_at', (string)$item->get('odi_subscription_cancelled_time'));
$stripe->apply_subscription_to_order_item($item, array(
	'status' => 'active', 'cancel_at_period_end' => false, 'cancel_at' => null, 'canceled_at' => null, 'current_period_end' => null,
));
$item = new OrderItem($item->key, TRUE);
check(strpos((string)$item->get('odi_subscription_period_end'), '1970') !== 0, 'an answer with no period end never writes 1970',
	(string)$item->get('odi_subscription_period_end'));

section('A cancel taken back, and one that ends at once');

$stripe->apply_subscription_to_order_item($item, array(
	'status' => 'active', 'cancel_at_period_end' => false, 'cancel_at' => null,
	'canceled_at' => null, 'current_period_end' => $period_end,
));
$item = new OrderItem($item->key, TRUE);
check($item->get('odi_subscription_cancelled_time') === null && !$item->get('odi_subscription_cancel_at_period_end'),
	'a cancel taken back clears the end', var_export($item->get('odi_subscription_cancelled_time'), true));

$stripe->apply_subscription_to_order_item($item, array(
	'status' => 'canceled', 'cancel_at_period_end' => false, 'cancel_at' => null,
	'canceled_at' => $asked, 'current_period_end' => $period_end,
));
$item = new OrderItem($item->key, TRUE);
check((string)$item->get('odi_subscription_cancelled_time') === gmdate('Y-m-d H:i:s', $asked),
	'one cancelled at once ends when it was cancelled', (string)$item->get('odi_subscription_cancelled_time'));
check(!in_array((int)$item->key, $ids('is_current_subscription'), true), 'and is no longer current');

harness_finish();
