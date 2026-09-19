<?php
/** @joinery-test
 * name: managed_site_purchase
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Managed hosting, phase 1: the buyer configures a site, pays once, and the
 * payment activates it (specs/managed_hosting_phase1_purchase.md).
 *
 * Everything between the configure page and the pipeline, without a payment
 * provider, a registrar or a cloud in the loop:
 *
 *  - **A draft holds nothing.** It is frozen for the cart with a quote and a
 *    sealed registrant, and it reserves no name, no slug, no instance.
 *  - **The cart carries one answer.** ManagedSiteRequirement refuses anything
 *    but the signed-in buyer's own frozen draft, stores its id, and adds the
 *    domain-year line from the frozen quote — deterministically.
 *  - **A stale draft is refused before the charge.** Edit after Continue
 *    returns the row to draft; checkAvailability() then answers with the
 *    sentence, while declining is free.
 *  - **Payment activates.** fulfill() turns pending_payment into ready with
 *    the paid order item, the hosting mode from the product, and the
 *    registration row from the sealed registrant and frozen quote. Twice is
 *    once. A refusal is alerted, never silent.
 *  - **The sweep** expires an old draft and thaws an old frozen one.
 *  - **The taken name** offers an alternate on the sites page, at the paid
 *    price or less, and returns the row to the queue.
 *
 * Run: php plugins/server_manager/tests/managed_site_purchase_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/store/includes/requirements/AbstractProductRequirement.php'));
require_once(PathHelper::getIncludePath('plugins/store/includes/FulfillmentRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/fulfillment_providers/CustomerCloudFulfillment.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/requirements/ManagedSiteRequirement.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ManagedSiteDraft.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ManagedDomainIntake.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/SweepSiteDrafts.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provisions_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/registered_domains_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/logic/profile_sites_logic.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/product_versions_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_item_requirements_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));

// ---------------------------------------------------------------------------
// Doubles: a scripted registrar, and a provider whose alert edge is captured.
// ---------------------------------------------------------------------------

class MspStubRegistrar implements DomainRegistrarProvider {
	public static $available = true;
	public static $price = '12.34';
	public static function getKey(): string { return 'mspstub'; }
	public static function getLabel(): string { return 'Stub Registrar'; }
	public static function isConfigured(): bool { return true; }
	public function checkAvailability(array $domains): array {
		$out = array();
		foreach ($domains as $d) {
			$out[strtolower($d)] = array('available' => self::$available,
				'price_year' => self::$available ? self::$price : null, 'premium' => false,
				'message' => self::$available ? '' : 'That name is already taken.');
		}
		return $out;
	}
	public function register(string $domain, array $registrant, int $years): array {
		return array('expiry' => gmdate('Y-m-d H:i:s', strtotime('+1 year')));
	}
	public function applyWhoisPrivacy(string $domain): void {}
	public function normalizeRegistrantPhone(string $phone): string {
		return preg_match('/^\+/', trim($phone)) ? trim($phone) : '';
	}
	public function dnsDriverKey(): string { return 'namecheap'; }
	public function dnsCredential(): array { return array(); }
	public function getExpiry(string $domain): ?string { return null; }
	public function inAccount(string $domain): bool { return true; }
	public function graduationMechanism(): string { return 'account_push'; }
}

class MspFulfillment extends CustomerCloudFulfillment {
	public static $alerts = array();
	protected function alert_activation_problem(User $user, OrderItem $order_item, string $reason): void {
		self::$alerts[] = array('order_item' => (int)$order_item->key, 'reason' => $reason);
	}
}

DomainRegistrarRegistry::reset();
ManagedDomainIntake::pinRegistrar(new MspStubRegistrar());
harness_defer(function () { DomainRegistrarRegistry::reset(); ManagedDomainIntake::pinRegistrar(null); });

// ---------------------------------------------------------------------------
// Fixtures: the Managed product, the domain-year product, a buyer, a stranger.
// ---------------------------------------------------------------------------

$run = substr(md5(uniqid('msp', true)), 0, 6);

function msp_product(string $name, string $link, array $fields = array()) {
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
function msp_version($product, string $name, string $price, string $type) {
	$version = new ProductVersion(NULL);
	$version->set('prv_pro_product_id', $product->key);
	$version->set('prv_version_name', $name);
	$version->set('prv_version_price', $price);
	$version->set('prv_price_type', $type);
	$version->set('prv_status', 1);
	$version->save();
	$version->load();
	harness_register_row('prv_product_versions', 'prv_product_version_id', $version->key);
	return $version;
}

$managed = msp_product('Managed hosting [test]', 'msp-managed-' . $run, array(
	'pro_fulfillment_provider' => 'customer_cloud', 'pro_fulfillment_ref' => 1, 'pro_max_cart_count' => 1));
$managed_version = msp_version($managed, 'Hosting', '12.99', 'month');
$domain_product = msp_product('Domain registration (1 year) [test]', 'msp-domain-' . $run);
$domain_version = msp_version($domain_product, 'One year', '0.00', 'user');

harness_set_setting_mem('store_domain_registration_product_id', (string)$domain_product->key);
harness_set_setting_mem('server_manager_domain_tlds', 'com net org');
harness_set_setting_mem('server_manager_hosted_regions', '');
harness_set_setting_mem('server_manager_customer_cloud_region', 'us-southeast');
harness_set_setting_mem('server_manager_draft_days', '14');

$buyer = make_user('MspBuyer' . $run);
$stranger = make_user('MspStranger' . $run);
$_SESSION['loggedin'] = 1;
$_SESSION['usr_user_id'] = (int)$buyer->key;
$_SESSION['permission'] = 0;

function msp_post(array $overrides = array()): array {
	return array_merge(array(
		'cvp_domain_source' => 'register',
		'cvp_domain'        => 'Msp-Register-Test.COM',
		'cvp_sitename'      => '',
		'cvp_buyer_email'   => 'admin@example.com',
		'md_first_name'           => 'Jane',
		'md_last_name'            => 'Smith',
		'usa_cco_country_code_id' => (string)ManagedDomainIntake::countryIdFor('US'),
		'usa_address1'            => '14 Elm Street',
		'usa_address2'            => '',
		'usa_city'                => 'Springfield',
		'usa_state'               => 'IL',
		'usa_zip_code_id'         => '62704',
		'phn_cco_country_code_id' => (string)ManagedDomainIntake::countryIdFor('US'),
		'phn_phone_number'        => '555 123 4567',
		'md_email'                => 'jane@example.com',
	), $overrides);
}
function msp_register_provision($row) {
	harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $row->key);
	return $row;
}
/** A paid order with one Managed line carrying the draft id, the way cart_charge stores it. */
function msp_paid_line($buyer, $product, $version, array $form_data): array {
	$order = new Order(NULL);
	$order->set('ord_usr_user_id', $buyer->key);
	$order->set('ord_status', Order::STATUS_PAID);
	$order->save();
	$order->load();
	harness_register_row('ord_orders', 'ord_order_id', $order->key);
	$item = new OrderItem(NULL);
	$item->set('odi_ord_order_id', $order->key);
	$item->set('odi_pro_product_id', $product->key);
	$item->set('odi_prv_product_version_id', $version->key);
	$item->set('odi_usr_user_id', $buyer->key);
	$item->set('odi_product_info', base64_encode(serialize($form_data)));
	$item->set('odi_price', $version->get('prv_version_price'));
	$item->set('odi_status', OrderItem::STATUS_PAID);
	$item->save();
	$item->load();
	harness_register_row('odi_order_items', 'odi_order_item_id', $item->key);
	return array($order, $item);
}

