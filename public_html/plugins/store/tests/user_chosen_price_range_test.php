<?php
/** @joinery-test
 * name: user_chosen_price_range
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 120
 */

/**
 * A product version priced "User Chooses" (prv_price_type 'user') and its
 * optional minimum and maximum (prv_min_price, prv_max_price).
 *
 *   1. The version row   — prepare() refuses a negative end and a minimum above
 *                          the maximum; the range sentence for both ends, one
 *                          end, and neither.
 *   2. get_price()       — refuses an amount under the minimum or over the
 *                          maximum, naming the range; takes one inside it; a
 *                          version with no range takes any amount above zero.
 *   3. validate_form()   — the product page's check, with the same wording; an
 *                          absent amount is refused for a user-priced version;
 *                          a fixed-price pick needs none and drops a typed one.
 *   4. The amount box    — sized so 500.00 can be typed (10 with no maximum),
 *                          the range as help text, and on a several-version
 *                          product revealed by the dropdown's visibility_rules
 *                          only for the user-priced version.
 *   5. Checkout          — product_logic adds a several-version product's
 *                          user-priced pick to the cart at the typed amount,
 *                          and its fixed pick at the version price.
 *   6. The admin editor  — saves the range for a user-priced version and
 *                          clears it for any other.
 *   7. The cart view     — shows the typed amount as "Amount" and keeps
 *                          "Donation Amount" for the donation product.
 *   8. The shown price   — get_readable_price() shows a user-priced version's
 *                          range, never its stored price; none with no range.
 *
 * Run: php plugins/store/tests/user_chosen_price_range_test.php
 *
 * @version 1.2 - section 8, the shown price of a user-priced version
 * @version 1.1 - the admin identity is set again after the forms render (a started session replaces $_SESSION)
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/logic.php');
harness_boot();

if (!class_exists('Product')) {
	harness_skip('the store plugin is not active here', 'Product does not resolve');
	harness_finish();
	exit;
}

// No payment provider: adding to the cart must not reach Stripe.
harness_set_setting_mem('checkout_type', 'none');
harness_set_setting_mem('use_paypal_checkout', 0);
harness_set_setting_mem('products_active', 1);
harness_set_setting_mem('site_currency', 'usd');
harness_set_setting_mem('cart_intermediate_page', 0);

// The admin editor's save is checked against the signed-in user. Rendering a
// form starts the PHP session (FormWriter's CSRF token), and under the runner,
// which buffers output, that session_start() succeeds and replaces $_SESSION
// with the stored, empty session. So the identity is set again right before
// the admin calls, after every form this suite renders.
function ucp_sign_in_admin() {
	$_SESSION['loggedin'] = 1;
	$_SESSION['usr_user_id'] = 1;
	$_SESSION['permission'] = 10;
}
ucp_sign_in_admin();

$run_id = substr(md5(uniqid('ucp', true)), 0, 8);

function ucp_product($name) {
	global $run_id;
	$product = new Product(NULL);
	$product->set('pro_name', $name . ' ' . $run_id);
	$product->set('pro_description', 'user_chosen_price_range fixture');
	$product->set('pro_short_description', 'user_chosen_price_range fixture');
	$product->set('pro_is_active', 1);
	$product->set('pro_link', 'ucp-' . strtolower(str_replace(' ', '-', $name)) . '-' . $run_id);
	$product->save();
	$product->load();
	$id = (int)$product->key;
	harness_defer(function () use ($id) {
		try {
			$p = new Product($id, TRUE);
			if ($p->key) $p->permanent_delete();
		} catch (\Throwable $e) {
			echo "  WARNING: could not delete product $id: " . $e->getMessage() . "\n";
		}
	});
	return $product;
}

function ucp_version($product, $name, $type, $price, $min = NULL, $max = NULL) {
	$version = new ProductVersion(NULL);
	$version->set('prv_pro_product_id', $product->key);
	$version->set('prv_version_name', $name);
	$version->set('prv_version_price', $price);
	$version->set('prv_status', 1);
	$version->set('prv_price_type', $type);
	$version->set('prv_min_price', $min);
	$version->set('prv_max_price', $max);
	$version->prepare();
	$version->save();
	$version->load();
	return $version;
}

/** The message a callable throws, or null when it throws nothing. */
function ucp_refusal(callable $fn) {
	try {
		$fn();
	} catch (\Throwable $e) {
		return $e->getMessage();
	}
	return null;
}

// ---------------------------------------------------------------------------
section('The version row');
// ---------------------------------------------------------------------------

