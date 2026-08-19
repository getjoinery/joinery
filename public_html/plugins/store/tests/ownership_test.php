<?php
/** @joinery-test
 * name: ownership
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */

/**
 * Own-once products: the store's ownership model.
 *
 * A product carrying an ownership tag can be bought once per person. This suite
 * covers the fact (the Ownership row), the three places the store enforces it
 * (product page, add to cart, charge time), and the two boundaries the design
 * depends on — ownership never touches pricing, and it never applies to
 * subscriptions.
 *
 *   1. user_owns()      — the single authority: direct tag, all-access, revoked,
 *                         deleted, and someone else's row.
 *   2. Collection       — the revoked and covers_tag options.
 *   3. Recorder         — a paid tagged item makes its recipient an owner,
 *                         idempotently; subscriptions and untagged products
 *                         record nothing.
 *   4. Cart guard       — an owned product is refused, a second copy is refused,
 *                         untagged products are unaffected.
 *   5. Charge guard     — the authoritative refusal, including a gift to an
 *                         owner and an all-access row covering a specific tag.
 *                         Nothing is charged and no price is altered.
 *   6. Coverage         — every cart check is against the account the line is
 *                         FOR: an owner can gift to a non-owner, a gift to an
 *                         owner is refused, a bundle cannot share a cart with a
 *                         product it covers for the same recipient, and two
 *                         recipients make two lines. The profile labels a tag
 *                         with the product names carrying it.
 *   7. Admin            — the ownership dropdown's four meanings round-trip, the
 *                         subscription boundary holds from both directions
 *                         (tag onto a subscription product, subscription version
 *                         onto a tagged product), revoke re-opens purchase, a
 *                         hand-granted row guards like a bought one, and a
 *                         duplicate grant is refused.
 *
 * Run: php plugins/store/tests/ownership_test.php
 *
 * @version 1.1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/logic.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('plugins/store/includes/ShoppingCart.php'));
require_once(PathHelper::getIncludePath('plugins/store/logic/product_logic.php'));
require_once(PathHelper::getIncludePath('plugins/store/logic/cart_charge_logic.php'));

// No payment provider is involved: every guard under test runs before any
// charge, and this keeps the suite in the db tier.
harness_set_setting_mem('checkout_type', 'none');
harness_set_setting_mem('use_paypal_checkout', 0);
harness_set_setting_mem('products_active', 1);
harness_set_setting_mem('email_service', '');
harness_set_setting_mem('email_fallback_service', '');

$run_id = substr(md5(uniqid('own', true)), 0, 8);

// The admin logic calls check_permission().
$_SESSION['loggedin'] = 1;
$_SESSION['usr_user_id'] = 1;
$_SESSION['permission'] = 10;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Create a product through the admin logic and return its id. */
function own_create_product($name, $link, array $overrides = array()) {
	$post = array_merge(array(
		'action' => 'add',
		'json_confirm' => '1',
		'pro_name' => $name,
		'pro_description' => 'Created by ownership_test',
		'pro_short_description' => 'ownership_test fixture',
		'pro_is_active' => '1',
		'pro_max_purchase_count' => '0',
		'pro_max_cart_count' => '0',
		'pro_expires' => '0',
		'pro_link' => $link,
		'pro_grp_group_id' => '',
		'pro_prg_product_group_id' => '',
		'pro_sbt_subscription_tier_id' => '',
		'pro_digital_link' => '',
		'pro_emt_receipt_template_id' => '',
		'pro_after_purchase_message' => '',
		'ownership_mode' => Product::OWNERSHIP_NONE,
		'pro_ownership_tag' => '',
		'system_requirements' => array('FullNameRequirement', 'EmailRequirement'),
	), $overrides);

	$result = harness_call_logic('plugins/store/admin/logic/admin_product_edit_logic.php',
		'admin_product_edit_logic', $post);
	if ($result->error) {
		return array(null, $result->error);
	}
	if ($result->redirect && preg_match('/pro_product_id=(\d+)/', $result->redirect, $m)) {
		$id = (int)$m[1];
		harness_defer(function () use ($id) {
			try {
				$p = new Product($id, TRUE);
				if ($p->key) $p->permanent_delete();
			} catch (\Throwable $e) {
				echo "  WARNING: could not delete product $id: " . $e->getMessage() . "\n";
			}
		});
		return array($id, null);
	}
	return array(null, 'no redirect');
}