// ---------------------------------------------------------------------------
section('Configure: a draft is validated in full and frozen, holding nothing');

$r = ManagedSiteDraft::save_and_freeze(null, msp_post(array('cvp_domain_source' => 'maybe')), $buyer);
check(count($r['errors']) === 1 && $r['row'] === null, 'a submission has to say whether we register the name');

MspStubRegistrar::$available = false;
$r = ManagedSiteDraft::save_and_freeze(null, msp_post(), $buyer);
check($r['row'] === null && stripos(implode(' ', $r['errors']), 'taken') !== false,
	'a taken name is refused at configure time, before anything is written', implode(' | ', $r['errors']));
MspStubRegistrar::$available = true;

$r = ManagedSiteDraft::save_and_freeze(null, msp_post(array('cvp_sitename' => '9bad name')), $buyer);
check($r['row'] === null && stripos(implode(' ', $r['errors']), 'site name') !== false,
	'a site name that cannot be a folder and database name is refused');
$r = ManagedSiteDraft::save_and_freeze(null, msp_post(array('cvp_domain' => str_repeat('a', 47) . '.com')), $buyer);
check($r['row'] === null && stripos(implode(' ', $r['errors']), 'too long') !== false,
	'a domain whose slug would not fit the slug column is refused with a sentence, not a crash',
	implode(' | ', $r['errors']));