$credit = ucp_product('Credit');
$ranged = ucp_version($credit, 'Any amount', 'user', '0.00', '5', '500');

check($ranged->price_range_text('$') === 'Enter an amount from $5 to $500', 'both ends name the range',
	$ranged->price_range_text('$'));

$probe = new ProductVersion(NULL);
$probe->set('prv_min_price', '5.50');
check($probe->price_range_text('$') === 'Enter an amount of at least $5.50', 'only a minimum',
	$probe->price_range_text('$'));
$probe->set('prv_min_price', NULL);
$probe->set('prv_max_price', '100');
check($probe->price_range_text('$') === 'Enter an amount of no more than $100', 'only a maximum',
	$probe->price_range_text('$'));
$probe->set('prv_max_price', NULL);
check($probe->price_range_text('$') === '', 'no range, no sentence');

$err = ucp_refusal(function () use ($credit) { ucp_version($credit, 'Backwards', 'user', '0', '50', '10'); });
check($err !== null && stripos($err, 'minimum amount is more than the maximum') !== false,
	'a minimum above the maximum is refused', (string)$err);
$err = ucp_refusal(function () use ($credit) { ucp_version($credit, 'Negative', 'user', '0', '-1', NULL); });
check($err !== null && stripos($err, 'zero or more') !== false, 'a negative minimum is refused', (string)$err);

$blank = ucp_version($credit, 'Blank ends', 'user', '0', '', '');
check($blank->get('prv_min_price') === NULL && $blank->get('prv_max_price') === NULL,
	'empty ends are stored as no range');

// ---------------------------------------------------------------------------
section('get_price()');
// ---------------------------------------------------------------------------

$err = ucp_refusal(function () use ($credit, $ranged) { $credit->get_price($ranged, array('user_price_override' => '4.99')); });
check($err !== null && strpos($err, 'Enter an amount from $5 to $500') !== false,
	'an amount under the minimum is refused, naming the range', (string)$err);
$err = ucp_refusal(function () use ($credit, $ranged) { $credit->get_price($ranged, array('user_price_override' => '500.01')); });
check($err !== null && strpos($err, 'Enter an amount from $5 to $500') !== false,
	'an amount over the maximum is refused, naming the range', (string)$err);
check($credit->get_price($ranged, array('user_price_override' => '5')) === '5.00', 'the minimum itself is taken');
check($credit->get_price($ranged, array('user_price_override' => '500.00')) === '500.00', 'the maximum itself is taken');
check($credit->get_price($ranged, array('user_price_override' => '20')) === '20.00', 'an amount inside is taken');

$open = ucp_version($credit, 'Open', 'user', '0');
check($credit->get_price($open, array('user_price_override' => '0.01')) === '0.01',
	'a version with no range takes any amount above zero, as before');
check($credit->get_price($open, array('user_price_override' => '99999')) === '99999.00',
	'and any large amount');
$err = ucp_refusal(function () use ($credit, $open) { $credit->get_price($open, array('user_price_override' => '0')); });
check($err !== null && strpos($err, 'missing a valid amount') !== false, 'zero is still refused', (string)$err);

// ---------------------------------------------------------------------------
section('validate_form()');
// ---------------------------------------------------------------------------

$session = SessionControl::get_instance();
$err = ucp_refusal(function () use ($credit, $ranged, $session) {
	$credit->validate_form(array('product_version' => $ranged->key, 'user_price_override' => '1'), $session);
});
check($err === 'Enter an amount from $5 to $500.', 'the product page refuses an amount under the minimum', (string)$err);
$err = ucp_refusal(function () use ($credit, $ranged, $session) {
	$credit->validate_form(array('product_version' => $ranged->key, 'user_price_override' => '501'), $session);
});
check($err === 'Enter an amount from $5 to $500.', 'and over the maximum', (string)$err);
$err = ucp_refusal(function () use ($credit, $ranged, $session) {
	$credit->validate_form(array('product_version' => $ranged->key), $session);
});
check($err !== null && strpos($err, 'Enter an amount from $5 to $500') !== false,
	'a user-priced version with no amount sent is refused', (string)$err);
list($data) = $credit->validate_form(array('product_version' => $ranged->key, 'user_price_override' => '$25,5'), $session);
check(($data['user_price_override'] ?? null) === '25.50', 'an accepted amount is kept in its plain form',
	var_export($data['user_price_override'] ?? null, true));
$err = ucp_refusal(function () use ($credit, $open, $session) {
	$credit->validate_form(array('product_version' => $open->key, 'user_price_override' => 'abc'), $session);
});
check($err === 'Enter the amount to pay as a number above zero, like 25 or 25.50.',
	'a version with no range keeps its wording', (string)$err);

