<?php
/** @joinery-test
 * name: managed_domain_requirement
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The checkout half of managed domains: what the buyer is allowed to submit,
 * what price gets attached to it, and what is filed once they have paid.
 *
 * The registrar is a stub here on purpose — the point is not whether Namecheap
 * answers (that is namecheap_registrar_test) but whether the requirement can
 * be talked into selling something it should not:
 *
 *  - **Nothing price-shaped is trusted from the POST.** A buyer who posts
 *    their own user_price_override for the domain line must not be able to
 *    move the number; the line is constructed server-side from the quote the
 *    registrar gave.
 *  - **A half-configured deployment refuses the order.** No registrar, or no
 *    domain-year product, has to fail the submission — quietly dropping the
 *    domain would take the buyer's money for hosting and silently not buy the
 *    name they asked for.
 *  - **post_purchase() files exactly one row, ever.** It runs on a best-effort
 *    path that can be re-entered; two rows would mean two registrations and
 *    two charges at the registrar.
 *
 * Sections: the configuration gate; syntax and TLD; the contact block; the
 * quote; the receipt row; the companion cart line and its price; intake and
 * its idempotency; the sealed registrant.
 *
 * Run: php plugins/server_manager/tests/managed_domain_requirement_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/store/includes/requirements/AbstractProductRequirement.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/requirements/ManagedDomainRequirement.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/registered_domains_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/product_versions_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_item_requirements_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));

// ---------------------------------------------------------------------------
// A registrar that answers from a script, so no network is touched.
// ---------------------------------------------------------------------------

class MdrStubRegistrar implements DomainRegistrarProvider {
	public static $available = true;
	public static $price = '12.34';
	public static $throw = false;

	public static function getKey(): string { return 'mdrstub'; }
	public static function getLabel(): string { return 'Stub Registrar'; }
	public static function isConfigured(): bool { return true; }

	public function checkAvailability(array $domains): array {
		if (self::$throw) {
			throw DomainRegistrarException::transient('stub is offline');
		}
		$out = array();
		foreach ($domains as $d) {
			$out[strtolower($d)] = array(
				'available'  => self::$available,
				'price_year' => self::$available ? self::$price : null,
				'premium'    => false,
				'message'    => self::$available ? '' : 'That name is already taken.',
			);
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

DomainRegistrarRegistry::reset();
harness_defer(function () { DomainRegistrarRegistry::reset(); });
check(DomainRegistrarRegistry::get('mdrstub') !== null,
	'a registrar defined in this process is discovered by interface');

// ---------------------------------------------------------------------------
// Fixtures: the domain-year product, priced by whoever adds the line.
// ---------------------------------------------------------------------------

$domain_product = new Product(NULL);
$domain_product->set('pro_name', 'Domain registration (1 year) [test]');
$domain_product->set('pro_link', 'mdr-domain-year-test-' . getmypid());
$domain_product->set('pro_is_active', true);
$domain_product->save();
$domain_product->load();
harness_register_row('pro_products', 'pro_product_id', $domain_product->key);

$domain_version = new ProductVersion(NULL);
$domain_version->set('prv_pro_product_id', $domain_product->key);
$domain_version->set('prv_version_name', 'One year');
$domain_version->set('prv_version_price', '0.00');
$domain_version->set('prv_price_type', 'user');
$domain_version->set('prv_status', 1);
$domain_version->save();
$domain_version->load();
harness_register_row('prv_product_versions', 'prv_product_version_id', $domain_version->key);

$hosting_product = new Product(NULL);
$hosting_product->set('pro_name', 'Hosting [test]');
$hosting_product->set('pro_link', 'mdr-hosting-test-' . getmypid());
$hosting_product->set('pro_is_active', true);
$hosting_product->save();
$hosting_product->load();
harness_register_row('pro_products', 'pro_product_id', $hosting_product->key);

harness_set_setting_mem('store_domain_registration_product_id', (string)$domain_product->key);
harness_set_setting_mem('server_manager_domain_tlds', 'com net org');

$buyer = make_user('MdrBuyer');

/** A complete, valid submission. */
function mdr_post(array $overrides = array()): array {
	return array_merge(array(
		'managed_domain_name' => 'Smith-Family-Test.COM',
		'md_first_name'       => 'Jane',
		'md_last_name'        => 'Smith',
		'md_address1'         => '14 Elm Street',
		'md_city'             => 'Springfield',
		'md_state_province'   => 'IL',
		'md_postal_code'      => '62704',
		'md_country'          => 'us',
		'md_phone'            => '+1 555 123 4567',
		'md_email'            => 'jane@example.com',
	), $overrides);
}