$r = ManagedSiteDraft::save_and_freeze(null, msp_post(array('cvp_buyer_email' => 'nope')), $buyer);
check($r['row'] === null && stripos(implode(' ', $r['errors']), 'email') !== false, 'a bad admin email is refused');
$r = ManagedSiteDraft::save_and_freeze(null, msp_post(array('phn_cco_country_code_id' => '')), $buyer);
check($r['row'] === null && stripos(implode(' ', $r['errors']), 'country code') !== false,
	'the registrant block is gated the same way the checkout field was');

$r = ManagedSiteDraft::save_and_freeze(null, msp_post(), $buyer);
check($r['errors'] === array() && $r['row'] !== null, 'a good submission is saved', implode(' | ', $r['errors']));
$draft = msp_register_provision($r['row']);
check($draft->get('cvp_status') === 'pending_payment', 'and frozen for the cart in the same step');
check($draft->get('cvp_origin') === 'buyer', 'it is buyer-origin');
check($draft->get('cvp_domain') === 'msp-register-test.com', 'the domain is normalized');
check($draft->get('cvp_slug') === 'msp-register-test-com', 'the slug follows the pipeline\'s rule');
check($draft->get('cvp_sitename') === 'msp_register_test', 'a blank site name defaults to the domain\'s first label, made safe');
check($draft->get('cvp_region') === 'us-southeast', 'one region in the list means the default, unasked');
check($draft->get('cvp_domain_source') === 'register' && (string)$draft->get('cvp_domain_quote') === '12.34',
	'the registrar\'s quote is frozen on the row', 'quote: ' . var_export($draft->get('cvp_domain_quote'), true));
check(trim((string)$draft->get('cvp_domain_quote_time')) !== '', 'with the time it was frozen');
$opened = $draft->open_registrant();
check(is_array($opened) && ($opened['first_name'] ?? '') === 'Jane' && ($opened['country'] ?? '') === 'US',
	'the registrant block is sealed on the row and reads back');
check($draft->get('cvp_external_order_item_id') === null, 'no order item: nothing has been paid for');
check($draft->get('cvp_hosting_mode') === 'customer', 'the hosting mode is not decided until activation');
check(new MultiRegisteredDomain(array('domain' => 'msp-register-test.com')) !== null
	&& count(new MultiRegisteredDomain(array('domain' => 'msp-register-test.com'))) === 0,
	'no registration row exists — a draft reserves no name');
check($draft->is_pre_payment(), 'the row knows it is before payment');

$r = ManagedSiteDraft::save_and_freeze(null, msp_post(array('cvp_domain_source' => 'own', 'cvp_domain' => 'Msp-Own-Test.rocks',
	'phn_phone_number' => '')), $buyer);
check($r['errors'] === array() && $r['row'] !== null, 'an owned domain needs no registrant block and no offered ending',
	implode(' | ', $r['errors']));
$own = msp_register_provision($r['row']);
check($own->get('cvp_domain_quote') === null && trim((string)$own->get('cvp_registrant_sealed')) === '',
	'and carries no quote and no registrant');

$values = ManagedSiteDraft::form_values($draft, $buyer);
check($values['cvp_domain'] === 'msp-register-test.com' && $values['usa_city'] === 'Springfield',
	'the form prefills from the row, registrant included');

// ---------------------------------------------------------------------------
section('Two buyers may draft the same name; a name a LIVE provision holds is refused');

$_SESSION['usr_user_id'] = (int)$stranger->key;
$r = ManagedSiteDraft::save_and_freeze(null, msp_post(), $stranger);
check($r['errors'] === array() && $r['row'] !== null, 'a second buyer may draft the same name — a draft holds nothing');
$rival = msp_register_provision($r['row']);
$_SESSION['usr_user_id'] = (int)$buyer->key;

$live = new CustomerCloudProvision(NULL);
$live->set('cvp_origin', 'admin');
$live->set('cvp_usr_user_id', 990000 + random_int(0, 9999));
$live->set('cvp_domain', 'msp-held-test.com');
$live->set('cvp_slug', 'msp-held-test-com');
$live->set('cvp_status', 'installing');
$live->save();
$live->load();
msp_register_provision($live);
$r = ManagedSiteDraft::save_and_freeze(null, msp_post(array('cvp_domain' => 'msp-held-test.com')), $buyer);
check($r['row'] === null && stripos(implode(' ', $r['errors']), 'already has a site') !== false,
	'a name a live provision holds is refused at Continue', implode(' | ', $r['errors']));
