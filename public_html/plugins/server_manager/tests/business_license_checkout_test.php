<?php
/** @joinery-test
 * name: business_license_checkout
 * tier: db
 * env: dev-only
 * needs: []
 * covers: [plugins/server_manager/includes/BusinessLicense.php, plugins/server_manager/includes/ManagedSiteDraft.php, plugins/server_manager/includes/requirements/ManagedSiteRequirement.php, plugins/server_manager/includes/fulfillment_providers/CustomerCloudFulfillment.php, plugins/store/data/ownerships_class.php, plugins/store/data/products_class.php, plugins/store/includes/ShoppingCart.php]
 */
/**
 * The business licence at checkout (specs/hosting_after_purchase.md WP7).
 *
 * The store: a product set to "Each purchase is one more" records an
 * ownership per purchase and never refuses a buyer who has one, while an
 * own-once product still does.
 *
 * The site setup: who the site is for is asked on every plan; a business
 * picks a licence to buy, or one it holds that no live site uses. A licence
 * bought rides as a cart line naming the site, is priced in the summary, must
 * still be in the cart at the charge, and once the store records it, covers
 * that site and no other. A held licence is named on the site at activation.
 * A site removed frees its licence.
 *
 * dev-only: it makes products with the real licence links.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

class BlcFulfillment extends CustomerCloudFulfillment {
	public static $alerts = array();
	protected function alert_activation_problem(User $user, OrderItem $order_item, string $reason): void {
		self::$alerts[] = $reason;
	}
}

foreach (BusinessLicense::KINDS as $kind => $def) {
	if ((new MultiProduct(array('pro_link' => $def['link'], 'deleted' => false)))->count_all() > 0) {
		section('Business licence at checkout');
		check(true, 'skipped: this site already has a product at /product/' . $def['link']);
		harness_finish();
		return;
	}
}

$run = substr(md5(uniqid('blc', true)), 0, 6);

function blc_product(string $name, string $link, array $fields = array()) {
	$product = new Product(NULL);
	$product->set('pro_name', $name);
	$product->set('pro_link', $link);
	$product->set('pro_is_active', true);
	foreach ($fields as $k => $v) { $product->set($k, $v); }
	$product->save();
	$product->load();
	harness_register_row('pro_products', 'pro_product_id', $product->key);
	return $product;
}
function blc_version($product, string $price, string $type) {
	$version = new ProductVersion(NULL);
	$version->set('prv_pro_product_id', $product->key);
	$version->set('prv_version_name', 'Default');
	$version->set('prv_version_price', $price);
	$version->set('prv_price_type', $type);
	$version->set('prv_status', 1);
	$version->save();
	$version->load();
	harness_register_row('prv_product_versions', 'prv_product_version_id', $version->key);
	return $version;
}
/** A paid line of a product in an order, with the form data the cart stored. */
function blc_line($buyer, $order, $product, $version, array $data) {
	$item = new OrderItem(NULL);
	$item->set('odi_ord_order_id', $order->key);
	$item->set('odi_pro_product_id', $product->key);
	$item->set('odi_prv_product_version_id', $version->key);
	$item->set('odi_usr_user_id', $buyer->key);
	$item->set('odi_product_info', base64_encode(serialize($data)));
	$item->set('odi_price', $version->get('prv_version_price'));
	$item->set('odi_status', OrderItem::STATUS_PAID);
	$item->save();
	$item->load();
	harness_register_row('odi_order_items', 'odi_order_item_id', $item->key);
	return $item;
}
function blc_order($buyer) {
	$order = new Order(NULL);
	$order->set('ord_usr_user_id', $buyer->key);
	$order->set('ord_status', Order::STATUS_PAID);
	$order->save();
	$order->load();
	harness_register_row('ord_orders', 'ord_order_id', $order->key);
	return $order;
}
function blc_post(string $domain, array $extra): array {
	return array_merge(array('plan' => 'managed', 'cvp_domain_source' => 'own', 'cvp_domain' => $domain,
		'cvp_sitename' => '', 'cvp_buyer_email' => 'admin@example.com'), $extra);
}
function blc_ownerships_of($user_id) {
	harness_defer(function () use ($user_id) {
		foreach (new MultiOwnership(array('user_id' => $user_id, 'include_deleted' => true)) as $own) {
			$own->permanent_delete();
		}
	});
}