/** Add a priced version to a product; returns the ProductVersion. */
function own_create_version($product_id, $version_name, $price, $price_type = 'single') {
	harness_call_logic('plugins/store/admin/logic/admin_product_edit_logic.php',
		'admin_product_edit_logic', array(
			'action' => 'new_version',
			'p' => $product_id,
			'version_name' => $version_name,
			'version_price' => $price,
			'prv_price_type' => $price_type,
			'prv_trial_period_days' => '0',
		), 'GET');

	$versions = new MultiProductVersion(
		array('product_id' => $product_id, 'version_name' => $version_name),
		array('prv_product_version_id' => 'DESC'), 1, 0);
	$versions->load();
	return $versions->count() > 0 ? $versions->get(0) : null;
}

/** A saved, paid order item for a product, credited to a user. */
function own_make_paid_item($product, $product_version, $user, $is_subscription = false) {
	$order = new Order(NULL);
	$order->set('ord_usr_user_id', $user->key);
	$order->set('ord_total_cost', '10.00');
	$order->set('ord_status', Order::STATUS_PAID);
	$order->save();
	harness_register_row('ord_orders', 'ord_order_id', $order->key);

	$order_item = new OrderItem(NULL);
	$order_item->set('odi_ord_order_id', $order->key);
	$order_item->set('odi_pro_product_id', $product->key);
	$order_item->set('odi_usr_user_id', $user->key);
	$order_item->set('odi_price', '10.00');
	$order_item->set('odi_status', OrderItem::STATUS_PAID);
	$order_item->set('odi_is_subscription', $is_subscription);
	if ($product_version) {
		$order_item->set('odi_prv_product_version_id', $product_version->key);
	}
	$order_item->save();
	$order_item->load();
	harness_register_row('odi_order_items', 'odi_order_item_id', $order_item->key);

	return array($order, $order_item);
}

/** Mint an ownership row directly and register it for teardown. */
function own_grant($user_id, $tag, array $extra = array()) {
	$ownership = new Ownership(NULL);
	$ownership->set('own_usr_user_id', $user_id);
	$ownership->set('own_tag', $tag);
	foreach ($extra as $field => $value) {
		$ownership->set($field, $value);
	}
	$ownership->save();
	$ownership->load();
	harness_register_row('own_ownerships', 'own_ownership_id', $ownership->key);
	return $ownership;
}

/** Empty the request-scoped cart between scenarios. */
function own_reset_cart() {
	$cart = ShoppingCart::current();
	$cart->items = array();
	$cart->coupon_codes = array();
	$cart->billing_user = null;
	$cart->persist();
	return $cart;
}

/** Cart form data for one product version. */
function own_form_data($product_version, $email, $first = 'Own', $last = 'Tester') {
	return array(
		'product_version' => $product_version->key,
		'full_name_first' => $first,
		'full_name_last' => $last,
		'email' => $email,
	);
}

// ---------------------------------------------------------------------------
section('Fixtures');
// ---------------------------------------------------------------------------

$buyer = make_user('own_buyer_' . $run_id);
$other = make_user('own_other_' . $run_id);
$giftee = make_user('own_giftee_' . $run_id);
check((bool)$buyer->key && (bool)$other->key && (bool)$giftee->key, 'three users created');

$tag = 'own-test-' . $run_id;

list($tagged_id, $tagged_err) = own_create_product('Own once ' . $run_id, 'own-once-' . $run_id, array(
	'ownership_mode' => Product::OWNERSHIP_SHARED,
	'pro_ownership_tag' => $tag,
));
check((bool)$tagged_id, 'tagged product created', (string)$tagged_err);
$tagged = new Product($tagged_id, TRUE);
check(trim((string)$tagged->get('pro_ownership_tag')) === $tag, 'tag stored on the product',
	(string)$tagged->get('pro_ownership_tag'));
$tagged_version = own_create_version($tagged_id, 'Standard', '10.00');
check($tagged_version && $tagged_version->key, 'tagged product has a priced version');

list($plain_id, $plain_err) = own_create_product('No limit ' . $run_id, 'own-plain-' . $run_id);
check((bool)$plain_id, 'untagged product created', (string)$plain_err);
$plain = new Product($plain_id, TRUE);
$plain_version = own_create_version($plain_id, 'Standard', '10.00');
check(trim((string)$plain->get('pro_ownership_tag')) === '', 'untagged product carries no tag');

// ---------------------------------------------------------------------------
section('user_owns()');
// ---------------------------------------------------------------------------

check(Ownership::user_owns($buyer->key, $tag) === false, 'no row: does not own');