check(CustomerCloudProvision::held_by_live('msp-register-test.com', 'msp-register-test-com') === null,
	'and a name held only by drafts is not held');

// ---------------------------------------------------------------------------
section('The origin rule: a buyer row leaves the pre-payment states only with an order item');

$refused = null;
try {
	$bad = new CustomerCloudProvision(NULL);
	$bad->set('cvp_origin', 'buyer');
	$bad->set('cvp_usr_user_id', $buyer->key);
	$bad->set('cvp_domain', 'msp-bad.com');
	$bad->set('cvp_slug', 'msp-bad-com');
	$bad->set('cvp_status', 'ready');
	$bad->save();
	msp_register_provision($bad);
} catch (CustomerCloudProvisionException $e) { $refused = $e->getMessage(); }
check($refused !== null, 'a buyer row at ready with no order item is refused', (string)$refused);
$refused = null;
try {
	$bad = new CustomerCloudProvision(NULL);
	$bad->set('cvp_origin', 'admin');
	$bad->set('cvp_usr_user_id', $buyer->key);
	$bad->set('cvp_domain', 'msp-bad2.com');
	$bad->set('cvp_slug', 'msp-bad2-com');
	$bad->set('cvp_status', 'draft');
	$bad->save();
	msp_register_provision($bad);
} catch (CustomerCloudProvisionException $e) { $refused = $e->getMessage(); }
check($refused !== null, 'and only a buyer row may be a draft', (string)$refused);

// ---------------------------------------------------------------------------
section('The cart carries one answer: the buyer\'s own frozen draft');

$provider = new MspFulfillment();
$reqs = $provider->extraRequirements($managed, 1);
check(count($reqs) === 1 && $reqs[0] instanceof ManagedSiteRequirement,
	'the fulfilment provider contributes ManagedSiteRequirement and nothing else');
check(!AbstractProductRequirement::has('ManagedSiteRequirement'),
	'it is not in the registry, so it never appears in the product-edit picker');

$req = new ManagedSiteRequirement();
check(count($req->validate(array(), $managed)) === 1, 'no draft id: refused');
check(count($req->validate(array('managed_site' => (int)$rival->key), $managed)) === 1,
	'another buyer\'s draft: refused');
check(count($req->validate(array('managed_site' => (int)$live->key), $managed)) === 1,
	'a row that is not a frozen draft: refused');
$_SESSION['loggedin'] = 0;
check(count($req->validate(array('managed_site' => (int)$draft->key), $managed)) === 1, 'a guest: refused');
$_SESSION['loggedin'] = 1;
check($req->validate(array('managed_site' => (int)$draft->key), $managed) === array(),
	'the buyer\'s own frozen draft: accepted');

list($data, $display) = $req->process(array('managed_site' => (int)$draft->key), $managed, null, null);
check(($data['managed_site']['answer'] ?? '') === (string)$draft->key, 'the draft id is stored in question/answer shape');
check(($data['managed_site_summary']['question'] ?? '') === 'Site'
	&& strpos((string)($data['managed_site_summary']['answer'] ?? ''), 'msp-register-test.com') === 0,
	'the site itself travels as a labelled answer, so the cart and the order show the domain');
check(is_array($data['managed_site_frozen'] ?? null) && ($data['managed_site_frozen']['question'] ?? '') !== ''
	&& ManagedSiteRequirement::frozen_from($data) === (string)$draft->get('cvp_update_time'),
	'the freeze is labelled too, and read back through frozen_from()');
foreach ($data as $k => $v) {
	check(is_array($v) && isset($v['question'], $v['answer']) || $k === 'managed_domain_price_line',
		'every stored key is question/answer-shaped (' . $k . ')');
}
check(($data['managed_domain_price_line'] ?? '') === '12.34', 'the frozen quote travels under the key the registration guard reads');
check(($data['managed_domain']['answer'] ?? '') === 'msp-register-test.com', 'and so does the domain');
check(isset($display['Site']) && strpos($display['Site'], 'msp-register-test.com') !== false,
	'the buyer sees the domain, not the draft id', var_export($display, true));