$managed = blc_product('Managed hosting [blc]', 'blc-managed-' . $run, array(
	'pro_fulfillment_provider' => 'customer_cloud', 'pro_fulfillment_ref' => 1));
$managed_version = blc_version($managed, '12.99', 'month');
$founder = blc_product('Founder Licence [blc]', BusinessLicense::KINDS['founder']['link']);
$founder->set('pro_ownership_tag', Ownership::tag_each_for_product($founder->key));
$founder->save();
$founder_version = blc_version($founder, '499.00', 'single');
$standard = blc_product('Standard Business Licence [blc]', BusinessLicense::KINDS['standard']['link']);
$standard_version = blc_version($standard, '399.00', 'single');
$once = blc_product('Course [blc]', 'blc-once-' . $run);
$once->set('pro_ownership_tag', Ownership::tag_for_product($once->key));
$once->save();
$once_version = blc_version($once, '20.00', 'single');
BusinessLicense::forget();

// No payment gateway: adding to the cart makes no Stripe product (as the store's ownership suite does).
harness_set_setting_mem('checkout_type', 'none');
harness_set_setting_mem('use_paypal_checkout', 0);
harness_set_setting_mem('products_active', 1);
harness_set_setting_mem('server_manager_hosted_regions', '');
harness_set_setting_mem('server_manager_customer_cloud_region', 'us-southeast');
$buyer = make_user('BlcBuyer' . $run);
$other = make_user('BlcOther' . $run);
blc_ownerships_of((int)$buyer->key);
blc_ownerships_of((int)$other->key);
$_SESSION['loggedin'] = 1;
$_SESSION['usr_user_id'] = (int)$buyer->key;
$_SESSION['permission'] = 0;

// ---------------------------------------------------------------------------
section('Each purchase is one more: the store never refuses a repeat');

check($founder->get_ownership_mode() === Product::OWNERSHIP_EACH && $founder->ownership_repeats(),
	'a product tagged each-product-{id} reads as Each purchase is one more');
check($once->get_ownership_mode() === Product::OWNERSHIP_ONCE && !$once->ownership_repeats(), 'own-once is unchanged');
$o1 = blc_order($buyer);
$l1 = blc_line($buyer, $o1, $founder, $founder_version, array());
$l2 = blc_line($buyer, $o1, $founder, $founder_version, array());
$own1 = Ownership::record_purchase($founder, $founder_version, $buyer, $l1, $o1);
$own2 = Ownership::record_purchase($founder, $founder_version, $buyer, $l2, $o1);
check($own1 && $own2 && (int)$own1->key !== (int)$own2->key, 'two purchases record two ownerships');
check((int)Ownership::record_purchase($founder, $founder_version, $buyer, $l1, $o1)->key === (int)$own1->key,
	'and recording stays once per order item');
$cart = ShoppingCart::current();
$cart->items = array();
$added = 0;
try {
	$cart->add_item($founder, array('product_version' => (int)$founder_version->key));
	$cart->add_item($founder, array('product_version' => (int)$founder_version->key));
	$added = count($cart->items);
} catch (ShoppingCartException $e) {
	$added = $e->getMessage();
}
check($added === 2, 'a buyer who holds it may put two more in the cart', (string)$added);
$cart->items = array();
$o0 = blc_order($buyer);
Ownership::record_purchase($once, $once_version, $buyer, blc_line($buyer, $o0, $once, $once_version, array()), $o0);
$refused = false;
try { $cart->add_item($once, array('product_version' => (int)$once_version->key)); } catch (ShoppingCartException $e) { $refused = true; }
check($refused, 'an own-once product the buyer owns is still refused');
$cart->items = array();
$cart->persist();
foreach (array($own1, $own2) as $own) { $own->permanent_delete(); }