// ---------------------------------------------------------------------------
section('The amount box');
// ---------------------------------------------------------------------------

$options = Product::amount_box_options(array($ranged));
check(($options['maxlength'] ?? 0) >= strlen('500.00'), '500.00 can be typed', json_encode($options));
check(($options['helptext'] ?? '') === 'Enter an amount from $5 to $500', 'the range is the help text', json_encode($options));
$options = Product::amount_box_options(array($open));
check(($options['maxlength'] ?? 0) === 10 && !isset($options['helptext']),
	'with no maximum the box takes 10 characters and has no help text', json_encode($options));

// A product with one user-priced version.
$single = ucp_product('Single');
$single_version = ucp_version($single, 'Pay what you like', 'user', '0', '5', '500');
ob_start();
$single->output_product_form(new FormWriterV2HTML5('product_form'), NULL, true);
$html = ob_get_clean();
check(strpos($html, 'name="user_price_override"') !== false, 'a single user-priced version shows the box');
check(preg_match('/name="product_version"[^>]*value="' . $single_version->key . '"|value="' . $single_version->key . '"[^>]*name="product_version"/', $html) === 1,
	'and names its version, so the page can check out');
check(strpos($html, 'maxlength="6"') !== false, 'sized to 500.00', $html);
check(strpos($html, 'Enter an amount from $5 to $500') !== false, 'with the range shown');

// A product with a fixed version and a user-priced one.
$mixed = ucp_product('Mixed');
$fixed = ucp_version($mixed, 'Standard', 'single', '30.00');
$chosen = ucp_version($mixed, 'Your amount', 'user', '0', '5', '500');
ob_start();
$mixed->output_product_form(new FormWriterV2HTML5('product_form'), NULL, true, $fixed->key);
$html = ob_get_clean();
check(strpos($html, 'name="user_price_override"') !== false, 'a several-version product with a user-priced one has the box');
check(strpos($html, 'Your amount - you choose the amount') !== false, 'the dropdown names the user-priced version for what it is');
$vis = null;
if (preg_match('#<script type="application/json" data-jy-visibility>(.*?)</script>#s', $html, $m)) {
	$vis = json_decode($m[1], true);
}
check(is_array($vis), 'the dropdown carries visibility rules', $html);
$rules = $vis['rules'] ?? array();
check(in_array('user_price_override', $rules[(string)$chosen->key]['show'] ?? array(), true),
	'picking the user-priced version shows the box', json_encode($rules));
check(in_array('user_price_override', $rules[(string)$fixed->key]['hide'] ?? array(), true),
	'picking the fixed version hides it', json_encode($rules));

$plain = ucp_product('Plain');
$plain_a = ucp_version($plain, 'A', 'single', '10.00');
$plain_b = ucp_version($plain, 'B', 'single', '20.00');
ob_start();
$plain->output_product_form(new FormWriterV2HTML5('product_form'), NULL, true);
$html = ob_get_clean();
check(strpos($html, 'user_price_override') === false && strpos($html, 'data-jy-visibility') === false,
	'a product with only fixed versions has no box and no rules');

// ---------------------------------------------------------------------------
section('Checkout');
// ---------------------------------------------------------------------------

$cart = ShoppingCart::current();
$cart->clear_cart();
$result = harness_call_logic('plugins/store/logic/product_logic.php', 'product_logic', array(
	'product_id' => $mixed->key, 'product_version' => $chosen->key, 'user_price_override' => '42',
));
check(empty($result->error) && !empty($result->redirect), 'the user-priced pick is added', (string)$result->error);
$line = end($cart->items);
check($line && (int)$line[5]->key === (int)$chosen->key && (string)$line[3] === '42.00',
	'at the typed amount', $line ? var_export($line[3], true) : 'no line');

$err = ucp_refusal(function () use ($mixed, $chosen) {
	$result = harness_call_logic('plugins/store/logic/product_logic.php', 'product_logic', array(
		'product_id' => $mixed->key, 'product_version' => $chosen->key, 'user_price_override' => '600',
	));
	if (!empty($result->error)) { throw new RuntimeException($result->error); }
});
check($err === 'Enter an amount from $5 to $500.', 'an amount over the maximum is refused, naming the range', (string)$err);
check(count($cart->items) === 1, 'the cart still has one line', (string)count($cart->items));