$direct = own_grant($buyer->key, $tag);
check(Ownership::user_owns($buyer->key, $tag) === true, 'direct tag row: owns');
check(Ownership::user_owns($other->key, $tag) === false, "another user's row does not confer ownership");
check(Ownership::user_owns($buyer->key, 'unrelated-' . $run_id) === false, 'a different tag is not owned');
check(Ownership::user_owns(0, $tag) === false, 'no user id: does not own');
check(Ownership::user_owns($buyer->key, '') === false, 'no tag: does not own');

$direct->set('own_revoked_time', gmdate('Y-m-d H:i:s'));
$direct->save();
check(Ownership::user_owns($buyer->key, $tag) === false, 'revoked row: does not own');
$direct->set('own_revoked_time', NULL);
$direct->save();
check(Ownership::user_owns($buyer->key, $tag) === true, 'un-revoked row: owns again');

$direct->set('own_delete_time', gmdate('Y-m-d H:i:s'));
$direct->save();
check(Ownership::user_owns($buyer->key, $tag) === false, 'deleted row: does not own');
$direct->set('own_delete_time', NULL);
$direct->save();

$star = own_grant($other->key, Ownership::TAG_ALL);
check(Ownership::user_owns($other->key, $tag) === true, 'all-access row covers a specific tag');
check(Ownership::user_owns($other->key, 'anything-' . $run_id) === true, 'all-access row covers any tag');

// ---------------------------------------------------------------------------
section('MultiOwnership options');
// ---------------------------------------------------------------------------

$active = new MultiOwnership(array('user_id' => $buyer->key, 'revoked' => FALSE));
check($active->count_all() === 1, 'revoked => FALSE selects the live row', $active->count_all() . ' rows');

$direct->set('own_revoked_time', gmdate('Y-m-d H:i:s'));
$direct->save();
$revoked_only = new MultiOwnership(array('user_id' => $buyer->key, 'revoked' => TRUE));
check($revoked_only->count_all() === 1, 'revoked => TRUE selects the revoked row', $revoked_only->count_all() . ' rows');
$still_active = new MultiOwnership(array('user_id' => $buyer->key, 'revoked' => FALSE));
check($still_active->count_all() === 0, 'revoked => FALSE excludes it', $still_active->count_all() . ' rows');
$direct->set('own_revoked_time', NULL);
$direct->save();

$covering = new MultiOwnership(array('covers_tag' => $tag, 'revoked' => FALSE));
$covering_ids = array();
foreach ($covering as $row) { $covering_ids[] = (int)$row->key; }
check(in_array((int)$direct->key, $covering_ids, true), 'covers_tag returns the exact-tag row');
check(in_array((int)$star->key, $covering_ids, true), 'covers_tag also returns the all-access row');

// ---------------------------------------------------------------------------
section('Payment-time recorder');
// ---------------------------------------------------------------------------

list($rec_order, $rec_item) = own_make_paid_item($tagged, $tagged_version, $giftee);
$recorded = Ownership::record_purchase($tagged, $tagged_version, $giftee, $rec_item, $rec_order);
check($recorded && $recorded->key, 'a paid tagged item records an ownership');
if ($recorded) {
	harness_register_row('own_ownerships', 'own_ownership_id', $recorded->key);
	check((int)$recorded->get('own_usr_user_id') === (int)$giftee->key,
		'recorded against the user fulfillment is for, not the order buyer');
	check($recorded->get('own_tag') === $tag, 'recorded with the product tag');
	check((int)$recorded->get('own_ord_order_id') === (int)$rec_order->key, 'recorded against the order');
	check((int)$recorded->get('own_odi_order_item_id') === (int)$rec_item->key, 'recorded against the order item');
	check(trim((string)$recorded->get('own_license_key')) === '',
		'core writes no key string — that is fulfillment vocabulary');
}
check(Ownership::user_owns($giftee->key, $tag) === true, 'the recipient now owns the tag');

$again = Ownership::record_purchase($tagged, $tagged_version, $giftee, $rec_item, $rec_order);
$rec_rows = new MultiOwnership(array('order_item_id' => $rec_item->key));
check($rec_rows->count_all() === 1, 'idempotent per order item: a re-run records no second row',
	$rec_rows->count_all() . ' rows');

list($plain_order, $plain_item) = own_make_paid_item($plain, $plain_version, $buyer);
$plain_recorded = Ownership::record_purchase($plain, $plain_version, $buyer, $plain_item, $plain_order);
check($plain_recorded === NULL, 'an untagged product records nothing');

// A subscription version cannot even be created on a tagged product any more
// (proven in the boundary section), so the backstop meets a bare subscription
// order item.
list($sub_order, $sub_item) = own_make_paid_item($tagged, NULL, $other, true);
$sub_recorded = Ownership::record_purchase($tagged, NULL, $other, $sub_item, $sub_order);
check($sub_recorded === NULL, 'a subscription order item is skipped (boundary backstop)');