// ---------------------------------------------------------------------------
section('Only a licence set to Each purchase is one more is on sale');

$offers = BusinessLicense::offers();
check(isset($offers['founder']) && !isset($offers['standard']),
	'the Founder licence is offered; the Standard one, still own-once in all but name, is not', json_encode(array_keys($offers)));
$standard->set('pro_ownership_tag', Ownership::tag_each_for_product($standard->key));
$standard->save();
BusinessLicense::forget();
check(count(BusinessLicense::offers()) === 2 && BusinessLicense::offers()['standard']['price'] === '$399.00',
	'once set, both are offered at their own price');

// ---------------------------------------------------------------------------
section('Who the site is for is asked on every plan');

$r = ManagedSiteDraft::save_and_freeze(null, blc_post('blc-none-' . $run . '.com', array()), $buyer);
check($r['row'] === null && in_array('Say who the site is for: personal use, or a business.', $r['errors'], true),
	'not answering is refused', implode(' | ', $r['errors']));
$r = ManagedSiteDraft::save_and_freeze(null, blc_post('blc-biz-' . $run . '.com', array('licence_use' => 'business')), $buyer);
check($r['row'] === null && in_array('Choose a business licence for the site.', $r['errors'], true),
	'a business that picks no licence is refused');
$r = ManagedSiteDraft::save_and_freeze(null, blc_post('blc-free-' . $run . '.com', array('licence_use' => 'free')), $buyer);
$free_site = $r['row'];
harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $free_site ? $free_site->key : 0);
check($free_site && (string)$free_site->get('cvp_license_choice') === 'free'
	&& BusinessLicense::site_line($free_site) === 'Free licence (personal use)', 'personal use is free and says so');
$t = ManagedSiteDraft::price_table($free_site, $managed, null);
check(count($t['lines']) === 1, 'and adds nothing to the price');

// ---------------------------------------------------------------------------
section('A licence bought with the site rides in the cart and the price');

$r = ManagedSiteDraft::save_and_freeze(null, blc_post('blc-founder-' . $run . '.com',
	array('licence_use' => 'business', 'licence_choice' => 'founder')), $buyer);
$site = $r['row'];
harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $site ? $site->key : 0);
check($site && (string)$site->get('cvp_license_choice') === 'founder', 'the choice is saved with the freeze', implode(' | ', $r['errors']));
$t = ManagedSiteDraft::price_table($site, $managed, null);
check($t['lines'][1]['label'] === 'Founder licence' && $t['lines'][1]['today'] === '$499.00' && $t['lines'][1]['then'] === ''
	&& $t['today'] === '$511.99' && $t['then'] === '$12.99 a month',
	'the summary has a one-time Founder licence line and the total due today counts it', json_encode($t));

$req = new ManagedSiteRequirement();
$req->validate(array('managed_site' => (int)$site->key), $managed);
list($data) = $req->process(array('managed_site' => (int)$site->key), $managed, null, null);
$lines = $req->extra_cart_lines($data, $managed);
check(count($lines) === 1 && (int)$lines[0]['product_id'] === (int)$founder->key
	&& BusinessLicense::draft_id_from_line($lines[0]['form_data']) === (int)$site->key
	&& ($lines[0]['form_data'][BusinessLicense::LINE_SUMMARY]['answer'] ?? '') === 'blc-founder-' . $run . '.com',
	'the licence line is the Founder product, naming the site and its domain', json_encode($lines));
check($req->extra_cart_lines($data, $managed) === $lines, 'identical data, identical line');

$provider = new BlcFulfillment();
check($provider->checkAvailability($managed, 1, 1, $data) === BusinessLicense::LINE_MISSING,
	'the charge refuses the site while its licence is not in the cart');
$cart = ShoppingCart::current();
$cart->items[] = array(1, $once, $lines[0]['form_data'] + array('product_version' => (int)$once_version->key),
	20.00, 0, $once_version);
$cart->persist();
check($provider->checkAvailability($managed, 1, 1, $data) === BusinessLicense::LINE_MISSING,
	'another product carrying the licence line\'s key is not the licence (B40)');
