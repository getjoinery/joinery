<?php
/** @joinery-test
 * name: optional_donation_pricing
 * tier: db
 * env: dev-only
 * needs: []
 */

/**
 * Optional-donation product identification is setting-driven, never a
 * hardcoded product ID. Regression for the getjoinery cart fatal: the store
 * once keyed the donation branches on product ID 4, so any site whose product
 * 4 was NOT a donation product priced it as an empty string and the cart view
 * crashed in number_format().
 *
 * Section order matters: Globalvars caches non-blank setting values on first
 * read, so all blank-setting checks run before the setting is pointed at the
 * fixture product.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/product_versions_class.php'));
require_once(PathHelper::getIncludePath('data/settings_class.php'));

const ODP_SETTING = 'store_optional_donation_product_id';

function odp_read_setting_row(): ?Setting {
	$rows = new MultiSetting(array('setting_name' => ODP_SETTING));
	$rows->load();
	foreach ($rows as $row) {
		return $row;
	}
	return null;
}

function odp_write_setting(string $value): void {
	$row = odp_read_setting_row();
	if ($row !== null) {
		$row->set('stg_value', $value);
		$row->save();
		return;
	}
	$setting = new Setting(NULL);
	$setting->set('stg_name', ODP_SETTING);
	$setting->set('stg_value', $value);
	$setting->save();
}

// Snapshot the setting and restore it whatever happens.
$odp_prior_row = odp_read_setting_row();
$odp_prior_value = $odp_prior_row !== null ? (string)$odp_prior_row->get('stg_value') : null;
harness_defer(function () use ($odp_prior_value) {
	if ($odp_prior_value === null) {
		$row = odp_read_setting_row();
		if ($row !== null) {
			$row->permanent_delete();
		}
	} else {
		odp_write_setting($odp_prior_value);
	}
});
odp_write_setting('');

// ---------------------------------------------------------------------------
// Fixture: an ordinary priced product
// ---------------------------------------------------------------------------

$product = new Product(NULL);
$product->set('pro_name', 'ODP fixture product');
$product->set('pro_description', 'optional_donation_pricing fixture');
$product->set('pro_short_description', 'optional_donation_pricing fixture');
$product->set('pro_is_active', 1);
$product->set('pro_link', 'odp-fixture-product-' . substr(md5(uniqid('', true)), 0, 8));
$product->save();
$product->load();
$product_id = (int)$product->key;
harness_defer(function () use ($product_id) {
	try {
		$p = new Product($product_id, TRUE);
		if ($p->key) $p->permanent_delete();
	} catch (\Throwable $e) {
		echo "  WARNING: could not delete product $product_id: " . $e->getMessage() . "\n";
	}
});

$version = new ProductVersion(NULL);
$version->set('prv_pro_product_id', $product_id);
$version->set('prv_version_name', 'ODP fixture version');
$version->set('prv_version_price', '5.00');
$version->set('prv_status', 1);
$version->set('prv_price_type', 'single');
$version->save();
$version->load();

// ---------------------------------------------------------------------------
section('Setting blank: every product prices normally');
// ---------------------------------------------------------------------------

check($product->is_optional_donation() === false,
	'no product is the donation product while the setting is blank');

$price = $product->get_price($version, array());
check(is_numeric($price) && abs((float)$price - 5.00) < 0.001,
	'get_price returns the version price with no checkout data',
	"got '" . var_export($price, true) . "'");

// The original bug: buyer data without a user_price key must not divert an
// ordinary product into the donation branch (which returned '').
$price = $product->get_price($version, array('email' => 'buyer@example.com'));
check(is_numeric($price) && abs((float)$price - 5.00) < 0.001,
	'get_price ignores the donation branch for ordinary checkout data',
	"got '" . var_export($price, true) . "'");

$readable = $product->get_readable_price();
check(is_string($readable) && strpos($readable, '5.00') !== false,
	'get_readable_price shows the version price',
	"got '" . var_export($readable, true) . "'");

// ---------------------------------------------------------------------------
section('Setting pointed at the fixture: donation branches activate');
// ---------------------------------------------------------------------------

odp_write_setting((string)$product_id);

check($product->is_optional_donation() === true,
	'the configured product identifies as the donation product');

$price = $product->get_price($version, array('user_price' => '$12,34'));
check($price === '12.34',
	'get_price uses the sanitized buyer-entered amount',
	"got '" . var_export($price, true) . "'");

$price = $product->get_price($version, array());
check(is_numeric($price) && abs((float)$price - 5.00) < 0.001,
	'without a buyer amount the donation product falls back to its version price',
	"got '" . var_export($price, true) . "'");

check($product->get_readable_price() === false,
	'the donation product displays no fixed price');

$other = new Product(NULL);
$other->set('pro_name', 'ODP other product');
$other->set('pro_description', 'optional_donation_pricing fixture');
$other->set('pro_short_description', 'optional_donation_pricing fixture');
$other->set('pro_is_active', 1);
$other->set('pro_link', 'odp-other-product-' . substr(md5(uniqid('', true)), 0, 8));
$other->save();
$other->load();
$other_id = (int)$other->key;
harness_defer(function () use ($other_id) {
	try {
		$p = new Product($other_id, TRUE);
		if ($p->key) $p->permanent_delete();
	} catch (\Throwable $e) {
		echo "  WARNING: could not delete product $other_id: " . $e->getMessage() . "\n";
	}
});

check($other->is_optional_donation() === false,
	'a different product is unaffected by the configured donation id');

harness_finish();