// ---------------------------------------------------------------------------
section('A deployment that cannot register a domain refuses the order');

harness_set_setting_mem('store_domain_registration_product_id', '');
$req = new ManagedDomainRequirement();
$errors = $req->validate(mdr_post(), $hosting_product);
check(count($errors) === 1, 'no domain-year product configured: one clear error');
check(stripos($errors[0] ?? '', 'not available') !== false,
	'and it says the feature is unavailable rather than blaming the buyer',
	'got: ' . ($errors[0] ?? ''));
check($req->extra_cart_lines(array(), $hosting_product) === array(),
	'nothing is added to the cart when the requirement was not part of the submission');

// A setting pointing at a product that no longer loads is worse than an empty
// one: it passes a `> 0` test, the cart line is silently skipped, and the
// pipeline goes on to register a domain nobody was charged for.
harness_set_setting_mem('store_domain_registration_product_id', '99999999');
$req = new ManagedDomainRequirement();
check(!ManagedDomainRequirement::domainProductSellable(),
	'a domain-year product that does not load is not sellable');
$errors = $req->validate(mdr_post(), $hosting_product);
check(count($errors) === 1 && stripos($errors[0], 'not available') !== false,
	'and the submission is refused rather than selling an unpriced domain');

harness_set_setting_mem('store_domain_registration_product_id', (string)$domain_product->key);
check(ManagedDomainRequirement::domainProductSellable(),
	'the configured product with an active version is sellable');

// ---------------------------------------------------------------------------
section('The name has to be one we can actually register');

$bad_names = array(
	''                    => 'an empty name',
	'nodot'               => 'a name with no ending',
	'has space.com'       => 'a name with a space',
	'-lead.com'           => 'a name starting with a dash',
	'smithfamily.rocks'   => 'an ending we do not offer',
);
foreach ($bad_names as $name => $why) {
	$req = new ManagedDomainRequirement();
	$errors = $req->validate(mdr_post(array('managed_domain_name' => $name)), $hosting_product);
	check(count($errors) > 0, $why . ' is refused');
}

$req = new ManagedDomainRequirement();
$errors = $req->validate(mdr_post(array('managed_domain_name' => 'smithfamily.rocks')), $hosting_product);
check(stripos(implode(' ', $errors), '.com') !== false,
	'the unsupported-ending message names the endings that do work',
	'got: ' . implode(' | ', $errors));

// ---------------------------------------------------------------------------
section('The contact block is the WHOIS record, so it must be complete');

foreach (array('md_first_name', 'md_last_name', 'md_address1', 'md_city',
		'md_postal_code', 'md_phone', 'md_email') as $field) {
	$req = new ManagedDomainRequirement();
	$errors = $req->validate(mdr_post(array($field => '')), $hosting_product);
	check(count($errors) > 0, 'a missing ' . $field . ' is refused');
}

$req = new ManagedDomainRequirement();
$errors = $req->validate(mdr_post(array('md_email' => 'not-an-email')), $hosting_product);
check(count($errors) > 0, 'an unusable registration email is refused');

$req = new ManagedDomainRequirement();
$errors = $req->validate(mdr_post(array('md_phone' => '5551234567')), $hosting_product);
check(count($errors) > 0, 'a phone number with no country code is refused, not guessed at');
check(stripos(implode(' ', $errors), 'country code') !== false,
	'and the message tells the buyer exactly what to add');

// ---------------------------------------------------------------------------
section('The quote comes from the registrar, at submit time');

MdrStubRegistrar::$available = false;
$req = new ManagedDomainRequirement();
$errors = $req->validate(mdr_post(), $hosting_product);
check(count($errors) > 0, 'a taken name fails validation even if the browser said otherwise');