$cart->items = array();
$cart->items[] = array(1, $founder, $lines[0]['form_data'] + array('product_version' => (int)$founder_version->key),
	499.00, 0, $founder_version);
$cart->persist();
check($provider->checkAvailability($managed, 1, 1, $data) === null, 'and passes once it is');
ManagedSiteDraft::drop_from_cart($site);
check(count(ShoppingCart::current()->items) === 0, 'Edit takes the licence line out with the site');

// The buyer removes only the licence line on the cart page: the site's own
// line is left, which cannot be paid for. It is not "already in your cart".
$cart = ShoppingCart::current();
$cart->items = array(
	array(1, $managed, $data + array('product_version' => (int)$managed_version->key), 12.99, 0, $managed_version),
	array(1, $founder, $lines[0]['form_data'] + array('product_version' => (int)$founder_version->key), 499.00, 0, $founder_version),
);
$cart->persist();
check(ManagedSiteDraft::in_cart($site) && ManagedSiteDraft::cart_missing($site) === ''
	&& ManagedSiteDraft::in_cart_whole($site), 'the site and its licence line together are in the cart, whole');
$cart = ShoppingCart::current();
unset($cart->items[1]);
$cart->items = array_values($cart->items);
$cart->persist();
check(ManagedSiteDraft::cart_missing($site) === 'licence', 'with the licence line removed, the cart lacks the licence');
check(!ManagedSiteDraft::in_cart_whole($site) && count(ShoppingCart::current()->items) === 0,
	'and the part-set is dropped whole, so Continue to payment is offered again');

// The other way round: the buyer removes the SITE's line. Its licence line
// came with it and goes with it; a licence the buyer added on its own stays.
$cart = ShoppingCart::current();
$cart->items = array(
	array(1, $managed, $data + array('product_version' => (int)$managed_version->key), 12.99, 0, $managed_version),
	array(1, $founder, $lines[0]['form_data'] + array('product_version' => (int)$founder_version->key), 499.00, 0, $founder_version),
	array(1, $founder, array('product_version' => (int)$founder_version->key), 499.00, 0, $founder_version),
);
$cart->persist();
check($cart->remove_item_with_companions(0) === TRUE, 'the site line is removed');
$left = array_values(ShoppingCart::current()->items);
check(count($left) === 1 && (int)$left[0][1]->key === (int)$founder->key
	&& BusinessLicense::draft_id_from_line((array)$left[0][2]) === 0,
	'its licence line goes with it, and a licence bought on its own stays', count($left) . ' line(s) left');
$cart = ShoppingCart::current();
$cart->items = array();
$cart->persist();

// ---------------------------------------------------------------------------
section('Paid: the licence the store records covers that site');

$order = blc_order($buyer);
$site_item = blc_line($buyer, $order, $managed, $managed_version, $data);
$lic_item = blc_line($buyer, $order, $founder, $founder_version, $lines[0]['form_data']);
$f = $provider->fulfill($buyer, $managed, $site_item, $order, 1);
$site = new CustomerCloudProvision((int)$site->key, TRUE);
check((int)($f['ref_id'] ?? 0) === (int)$site->key && (string)$site->get('cvp_status') === 'ready',
	'the site activates', json_encode($f));
check(BusinessLicense::site_line($site) === 'Founder licence (being recorded)',
	'before the store records the licence, Your Sites says it is on its way');
$bought = Ownership::record_purchase($founder, $founder_version, $buyer, $lic_item, $order);
check($bought && (int)BusinessLicense::for_site($site)->key === (int)$bought->key && BusinessLicense::site_line($site) === 'Founder licence',
	'once recorded, it is the licence covering the site');
check(BusinessLicense::unused_by((int)$buyer->key) === array(), 'and it is not free for another site');

$o_odd = blc_order($buyer);
$odd_line = blc_line($buyer, $o_odd, $once, $once_version, $lines[0]['form_data']);
$odd = Ownership::record_purchase($once, $once_version, $buyer, $odd_line, $o_odd);
check($odd && BusinessLicense::site_using($odd) === null && (int)BusinessLicense::for_site($site)->key === (int)$bought->key,
	'a non-licence purchase naming the site neither covers it nor is in use by it (B40)');
