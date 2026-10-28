<?php
/** @joinery-test
 * name: stripe_product_self_heal
 * tier: db
 * env: dev-only
 * needs: []
 */

/**
 * A product with no Stripe product in the checkout's mode gets one at
 * checkout, the way its prices already do.
 *
 * The product form mints a Stripe product only in the mode the site is in
 * when the form is saved, and anything else that writes a product (a content
 * pack, the API, a script) mints none. Checkout used to refuse such a product
 * ("This product does not have a stripe product id"). Covered:
 *   - a product written straight through the model is priced in test mode,
 *     and its test-mode id is minted and stored, by a buyer's request;
 *   - the second request mints nothing;
 *   - live mode mints its own id and leaves the test one alone;
 *   - a Stripe refusal stores nothing and says why.
 *
 * StripeHelper runs as written; only its Stripe client is a double. Nothing
 * reaches Stripe.
 *
 * Run: php plugins/store/tests/stripe_product_self_heal_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();

require_once(PathHelper::getIncludePath('plugins/store/includes/StripeHelper.php'));

$run_id = substr(md5(uniqid('sph', true)), 0, 8);

/** One Stripe API service: counts each call, answers from a script. */
class SphFakeService {
	public $calls = array();
	public $answers = array();
	public function __call($method, $args) {
		$this->calls[] = array($method, $args);
		return isset($this->answers[$method]) ? call_user_func_array($this->answers[$method], $args) : null;
	}
	public function count($method) {
		return count(array_filter($this->calls, function ($c) use ($method) { return $c[0] === $method; }));
	}
}

class SphFakeClient {
	public $products, $prices;
	public function __construct($mode) {
		$this->products = new SphFakeService();
		$this->prices = new SphFakeService();
		$this->products->answers['create'] = function ($params) use ($mode) {
			return array('id' => 'prod_sph_' . $mode);
		};
		$this->prices->answers['all'] = function ($params) {
			return new class { public function autoPagingIterator() { return array(); } };
		};
		$this->prices->answers['create'] = function ($params) {
			return (object)array('id' => 'price_sph_' . $params['product']);
		};
	}
}

/** StripeHelper as written, on the double, in a named mode. */
class SphStripe extends StripeHelper {
	public $client;
	public function __construct($test_mode) {
		$this->test_mode = $test_mode;
		$this->client = new SphFakeClient($test_mode ? 'test' : 'live');
		(new ReflectionProperty('StripeHelper', 'stripe'))->setValue($this, $this->client);
	}
}

// ---------------------------------------------------------------------------
// Fixture: a product and version written through the model, not the form
// ---------------------------------------------------------------------------

$admin = make_user('SphAdmin' . $run_id, 10);
$_SESSION['loggedin'] = 1;
$_SESSION['usr_user_id'] = (int)$admin->key;
$_SESSION['permission'] = 10;
harness_set_setting_mem('site_currency', 'usd');

$product = new Product(NULL);
$product->set('pro_name', 'SPH Hosting ' . $run_id);
$product->set('pro_link', 'sph-hosting-' . $run_id);
$product->set('pro_is_active', TRUE);
$product->prepare();
$product->save();
$product->load();
$product_id = (int)$product->key;
harness_defer(function () use ($product_id) {
	try { $p = new Product($product_id, TRUE); if ($p->key) $p->permanent_delete(); }
	catch (\Throwable $e) { echo "  WARNING: product $product_id not deleted: " . $e->getMessage() . "\n"; }
});

$version = new ProductVersion(NULL);
$version->set('prv_pro_product_id', $product_id);
$version->set('prv_version_name', 'Hosting');
$version->set('prv_version_price', '12.99');
$version->set('prv_price_type', 'month');
$version->set('prv_status', 1);
$version->save();
$version->load();

check($product_id && $version->key, 'a product and version are written through the model');
check((string)$product->get('pro_stripe_product_id') === '' && (string)$product->get('pro_stripe_product_id_test') === '',
	'with no Stripe product id in either mode');

// The rest runs as a buyer: the id is the platform's bookkeeping, not the buyer's write.
$buyer = make_user('SphBuyer' . $run_id, 0);
$_SESSION['usr_user_id'] = (int)$buyer->key;
$_SESSION['permission'] = 0;

// ---------------------------------------------------------------------------
section('Test mode mints and stores its own id');
// ---------------------------------------------------------------------------

$test = new SphStripe(true);
$price = $test->get_or_create_price(new ProductVersion($version->key, TRUE));
$stored = new Product($product_id, TRUE);
check($price && $price->id === 'price_sph_prod_sph_test', 'the price is made under the minted product', $price ? $price->id : 'none');
check((string)$stored->get('pro_stripe_product_id_test') === 'prod_sph_test', 'the test-mode id is stored on the product',
	(string)$stored->get('pro_stripe_product_id_test'));
check((string)$stored->get('pro_stripe_product_id') === '', 'the live-mode id is left empty');

$test->get_or_create_price(new ProductVersion($version->key, TRUE));
check($test->client->products->count('create') === 1, 'a second checkout mints nothing more',
	$test->client->products->count('create') . ' creates');

// ---------------------------------------------------------------------------
section('Live mode mints its own id');
// ---------------------------------------------------------------------------

$live = new SphStripe(false);
$live->get_or_create_price(new ProductVersion($version->key, TRUE));
$stored = new Product($product_id, TRUE);
check((string)$stored->get('pro_stripe_product_id') === 'prod_sph_live', 'the live-mode id is stored',
	(string)$stored->get('pro_stripe_product_id'));
check((string)$stored->get('pro_stripe_product_id_test') === 'prod_sph_test', 'and the test-mode id is untouched');

// ---------------------------------------------------------------------------
section('A Stripe refusal stores nothing');
// ---------------------------------------------------------------------------

$stored->set('pro_stripe_product_id_test', NULL);
SystemBase::server_initiated_write(function () use ($stored) { $stored->save(); });
$refusing = new SphStripe(true);
$refusing->client->products->answers['create'] = function ($params) {
	throw new \Stripe\Exception\ApiConnectionException('Stripe unreachable');
};
$error = '';
try {
	$refusing->get_or_create_price(new ProductVersion($version->key, TRUE));
} catch (SystemDisplayablePermanentError $e) {
	$error = $e->getMessage();
}
check(strpos($error, 'Stripe product creation failed') !== false && strpos($error, 'Stripe unreachable') !== false,
	'the checkout is refused with Stripe\'s reason', $error);
check((string)(new Product($product_id, TRUE))->get('pro_stripe_product_id_test') === '', 'and no id is stored');
check($refusing->client->prices->count('create') === 0, 'and no price is made');

harness_finish();