$lines = $req->extra_cart_lines($data, $managed);
check(count($lines) === 1 && (int)$lines[0]['product_id'] === (int)$domain_product->key,
	'exactly one companion line, the domain-year product');
check(($lines[0]['form_data']['user_price_override'] ?? '') === '12.34', 'priced at the frozen quote');
MspStubRegistrar::$price = '99.00';
check($req->extra_cart_lines($data, $managed) === $lines, 'and never re-quoted: identical data, identical line');
MspStubRegistrar::$price = '12.34';
$repriced = $domain_product->get_price($domain_version, $lines[0]['form_data']);
check((string)$repriced === '12.34', 'a prv_price_type=user version prices the line from its stored form data');

$req_own = new ManagedSiteRequirement();
$req_own->validate(array('managed_site' => (int)$own->key), $managed);
list($data_own) = $req_own->process(array('managed_site' => (int)$own->key), $managed, null, null);
check(!isset($data_own['managed_domain_price_line']) && $req_own->extra_cart_lines($data_own, $managed) === array(),
	'an owned domain contributes no domain line');

// ---------------------------------------------------------------------------
section('The stale draft is refused before the charge');

check($provider->checkAvailability($managed, 1, 1, $data) === null, 'a frozen draft passes the pre-charge check');
ManagedSiteDraft::unfreeze($draft);
$draft->load();
check($draft->get('cvp_status') === 'draft', 'Edit returns the row to draft');
check($provider->checkAvailability($managed, 1, 1, $data) === CustomerCloudFulfillment::STALE_DRAFT_SENTENCE,
	'and the charge is refused with the sentence');
check($provider->checkAvailability($managed, 1, 1, array()) !== null, 'a line with no draft at all is refused too');
sleep(1);   // the freeze time is whole seconds; a re-freeze in the same second would look like the first
$r = ManagedSiteDraft::save_and_freeze($draft, msp_post(), $buyer);
check($r['errors'] === array(), 're-freezing after Edit works', implode(' | ', $r['errors']));
$draft->load();
check($provider->checkAvailability($managed, 1, 1, $data) === CustomerCloudFulfillment::STALE_DRAFT_SENTENCE,
	'a line built from the EARLIER freeze is still stale — same id, different freeze');
$req2 = new ManagedSiteRequirement();
$req2->validate(array('managed_site' => (int)$draft->key), $managed);
list($data) = $req2->process(array('managed_site' => (int)$draft->key), $managed, null, null);
$_SESSION['usr_user_id'] = (int)$stranger->key;
check($provider->checkAvailability($managed, 1, 1, $data) !== null, 'a frozen draft that is not the session user\'s is refused');
$_SESSION['usr_user_id'] = (int)$buyer->key;
check($provider->checkAvailability($managed, 1, 1, $data) === null, 'a line built from the current freeze passes');

// The store asks through one helper, with a real cart line, wherever a
// payment step is about to start.
FulfillmentRegistry::register($provider);
$r = ManagedSiteDraft::save_and_freeze(null, msp_post(array('cvp_domain' => 'msp-cart-test.com')), $buyer);
$carted = msp_register_provision($r['row']);
$req_c = new ManagedSiteRequirement();
$req_c->validate(array('managed_site' => (int)$carted->key), $managed);
list($data_c) = $req_c->process(array('managed_site' => (int)$carted->key), $managed, null, null);
// Lines are hand-built the way the store's own suites do it: add_item() wants
// a Stripe price behind every subscription version, and no gateway is here.
$cart = ShoppingCart::current();
$cart->items[] = array(1, $managed, $data_c + array('product_version' => (int)$managed_version->key), 12.99, 0, $managed_version);
$cart->persist();
check(FulfillmentRegistry::cartRefusal($cart) === null, 'the cart helper passes a cart whose line is current');
ManagedSiteDraft::unfreeze($carted);
$cart = ShoppingCart::current();
check(count($cart->items) === 0, 'Edit drops the line from the session cart');
$cart->items[] = array(1, $managed, $data_c + array('product_version' => (int)$managed_version->key), 12.99, 0, $managed_version);
check(FulfillmentRegistry::cartRefusal($cart) === CustomerCloudFulfillment::STALE_DRAFT_SENTENCE,
	'and a stale line put back is refused by the cart helper with the sentence');
$cart->items = array();
$cart->persist();

// ---------------------------------------------------------------------------
section('Payment activates: pending_payment -> ready, the domain row filed');