$odd->permanent_delete();

// ---------------------------------------------------------------------------
section('A licence paid for alone, its site left unpaid, is free (B44)');

$r = ManagedSiteDraft::save_and_freeze(null, blc_post('blc-unpaid-' . $run . '.com',
	array('licence_use' => 'business', 'licence_choice' => 'founder')), $buyer);
$unpaid = $r['row'];
harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $unpaid ? $unpaid->key : 0);
$o_alone = blc_order($buyer);
$alone = Ownership::record_purchase($founder, $founder_version, $buyer,
	blc_line($buyer, $o_alone, $founder, $founder_version, BusinessLicense::cart_line((int)$unpaid->key, 'founder', 'x')[0]['form_data']), $o_alone);
check(BusinessLicense::site_using($alone) === null && count(BusinessLicense::unused_by((int)$buyer->key)) === 1,
	'the licence is free for any site while its draft is unpaid');
$alone->permanent_delete();

// ---------------------------------------------------------------------------
section('A licence held covers one more site, and only one');

$o2 = blc_order($buyer);
$spare = Ownership::record_purchase($standard, $standard_version, $buyer,
	blc_line($buyer, $o2, $standard, $standard_version, array()), $o2);
$unused = BusinessLicense::unused_by((int)$buyer->key);
check(count($unused) === 1 && (int)$unused[0]->key === (int)$spare->key, 'a licence bought on its own is free to use');
$held_choice = BusinessLicense::HELD_PREFIX . (int)$spare->key;
check(BusinessLicense::choice_from(array('licence_use' => 'business', 'licence_choice' => $held_choice), (int)$other->key, 0)['error'] !== '',
	'another buyer cannot choose it');
$r = ManagedSiteDraft::save_and_freeze(null, blc_post('blc-held-' . $run . '.com',
	array('licence_use' => 'business', 'licence_choice' => $held_choice)), $buyer);
$held_site = $r['row'];
harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $held_site ? $held_site->key : 0);
check($held_site && (string)$held_site->get('cvp_license_choice') === $held_choice, 'the buyer chooses it', implode(' | ', $r['errors']));
$t = ManagedSiteDraft::price_table($held_site, $managed, null);
check($t['lines'][1]['label'] === 'Business licence' && $t['today'] === '$12.99', 'it costs nothing more');
check(BusinessLicense::site_line($held_site) === BusinessLicense::label(BusinessLicense::kind_of_tag((string)$spare->get('own_tag'))) . ', one you already own'
	&& stripos(BusinessLicense::site_line($held_site), 'no longer held') === false,
	'and the summary names it, before payment ties it to the site', BusinessLicense::site_line($held_site));
$req_h = new ManagedSiteRequirement();
$req_h->validate(array('managed_site' => (int)$held_site->key), $managed);
list($data_h) = $req_h->process(array('managed_site' => (int)$held_site->key), $managed, null, null);
check($req_h->extra_cart_lines($data_h, $managed) === array(), 'and brings no licence line');
check($provider->checkAvailability($managed, 1, 1, $data_h) === null, 'the held licence passes the charge check');
$r = ManagedSiteDraft::save_and_freeze(null, blc_post('blc-same-' . $run . '.com',
	array('licence_use' => 'business', 'licence_choice' => $held_choice)), $buyer);
$same = $r['row'];
harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $same ? $same->key : 0);
$req_s = new ManagedSiteRequirement();
$req_s->validate(array('managed_site' => (int)$same->key), $managed);
list($data_s) = $req_s->process(array('managed_site' => (int)$same->key), $managed, null, null);
$cart = ShoppingCart::current();
$cart->items = array(
	array(1, $managed, $data_h + array('product_version' => (int)$managed_version->key), 12.99, 0, $managed_version),
	array(1, $managed, $data_s + array('product_version' => (int)$managed_version->key), 12.99, 0, $managed_version),
);
$cart->persist();
check($provider->checkAvailability($managed, 1, 1, $data_h) !== null && $provider->checkAvailability($managed, 1, 1, $data_s) !== null,
	'two sites in one cart naming the same held licence are refused (B41)');