MdrStubRegistrar::$available = true;
MdrStubRegistrar::$throw = true;
$req = new ManagedDomainRequirement();
$errors = $req->validate(mdr_post(), $hosting_product);
check(count($errors) > 0, 'an unreachable registrar fails the submission rather than guessing');
check(stripos(implode(' ', $errors), 'try again') !== false,
	'and asks the buyer to retry instead of reporting a defect');
MdrStubRegistrar::$throw = false;

MdrStubRegistrar::$price = '12.34';
$req = new ManagedDomainRequirement();
$errors = $req->validate(mdr_post(), $hosting_product);
check($errors === array(), 'a good submission validates', implode(' | ', $errors));

list($data, $display) = $req->process(mdr_post(), $hosting_product, null, null);
check(($data['managed_domain']['answer'] ?? '') === 'smith-family-test.com',
	'the name is normalized to lowercase before anything is stored',
	'got: ' . var_export($data['managed_domain']['answer'] ?? null, true));
check(($data['managed_domain_price_line'] ?? '') === '12.34',
	'the server-derived quote travels with the form data');
check(($data['md_country'] ?? '') === 'US', 'the country is stored upper-case ISO-2');
check(isset($display['Domain']), 'the buyer sees the domain on the confirmation');

// ---------------------------------------------------------------------------
section('The answers survive as order-item rows');

$order = new Order(NULL);
$order->set('ord_usr_user_id', $buyer->key);
$order->save();
$order->load();
harness_register_row('ord_orders', 'ord_order_id', $order->key);

$order_item = new OrderItem(NULL);
$order_item->set('odi_ord_order_id', $order->key);
$order_item->set('odi_pro_product_id', $hosting_product->key);
$order_item->set('odi_usr_user_id', $buyer->key);
$order_item->save();
$order_item->load();
harness_register_row('odi_order_items', 'odi_order_item_id', $order_item->key);

$order_item->save_cart_data($data);
$rows = new MultiOrderItemRequirement(array('order_item_id' => $order_item->key));
$rows->load();
$labels = array();
foreach ($rows as $row) {
	$labels[$row->get('oir_label')] = $row->get('oir_answer');
	harness_register_row('oir_order_item_requirements', 'oir_order_item_requirement_id', $row->key);
}
check(($labels['Registered domain'] ?? '') === 'smith-family-test.com',
	'the domain lands as a labelled row, readable on the order',
	'got: ' . var_export($labels['Registered domain'] ?? null, true));
check(isset($labels['md_first_name']), 'the contact fields are stored alongside it');

// ---------------------------------------------------------------------------
section('The domain year is its own cart line, priced by the server');

$lines = $req->extra_cart_lines($data, $hosting_product);
check(count($lines) === 1, 'exactly one companion line');
check((int)$lines[0]['product_id'] === (int)$domain_product->key,
	'it is the configured domain-year product');
check(($lines[0]['form_data']['user_price_override'] ?? '') === '12.34',
	'priced at the server-derived quote');
check(($lines[0]['form_data']['managed_domain']['answer'] ?? '') === 'smith-family-test.com',
	'and it names the domain, so the receipt says what was bought');

// The line's price must survive being re-derived, because coupon changes
// re-call get_price() with the persisted form data and never with the API.
$repriced = $domain_product->get_price($domain_version, $lines[0]['form_data']);
check((string)$repriced === '12.34',
	'a prv_price_type=user version prices the line from its stored form data',
	'got: ' . var_export($repriced, true));
check($domain_version->is_subscription() === false,
	'the domain line never recurs — one year, one charge, never again from us');

// A buyer posting their own price cannot move the line: the requirement builds
// the line's form data from its own quote, never from the submission.
$tampered = mdr_post(array('user_price_override' => '0.01', 'managed_domain_price_line' => '0.01'));
$req2 = new ManagedDomainRequirement();
$req2->validate($tampered, $hosting_product);
list($data2) = $req2->process($tampered, $hosting_product, null, null);
$lines2 = $req2->extra_cart_lines($data2, $hosting_product);
check(($lines2[0]['form_data']['user_price_override'] ?? '') === '12.34',
	'a posted price is ignored — the quote wins');

