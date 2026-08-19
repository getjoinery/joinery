<?php
/** @joinery-test
 * name: license_key_minting
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * License key minting — the optional fulfillment layer on top of ownership.
 *
 * Ownership is the fact: the store records it itself when a tagged product is
 * paid for. A key string is one operator's artifact for proving that fact to a
 * machine elsewhere, and mint_license_key_product_script is what stamps it onto
 * the ownership the store already recorded and mails it out.
 *
 * So the script must: stamp a key on the core-recorded row, leave an existing
 * key alone on a re-run, and mint nothing at all when there is no ownership row
 * (a product with no tag). The key must surface on the buyer's profile and must
 * never end up in the error log.
 *
 * Email delivery is deliberately neutralised (the outbound service settings
 * are blanked in-memory) — a failed or absent email must not affect minting.
 *
 * Run: php plugins/store/tests/license_key_minting_test.php
 *
 * @version 2.0.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/logic.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));

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
$product->set('pro_ownership_tag', 'server_manager');
$product->set('pro_product_scripts', 'mint_license_key_product_script');
$product->save();
harness_register_row('pro_products', 'pro_product_id', $product->key);
check((bool)$product->key, 'product created with an ownership tag + mint script');

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

// The store records ownership at payment, before any purchase script runs.
$ownership = Ownership::record_purchase($product, NULL, $buyer, $order_item, $order);
check($ownership && $ownership->key, 'core recorded the ownership for the paid item');
if ($ownership) {
	harness_register_row('own_ownerships', 'own_ownership_id', $ownership->key);
	check(trim((string)$ownership->get('own_license_key')) === '',
		'core writes no key string of its own');
}

$product->run_product_scripts($buyer, $order_item, $order);

$owned = new MultiOwnership(array('order_item_id' => $order_item->key));
check($owned->count_all() === 1, 'the script created no second row', $owned->count_all() . ' rows');

$minted = null;
foreach ($owned as $row) { $minted = $row; }
if ($minted) {
	check((int)$minted->get('own_usr_user_id') === (int)$buyer->key, 'ownership is the buyer\'s');
	check((int)$minted->get('own_ord_order_id') === (int)$order->key, 'ownership records the order');
	check($minted->get('own_tag') === 'server_manager', 'ownership records the tag');
	check((bool)preg_match('/^JNRY(-[A-HJ-NP-Z2-9]{4}){4}$/', (string)$minted->get('own_license_key')),
		'key format is JNRY-XXXX-XXXX-XXXX-XXXX with an unambiguous alphabet',
		'key ' . (trim((string)$minted->get('own_license_key')) === '' ? 'absent' : 'present'));
}

// A retry of the same purchase context must not replace the key.
$first_key = $minted ? $minted->get('own_license_key') : null;
$product->run_product_scripts($buyer, $order_item, $order);
$after_retry = new Ownership($minted ? $minted->key : NULL, TRUE);
check($first_key && $after_retry->get('own_license_key') === $first_key,
	'idempotent: a re-run keeps the key already issued');

// A product with no ownership tag has no ownership row, so nothing is minted.
$plain = new Product(NULL);
$plain->set('pro_name', 'Plain product ' . $run_id);
$plain->set('pro_link', 'plain-' . $run_id);
$plain->set('pro_product_scripts', 'mint_license_key_product_script');
$plain->save();
harness_register_row('pro_products', 'pro_product_id', $plain->key);

$plain_order_item = new OrderItem(NULL);
$plain_order_item->set('odi_ord_order_id', $order->key);
$plain_order_item->set('odi_pro_product_id', $plain->key);
$plain_order_item->set('odi_usr_user_id', $buyer->key);
$plain_order_item->set('odi_price', '0.00');
$plain_order_item->save();
$plain_order_item->load();
harness_register_row('odi_order_items', 'odi_order_item_id', $plain_order_item->key);

check(Ownership::record_purchase($plain, NULL, $buyer, $plain_order_item, $order) === NULL,
	'an untagged product records no ownership');
$plain->run_product_scripts($buyer, $plain_order_item, $order);
$all_for_buyer = new MultiOwnership(array('user_id' => $buyer->key));
check($all_for_buyer->count_all() === 1, 'no ownership row: nothing minted',
	$all_for_buyer->count_all() . ' rows');

// ---------------------------------------------------------------------------
section('Profile display');
// ---------------------------------------------------------------------------

$_SESSION['loggedin'] = 1;
$_SESSION['usr_user_id'] = $buyer->key;
$_SESSION['permission'] = 0;
$_SERVER['REQUEST_URI'] = '/profile/orders';

$result = harness_call_logic('plugins/store/logic/orders_profile_logic.php', 'orders_profile_logic',
	array(), 'GET');
$profile_ownerships = $result->data['ownerships'] ?? null;
$found_on_profile = false;
if ($profile_ownerships) {
	foreach ($profile_ownerships as $po) {
		if ($minted && $po->get('own_license_key') === $minted->get('own_license_key')) {
			$found_on_profile = true;
		}
	}
}
check($found_on_profile, 'the minted key appears on the buyer profile orders page');

// ---------------------------------------------------------------------------
section('Key never logged');
// ---------------------------------------------------------------------------

$log_path = PathHelper::getSiteRoot() . '/logs/error.log';
$log_tail = '';
if ($minted && $minted->get('own_license_key') && file_exists($log_path)) {
	$fh = fopen($log_path, 'r');
	$size = filesize($log_path);
	fseek($fh, max(0, $size - 2 * 1024 * 1024));
	$log_tail = (string)stream_get_contents($fh);
	fclose($fh);
}
check($minted && strpos($log_tail, $minted->get('own_license_key')) === false,
	'key value absent from the error log');

harness_finish();
