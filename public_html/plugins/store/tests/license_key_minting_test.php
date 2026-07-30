<?php
/** @joinery-test
 * name: license_key_minting
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * License key minting — the purchase hook that turns a product sale into a
 * recorded plugin license.
 *
 * A product whose "Licenses plugin" field names a plugin and whose scripts
 * include mint_license_key_product_script must, on purchase, record exactly
 * one key against the buyer, order, and plugin — idempotently, so a webhook
 * retry can never mint a duplicate. The key must surface on the buyer's
 * profile orders page and must never end up in the error log.
 *
 * Email delivery is deliberately neutralised (the outbound service settings
 * are blanked in-memory) — a failed or absent email must not affect minting.
 *
 * Run: php plugins/store/tests/license_key_minting_test.php
 *
 * @version 1.0.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/logic.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/license_keys_class.php'));

// No real mail leaves the box: blank the outbound service for this process.
harness_set_setting_mem('email_service', '');
harness_set_setting_mem('email_fallback_service', '');

$run_id = substr(md5(uniqid('lk', true)), 0, 8);

// ---------------------------------------------------------------------------
section('Fixtures');
// ---------------------------------------------------------------------------

$buyer = make_user('lk_buyer_' . $run_id);
check((bool)$buyer->key, 'buyer created');

$product = new Product(NULL);
$product->set('pro_name', 'License test product ' . $run_id);
$product->set('pro_link', 'license-test-' . $run_id);
$product->set('pro_is_active', 1);
$product->set('pro_licensed_plugin', 'server_manager');
$product->set('pro_product_scripts', 'mint_license_key_product_script');
$product->save();
harness_register_row('pro_products', 'pro_product_id', $product->key);
check((bool)$product->key, 'product created with licensed plugin + mint script');

$order = new Order(NULL);
$order->set('ord_usr_user_id', $buyer->key);
$order->set('ord_total_cost', '49.00');
$order->set('ord_status', 1);
$order->save();
harness_register_row('ord_orders', 'ord_order_id', $order->key);

$order_item = new OrderItem(NULL);
$order_item->set('odi_ord_order_id', $order->key);
$order_item->set('odi_pro_product_id', $product->key);
$order_item->set('odi_usr_user_id', $buyer->key);
$order_item->set('odi_price', '49.00');
$order_item->save();
harness_register_row('odi_order_items', 'odi_order_item_id', $order_item->key);
check((bool)$order_item->key, 'order and order item created');

// ---------------------------------------------------------------------------
section('Minting');
// ---------------------------------------------------------------------------

$product->run_product_scripts($buyer, $order_item, $order);

$keys = new MultiLicenseKey(array('order_item_id' => $order_item->key));
$keys->load();
check($keys->count() === 1, 'exactly one key minted', $keys->count() . ' keys');

$minted = null;
foreach ($keys as $k) { $minted = $k; }
if ($minted) {
	harness_register_row('lck_license_keys', 'lck_license_key_id', $minted->key);
	check((int)$minted->get('lck_usr_user_id') === (int)$buyer->key, 'key recorded against the buyer');
	check((int)$minted->get('lck_ord_order_id') === (int)$order->key, 'key recorded against the order');
	check($minted->get('lck_plugin_name') === 'server_manager', 'key recorded against the plugin');
	check((bool)preg_match('/^JNRY(-[A-HJ-NP-Z2-9]{4}){4}$/', $minted->get('lck_key')),
		'key format is JNRY-XXXX-XXXX-XXXX-XXXX with an unambiguous alphabet');
}

// A retry of the same purchase context must not mint a second key.
$product->run_product_scripts($buyer, $order_item, $order);
$keys_again = new MultiLicenseKey(array('order_item_id' => $order_item->key));
$keys_again->load();
check($keys_again->count() === 1, 'idempotent: re-run mints no second key', $keys_again->count() . ' keys');

// A product with no licensed plugin mints nothing.
$plain = new Product(NULL);
$plain->set('pro_name', 'Plain product ' . $run_id);
$plain->set('pro_link', 'plain-' . $run_id);
$plain->set('pro_product_scripts', 'mint_license_key_product_script');
$plain->save();
harness_register_row('pro_products', 'pro_product_id', $plain->key);
$plain->run_product_scripts($buyer, $order_item, $order);
$all_for_buyer = new MultiLicenseKey(array('user_id' => $buyer->key));
$all_for_buyer->load();
check($all_for_buyer->count() === 1, 'no licensed plugin set: nothing minted', $all_for_buyer->count() . ' keys');

// ---------------------------------------------------------------------------
section('Profile display');
// ---------------------------------------------------------------------------

$_SESSION['loggedin'] = 1;
$_SESSION['usr_user_id'] = $buyer->key;
$_SESSION['permission'] = 0;
$_SERVER['REQUEST_URI'] = '/profile/orders';

$result = harness_call_logic('plugins/store/logic/orders_profile_logic.php', 'orders_profile_logic',
	array(), 'GET');
$profile_keys = $result->data['license_keys'] ?? null;
$found_on_profile = false;
if ($profile_keys) {
	foreach ($profile_keys as $pk) {
		if ($minted && $pk->get('lck_key') === $minted->get('lck_key')) {
			$found_on_profile = true;
		}
	}
}
check($found_on_profile, 'minted key appears on the buyer profile orders page');

// ---------------------------------------------------------------------------
section('Key never logged');
// ---------------------------------------------------------------------------

$log_path = PathHelper::getSiteRoot() . '/logs/error.log';
$log_tail = '';
if ($minted && file_exists($log_path)) {
	$fh = fopen($log_path, 'r');
	$size = filesize($log_path);
	fseek($fh, max(0, $size - 2 * 1024 * 1024));
	$log_tail = (string)stream_get_contents($fh);
	fclose($fh);
}
check($minted && strpos($log_tail, $minted->get('lck_key')) === false,
	'key value absent from the error log');

harness_finish();