// ---------------------------------------------------------------------------
section('Intake files exactly one row, however many times it runs');

$req->post_purchase($data, $order_item, $buyer, $order);
$req->post_purchase($data, $order_item, $buyer, $order);

$filed = new MultiRegisteredDomain(array(
	'external_order_item_id' => (int)$order_item->key, 'deleted' => false));
$filed->load();
check(count($filed) === 1, 'two invocations, one row — no double registration',
	'rows: ' . count($filed));

$rdm = null;
foreach ($filed as $row) { $rdm = $row; }
if ($rdm) {
	harness_register_row('rdm_registered_domains', 'rdm_id', $rdm->key);
	check($rdm->get('rdm_domain') === 'smith-family-test.com', 'the row carries the domain');
	check((int)$rdm->get('rdm_usr_user_id') === (int)$buyer->key, 'and the buyer who owns it');
	check($rdm->get('rdm_status') === RegisteredDomain::STATUS_PENDING,
		'it starts pending — nothing is bought until the pipeline runs');
	check($rdm->get('rdm_graduation_state') === RegisteredDomain::GRAD_OPERATOR,
		'custody starts with the operator');
	check((string)$rdm->get('rdm_price_paid') === '12.34', 'the charged price is recorded');
	check(trim((string)$rdm->get('rdm_registered_time')) === '',
		'and no registration time is claimed before one happened');
}

section('The registrant snapshot round-trips');

if ($rdm) {
	$stored = $rdm->open_registrant();
	check(is_array($stored), 'the sealed contact block reads back as an array');
	check(($stored['first_name'] ?? '') === 'Jane' && ($stored['email'] ?? '') === 'jane@example.com',
		'with the buyer\'s details intact');
	check(($stored['country'] ?? '') === 'US', 'and the country still ISO-2');
	$raw = (string)$rdm->get('rdm_registrant_sealed');
	check(strpos($raw, 'Elm Street') === false || !class_exists('SecretBox', false)
			|| trim((string)Globalvars::get_instance()->get_setting('secret_box_key', false, true)) === '',
		'the home address is not sitting in the column in plain sight (where a key exists)');
}

section('Editing the cart line replaces the domain line, never duplicates it');

// The buyer goes back and changes the name. The companion line was priced from
// the answer being replaced, so leaving it would charge for a domain they no
// longer asked for — at the old price, under the old name.
MdrStubRegistrar::$price = '21.50';
$req3 = new ManagedDomainRequirement();
$edited_post = mdr_post(array('managed_domain_name' => 'other-name-test.net'));
check($req3->validate($edited_post, $hosting_product) === array(), 'the new name validates');
list($edited_data) = $req3->process($edited_post, $hosting_product, null, null);

$stale_lines = $req3->extra_cart_lines($data, $hosting_product);
$fresh_lines = $req3->extra_cart_lines($edited_data, $hosting_product);

check(count($stale_lines) === 1 && count($fresh_lines) === 1,
	'both the old and the new answer describe exactly one line');
check($stale_lines[0]['form_data'] !== $fresh_lines[0]['form_data'],
	'and they differ, so the cart sync has something to match on');
check(($fresh_lines[0]['form_data']['managed_domain']['answer'] ?? '') === 'other-name-test.net',
	'the fresh line names the new domain');
check(($fresh_lines[0]['form_data']['user_price_override'] ?? '') === '21.50',
	'at the new quote');
check(($stale_lines[0]['form_data']['managed_domain']['answer'] ?? '') === 'smith-family-test.com',
	'the stale line still describes the old answer — which is how product_logic finds it in the cart');
MdrStubRegistrar::$price = '12.34';

section('A submission with no domain contributes nothing');

$empty_req = new ManagedDomainRequirement();
check($empty_req->extra_cart_lines(array('product_version' => 1), $hosting_product) === array(),
	'form data with no quote produces no line');
$empty_req->post_purchase(array('product_version' => 1), $order_item, $buyer, $order);
$still = new MultiRegisteredDomain(array(
	'external_order_item_id' => (int)$order_item->key, 'deleted' => false));
$still->load();
check(count($still) === 1, 'and no extra row appears');

harness_finish();