// The profile names what a tag means — a buyer sees the product, not the tag.
$_SESSION['usr_user_id'] = $giftee->key;
$profile = harness_call_logic('plugins/store/logic/orders_profile_logic.php',
	'orders_profile_logic', array(), 'GET');
check(isset($profile->data['ownership_labels'][$tag])
	&& strpos($profile->data['ownership_labels'][$tag], 'Own once ' . $run_id) !== false,
	'the profile labels a tag with the product name carrying it',
	json_encode($profile->data['ownership_labels'] ?? null));
$_SESSION['usr_user_id'] = 1;

// ---------------------------------------------------------------------------
section('Add to cart');
// ---------------------------------------------------------------------------

$_SESSION['usr_user_id'] = $buyer->key;
$_SESSION['permission'] = 0;

own_reset_cart();
$cart_error = '';
try {
	ShoppingCart::current()->add_item($tagged, own_form_data($tagged_version, $buyer->get('usr_email')));
}
catch (ShoppingCartException $e) {
	$cart_error = $e->getMessage();
}
check($cart_error !== '' && stripos($cart_error, 'already own') !== false,
	'adding an owned tagged product is refused', $cart_error);
check(count(ShoppingCart::current()->items) === 0, 'nothing was added to the cart');

own_reset_cart();
$_SESSION['usr_user_id'] = $other->key;   // holds an all-access row
$star_error = '';
try {
	ShoppingCart::current()->add_item($tagged, own_form_data($tagged_version, $other->get('usr_email')));
}
catch (ShoppingCartException $e) {
	$star_error = $e->getMessage();
}
check($star_error !== '', 'an all-access owner is refused the specific product too', $star_error);

// A gift line is checked against the RECIPIENT. The buyer owns nothing that
// matters here; the giftee owns the tag from the recorder section.
own_reset_cart();
$_SESSION['usr_user_id'] = $buyer->key;
$gift_to_owner_error = '';
try {
	ShoppingCart::current()->add_item($tagged, own_form_data($tagged_version, $giftee->get('usr_email')));
}
catch (ShoppingCartException $e) {
	$gift_to_owner_error = $e->getMessage();
}
check($gift_to_owner_error !== '' && stripos($gift_to_owner_error, 'already owned by') !== false,
	'a gift to someone who already owns it is refused at the cart, naming the recipient',
	$gift_to_owner_error);

own_reset_cart();
$_SESSION['usr_user_id'] = $giftee->key;
// The giftee owns the tag from the recorder section; take that away so this
// user can exercise the unowned path.
$recorded->set('own_revoked_time', gmdate('Y-m-d H:i:s'));
$recorded->save();
$added_ok = true;
try {
	ShoppingCart::current()->add_item($tagged, own_form_data($tagged_version, $giftee->get('usr_email')));
}
catch (ShoppingCartException $e) {
	$added_ok = false;
	check(false, 'an unowned tagged product adds normally', $e->getMessage());
}
if ($added_ok) {
	check(count(ShoppingCart::current()->items) === 1, 'an unowned tagged product adds normally');
}

$dup_error = '';
try {
	ShoppingCart::current()->add_item($tagged, own_form_data($tagged_version, $giftee->get('usr_email')));
}
catch (ShoppingCartException $e) {
	$dup_error = $e->getMessage();
}
check($dup_error !== '', 'a second copy of a tagged product is refused — own-once caps at one', $dup_error);
check(count(ShoppingCart::current()->items) === 1, 'the cart still holds exactly one line');

// The buyer owns the tag themself, but a gift line is for its recipient — an
// owner buying the same thing for someone who does not own it goes through.
own_reset_cart();
$_SESSION['usr_user_id'] = $buyer->key;
$owner_gift_ok = true;
try {
	ShoppingCart::current()->add_item($tagged, own_form_data($tagged_version, $giftee->get('usr_email')));
}
catch (ShoppingCartException $e) {
	$owner_gift_ok = false;
	check(false, 'an owner can still gift the tagged product to a non-owner', $e->getMessage());
}
if ($owner_gift_ok) {
	check(count(ShoppingCart::current()->items) === 1,
		'an owner can still gift the tagged product to a non-owner');
}
$_SESSION['usr_user_id'] = $giftee->key;