list($order, $item) = msp_paid_line($buyer, $managed, $managed_version, $data);
$item->save_cart_data($data);
$oir = new MultiOrderItemRequirement(array('order_item_id' => $item->key));
foreach ($oir as $row) { harness_register_row('oir_order_item_requirements', 'oir_order_item_requirement_id', $row->key); }

MspFulfillment::$alerts = array();
$f1 = $provider->fulfill($buyer, $managed, $item, $order, 1);
check((int)($f1['ref_id'] ?? 0) === (int)$draft->key, 'fulfill activates the draft the line names', var_export($f1, true));
check(($f1['label'] ?? '') === 'Server for msp-register-test.com', 'the order line says which server');
$draft->load();
check($draft->get('cvp_status') === 'ready', 'the row is ready for the pipeline');
check((int)$draft->get('cvp_external_order_item_id') === (int)$item->key, 'with the paid order item stamped');
check($draft->get('cvp_hosting_mode') === 'operator', 'the hosting mode comes from the product\'s reference');
check($draft->get('cvp_mail_state') === 'pending', 'the hosted mail leg is owed from birth');
check(trim((string)$draft->get('cvp_buyer_name')) !== '', 'the buyer\'s name rides the row');
check(MspFulfillment::$alerts === array(), 'no alert on a clean activation');

$filed = new MultiRegisteredDomain(array('external_order_item_id' => (int)$item->key, 'deleted' => false));
$rdm = null;
foreach ($filed as $row) { $rdm = $row; }
check($rdm !== null, 'a registration row is filed for the paid line');
if ($rdm) {
	harness_register_row('rdm_registered_domains', 'rdm_registered_domain_id', $rdm->key);
	check($rdm->get('rdm_domain') === 'msp-register-test.com', 'it carries the domain');
	check((int)$rdm->get('rdm_usr_user_id') === (int)$buyer->key, 'and the buyer');
	check($rdm->get('rdm_status') === RegisteredDomain::STATUS_PENDING, 'it starts pending — nothing is bought until the pipeline runs');
	check((string)$rdm->get('rdm_price_paid') === '12.34', 'the frozen quote is the recorded price');
	$stored = $rdm->open_registrant();
	check(is_array($stored) && ($stored['first_name'] ?? '') === 'Jane' && ($stored['email'] ?? '') === 'jane@example.com',
		'the sealed registrant moved from the draft to the registration row');
	$draft->load();
	check(trim((string)$draft->get('cvp_registrant_sealed')) === '', 'and the draft no longer holds a copy');
	check(JobResultProcessor::plane_registered_domain($draft), 'the welcome email knows this plane registered the name');
}

// A live purchase names what it creates plainly; a test-mode purchase puts
// test_ in front of every external name, so a rehearsal's leftovers are
// told from a customer's in every provider's console.
check(!$draft->is_test_purchase() && $draft->external_name_prefix() === '',
	'a site bought with a live payment names its instance, node and mail account plainly');
$order->set('ord_test_mode', true);
$order->save();
$draft_again = new CustomerCloudProvision($draft->key, TRUE);
check($draft_again->is_test_purchase() && $draft_again->external_name_prefix() === 'test_',
	'a site bought with a test-mode payment names them with test_ in front');
check(strpos(Smtp2GoClient::mintUsername('msp-register-test-com', $draft_again->external_name_prefix()), 'test_mspregistertestcom-') === 0,
	'the SMTP username carries the prefix as given');
$order->set('ord_test_mode', false);
$order->save();

$f2 = $provider->fulfill($buyer, $managed, $item, $order, 1);
check((int)($f2['ref_id'] ?? 0) === (int)$draft->key, 'a second fulfil for the same line returns the same row');
$dupes = new MultiCustomerCloudProvision(array('external_order_item_id' => (int)$item->key, 'deleted' => false));
check((int)$dupes->count_all() === 1, 'and creates nothing');
$filed2 = new MultiRegisteredDomain(array('external_order_item_id' => (int)$item->key, 'deleted' => false));
check(count($filed2) === 1, 'nor a second registration row');

// An owned domain: activated, no registration row, the welcome email will
// carry the A record.
list($order_own, $item_own) = msp_paid_line($buyer, $managed, $managed_version, $data_own);
$f3 = $provider->fulfill($buyer, $managed, $item_own, $order_own, 1);
$own->load();
check((int)($f3['ref_id'] ?? 0) === (int)$own->key && $own->get('cvp_status') === 'ready', 'an owned-domain draft activates too');
$none = new MultiRegisteredDomain(array('external_order_item_id' => (int)$item_own->key, 'deleted' => false));
check(count($none) === 0, 'with no registration row');
check(!JobResultProcessor::plane_registered_domain($own), 'so the welcome email carries the A-record instruction');

