<?php
/** @joinery-test
 * name: products
 * tier: live
 * env: dev-only
 * needs: [stripe-test-keys]
 * timeout: 600
 */

/**
 * Products end to end: admin creation, cart, coupons, and a real Stripe
 * test-mode charge.
 *
 * Everything runs against the copied test database, and the creation section
 * asserts that explicitly — a product that turns up in the production database
 * means test isolation is broken, which is a hard failure rather than a warning.
 *
 * Four sections, cheapest first:
 *
 *   1. Creation — a product is created through admin_product_edit_logic (the
 *      same entry point the admin page uses), verified by reload, and given a
 *      priced version.
 *   2. Cart — products go in through product_logic, the running total tracks
 *      what is in the cart, and removal/clearing work.
 *   3. Coupons — a fixed-amount and a percentage coupon are created through the
 *      admin logic and applied to a known cart, asserting the resulting total.
 *      A nonexistent code is refused.
 *   4. Payment — the configured checkout path (stripe_regular) is driven with
 *      Stripe's tok_visa test token through cart_charge_logic, then the order,
 *      its items, and the Stripe charge amount are verified against each other.
 *
 * The order created by the charge is located by id, not by "created in the last
 * 60 seconds" — a concurrent run or a slow box must not be able to make this
 * suite verify somebody else's order.
 *
 * Run: php plugins/store/tests/products_test.php
 *
 * @version 1.0.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/logic.php');
harness_boot();
harness_test_mode();
set_time_limit(600);

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/includes/StripeHelper.php'));
require_once(PathHelper::getIncludePath('plugins/store/includes/ShoppingCart.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/product_versions_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/coupon_codes_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/logic/product_logic.php'));
require_once(PathHelper::getIncludePath('plugins/store/logic/cart_charge_logic.php'));

$settings = Globalvars::get_instance();
$run_id = substr(md5(uniqid('pt', true)), 0, 8);

// The admin logic calls check_permission(); an admin session satisfies it, and
// test_mode routes StripeHelper at the test keys.
$_SESSION['loggedin'] = 1;
$_SESSION['usr_user_id'] = 1;
$_SESSION['permission'] = 10;
$_SESSION['test_mode'] = true;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a product through the admin logic and return its id. The new id comes
 * from the post-save redirect, which is the only place the logic reports it.
 */
function pt_create_product($name, $link, array $overrides = array()) {
	$post = array_merge(array(
		'action'              => 'add',
		'json_confirm'        => '1',
		'pro_name'            => $name,
		'pro_description'     => 'Created by products_test',
		'pro_short_description' => 'products_test fixture',
		'pro_is_active'       => '1',
		'pro_max_purchase_count' => '0',
		'pro_max_cart_count'  => '0',
		'pro_expires'         => '0',
		'pro_link'            => $link,
		// Name + email are what the cart needs to prefill a billing user.
		'system_requirements' => array('FullNameRequirement', 'EmailRequirement'),
	), $overrides);

	$result = harness_call_logic_ok(
		'plugins/store/admin/logic/admin_product_edit_logic.php',
		'admin_product_edit_logic',
		$post
	);

	if ($result->redirect && preg_match('/pro_product_id=(\d+)/', $result->redirect, $m)) {
		$id = (int)$m[1];
		// permanent_delete() walks the registered deletion rules, so the
		// requirement instances the admin logic just attached go with it. A raw
		// DELETE on pro_products would trip their foreign key and leave the
		// product behind.
		harness_defer(function () use ($id) {
			try {
				$p = new Product($id, TRUE);
				if ($p->key) $p->permanent_delete();
			} catch (\Throwable $e) {
				echo "  WARNING: could not delete product $id: " . $e->getMessage() . "\n";
			}
		});
		return $id;
	}
	return null;
}

/** Add a priced version to a product through the admin logic. */
function pt_create_version($product_id, $version_name, $price, $price_type = 'single') {
	harness_call_logic_ok(
		'plugins/store/admin/logic/admin_product_edit_logic.php',
		'admin_product_edit_logic',
		array(
			'action'                => 'new_version',
			'p'                     => $product_id,
			'version_name'          => $version_name,
			'version_price'         => $price,
			'prv_price_type'        => $price_type,
			'prv_trial_period_days' => '0',
		),
		'GET'
	);

	$versions = new MultiProductVersion(
		array('product_id' => $product_id, 'version_name' => $version_name),
		array('prv_product_version_id' => 'DESC'), 1, 0
	);
	$versions->load();
	// The version is removed by the product's own permanent_delete cascade, so
	// it is deliberately not registered separately.
	return $versions->count() > 0 ? $versions->get(0) : null;
}