own_reset_cart();
$plain_added = true;
try {
	ShoppingCart::current()->add_item($plain, own_form_data($plain_version, $giftee->get('usr_email')));
	ShoppingCart::current()->add_item($plain, own_form_data($plain_version, $giftee->get('usr_email')));
}
catch (ShoppingCartException $e) {
	$plain_added = false;
}
check($plain_added && count(ShoppingCart::current()->items) === 2,
	'untagged products are unaffected — two copies go in fine',
	count(ShoppingCart::current()->items) . ' lines');

// ---------------------------------------------------------------------------
section('Charge time');
// ---------------------------------------------------------------------------

// Build the cart while the buyer owns nothing, then grant ownership — the shape
// of a replayed checkout URL, and the case the earlier guards cannot catch.
own_reset_cart();
$_SESSION['usr_user_id'] = $giftee->key;
ShoppingCart::current()->add_item($tagged, own_form_data($tagged_version, $giftee->get('usr_email')));
$cart = ShoppingCart::current();
$cart->billing_user = array(
	'billing_first_name' => 'Own',
	'billing_last_name' => 'Tester',
	'billing_email' => $giftee->get('usr_email'),
);
$cart->persist();
$total_before = $cart->get_total();

$recorded->set('own_revoked_time', NULL);   // the giftee owns it again
$recorded->save();

$charge_result = harness_call_logic('plugins/store/logic/cart_charge_logic.php', 'cart_charge_logic',
	array(), 'POST');
check($charge_result->redirect === '/checkout', 'checkout refuses an owned item',
	(string)$charge_result->redirect);

$paid_for_tagged = new MultiOrderItem(array('product_id' => $tagged->key, 'user_id' => $giftee->key,
	'status' => OrderItem::STATUS_PAID));
check($paid_for_tagged->count_all() === 1,
	'no new paid line was created — only the fixture from the recorder section',
	$paid_for_tagged->count_all() . ' paid lines');

check(abs((float)ShoppingCart::current()->get_total() - (float)$total_before) < 0.001,
	'the refusal changed no price — ownership refuses a sale, it never discounts one',
	ShoppingCart::current()->get_total() . ' vs ' . $total_before);

// A gift to someone who already owns it is refused on the recipient's behalf.
own_reset_cart();
$_SESSION['usr_user_id'] = $buyer->key;
$gift_form = own_form_data($tagged_version, $giftee->get('usr_email'), 'Gift', 'Recipient');
$gift_cart = ShoppingCart::current();
// Pushed past add_item deliberately: the charge-time guard must catch a gift
// to an owner on its own, whatever the cart happened to let through.
$gift_cart->items[] = array(1, $tagged, $gift_form, 10.00, 0, $tagged_version);
$gift_cart->billing_user = array(
	'billing_first_name' => 'Own',
	'billing_last_name' => 'Buyer',
	'billing_email' => $buyer->get('usr_email'),
);
$gift_cart->persist();

$gift_result = harness_call_logic('plugins/store/logic/cart_charge_logic.php', 'cart_charge_logic',
	array(), 'POST');
check($gift_result->redirect === '/checkout',
	'a gift to someone who already owns it is refused at charge time',
	(string)$gift_result->redirect);

// An all-access row covers a specific tag at charge time too.
own_reset_cart();
$_SESSION['usr_user_id'] = $other->key;
$star_cart = ShoppingCart::current();
$star_cart->items[] = array(1, $tagged, own_form_data($tagged_version, $other->get('usr_email')), 10.00, 0, $tagged_version);
$star_cart->billing_user = array(
	'billing_first_name' => 'Star',
	'billing_last_name' => 'Owner',
	'billing_email' => $other->get('usr_email'),
);
$star_cart->persist();
$star_result = harness_call_logic('plugins/store/logic/cart_charge_logic.php', 'cart_charge_logic',
	array(), 'POST');
check($star_result->redirect === '/checkout',
	'an all-access owner is refused a specific tagged product at charge time',
	(string)$star_result->redirect);

own_reset_cart();

// ---------------------------------------------------------------------------
section('Product page');
// ---------------------------------------------------------------------------

$_SESSION['usr_user_id'] = $giftee->key;
$owned_page = harness_call_logic('plugins/store/logic/product_logic.php', 'product_logic',
	array('product_id' => $tagged->key), 'GET');
check(!empty($owned_page->data['already_owned']), 'an owner sees the already-owned notice');

$_SESSION['usr_user_id'] = $buyer->key;
$recorded->set('own_revoked_time', gmdate('Y-m-d H:i:s'));
$recorded->save();
$direct->set('own_revoked_time', gmdate('Y-m-d H:i:s'));
$direct->save();
$unowned_page = harness_call_logic('plugins/store/logic/product_logic.php', 'product_logic',
	array('product_id' => $tagged->key), 'GET');