// ---------------------------------------------------------------------------
section('A refusal at fulfil is alerted with the order item, never silent');

$r = ManagedSiteDraft::save_and_freeze(null, msp_post(array('cvp_domain' => 'msp-refused-test.com')), $buyer);
$edited = msp_register_provision($r['row']);
$req3 = new ManagedSiteRequirement();
$req3->validate(array('managed_site' => (int)$edited->key), $managed);
list($data3) = $req3->process(array('managed_site' => (int)$edited->key), $managed, null, null);
list($order3, $item3) = msp_paid_line($buyer, $managed, $managed_version, $data3);
ManagedSiteDraft::unfreeze($edited);   // the buyer edited after the line was made
MspFulfillment::$alerts = array();
$f4 = $provider->fulfill($buyer, $managed, $item3, $order3, 1);
check(($f4['ref_id'] ?? null) === null && stripos((string)$f4['label'], 'attention') !== false,
	'a draft that moved is not activated', var_export($f4, true));
check(count(MspFulfillment::$alerts) === 1 && MspFulfillment::$alerts[0]['order_item'] === (int)$item3->key
	&& stripos(MspFulfillment::$alerts[0]['reason'], 'pending_payment') !== false,
	'and the operator is told which order item, and why', var_export(MspFulfillment::$alerts, true));
$edited->load();
check($edited->get('cvp_status') === 'draft' && $edited->get('cvp_external_order_item_id') === null,
	'the draft itself is untouched — a person decides');

// The slug rule at activation: a live provision took the name meanwhile.
$_SESSION['usr_user_id'] = (int)$stranger->key;
$req4 = new ManagedSiteRequirement();
$req4->validate(array('managed_site' => (int)$rival->key), $managed);
list($data4) = $req4->process(array('managed_site' => (int)$rival->key), $managed, null, null);
list($order4, $item4) = msp_paid_line($stranger, $managed, $managed_version, $data4);
MspFulfillment::$alerts = array();
$f5 = $provider->fulfill($stranger, $managed, $item4, $order4, 1);
$rival->load();
check(($f5['ref_id'] ?? null) === null, 'the second buyer to pay for the same name is not activated');
check($rival->get('cvp_status') === 'failed' && (int)$rival->get('cvp_external_order_item_id') === (int)$item4->key,
	'the row fails closed with the paid order item on it, so it is on the operator\'s board',
	$rival->get('cvp_status') . ' / ' . var_export($rival->get('cvp_external_order_item_id'), true));
check(count(MspFulfillment::$alerts) === 1 && stripos(MspFulfillment::$alerts[0]['reason'], 'already held') !== false,
	'and the alert names the clash', var_export(MspFulfillment::$alerts, true));
$_SESSION['usr_user_id'] = (int)$buyer->key;

// ---------------------------------------------------------------------------
section('The sweep: an old draft goes, an old frozen draft thaws, fresh ones stay');

$db = DbConnector::get_instance()->get_db_link();
$r = ManagedSiteDraft::save_and_freeze(null, msp_post(array('cvp_domain' => 'msp-old-draft.com')), $buyer);
$old_draft = msp_register_provision($r['row']);
ManagedSiteDraft::unfreeze($old_draft);
$r = ManagedSiteDraft::save_and_freeze(null, msp_post(array('cvp_domain' => 'msp-old-frozen.com')), $buyer);
$old_frozen = msp_register_provision($r['row']);
$r = ManagedSiteDraft::save_and_freeze(null, msp_post(array('cvp_domain' => 'msp-fresh-frozen.com')), $buyer);
$fresh = msp_register_provision($r['row']);
$backdate = gmdate('Y-m-d H:i:s', time() - 15 * 86400);
$q = $db->prepare('UPDATE cvp_customer_cloud_provisions SET cvp_update_time = ? WHERE cvp_customer_cloud_provision_id IN (?, ?)');
$q->execute(array($backdate, (int)$old_draft->key, (int)$old_frozen->key));