/** Create a coupon through the admin logic; returns the CouponCode or null. */
function pt_create_coupon($code, array $spec) {
	harness_call_logic_ok(
		'plugins/store/admin/logic/admin_coupon_code_edit_logic.php',
		'admin_coupon_code_edit_logic',
		array_merge(array(
			'action'                    => 'add',
			'json_confirm'              => '1',
			'ccd_code'                  => $code,
			'ccd_is_active'             => '1',
			'ccd_max_num_uses'          => '100',
			'ccd_is_stackable'          => '0',
			'ccd_applies_to'            => '0',
			'ccd_amount_discount'       => '',
			'ccd_percent_discount'      => '',
			'ccd_start_time'            => '',
			'ccd_end_time'              => '',
			'ccd_usr_user_id_affiliate' => '',
		), $spec)
	);

	$coupon = CouponCode::GetByColumn('ccd_code', strtolower($code));
	if ($coupon && $coupon->key) {
		harness_register_row('ccd_coupon_codes', 'ccd_coupon_code_id', $coupon->key);
		return $coupon;
	}
	return null;
}

/** Put a product's first version in the cart via product_logic. */
function pt_add_to_cart($product_id, array $form_data = array()) {
	$product = new Product($product_id, TRUE);
	$versions = $product->get_product_versions(TRUE);
	$versions->load();
	if ($versions->count() === 0) return false;

	$post = array_merge(array(
		'product_id'      => $product_id,
		'product_version' => $versions->get(0)->key,
		'cart'            => '1',
		'full_name_first' => 'Products',
		'full_name_last'  => 'Tester',
		'email'           => 'products_test_' . $GLOBALS['run_id'] . '@example.com',
	), $form_data);

	$original_post = $_POST; $original_request = $_REQUEST;
	$_POST = $post; $_REQUEST = $post;
	try {
		product_logic($post);
	} finally {
		$_POST = $original_post; $_REQUEST = $original_request;
	}

	foreach (ShoppingCart::current()->get_detailed_items() as $item) {
		if ($item['product_version']->get('prv_pro_product_id') == $product_id) return true;
	}
	return false;
}

/** True when the cart holds a line for this product. */
function pt_cart_has($product_id) {
	foreach (ShoppingCart::current()->get_detailed_items() as $item) {
		if ($item['product_version']->get('prv_pro_product_id') == $product_id) return true;
	}
	return false;
}

/**
 * Remove fixtures stranded by an earlier crashed run. Matched on this suite's
 * own generated pro_link prefixes (pt-widget-<hex> / pt-gadget-<hex>), not on a
 * loose name match, so a real product can never be caught by it.
 */