$cart->items = array();
$cart->persist();
$same->soft_delete();
$o3 = blc_order($buyer);
$held_item = blc_line($buyer, $o3, $managed, $managed_version, $data_h);
BlcFulfillment::$alerts = array();
$provider->fulfill($buyer, $managed, $held_item, $o3, 1);
$held_site = new CustomerCloudProvision((int)$held_site->key, TRUE);
check((int)$held_site->get('cvp_own_ownership_id') === (int)$spare->key && BlcFulfillment::$alerts === array()
	&& BusinessLicense::site_line($held_site) === 'Standard business licence',
	'activation names the held licence on the site', json_encode(BlcFulfillment::$alerts));

$r = ManagedSiteDraft::save_and_freeze(null, blc_post('blc-twice-' . $run . '.com', array('licence_use' => 'business', 'licence_choice' => $held_choice)), $buyer);
check($r['row'] === null && in_array('That licence is already in use by another site. Choose another.', $r['errors'], true),
	'a second site cannot choose the same licence');

// ---------------------------------------------------------------------------
section('A licence revoked reads as no longer held (B42)');

$bought->set('own_revoked_time', gmdate('Y-m-d H:i:s'));
$bought->save();
check(BusinessLicense::for_site($site) === null && BusinessLicense::site_line($site) === 'Business licence no longer held',
	'a refunded licence is not shown as on its way', BusinessLicense::site_line($site));
$bought->set('own_revoked_time', null);
$bought->save();

// ---------------------------------------------------------------------------
section('A product\'s buyers keep owning it when its ownership changes (B43)');

$retag = blc_product('Old licence [blc]', 'blc-retag-' . $run);
$retag->set('pro_ownership_tag', Ownership::tag_for_product($retag->key));
$retag->save();
$retag_version = blc_version($retag, '10.00', 'single');
$o_rt = blc_order($other);
$rt = Ownership::record_purchase($retag, $retag_version, $other, blc_line($other, $o_rt, $retag, $retag_version, array()), $o_rt);
$grant = new Ownership(NULL);
$grant->set('own_usr_user_id', $other->key);
$grant->set('own_tag', Ownership::tag_for_product($retag->key));
$grant->save();
check(Ownership::retag_purchases_of_product($retag->key, Ownership::tag_for_product($retag->key),
	Ownership::tag_each_for_product($retag->key)) === 2
	&& (string)(new Ownership($rt->key, TRUE))->get('own_tag') === Ownership::tag_each_for_product($retag->key)
	&& (string)(new Ownership($grant->key, TRUE))->get('own_tag') === Ownership::tag_each_for_product($retag->key),
	'from its own tag, its purchases and its manual grants all move (B45)');
$shared = 'blc-shared-' . $run;
$sp = new Ownership(NULL);
$sp->set('own_usr_user_id', $other->key);
$sp->set('own_tag', $shared);
$sp->save();
$sp_bought = Ownership::record_purchase($retag, $retag_version, $other, blc_line($other, $o_rt, $retag, $retag_version, array()), $o_rt);
$sp_bought->set('own_tag', $shared);
$sp_bought->save();
check(Ownership::retag_purchases_of_product($retag->key, $shared, 'blc-new-' . $run) === 1
	&& (string)(new Ownership($sp->key, TRUE))->get('own_tag') === $shared,
	'from a shared tag only its purchases move; a grant of the shared tag stays');

// ---------------------------------------------------------------------------
section('A site removed frees its licence');

$held_site->soft_delete();
$site->soft_delete();
$freed = array_map(function ($o) { return (int)$o->key; }, BusinessLicense::unused_by((int)$buyer->key));
sort($freed);
$expect = array((int)$bought->key, (int)$spare->key);
sort($expect);
check($freed === $expect, 'both licences are free again', json_encode($freed));

harness_finish();