$sweep = (new SweepSiteDrafts())->run(array());
check(($sweep['status'] ?? '') === 'success', 'the sweep ran', var_export($sweep, true));
$old_draft->load();
$old_frozen->load();
$fresh->load();
check(trim((string)$old_draft->get('cvp_delete_time')) !== '', 'a draft older than the setting is deleted');
check($old_frozen->get('cvp_status') === 'draft' && trim((string)$old_frozen->get('cvp_delete_time')) === '',
	'a frozen draft older than the setting returns to draft — its quote is stale');
check($fresh->get('cvp_status') === 'pending_payment', 'a fresh frozen draft is untouched');
$draft->load();
check($draft->get('cvp_status') === 'ready', 'and an activated row is never seen by the sweep');
$sweep2 = (new SweepSiteDrafts())->run(array());
check(($sweep2['status'] ?? '') === 'skipped', 'a second pass finds nothing to do');

// ---------------------------------------------------------------------------
section('The taken name: an alternate at the paid price, back into the queue');

if ($rdm) {
	$rdm->set('rdm_taken_time', gmdate('Y-m-d H:i:s'));
	$rdm->fail('The registrar reports msp-register-test.com is no longer available.');
	$rdm->load();
	check($rdm->offers_alternate(), 'a row the registrar found taken offers an alternate');

	$fresh_fail = new RegisteredDomain(NULL);
	$fresh_fail->set('rdm_domain', 'msp-other-failure.com');
	$fresh_fail->set('rdm_usr_user_id', $buyer->key);
	$fresh_fail->set('rdm_price_paid', '12.34');
	$fresh_fail->set('rdm_status', RegisteredDomain::STATUS_FAILED);
	$fresh_fail->set('rdm_error', 'The order contains no paid domain-registration line.');
	$fresh_fail->save();
	$fresh_fail->load();
	harness_register_row('rdm_registered_domains', 'rdm_registered_domain_id', $fresh_fail->key);
	check(!$fresh_fail->offers_alternate(), 'a row that failed for any other reason does not');

	$m = profile_sites_take_alternate(array('rdm_registered_domain_id' => $rdm->key, 'alternate_domain' => 'bad name'), (int)$buyer->key);
	check(!$m['ok'], 'a malformed alternate is refused');
	$m = profile_sites_take_alternate(array('rdm_registered_domain_id' => $rdm->key, 'alternate_domain' => 'msp-alt.com'), (int)$stranger->key);
	check(!$m['ok'], 'somebody else\'s row is not theirs to rename');
	$m = profile_sites_take_alternate(array('rdm_registered_domain_id' => $fresh_fail->key, 'alternate_domain' => 'msp-alt.com'), (int)$buyer->key);
	check(!$m['ok'], 'a row not waiting for a name is refused');

	MspStubRegistrar::$price = '20.00';
	$m = profile_sites_take_alternate(array('rdm_registered_domain_id' => $rdm->key, 'alternate_domain' => 'msp-dearer.com'), (int)$buyer->key);
	check(!$m['ok'] && stripos($m['text'], 'more than') !== false, 'a name dearer than the paid amount is refused, and says so', $m['text']);
	MspStubRegistrar::$price = '12.34';

	MspStubRegistrar::$available = false;
	$m = profile_sites_take_alternate(array('rdm_registered_domain_id' => $rdm->key, 'alternate_domain' => 'msp-alt.com'), (int)$buyer->key);
	check(!$m['ok'], 'a taken alternate is refused');
	MspStubRegistrar::$available = true;

	// The provision is at ready with no box yet: its own name follows.
	$m = profile_sites_take_alternate(array('rdm_registered_domain_id' => $rdm->key, 'alternate_domain' => 'Msp-Alt.COM'), (int)$buyer->key);
	check($m['ok'], 'an available alternate at the paid price is accepted', $m['text']);
	$rdm->load();
	check($rdm->get('rdm_domain') === 'msp-alt.com' && $rdm->get('rdm_status') === RegisteredDomain::STATUS_PENDING,
		'the row carries the new name and is back in the queue');
	check(trim((string)$rdm->get('rdm_taken_time')) === '' && trim((string)$rdm->get('rdm_error')) === '',
		'the taken stamp and the error are cleared');
	check((string)$rdm->get('rdm_price_paid') === '12.34', 'the paid amount is unchanged — the registration guard compares against it');
	$draft->load();
	check($draft->get('cvp_domain') === 'msp-alt.com' && $draft->get('cvp_slug') === 'msp-alt-com',
		'the provision\'s own domain and slug follow while no box exists');
}

harness_finish();
