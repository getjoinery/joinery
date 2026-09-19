<?php
/** @joinery-test
 * name: managed_domain_intake
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The one gate for what a domain and a registrant may be: ManagedDomainIntake,
 * which the configure page, the taken-name alternate and the pipeline all call.
 *
 * The registrar is a stub here on purpose — the point is not whether Namecheap
 * answers (that is namecheap_registrar_test) but whether the intake can be
 * talked into accepting something it should not:
 *
 *  - **A half-configured deployment refuses the submission.** No registrar, or
 *    no domain-year product, has to fail the whole thing — quietly dropping
 *    the domain would take the buyer's money for hosting and silently not buy
 *    the name they asked for.
 *  - **The contact block is the WHOIS record**, so it has to be complete, and
 *    a phone number without its country code is refused, never guessed at.
 *  - **The quote is the registrar's, at submit time.** Nothing price-shaped
 *    from a browser reaches it.
 *
 * Sections: the configuration gate; syntax and TLD; an owned name; the contact
 * block; the quote; the whole intake; the registrant round trip.
 *
 * Run: php plugins/server_manager/tests/managed_domain_intake_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ManagedDomainIntake.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/product_versions_class.php'));

// ---------------------------------------------------------------------------
// A registrar that answers from a script, so no network is touched.
// ---------------------------------------------------------------------------

class MdiStubRegistrar implements DomainRegistrarProvider {
	public static $available = true;
	public static $price = '12.34';
	public static $throw = false;

	public static function getKey(): string { return 'mdistub'; }
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
check(DomainRegistrarRegistry::get('mdistub') !== null,
	'a registrar defined in this process is discovered by interface');
// Pinned, because a deployment with the real registrar configured would
// otherwise be asked live for every quote below.
ManagedDomainIntake::pinRegistrar(new MdiStubRegistrar());
harness_defer(function () { ManagedDomainIntake::pinRegistrar(null); });

// ---------------------------------------------------------------------------
// Fixtures: the domain-year product, priced by whoever adds the line.
// ---------------------------------------------------------------------------

$domain_product = new Product(NULL);
$domain_product->set('pro_name', 'Domain registration (1 year) [test]');
$domain_product->set('pro_link', 'mdi-domain-year-test-' . getmypid());
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

harness_set_setting_mem('store_domain_registration_product_id', (string)$domain_product->key);
harness_set_setting_mem('server_manager_domain_tlds', 'com net org');

/** A complete, valid submission, in the configure page's field names. */
function mdi_post(array $overrides = array()): array {
	return array_merge(array(
		'cvp_domain'        => 'Smith-Family-Test.COM',
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

// ---------------------------------------------------------------------------
section('A deployment that cannot register a domain refuses the submission');

harness_set_setting_mem('store_domain_registration_product_id', '99999999');
check(!ManagedDomainIntake::domainProductSellable(), 'a domain-year product that does not load is not sellable');
check(!ManagedDomainIntake::sellable(), 'so the feature as a whole is off');
$r = ManagedDomainIntake::validateRegistration(mdi_post());
check(count($r['errors']) === 1 && stripos($r['errors'][0], 'not available') !== false,
	'and the submission is refused rather than selling an unpriced domain', implode(' | ', $r['errors']));

harness_set_setting_mem('store_domain_registration_product_id', (string)$domain_product->key);
check(ManagedDomainIntake::domainProductSellable(), 'the configured product with an active version is sellable');
check(ManagedDomainIntake::sellable(), 'with a registrar and a sellable product the feature is on');

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
	check(ManagedDomainIntake::checkName($name)['error'] !== '', $why . ' is refused');
}
check(stripos(ManagedDomainIntake::checkName('smithfamily.rocks')['error'], '.com') !== false,
	'the unsupported-ending message names the endings that do work');
check(ManagedDomainIntake::checkName('https://Smith-Family.COM/path')['domain'] === 'smith-family.com',
	'a pasted URL is normalized to the bare lowercase name');

// ---------------------------------------------------------------------------
section('A name the buyer already owns is checked for shape only');

check(ManagedDomainIntake::checkOwnName('MySite.Rocks')['error'] === '',
	'an owned name may have any ending — nothing is bought');
check(ManagedDomainIntake::checkOwnName('MySite.Rocks')['domain'] === 'mysite.rocks', 'and is normalized');
check(ManagedDomainIntake::checkOwnName('not a domain')['error'] !== '', 'but it still has to look like a domain');
check(ManagedDomainIntake::checkOwnName('')['error'] !== '', 'and cannot be empty');

// ---------------------------------------------------------------------------
section('The contact block is the WHOIS record, so it must be complete');

$registrar = new MdiStubRegistrar();
foreach (array('md_first_name', 'md_last_name', 'usa_cco_country_code_id', 'usa_address1', 'usa_city',
		'usa_zip_code_id', 'phn_cco_country_code_id', 'phn_phone_number', 'md_email') as $field) {
	$errors = ManagedDomainIntake::checkRegistrant(mdi_post(array($field => '')), $registrar);
	check(count($errors) > 0, 'a missing ' . $field . ' is refused');
}
check(count(ManagedDomainIntake::checkRegistrant(mdi_post(array('md_email' => 'not-an-email')), $registrar)) > 0,
	'an unusable registration email is refused');
$errors = ManagedDomainIntake::checkRegistrant(mdi_post(array('phn_cco_country_code_id' => '')), $registrar);
check(count($errors) > 0, 'a phone number with no country code is refused, not guessed at');
check(stripos(implode(' ', $errors), 'country code') !== false, 'and the message tells the buyer exactly what to add');
$errors = ManagedDomainIntake::checkRegistrant(mdi_post(array('usa_cco_country_code_id' => '999999')), $registrar);
check(count($errors) > 0, 'a country id that names no row is refused');
check(ManagedDomainIntake::phoneFrom(mdi_post()) === '+1 5551234567', 'the phone is composed as +CC digits for the registrar seam');
check(ManagedDomainIntake::checkRegistrant(mdi_post(), $registrar) === array(), 'a complete block passes');

// ---------------------------------------------------------------------------
section('The quote comes from the registrar, at submit time');

MdiStubRegistrar::$available = false;
$q = ManagedDomainIntake::quote('smith-family-test.com');
check($q['price'] === null && $q['error'] !== '', 'a taken name has no price and says so');

MdiStubRegistrar::$available = true;
MdiStubRegistrar::$throw = true;
$q = ManagedDomainIntake::quote('smith-family-test.com');
check($q['price'] === null && stripos($q['error'], 'try again') !== false,
	'an unreachable registrar fails the quote and asks the buyer to retry');
MdiStubRegistrar::$throw = false;

MdiStubRegistrar::$price = '12.34';
$q = ManagedDomainIntake::quote('smith-family-test.com');
check($q['price'] === '12.34' && $q['error'] === '', 'an available name is priced by the registrar');

// ---------------------------------------------------------------------------
section('The whole intake: cheap gates first, the live quote last');

MdiStubRegistrar::$throw = true;
$r = ManagedDomainIntake::validateRegistration(mdi_post(array('phn_phone_number' => '')));
check(count($r['errors']) > 0 && stripos(implode(' ', $r['errors']), 'try again') === false,
	'a submission that fails a free check never reaches the registrar');
MdiStubRegistrar::$throw = false;

$r = ManagedDomainIntake::validateRegistration(mdi_post(array('user_price_override' => '0.01')));
check($r['errors'] === array(), 'a good submission validates', implode(' | ', $r['errors']));
check($r['domain'] === 'smith-family-test.com', 'the name is normalized to lowercase');
check($r['price'] === '12.34', 'the price is the registrar\'s quote, not anything the browser posted');
check(($r['registrant']['country'] ?? '') === 'US' && ($r['registrant']['first_name'] ?? '') === 'Jane',
	'the registrant block is returned in the registrar\'s shape');

// ---------------------------------------------------------------------------
section('The registrant block round-trips between form fields and the registrar shape');

$registrant = ManagedDomainIntake::registrantFrom(mdi_post());
$fields = ManagedDomainIntake::fieldsFromRegistrant($registrant);
check($registrant['country'] === 'US' && $registrant['phone'] === '+1 5551234567' && $registrant['postal_code'] === '62704',
	'registrantFrom() yields the WHOIS shape: ISO-2 country, +CC digits phone');
check($fields['md_first_name'] === 'Jane' && $fields['usa_cco_country_code_id'] === (string)ManagedDomainIntake::countryIdFor('US')
	&& $fields['phn_phone_number'] === '5551234567',
	'fieldsFromRegistrant() is the inverse of registrantFrom()');
check(ManagedDomainIntake::registrantFrom($fields) === $registrant, 'and back again, exactly');
check(count(ManagedDomainIntake::fieldsFromRegistrant(null)) === count(ManagedDomainIntake::CONTACT_FIELDS),
	'no stored block gives every field, empty');
$us_row = ManagedDomainIntake::countryRow(ManagedDomainIntake::countryIdFor('US'));
check($us_row !== null && $us_row['iso'] === 'US' && $us_row['code'] === '1', 'the country table answers with the ISO code and the dialling code');
check(ManagedDomainIntake::countryRow(0) === null && ManagedDomainIntake::countryRow(999999) === null, 'and nothing for an id that names no row');

harness_finish();