check(empty($unowned_page->data['already_owned']), 'a non-owner sees the buy button');
check(Ownership::user_owns($buyer->key, $tag) === false,
	'revoke re-opens purchase — the guards exclude revoked rows');

$untagged_page = harness_call_logic('plugins/store/logic/product_logic.php', 'product_logic',
	array('product_id' => $plain->key), 'GET');
check(empty($untagged_page->data['already_owned']), 'an untagged product is never already-owned');

// ---------------------------------------------------------------------------
section('Admin: the four meanings');
// ---------------------------------------------------------------------------

$_SESSION['usr_user_id'] = 1;
$_SESSION['permission'] = 10;

list($once_id, $once_err) = own_create_product('Own once derived ' . $run_id, 'own-derived-' . $run_id, array(
	'ownership_mode' => Product::OWNERSHIP_ONCE,
));
check((bool)$once_id, 'own-once product saved', (string)$once_err);
$once = new Product($once_id, TRUE);
check($once->get('pro_ownership_tag') === Ownership::tag_for_product($once_id),
	'a brand-new own-once product derives its tag after the insert',
	(string)$once->get('pro_ownership_tag'));
check($once->get_ownership_mode() === Product::OWNERSHIP_ONCE, 'the stored tag maps back to "Own once"');

list($bundle_id, $bundle_err) = own_create_product('Bundle ' . $run_id, 'own-bundle-' . $run_id, array(
	'ownership_mode' => Product::OWNERSHIP_BUNDLE,
));
check((bool)$bundle_id, 'bundle product saved', (string)$bundle_err);
$bundle = new Product($bundle_id, TRUE);
check($bundle->get('pro_ownership_tag') === Ownership::TAG_ALL, 'a bundle stores the all-access tag');
check($bundle->get_ownership_mode() === Product::OWNERSHIP_BUNDLE, 'the all-access tag maps back to "Bundle"');

check($tagged->get_ownership_mode() === Product::OWNERSHIP_SHARED,
	'an operator-named tag maps back to "shared with other products"');
check($plain->get_ownership_mode() === Product::OWNERSHIP_NONE, 'an empty tag maps back to "No limit"');

list($blank_id, $blank_err) = own_create_product('Shared blank ' . $run_id, 'own-blank-' . $run_id, array(
	'ownership_mode' => Product::OWNERSHIP_SHARED,
	'pro_ownership_tag' => '',
));
check($blank_id === null && $blank_err, '"shared" with no tag typed fails validation', (string)$blank_err);

// has_ownership_tag treats NULL and '' alike — both mean untagged.
$once->set('pro_ownership_tag', '');
$once->save();
$untagged_products = new MultiProduct(array('has_ownership_tag' => FALSE));
$untagged_ids = array();
foreach ($untagged_products as $up) { $untagged_ids[] = (int)$up->key; }
check(in_array((int)$plain->key, $untagged_ids, true) && in_array((int)$once->key, $untagged_ids, true)
	&& !in_array((int)$tagged->key, $untagged_ids, true),
	'has_ownership_tag FALSE selects NULL-tagged and empty-tagged products, never tagged ones');
$once->set('pro_ownership_tag', Ownership::tag_for_product($once->key));
$once->save();

// ---------------------------------------------------------------------------
section('Cart: what a bundle covers');
// ---------------------------------------------------------------------------

$bundle_version = own_create_version($bundle_id, 'Standard', '20.00');
check($bundle_version && $bundle_version->key, 'bundle product has a priced version');

$fresh = make_user('own_fresh_' . $run_id);

// Nobody involved here owns anything: the giftee's and buyer's rows were
// revoked in the sections above.
$_SESSION['usr_user_id'] = $buyer->key;
$_SESSION['permission'] = 0;
own_reset_cart();
ShoppingCart::current()->add_item($tagged, own_form_data($tagged_version, $giftee->get('usr_email')));

$covered_error = '';
try {
	ShoppingCart::current()->add_item($bundle, own_form_data($bundle_version, $giftee->get('usr_email')));
}
catch (ShoppingCartException $e) {
	$covered_error = $e->getMessage();
}
check($covered_error !== '' && stripos($covered_error, 'bundle') !== false,
	'a bundle cannot join a cart line it covers, for the same recipient', $covered_error);

$other_recipient_ok = true;
try {
	ShoppingCart::current()->add_item($bundle, own_form_data($bundle_version, $fresh->get('usr_email')));
}
catch (ShoppingCartException $e) {
	$other_recipient_ok = false;
	check(false, 'the bundle for a different recipient is a different ownership', $e->getMessage());
}
if ($other_recipient_ok) {
	check(count(ShoppingCart::current()->items) === 2,
		'the bundle for a different recipient is a different ownership');
}

