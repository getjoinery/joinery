<?php
/** @joinery-test
 * name: subscription_cancel
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Cancelling a subscription goes to the provider that bills it, and only by POST.
 *
 * B1 (specs/admin_subscription_cancel.md): the cancel called Stripe for every
 * subscription, so a PayPal, App Store or Google Play one failed with "unable
 * to cancel". B2: Cancel was a link, and the route acted on a GET, so any page
 * a signed-in buyer — or an admin, from the user page's panel — visited could
 * cancel a subscription.
 *
 * Both providers are doubled through the model's seams; nothing reaches Stripe
 * or PayPal.
 *
 * Run: php plugins/store/tests/subscription_cancel_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/logic/orders_recurring_action_logic.php'));

class ScFakeStripe {
	public $cancels = array();
	public $refreshed = 0;
	public function cancel_subscription($id, $type) { $this->cancels[] = array($id, $type); return (object)array('id' => $id); }
	public function update_subscription_in_order_item($item) { $this->refreshed++; return true; }
}

class ScFakePaypal {
	public $cancels = array();
	public function cancel_subscription($id, $reason = '') { $this->cancels[] = $id; return true; }
}

/** An order item whose provider clients are the doubles. */
class ScOrderItem extends OrderItem {
	public static $stripe;
	public static $paypal;
	protected function stripe_helper() { return self::$stripe; }
	protected function paypal_helper() { return self::$paypal; }
}

$suffix = getmypid() . '-' . random_int(1000, 9999);
$buyer = make_user('SubCancel');

$order = new Order(NULL);
$order->set('ord_usr_user_id', $buyer->key);
$order->save();
$order->load();
harness_register_row('ord_orders', 'ord_order_id', $order->key);

function sc_item($order, $buyer, array $provider_fields) {
	$item = new OrderItem(NULL);
	$item->set('odi_ord_order_id', $order->key);
	$item->set('odi_pro_product_id', 999999001);
	$item->set('odi_usr_user_id', $buyer->key);
	$item->set('odi_price', '9.00');
	$item->set('odi_status', OrderItem::STATUS_PAID);
	$item->set('odi_is_subscription', true);
	$item->set('odi_subscription_status', 'active');
	foreach ($provider_fields as $field => $value) { $item->set($field, $value); }
	$item->save();
	$item->load();
	harness_register_row('odi_order_items', 'odi_order_item_id', $item->key);
	return new ScOrderItem((int)$item->key, TRUE);
}

function sc_throws(callable $fn): string {
	try { $fn(); } catch (Throwable $e) { return $e->getMessage(); }
	return '';
}

$stripe_item = sc_item($order, $buyer, array('odi_stripe_subscription_id' => 'sub_sc_' . $suffix));
$paypal_item = sc_item($order, $buyer, array('odi_paypal_subscription_id' => 'I-SC' . $suffix));
$apple_item  = sc_item($order, $buyer, array('odi_app_store_original_transaction_id' => 'sc' . $suffix));
$play_item   = sc_item($order, $buyer, array('odi_play_purchase_token' => 'sc-' . $suffix));
$bare_item   = sc_item($order, $buyer, array());

// Acting as the buyer, who may write their own order items.
$_SESSION['loggedin'] = 1;
$_SESSION['usr_user_id'] = $buyer->key;
$_SESSION['permission'] = 0;

// ---------------------------------------------------------------------------
section('A Stripe subscription is cancelled at Stripe, as asked');
ScOrderItem::$stripe = new ScFakeStripe();
ScOrderItem::$paypal = new ScFakePaypal();
check($stripe_item->cancel_subscription_order_item(false, 'period_end') === true, 'the cancel succeeds');
check(ScOrderItem::$stripe->cancels === array(array('sub_sc_' . $suffix, 'period_end')), 'Stripe is asked, with its id and the timing',
	json_encode(ScOrderItem::$stripe->cancels));
check(ScOrderItem::$stripe->refreshed === 1, 'and the row is read back from Stripe');
check(ScOrderItem::$paypal->cancels === array(), 'PayPal is not asked');