function pt_preclean() {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->query("SELECT pro_product_id FROM pro_products
		WHERE pro_link ~ '^pt-(widget|gadget)-[0-9a-f]{8}$'");
	$stranded = $q->fetchAll(PDO::FETCH_COLUMN);
	foreach ($stranded as $id) {
		try {
			$p = new Product((int)$id, TRUE);
			if ($p->key) $p->permanent_delete();
		} catch (\Throwable $e) {
			echo "  WARNING: preclean could not delete product $id: " . $e->getMessage() . "\n";
		}
	}
	$c = $db->query("SELECT count(*) FROM ccd_coupon_codes WHERE ccd_code ~ '^pt_(15off|10pct)_[0-9a-f]{8}$'")->fetchColumn();
	if ($c > 0) {
		$db->exec("DELETE FROM ccd_coupon_codes WHERE ccd_code ~ '^pt_(15off|10pct)_[0-9a-f]{8}$'");
	}
	return count($stranded) + (int)$c;
}

/** The highest order id right now — the watermark for finding what a charge creates. */
function pt_max_order_id() {
	$db = DbConnector::get_instance()->get_db_link();
	return (int)$db->query('SELECT COALESCE(MAX(ord_order_id), 0) FROM ord_orders')->fetchColumn();
}

// ===========================================================================
section('Preconditions');
// ===========================================================================

$has_stripe_keys = !empty($settings->get_setting('stripe_api_key_test'))
	&& !empty($settings->get_setting('stripe_api_pkey_test'));
check($has_stripe_keys, 'Stripe test keys configured',
	$has_stripe_keys ? '' : 'stripe_api_key_test / stripe_api_pkey_test are unset');

$products_active = (bool)$settings->get_setting('products_active');
check($products_active, 'store is enabled', $products_active ? '' : 'products_active is off');

// Live keys in a test run would charge real money — refuse to continue.
$pk = (string)$settings->get_setting('stripe_api_key_test');
$sk = (string)$settings->get_setting('stripe_api_pkey_test');
$no_live_keys = strpos($pk, 'pk_live_') !== 0 && strpos($sk, 'sk_live_') !== 0
	&& strpos($pk, 'sk_live_') !== 0 && strpos($sk, 'pk_live_') !== 0;
check($no_live_keys, 'no live Stripe keys in the test key settings',
	$no_live_keys ? '' : 'LIVE key detected in a _test setting — refusing to charge');

$checkout_type = (string)$settings->get_setting('checkout_type');
check($checkout_type !== '', 'a checkout type is configured', "checkout_type = '$checkout_type'");

if (!$has_stripe_keys || !$products_active || !$no_live_keys) {
	harness_finish();
}

// ===========================================================================
section('Product creation through the admin logic');
// ===========================================================================

$stranded = pt_preclean();
if ($stranded > 0) echo "  Precleaned $stranded fixture(s) stranded by an earlier run\n";

$p1_name = 'PT Widget ' . $run_id;
$p1_id = pt_create_product($p1_name, 'pt-widget-' . $run_id);
check($p1_id !== null, 'admin logic creates a product and reports its id',
	$p1_id === null ? 'no pro_product_id in the redirect' : "id $p1_id");

if ($p1_id === null) {
	harness_finish(); // nothing downstream can run without a product
}

$p1 = new Product($p1_id, TRUE);
check($p1->get('pro_name') === $p1_name, 'the product reloads with the submitted name',
	"got '" . $p1->get('pro_name') . "'");
check((bool)$p1->get('pro_is_active'), 'the product is active as submitted');

// Test isolation: the same id must NOT carry this product in the production
// database. A match there means the writes went to the live site.
DbConnector::get_instance()->close_test_mode();
$leaked = false;
try {
	$prod_copy = new Product($p1_id, TRUE);
	$leaked = ($prod_copy->get('pro_name') === $p1_name);
} catch (Exception $e) {
	$leaked = false; // absent from production, which is what we want
}
DbConnector::get_instance()->set_test_mode();
check(!$leaked, 'the product was written to the test database, not production',
	$leaked ? "product '$p1_name' is present in the PRODUCTION database" : '');

$p1_price = 49.99;
$v1 = pt_create_version($p1_id, 'One-time Purchase', (string)$p1_price);
check($v1 !== null, 'admin logic creates a product version');
check($v1 && abs((float)$v1->get('prv_version_price') - $p1_price) < 0.01,
	'the version stores the submitted price',
	$v1 ? 'price ' . $v1->get('prv_version_price') : 'no version');

// ===========================================================================
section('Shopping cart');
// ===========================================================================

$cart = ShoppingCart::current();
$cart->clear_cart();

check(pt_add_to_cart($p1_id), 'a product with a version goes into the cart');
check(pt_cart_has($p1_id), 'the cart reports the added product');
check(abs(ShoppingCart::current()->get_total() - $p1_price) < 0.01,
	'the cart total equals the version price',
	'total ' . ShoppingCart::current()->get_total() . ", expected $p1_price");

// A second, differently-priced product proves the total is summed, not echoed.
$p2_price = 25.00;
$p2_id = pt_create_product('PT Gadget ' . $run_id, 'pt-gadget-' . $run_id);
check($p2_id !== null, 'a second product is created for the multi-item cart');

if ($p2_id !== null) {
	pt_create_version($p2_id, 'One-time Purchase', (string)$p2_price);
	check(pt_add_to_cart($p2_id), 'a second product is added to the cart');
	check(abs(ShoppingCart::current()->get_total() - ($p1_price + $p2_price)) < 0.01,
		'the cart total sums both items',
		'total ' . ShoppingCart::current()->get_total() . ', expected ' . ($p1_price + $p2_price));

	// Remove just the second item; the first must survive.
	$cart = ShoppingCart::current();
	foreach ($cart->get_detailed_items() as $item) {
		if ($item['product_version']->get('prv_pro_product_id') == $p2_id) {
			$cart->remove_item($item['id']);
			break;
		}
	}
	check(!pt_cart_has($p2_id), 'removing an item takes it out of the cart');
	check(pt_cart_has($p1_id), 'removing one item leaves the other in place');
	check(abs(ShoppingCart::current()->get_total() - $p1_price) < 0.01,
		'the total drops back to the remaining item',
		'total ' . ShoppingCart::current()->get_total());
}

ShoppingCart::current()->clear_cart();
check(count(ShoppingCart::current()->get_detailed_items()) === 0, 'clearing empties the cart');

// ===========================================================================
section('Coupon codes');
// ===========================================================================

// A known single-item cart, so the discount arithmetic is unambiguous.
ShoppingCart::current()->clear_cart();
pt_add_to_cart($p1_id);
$base_total = ShoppingCart::current()->get_total();
check(abs($base_total - $p1_price) < 0.01, 'coupon fixture cart holds the expected base total',
	"total $base_total");

$amount_code = 'pt_15off_' . $run_id;
$amount_coupon = pt_create_coupon($amount_code, array('ccd_amount_discount' => '15.00'));
check($amount_coupon !== null, 'admin logic creates a fixed-amount coupon');

if ($amount_coupon !== null) {
	$cart = ShoppingCart::current();
	$applied = $cart->add_coupon($amount_code);
	check($applied === 1, 'the fixed-amount coupon applies',
		$applied === 1 ? '' : 'add_coupon returned ' . var_export($applied, true));

	$expected = $base_total - 15.00;
	$after = ShoppingCart::current()->get_total();
	check(abs($after - $expected) < 0.01, 'a 15.00 coupon takes 15.00 off the total',
		"total $after, expected $expected");

	ShoppingCart::current()->remove_coupon($amount_code);
	check(abs(ShoppingCart::current()->get_total() - $base_total) < 0.01,
		'removing the coupon restores the original total',
		'total ' . ShoppingCart::current()->get_total());
}

$pct_code = 'pt_10pct_' . $run_id;
$pct_coupon = pt_create_coupon($pct_code, array('ccd_percent_discount' => '10'));
check($pct_coupon !== null, 'admin logic creates a percentage coupon');

if ($pct_coupon !== null) {
	$cart = ShoppingCart::current();
	$applied = $cart->add_coupon($pct_code);
	check($applied === 1, 'the percentage coupon applies',
		$applied === 1 ? '' : 'add_coupon returned ' . var_export($applied, true));

	$expected = round($base_total * 0.90, 2);
	$after = round(ShoppingCart::current()->get_total(), 2);
	check(abs($after - $expected) < 0.01, 'a 10 percent coupon takes a tenth off the total',
		"total $after, expected $expected");

	ShoppingCart::current()->remove_coupon($pct_code);
}

// A code that does not exist must be refused rather than silently ignored.
$bogus = ShoppingCart::current()->add_coupon('pt_nonexistent_' . $run_id);
check($bogus !== 1, 'an unknown coupon code is refused',
	'add_coupon returned ' . var_export($bogus, true));
check(abs(ShoppingCart::current()->get_total() - $base_total) < 0.01,
	'a refused coupon leaves the total untouched',
	'total ' . ShoppingCart::current()->get_total());

// ===========================================================================
section('Payment: real Stripe test-mode charge');
// ===========================================================================

if ($checkout_type !== 'stripe_regular') {
	// stripe_checkout completes on Stripe's hosted page, which needs a human;
	// skipping is honest, and no order is fabricated to manufacture a pass.
	harness_skip('tokenized charge through cart_charge_logic',
		"checkout_type is '$checkout_type'; only stripe_regular can be driven headlessly");
} else {
	ShoppingCart::current()->clear_cart();
	pt_add_to_cart($p1_id);
	$charge_total = ShoppingCart::current()->get_total();
	check(abs($charge_total - $p1_price) < 0.01, 'payment fixture cart holds one product at its price',
		"total $charge_total");

	// The checkout page establishes who is being billed before charging. The
	// data comes from the product's own requirement fields, so this also proves
	// the requirement -> cart-item -> billing-user chain carries the name and
	// email through; without it the charge fails on a missing first name.
	$prefilled = ShoppingCart::current()->billing_user_prefill_from_items();
	check($prefilled === true, 'billing details prefill from the cart item requirement data',
		'billing_user_prefill_from_items returned ' . var_export($prefilled, true));
	check(ShoppingCart::current()->is_billing_user_complete(),
		'the prefilled billing user is complete enough to charge');

	$watermark = pt_max_order_id();

	// tok_visa is Stripe's always-succeeds test token, standing in for what the
	// checkout page's JavaScript would tokenize from real card fields.
	$post = array('stripeToken' => 'tok_visa', 'password' => '');
	$original_post = $_POST; $original_request = $_REQUEST; $original_get = $_GET;
	$original_method = $_SERVER['REQUEST_METHOD'] ?? null;
	$original_send = $_SESSION['send_emails'] ?? true;

	$_POST = $post; $_REQUEST = $post; $_GET = array();
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_SESSION['send_emails'] = false; // receipts are not what this section tests

	$charge_error = null;
	$charge_redirect = null;
	try {
		$result = cart_charge_logic($post);
		if ($result->error) $charge_error = $result->error;
		$charge_redirect = $result->redirect;
	} catch (Exception $e) {
		$charge_error = $e->getMessage();
	} finally {
		$_POST = $original_post; $_REQUEST = $original_request; $_GET = $original_get;
		if ($original_method === null) unset($_SERVER['REQUEST_METHOD']);
		else $_SERVER['REQUEST_METHOD'] = $original_method;
		$_SESSION['send_emails'] = $original_send;
	}
	check($charge_error === null, 'cart_charge_logic raises no exception', (string)$charge_error);

	// A failed checkout does NOT populate LogicResult->error — _checkout_error()
	// stashes a display message and redirects back to /checkout, while success
	// renders the receipt page. So the discriminator is the bounce to /checkout;
	// asserting only on ->error would pass on a declined card.
	check($charge_redirect !== '/checkout', 'the charge does not bounce back to checkout',
		'redirect = ' . var_export($charge_redirect, true));

	// Locate the order by id watermark, never by wall-clock proximity.
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare('SELECT ord_order_id FROM ord_orders WHERE ord_order_id > ? ORDER BY ord_order_id DESC LIMIT 1');
	$q->execute(array($watermark));
	$new_order_id = $q->fetchColumn();
	check($new_order_id !== false && $new_order_id !== null,
		'the charge creates exactly one identifiable new order',
		'watermark ' . $watermark);

	if ($new_order_id) {
		$order = new Order((int)$new_order_id, TRUE);
		harness_register_row('ord_orders', 'ord_order_id', $order->key);

		check((int)$order->get('ord_status') === Order::STATUS_PAID, 'the order is marked paid',
			'status ' . $order->get('ord_status'));
		check(abs((float)$order->get('ord_total_cost') - $charge_total) < 0.01,
			'the order total matches the cart total',
			'order ' . $order->get('ord_total_cost') . ", cart $charge_total");

		$items = new MultiOrderItem(array('odi_ord_order_id' => $order->key), array('odi_order_item_id' => 'ASC'));
		$items->load();
		check($items->count() === 1, 'the order has one item, matching the cart',
			$items->count() . ' item(s)');

		$item = $items->count() > 0 ? $items->get(0) : null;
		if ($item) {
			harness_register_row('odi_order_items', 'odi_order_item_id', $item->key);
			check((int)$item->get('odi_pro_product_id') === (int)$p1_id,
				'the order item points at the purchased product',
				'product ' . $item->get('odi_pro_product_id') . ", expected $p1_id");
			check(abs((float)$item->get('odi_price') - $p1_price) < 0.01,
				'the order item records the version price',
				'price ' . $item->get('odi_price'));
		}

		// The money actually moved: ask Stripe, do not trust our own row.
		$charge_id = $order->get('ord_stripe_charge_id');
		$intent_id = $order->get('ord_stripe_payment_intent_id');
		check(!empty($charge_id) || !empty($intent_id),
			'the order carries a Stripe charge or payment intent id');

		if (!empty($charge_id) || !empty($intent_id)) {
			try {
				$helper = new StripeHelper();
				$charge = $helper->get_charge_from_order($order);
				check($charge && $charge->paid && $charge->status === 'succeeded',
					'Stripe reports the charge as paid and succeeded',
					$charge ? 'status ' . $charge->status : 'no charge returned');
				check($charge && abs(($charge->amount / 100) - (float)$order->get('ord_total_cost')) < 0.01,
					'the Stripe charge amount matches the order total',
					$charge ? 'stripe ' . ($charge->amount / 100) . ' vs order ' . $order->get('ord_total_cost') : '');
			} catch (Exception $e) {
				check(false, 'Stripe charge verification', $e->getMessage());
			}
		}
	}
}

ShoppingCart::current()->clear_cart();
harness_finish();