// The reverse order clashes the same way.
own_reset_cart();
ShoppingCart::current()->add_item($bundle, own_form_data($bundle_version, $giftee->get('usr_email')));
$reverse_error = '';
try {
	ShoppingCart::current()->add_item($tagged, own_form_data($tagged_version, $giftee->get('usr_email')));
}
catch (ShoppingCartException $e) {
	$reverse_error = $e->getMessage();
}
check($reverse_error !== '', 'a covered product cannot join a cart holding the bundle', $reverse_error);

// Two gifts of the same thing to two different people is a fine order.
own_reset_cart();
$two_gifts_ok = true;
try {
	ShoppingCart::current()->add_item($tagged, own_form_data($tagged_version, $giftee->get('usr_email')));
	ShoppingCart::current()->add_item($tagged, own_form_data($tagged_version, $fresh->get('usr_email')));
}
catch (ShoppingCartException $e) {
	$two_gifts_ok = false;
	check(false, 'the same tagged product for two recipients goes in as two lines', $e->getMessage());
}
if ($two_gifts_ok) {
	check(count(ShoppingCart::current()->items) === 2,
		'the same tagged product for two recipients goes in as two lines');
}
own_reset_cart();

$_SESSION['usr_user_id'] = 1;
$_SESSION['permission'] = 10;

// ---------------------------------------------------------------------------
section('Admin: boundaries and actions');
// ---------------------------------------------------------------------------

// A subscription product refuses a tag. $tagged can no longer grow a
// subscription version (proven just below), so build a separate product whose
// subscription version exists first.
list($subprod_id, $subprod_err) = own_create_product('Subscription product ' . $run_id, 'own-subprod-' . $run_id);
check((bool)$subprod_id, 'subscription fixture product created', (string)$subprod_err);
$subprod = new Product($subprod_id, TRUE);
$subprod_version = own_create_version($subprod_id, 'Monthly ' . $run_id, '5.00', 'month');
check($subprod_version && $subprod_version->key, 'an untagged product takes a subscription version');

$sub_save = harness_call_logic('plugins/store/admin/logic/admin_product_edit_logic.php',
	'admin_product_edit_logic', array(
		'action' => 'add',
		'edit_primary_key_value' => $subprod->key,
		'pro_name' => $subprod->get('pro_name'),
		'pro_description' => '',
		'pro_short_description' => '',
		'pro_is_active' => '1',
		'pro_max_purchase_count' => '0',
		'pro_max_cart_count' => '0',
		'pro_expires' => '0',
		'pro_link' => $subprod->get('pro_link'),
		'pro_grp_group_id' => '',
		'pro_prg_product_group_id' => '',
		'pro_sbt_subscription_tier_id' => '',
		'pro_digital_link' => '',
		'pro_emt_receipt_template_id' => '',
		'pro_after_purchase_message' => '',
		'ownership_mode' => Product::OWNERSHIP_SHARED,
		'pro_ownership_tag' => $tag,
	));
check((bool)$sub_save->error && stripos($sub_save->error, 'one-time') !== false,
	'a subscription product refuses an ownership tag, with a reason',
	(string)$sub_save->error);

// The boundary holds from the other direction too, on both paths that can
// create a version.
$new_version_refused = harness_call_logic('plugins/store/admin/logic/admin_product_edit_logic.php',
	'admin_product_edit_logic', array(
		'action' => 'new_version',
		'p' => $tagged->key,
		'version_name' => 'Monthly refused ' . $run_id,
		'version_price' => '5.00',
		'prv_price_type' => 'month',
		'prv_trial_period_days' => '0',
	), 'GET');
check((bool)$new_version_refused->error && stripos($new_version_refused->error, 'one-time') !== false,
	'a tagged product refuses a new subscription version', (string)$new_version_refused->error);

$edit_version_refused = harness_call_logic('plugins/store/admin/logic/admin_product_version_edit_logic.php',
	'admin_product_version_edit_logic', array(
		'product_id' => $tagged->key,
		'version_name' => 'Monthly refused ' . $run_id,
		'version_price' => '5.00',
		'prv_price_type' => 'month',
		'prv_trial_period_days' => '0',
	), 'POST');
check((bool)$edit_version_refused->error && stripos($edit_version_refused->error, 'one-time') !== false,
	'the version edit page refuses it too', (string)$edit_version_refused->error);