// ---------------------------------------------------------------------------
section('A PayPal subscription is cancelled at PayPal, never at Stripe');
ScOrderItem::$stripe = new ScFakeStripe();
ScOrderItem::$paypal = new ScFakePaypal();
check($paypal_item->cancel_subscription_order_item(false, 'period_end') === true, 'the cancel succeeds');
check(ScOrderItem::$paypal->cancels === array('I-SC' . $suffix), 'PayPal is asked, with its id', json_encode(ScOrderItem::$paypal->cancels));
check(ScOrderItem::$stripe->cancels === array(), 'Stripe is not asked');
$fresh = new OrderItem((int)$paypal_item->key, TRUE);
check($fresh->get('odi_subscription_status') === 'canceled' && (string)$fresh->get('odi_subscription_cancelled_time') !== '',
	'the row records it cancelled now: PayPal stops billing at once');

// ---------------------------------------------------------------------------
section('An app-store subscription is refused with where its buyer cancels it');
ScOrderItem::$stripe = new ScFakeStripe();
ScOrderItem::$paypal = new ScFakePaypal();
$apple = sc_throws(function () use ($apple_item) { $apple_item->cancel_subscription_order_item(false, 'period_end'); });
check(strpos($apple, 'App Store') !== false && strpos($apple, 'Apple ID') !== false, 'the App Store one names where', $apple);
$play = sc_throws(function () use ($play_item) { $play_item->cancel_subscription_order_item(false, 'period_end'); });
check(strpos($play, 'Google Play') !== false, 'the Google Play one names where', $play);
$bare = sc_throws(function () use ($bare_item) { $bare_item->cancel_subscription_order_item(false, 'period_end'); });
check(strpos($bare, 'not linked to a payment provider') !== false, 'one with no provider says so', $bare);
check(ScOrderItem::$stripe->cancels === array() && ScOrderItem::$paypal->cancels === array(), 'and no provider is asked');
check($apple_item->subscription_cancel_blocker() !== null && $stripe_item->subscription_cancel_blocker() === null
	&& $paypal_item->subscription_cancel_blocker() === null, 'the pages read the same answer to show text instead of a button');

// ---------------------------------------------------------------------------
section('The cancel route refuses a GET');
$settings = Globalvars::get_instance();
$was_method = $_SERVER['REQUEST_METHOD'] ?? null;
$_SERVER['REQUEST_METHOD'] = 'GET';
try {
	$stripe_before = new OrderItem((int)$stripe_item->key, TRUE);
	$result = orders_recurring_action_logic(array('order_item_id' => (int)$stripe_item->key));
	if ($settings->get_setting('products_active')) {
		check((string)$result->error === 'Cancel a subscription with the Cancel button on your subscriptions page.',
			'a GET is refused, before anything is asked of a provider', (string)$result->error);
	} else {
		check(false, 'products_active is on for this check', 'the store is switched off on this site');
	}
	$stripe_after = new OrderItem((int)$stripe_item->key, TRUE);
	check((string)$stripe_after->get('odi_subscription_status') === (string)$stripe_before->get('odi_subscription_status'),
		'and the subscription is untouched');
} finally {
	if ($was_method === null) { unset($_SERVER['REQUEST_METHOD']); } else { $_SERVER['REQUEST_METHOD'] = $was_method; }
}

// ---------------------------------------------------------------------------
section('Nothing links to the cancel route');
$root = PathHelper::getIncludePath('');
$links = array();
foreach (array('plugins', 'theme', 'views', 'adm') as $dir) {
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . $dir, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $file) {
		if ($file->getExtension() !== 'php' || strpos($file->getPathname(), '/tests/') !== false) { continue; }
		if (preg_match('#href=["\'][^"\']*/profile/orders_recurring_action#', (string)file_get_contents($file->getPathname()))) {
			$links[] = substr($file->getPathname(), strlen($root));
		}
	}
}
check($links === array(), 'no page offers a cancel as a link', implode(', ', $links));

harness_finish();