$result = harness_call_logic('plugins/store/logic/product_logic.php', 'product_logic', array(
	'product_id' => $mixed->key, 'product_version' => $fixed->key, 'user_price_override' => '',
));
check(empty($result->error), 'the fixed pick needs no amount', (string)$result->error);
$line = end($cart->items);
check($line && (int)$line[5]->key === (int)$fixed->key && abs((float)$line[3] - 30.00) < 0.001
	&& !isset($line[2]['user_price_override']), 'it is added at the version price, with no amount kept',
	$line ? var_export($line[3], true) : 'no line');
$cart->clear_cart();

// ---------------------------------------------------------------------------
section('The admin editor');
// ---------------------------------------------------------------------------

ucp_sign_in_admin();

$result = harness_call_logic('plugins/store/admin/logic/admin_product_version_edit_logic.php',
	'admin_product_version_edit_logic', array(
		'product_id' => $credit->key, 'edit_primary_key_value' => $open->key,
		'version_name' => 'Open', 'version_price' => '0', 'prv_price_type' => 'user',
		'prv_trial_period_days' => '0', 'prv_min_price' => '5', 'prv_max_price' => '500',
	));
$open = new ProductVersion($open->key, TRUE);
check(empty($result->error) && (float)$open->get('prv_min_price') === 5.0 && (float)$open->get('prv_max_price') === 500.0,
	'the range is saved for a user-priced version', (string)$result->error);

$result = harness_call_logic('plugins/store/admin/logic/admin_product_version_edit_logic.php',
	'admin_product_version_edit_logic', array(
		'product_id' => $credit->key, 'edit_primary_key_value' => $open->key,
		'version_name' => 'Open', 'version_price' => '10', 'prv_price_type' => 'single',
		'prv_trial_period_days' => '0', 'prv_min_price' => '5', 'prv_max_price' => '500',
	));
$open = new ProductVersion($open->key, TRUE);
check($open->get('prv_min_price') === NULL && $open->get('prv_max_price') === NULL,
	'and cleared when the version is no longer user-priced');

// ---------------------------------------------------------------------------
section('The cart view');
// ---------------------------------------------------------------------------

$cart_source = file_get_contents(PathHelper::getIncludePath('plugins/store/views/cart.php'));
check(strpos($cart_source, "\$meta_rows['Amount'] = \$currency_symbol . htmlspecialchars(\$data['user_price_override']") !== false,
	'the cart shows a typed amount as Amount');
check(preg_match("/user_price'\\]\\) && \\\$product->is_optional_donation\\(\\)\\) \\{\\s*\\\$meta_rows\\['Donation Amount'\\]/", $cart_source) === 1,
	'and keeps Donation Amount for the donation product');

section('The shown price');
// ---------------------------------------------------------------------------

$shown = ucp_product('Shown');
$shown_v = ucp_version($shown, 'Any amount', 'user', '10.00', '5', '500');
check($shown->get_readable_price() === '$5 to $500',
	'a single user-priced version shows its range, not its stored price', var_export($shown->get_readable_price(), true));
check($shown->get_readable_price($shown_v->key) === '$5 to $500',
	'and so does the same version asked for by id');

$min_only = ucp_product('MinOnly');
ucp_version($min_only, 'At least', 'user', '10.00', '5.50', NULL);
check($min_only->get_readable_price() === '$5.50 or more', 'a minimum alone reads as or more', var_export($min_only->get_readable_price(), true));

$max_only = ucp_product('MaxOnly');
ucp_version($max_only, 'At most', 'user', '10.00', NULL, '50');
check($max_only->get_readable_price() === 'Up to $50', 'a maximum alone reads as up to', var_export($max_only->get_readable_price(), true));

$no_range = ucp_product('NoRange');
ucp_version($no_range, 'Anything', 'user', '10.00');
check($no_range->get_readable_price() === false, 'no range: no price is shown at all', var_export($no_range->get_readable_price(), true));

$spread = ucp_product('Spread');
ucp_version($spread, 'Standard', 'single', '30.00');
ucp_version($spread, 'Your amount', 'user', '10.00', '5', '500');
check($spread->get_readable_price() === '$5.00 - $500.00',
	'several versions span the user range and the fixed price', var_export($spread->get_readable_price(), true));

$open_top = ucp_product('OpenTop');
ucp_version($open_top, 'Standard', 'single', '30.00');
ucp_version($open_top, 'Your amount', 'user', '10.00', '5', NULL);
check($open_top->get_readable_price() === 'From $5.00',
	'a user version with no maximum opens the top of the span', var_export($open_top->get_readable_price(), true));

check($plain->get_readable_price() === '$10.00 - $20.00', 'fixed versions alone read as before', var_export($plain->get_readable_price(), true));

harness_finish();