// The admin page's own actions: grant by hand, then revoke and un-revoke.
$grant_result = harness_call_logic('plugins/store/admin/logic/admin_ownerships_logic.php',
	'admin_ownerships_logic', array(
		'action' => 'grant',
		'grant_usr_user_id' => $buyer->key,
		'grant_tag' => $tag,
	));
check($grant_result->redirect === '/plugins/store/admin/admin_ownerships',
	'the admin grant action saves and returns to the list', (string)$grant_result->redirect);

$granted = new MultiOwnership(array('user_id' => $buyer->key, 'tag' => $tag, 'revoked' => FALSE));
$comp = NULL;
foreach ($granted as $row) { $comp = $row; }
check($comp && $comp->key, 'the grant created an ownership row');
if ($comp) {
	harness_register_row('own_ownerships', 'own_ownership_id', $comp->key);
	check(!$comp->get('own_ord_order_id'), 'a manual grant carries no order');
	check(trim((string)$comp->get('own_license_key')) === '', 'a manual grant writes no key string');
}
check(Ownership::user_owns($buyer->key, $tag) === true, 'a manual grant confers ownership');

$no_user = harness_call_logic('plugins/store/admin/logic/admin_ownerships_logic.php',
	'admin_ownerships_logic', array('action' => 'grant', 'grant_usr_user_id' => '', 'grant_tag' => $tag));
check((bool)$no_user->error, 'a grant with no owner is refused', (string)$no_user->error);

$no_tag = harness_call_logic('plugins/store/admin/logic/admin_ownerships_logic.php',
	'admin_ownerships_logic', array('action' => 'grant', 'grant_usr_user_id' => $buyer->key, 'grant_tag' => ''));
check((bool)$no_tag->error, 'a grant with no tag is refused', (string)$no_tag->error);

if ($comp) {
	harness_call_logic('plugins/store/admin/logic/admin_ownerships_logic.php',
		'admin_ownerships_logic', array('action' => 'revoke', 'own_ownership_id' => $comp->key));
	check(Ownership::user_owns($buyer->key, $tag) === false,
		'the admin revoke action re-opens purchase');

	harness_call_logic('plugins/store/admin/logic/admin_ownerships_logic.php',
		'admin_ownerships_logic', array('action' => 'unrevoke', 'own_ownership_id' => $comp->key));
	check(Ownership::user_owns($buyer->key, $tag) === true,
		'the admin un-revoke action restores ownership');

	$dup_grant = harness_call_logic('plugins/store/admin/logic/admin_ownerships_logic.php',
		'admin_ownerships_logic', array(
			'action' => 'grant',
			'grant_usr_user_id' => $buyer->key,
			'grant_tag' => $tag,
		));
	check((bool)$dup_grant->error && stripos($dup_grant->error, 'already owns') !== false,
		'granting what is already owned is refused — one live row per person per tag',
		(string)$dup_grant->error);
}

// The detail view loads and reports what the tag covers.
if ($comp) {
	$detail = harness_call_logic('plugins/store/admin/logic/admin_ownership_edit_logic.php',
		'admin_ownership_edit_logic', array('own_ownership_id' => $comp->key), 'GET');
	check(!$detail->error && isset($detail->data['ownership']), 'the ownership detail view loads',
		(string)$detail->error);
	$covered_names = array();
	if (isset($detail->data['covered_products'])) {
		foreach ($detail->data['covered_products'] as $cp) { $covered_names[] = $cp->key; }
	}
	check(in_array((int)$tagged->key, array_map('intval', $covered_names), true),
		'the detail view lists the products the tag covers');

	// A soft-deleted row confers nothing; a stale URL to it must not render
	// it as an active ownership.
	$comp->set('own_delete_time', gmdate('Y-m-d H:i:s'));
	$comp->save();
	$deleted_detail = harness_call_logic('plugins/store/admin/logic/admin_ownership_edit_logic.php',
		'admin_ownership_edit_logic', array('own_ownership_id' => $comp->key), 'GET');
	check((bool)$deleted_detail->error, 'a soft-deleted ownership is refused, not shown as active',
		(string)$deleted_detail->error);
	$comp->set('own_delete_time', NULL);
	$comp->save();
}

$_SESSION['usr_user_id'] = $buyer->key;
$_SESSION['permission'] = 0;
own_reset_cart();
$comp_error = '';
try {
	ShoppingCart::current()->add_item($tagged, own_form_data($tagged_version, $buyer->get('usr_email')));
}
catch (ShoppingCartException $e) {
	$comp_error = $e->getMessage();
}
check($comp_error !== '', 'a manually granted ownership blocks the cart like a purchased one', $comp_error);
own_reset_cart();

harness_finish();
