<?php
/** @joinery-test
 * name: content_pack
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */

/**
 * Content packs (specs/content_pack_feature.md): a pack is checked, shows a
 * plan, and applies through the models in one transaction. Covered:
 *   - a pack on an empty store is all new, applies, and a second check is all same;
 *   - a field edited after an apply is "edited here", unticked, and survives;
 *   - a pack change is "change", including a field that was same at the first apply;
 *   - on_create never switches a product back off;
 *   - a rename is found through match_also and takes the new key;
 *   - a soft-deleted product holding the link is neither found nor revived;
 *   - unlisted retires the other version, and not when the new version is unticked;
 *   - the product rules (B2) in the models, judged only on a changed value;
 *   - every refusal at read, by name, with nothing written;
 *   - a failure mid-apply leaves nothing behind, and a stale plan is refused;
 *   - two rows for one match refuse; only a superadmin applies.
 *
 * Runs on the test database: a pack writes a real setting.
 *
 * Run: php tests/core/content_pack_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

require_once(PathHelper::getIncludePath('logic/content_pack_check_logic.php'));
require_once(PathHelper::getIncludePath('logic/content_pack_apply_logic.php'));

$run = substr(md5(uniqid('cpt', true)), 0, 8);
$session = SessionControl::get_instance();

$admin = make_user('CptAdmin' . $run, 10);
$_SESSION['loggedin'] = 1;
$_SESSION['usr_user_id'] = (int)$admin->key;
$_SESSION['permission'] = 10;

// Providers register from each plugin's serve.php on a web request, which a
// CLI test never makes.
FulfillmentRegistry::register(new CustomerCloudFulfillment());

/** Delete every product this run made, live or not, with its versions. */
harness_defer(function () use ($run) {
	$db = DbConnector::get_instance()->get_db_link();
	$ids = $db->query("SELECT pro_product_id FROM pro_products WHERE pro_link LIKE 'cpt-%-" . $run . "%'")->fetchAll(PDO::FETCH_COLUMN);
	$targets = array();
	foreach ($ids as $id) {
		$targets[] = 'Product:' . (int)$id;
		foreach (new MultiProductVersion(array('prv_pro_product_id' => (int)$id)) as $version) {
			$targets[] = 'ProductVersion:' . (int)$version->key;
		}
		try { (new Product((int)$id, TRUE))->permanent_delete(); }
		catch (\Throwable $e) { echo "  WARNING: product $id not deleted: " . $e->getMessage() . "\n"; }
	}
	// Only this run's rows: the values its packs established on its own products.
	if ($targets) {
		$in = implode(',', array_fill(0, count($targets), '?'));
		$db->prepare("DELETE FROM cpv_content_pack_values WHERE cpv_target IN ($in)")->execute($targets);
	}
	$db->prepare("DELETE FROM cpv_content_pack_values WHERE cpv_target = 'setting' AND cpv_cpa_content_pack_apply_id IN
		(SELECT cpa_content_pack_apply_id FROM cpa_content_pack_applies WHERE cpa_pack_name LIKE ?)")->execute(array('cpt-%' . $run . '%'));
});

/** A pack zip's bytes from a manifest and its text files; $root_files go at the zip's root. */
function cpt_zip(array $manifest, array $files = array(), $folder = '', array $root_files = array()) {
	$path = tempnam(sys_get_temp_dir(), 'cpt');
	$zip = new ZipArchive();
	$zip->open($path, ZipArchive::OVERWRITE);
	$zip->addFromString($folder . 'pack.json', json_encode($manifest));
	foreach ($files as $name => $contents) {
		$zip->addFromString($folder . $name, $contents);
	}
	foreach ($root_files as $name => $contents) {
		$zip->addFromString($name, $contents);
	}
	$zip->close();
	$bytes = file_get_contents($path);
	unlink($path);
	return $bytes;
}

/** The plan's default ticks. */
function cpt_default_ticks(array $plan) {
	$ticks = array();
	foreach ($plan['items'] as $item) {
		if (!empty($item['tick']) && !empty($item['default'])) $ticks[] = $item['tick'];
		foreach ($item['lines'] ?? array() as $line) {
			if (!empty($line['tick']) && !empty($line['default'])) $ticks[] = $line['tick'];
		}
		foreach ($item['records'] ?? array() as $record) {
			if (!empty($record['default'])) $ticks[] = $record['tick'];
		}
	}
	return $ticks;
}

/** Every line the plan offers. */
function cpt_all_ticks(array $plan) {
	$ticks = array();
	foreach ($plan['items'] as $item) {
		if (!empty($item['tick'])) $ticks[] = $item['tick'];
		foreach ($item['lines'] ?? array() as $line) if (!empty($line['tick'])) $ticks[] = $line['tick'];
		foreach ($item['records'] ?? array() as $record) $ticks[] = $record['tick'];
	}
	return $ticks;
}

/** Check a pack: [apply_id, plan] or [null, error]. */
function cpt_check($bytes) {
	$result = content_pack_check_bytes($bytes, SessionControl::get_instance());
	if ($result->error) return array(null, $result->error);
	return array($result->data['apply_id'], $result->data['plan']);
}

/** Check and apply with the default ticks (or $ticks). Returns [plan, error, written]. */
function cpt_apply($bytes, $ticks = null, $before_apply = null) {
	list($apply_id, $plan) = cpt_check($bytes);
	if (!$apply_id) return array(null, $plan, null);
	if ($before_apply) $before_apply($plan);
	$result = content_pack_apply_logic(array('apply_id' => $apply_id, 'plan_hash' => $plan['hash'],
		'ticks' => $ticks === null ? cpt_default_ticks($plan) : $ticks));
	return array($plan, $result->error, $result->error ? null : $result->data['written']);
}

/** Every line state in a plan, as "field:state". */
function cpt_states(array $plan) {
	$states = array();
	foreach ($plan['items'] as $item) {
		foreach ($item['lines'] ?? array() as $line) $states[] = $line['field'] . ':' . $line['state'];
		if ($item['kind'] === 'setting') $states[] = $item['name'] . ':' . $item['state'];
	}
	return $states;
}

function cpt_line(array $plan, $field) {
	foreach ($plan['items'] as $item) {
		foreach ($item['lines'] ?? array() as $line) if ($line['field'] === $field) return $line;
	}
	return null;
}

function cpt_product_by_link($link) {
	$found = new MultiProduct(array('pro_link' => $link, 'deleted' => false));
	foreach ($found as $p) return $p;
	return null;
}

function cpt_versions($product_id) {
	$out = array();
	foreach (new MultiProductVersion(array('prv_pro_product_id' => (int)$product_id)) as $v) {
		$out[$v->get('prv_version_name')] = $v;
	}
	return $out;
}

function cpt_pack($run, array $overrides = array()) {
	$link = 'cpt-hosted-' . $run;
	$manifest = array(
		'pack' => 'cpt-' . $run, 'version' => '1',
		'requires' => array('plugins' => array('store')),
		'entries' => array(
			array('id' => 'hosted', 'model' => 'Product',
				'match' => array('pro_link' => $link),
				'set' => array('pro_name' => 'CPT Hosting ' . $run, 'pro_short_description' => 'Short v1',
					'pro_description' => array('file' => 'description.txt'),
					'pro_after_purchase_message' => "Thanks.\nv1",
					'pro_fulfillment_provider' => 'customer_cloud', 'pro_fulfillment_ref' => 1),
				'on_create' => array('pro_is_active' => false, 'pro_max_cart_count' => 1)),
			array('model' => 'ProductVersion',
				'match' => array('prv_pro_product_id' => array('ref' => 'hosted'), 'prv_version_name' => 'Hosting'),
				'set' => array('prv_version_price' => '12.99', 'prv_price_type' => 'month', 'prv_trial_period_days' => null),
				'on_create' => array('prv_status' => 1, 'prv_order' => 1)),
			array('model' => 'ProductVersion',
				'unlisted' => array('where' => array('prv_pro_product_id' => array('ref' => 'hosted'), 'prv_status' => 1),
					'set' => array('prv_status' => 0))),
			array('setting' => 'store_domain_registration_product_id', 'value' => array('ref' => 'hosted')),
		),
	);
	return array_replace_recursive($manifest, $overrides);
}

$files = array('description.txt' => "Line one.\r\nLine two.");

// ---------------------------------------------------------------------------
section('A pack on an empty store');
// ---------------------------------------------------------------------------

// The test database carries the live site's settings, so the setting already
// holds a value no pack put there: "edited here", unticked until ticked.
list($apply_id, $plan) = cpt_check(cpt_zip(cpt_pack($run), $files));
$setting_item = $plan ? end($plan['items']) : null;
check($setting_item && $setting_item['kind'] === 'setting' && (trim((string)$setting_item['current']) === ''
		? $setting_item['state'] === ContentPack::STATE_CHANGE
		: ($setting_item['state'] === ContentPack::STATE_EDITED && empty($setting_item['default']))),
	'a setting holding a value no pack wrote is "edited here" and unticked; an empty one is a change', json_encode($setting_item));
$result = content_pack_apply_logic(array('apply_id' => $apply_id, 'plan_hash' => $plan['hash'], 'ticks' => cpt_all_ticks($plan)));
$error = $result->error;
check($error === null, 'the pack checks and applies', (string)$error);
check($plan && $plan['items'][0]['key'] === null && $plan['items'][1]['key'] === null, 'the product and version are new');
$product = cpt_product_by_link('cpt-hosted-' . $run);
check($product !== null, 'the product exists');
$pid = $product ? (int)$product->key : 0;
check($product && $product->get('pro_name') === 'CPT Hosting ' . $run && !$product->get('pro_is_active')
	&& (int)$product->get('pro_max_cart_count') === 1 && $product->get('pro_fulfillment_provider') === 'customer_cloud',
	'with its set and on_create fields');
check($product && $product->get('pro_description') === "Line one.\r\nLine two.", 'and its description from the zip\'s file');
$versions = cpt_versions($pid);
check(isset($versions['Hosting']) && (string)$versions['Hosting']->get('prv_version_price') === '12.99'
	&& $versions['Hosting']->get('prv_price_type') === 'month' && (int)$versions['Hosting']->get('prv_status') === 1,
	'the version exists, active, at 12.99 a month');
check(get_setting_raw('store_domain_registration_product_id') === (string)$pid, 'the setting names the new product',
	(string)get_setting_raw('store_domain_registration_product_id'));

list($apply_id, $plan) = cpt_check(cpt_zip(cpt_pack($run), $files));
$not_same = array_filter(cpt_states($plan), function ($s) { return substr($s, -5) !== ':same'; });
check(!$not_same, 'a second check is all same', implode(', ', $not_same));
check(!cpt_default_ticks($plan), 'and offers nothing to tick');

// ---------------------------------------------------------------------------
section('Edits made on the site, and changes made by the pack');
// ---------------------------------------------------------------------------

$product = new Product($pid, TRUE);
$product->set('pro_short_description', 'Edited by hand');
$product->set('pro_is_active', true);
$product->save();

$v2 = cpt_pack($run, array('version' => '2', 'entries' => array(0 => array('set' => array(
	'pro_short_description' => 'Short v2',
	'pro_after_purchase_message' => "Thanks.\nv2",
	'pro_name' => 'CPT Hosting v2 ' . $run)))));
list($plan, $error) = cpt_apply(cpt_zip($v2, $files));
check($error === null, 'version 2 applies', (string)$error);
$line = $plan ? cpt_line($plan, 'pro_short_description') : null;
check($line && $line['state'] === ContentPack::STATE_EDITED && empty($line['default']),
	'a field edited by hand is "edited here", unticked', json_encode($line));
$line = $plan ? cpt_line($plan, 'pro_after_purchase_message') : null;
check($line && $line['state'] === ContentPack::STATE_CHANGE && !empty($line['default']),
	'a field that was same at the first apply is "change" when the pack changes it', json_encode($line));
$product = new Product($pid, TRUE);
check($product->get('pro_short_description') === 'Edited by hand', 'the hand edit survives the apply');
check($product->get('pro_name') === 'CPT Hosting v2 ' . $run && $product->get('pro_after_purchase_message') === "Thanks.\nv2",
	'the pack\'s changes are written');
check((bool)$product->get('pro_is_active') === true, 'on_create never switches the product back off');

// The editor saves CRLF; the same text with LF endings is not a difference.
$product->set('pro_description', "Line one.\nLine two.");
$product->save();
list($apply_id, $plan) = cpt_check(cpt_zip($v2, $files));
$line = cpt_line($plan, 'pro_description');
check($line && $line['state'] === ContentPack::STATE_SAME, 'line endings alone are not a difference', json_encode($line));

// ---------------------------------------------------------------------------
section('A rename, and a deleted product holding the link');
// ---------------------------------------------------------------------------

$renamed = cpt_pack($run, array('entries' => array(0 => array(
	'match' => array('pro_link' => 'cpt-renamed-' . $run),
	'set' => $v2['entries'][0]['set']))));
$renamed['entries'][0]['match_also'] = array(array('pro_link' => 'cpt-hosted-' . $run));
list($plan, $error) = cpt_apply(cpt_zip($renamed, $files));
check($error === null && $plan['items'][0]['key'] === $pid && $plan['items'][0]['via'] === 'match_also',
	'the product is found by its earlier link', (string)$error);
check((new Product($pid, TRUE))->get('pro_link') === 'cpt-renamed-' . $run, 'and takes the new link');

$ghost = new Product(NULL);
$ghost->set('pro_name', 'CPT Ghost ' . $run);
$ghost->set('pro_link', 'cpt-ghost-' . $run);
$ghost->save();
$ghost->load();
$ghost_id = (int)$ghost->key;
$ghost->soft_delete();
$ghost_pack = array('pack' => 'cpt-ghost-' . $run, 'entries' => array(array('model' => 'Product',
	'match' => array('pro_link' => 'cpt-ghost-' . $run), 'set' => array('pro_name' => 'CPT Ghost reborn ' . $run))));
list($plan, $error) = cpt_apply(cpt_zip($ghost_pack));
check($error === null && $plan['items'][0]['key'] === null, 'a deleted product holding the link is not found', (string)$error);
check((new Product($ghost_id, TRUE))->get('pro_delete_time') !== null, 'and is not revived');

// ---------------------------------------------------------------------------
section('Unlisted versions');
// ---------------------------------------------------------------------------

$setup = new ProductVersion(NULL);
$setup->set('prv_pro_product_id', $pid);
$setup->set('prv_version_name', 'Setup');
$setup->set('prv_version_price', '39.99');
$setup->set('prv_price_type', 'single');
$setup->set('prv_status', 1);
$setup->save();
$setup->load();
list($plan, $error) = cpt_apply(cpt_zip($renamed, $files));
check($error === null && (int)(new ProductVersion($setup->key, TRUE))->get('prv_status') === 0, 'the other active version is retired', (string)$error);
check((int)cpt_versions($pid)['Hosting']->get('prv_status') === 1, 'the named version stays active');

// A product with only the old version, and a pack whose new version is unticked.
$solo_link = 'cpt-solo-' . $run;
$solo = new Product(NULL);
$solo->set('pro_name', 'CPT Solo ' . $run);
$solo->set('pro_link', $solo_link);
$solo->save();
$solo->load();
$old = new ProductVersion(NULL);
$old->set('prv_pro_product_id', $solo->key);
$old->set('prv_version_name', 'Old');
$old->set('prv_version_price', '5.00');
$old->set('prv_price_type', 'single');
$old->set('prv_status', 1);
$old->save();
$old->load();
$solo_pack = array('pack' => 'cpt-solo-' . $run, 'entries' => array(
	array('id' => 'p', 'model' => 'Product', 'match' => array('pro_link' => $solo_link), 'set' => array()),
	array('model' => 'ProductVersion', 'match' => array('prv_pro_product_id' => array('ref' => 'p'), 'prv_version_name' => 'New'),
		'set' => array('prv_version_price' => '6.00', 'prv_price_type' => 'single'), 'on_create' => array('prv_status' => 1)),
	array('model' => 'ProductVersion', 'unlisted' => array('where' => array('prv_pro_product_id' => array('ref' => 'p'), 'prv_status' => 1),
		'set' => array('prv_status' => 0)))));
list($apply_id, $plan) = cpt_check(cpt_zip($solo_pack));
$unlisted_ticks = array();
foreach ($plan['items'] as $item) {
	foreach ($item['records'] ?? array() as $record) $unlisted_ticks[] = $record['tick'];
}
$result = content_pack_apply_logic(array('apply_id' => $apply_id, 'plan_hash' => $plan['hash'], 'ticks' => $unlisted_ticks));
check($result->error === null && $unlisted_ticks, 'the pack applies with only the retirement ticked', (string)$result->error);
check((int)(new ProductVersion($old->key, TRUE))->get('prv_status') === 1 && !isset(cpt_versions($solo->key)['New']),
	'the old version is not retired when the new one is not created');

// ---------------------------------------------------------------------------
section('The product rules live in the models (B2)');
// ---------------------------------------------------------------------------

$refused = function (callable $fn) {
	try { $fn(); return ''; } catch (SystemDisplayableError $e) { return $e->getMessage(); }
};
$p = new Product(NULL);
$p->set('pro_name', 'CPT Rules ' . $run);
$p->set('pro_link', 'CPT Rules ' . $run);
check(strpos($refused(function () use ($p) { $p->prepare(); }), 'lower-case') !== false, 'an unslugged link is refused');
$p->set('pro_link', 'cpt-rules-' . $run);
$p->set('pro_fulfillment_provider', 'no_such_provider');
check(strpos($refused(function () use ($p) { $p->prepare(); }), 'no fulfilment provider') !== false, 'an unknown provider is refused');
$p->set('pro_fulfillment_provider', NULL);
$p->prepare();
$p->save();
$p->load();

// A row that predates the rules still saves untouched.
$db = DbConnector::get_instance()->get_db_link();
$db->prepare("UPDATE pro_products SET pro_link = ?, pro_fulfillment_provider = 'retired_provider' WHERE pro_product_id = ?")
	->execute(array('CPT Legacy ' . $run, $p->key));
$legacy = new Product($p->key, TRUE);
$legacy->set('pro_is_active', false);
check($refused(function () use ($legacy) { $legacy->prepare(); $legacy->save(); }) === '', 'a legacy row with a bad link and provider still saves');
$db->prepare("UPDATE pro_products SET pro_link = ?, pro_fulfillment_provider = NULL WHERE pro_product_id = ?")
	->execute(array('cpt-rules-' . $run, $p->key));

$v = new ProductVersion(NULL);
$v->set('prv_pro_product_id', $p->key);
$v->set('prv_version_name', 'Monthly');
$v->set('prv_version_price', '1.00');
$v->set('prv_status', 1);
$v->set('prv_price_type', 'monthly');
check(strpos($refused(function () use ($v) { $v->prepare(); }), 'not a price type') !== false, 'an unknown price type is refused');
$v->set('prv_price_type', 'month');
$v->prepare();
$v->save();
$v->load();

$tagged = new Product($p->key, TRUE);
$tagged->set('pro_ownership_tag', 'cpt-tag-' . $run);
check(strpos($refused(function () use ($tagged) { $tagged->prepare(); }), 'one-time purchases only') !== false,
	'an ownership tag on a product with a subscription version is refused');
$db->prepare("UPDATE pro_products SET pro_ownership_tag = ? WHERE pro_product_id = ?")->execute(array('cpt-tag-' . $run, $p->key));
$sub = new ProductVersion(NULL);
$sub->set('prv_pro_product_id', $p->key);
$sub->set('prv_version_name', 'Yearly');
$sub->set('prv_version_price', '10.00');
$sub->set('prv_status', 1);
$sub->set('prv_price_type', 'year');
check(strpos($refused(function () use ($sub) { $sub->prepare(); }), 'cannot be added') !== false,
	'a subscription version on a tagged product is refused');

// ---------------------------------------------------------------------------
section('Refusals when a pack is read');
// ---------------------------------------------------------------------------

$count_applies = function () { return (int)DbConnector::get_instance()->get_db_link()->query('SELECT COUNT(*) FROM cpa_content_pack_applies')->fetchColumn(); };
$base = array('pack' => 'cpt-refuse-' . $run, 'entries' => array(array('model' => 'Product',
	'match' => array('pro_link' => 'cpt-refuse-' . $run), 'set' => array('pro_name' => 'x'))));
$cases = array(
	'an unknown key' => array(array_merge($base, array('extra' => 1)), array(), 'unknown key'),
	'a model packs may not write' => array(array_merge($base, array('entries' => array(array('model' => 'User',
		'match' => array('usr_email' => 'x@example.com'), 'set' => array('usr_permission' => 10))))), array(), 'not a model a pack may write'),
	'a field the model does not open' => array(array_replace_recursive($base, array('entries' => array(0 => array('set' => array('pro_stripe_product_id' => 'prod_x'))))), array(), 'does not let a pack write'),
	'a match that is not a declared key' => array(array_replace_recursive($base, array('entries' => array(0 => array('match' => array('pro_name' => 'x'))))), array(), 'key sets'),
	'a secret setting' => array(array_merge($base, array('entries' => array(array('setting' => 'dns_install_credential', 'value' => 'x')))), array(), 'credential'),
	'a managed setting' => array(array_merge($base, array('entries' => array(array('setting' => 'backup_service_folder', 'value' => 'x')))), array(), 'written by the site'),
	'an undeclared setting' => array(array_merge($base, array('entries' => array(array('setting' => 'cpt_no_such_setting', 'value' => 'x')))), array(), 'not a declared setting'),
	'a missing file' => array(array_replace_recursive($base, array('entries' => array(0 => array('set' => array('pro_description' => array('file' => 'gone.txt')))))), array(), 'no file'),
	'an unused file' => array($base, array('stray.txt' => 'x'), 'no entry uses it'),
	'a file name with a path' => array(array_replace_recursive($base, array('entries' => array(0 => array('set' => array('pro_description' => array('file' => '../etc/passwd')))))), array(), 'not a file name'),
	'an unknown ref' => array(array_replace_recursive($base, array('entries' => array(0 => array('set' => array('pro_fulfillment_ref' => array('ref' => 'nobody')))))), array(), 'no record entry'),
	'an inactive required plugin' => array(array_merge($base, array('requires' => array('plugins' => array('cpt_no_such_plugin')))), array(), 'not active'),
	'refs in a loop' => array(array('pack' => 'cpt-loop', 'entries' => array(
		array('id' => 'a', 'model' => 'Product', 'match' => array('pro_link' => 'cpt-loop-' . $run), 'set' => array('pro_fulfillment_ref' => array('ref' => 'b'))),
		array('id' => 'b', 'model' => 'ProductVersion', 'match' => array('prv_pro_product_id' => array('ref' => 'a'), 'prv_version_name' => 'x')))), array(), 'loop'),
);
foreach ($cases as $label => $case) {
	list($manifest, $case_files, $expect) = $case;
	$before = $count_applies();
	list($apply_id, $error) = cpt_check(cpt_zip($manifest, $case_files));
	check($apply_id === null && strpos((string)$error, $expect) !== false && $count_applies() === $before,
		$label . ' is refused by name, with nothing kept', (string)$error);
}
list($apply_id, $error) = cpt_check(cpt_zip($ghost_pack, array(), 'folder/'));
check($apply_id !== null, 'a zip of one folder is read as that folder', is_string($error) ? $error : '');
// Finder puts __MACOSX at the zip's root and a .DS_Store beside the files.
$mac_zip = cpt_zip($ghost_pack, array('.DS_Store' => 'x'), 'folder/', array('__MACOSX/folder/._pack.json' => 'x'));
list($apply_id, $error) = cpt_check($mac_zip);
check($apply_id !== null, 'a zip made on a Mac (__MACOSX, .DS_Store) reads as its folder', is_string($error) ? $error : '');
$misfit = $base;
$misfit['entries'][0]['match_also'] = array(array('pro_fulfillment_ref' => 'abc'));
list($apply_id, $error) = cpt_check(cpt_zip($misfit));
check($apply_id === null && strpos((string)$error, 'does not fit its column') !== false,
	'a lookup value its column cannot hold is refused by name', (string)$error);
list($apply_id, $error) = cpt_check('not a zip');
check($apply_id === null && strpos($error, 'not a zip') !== false, 'a file that is not a zip is refused', (string)$error);

// ---------------------------------------------------------------------------
section('Failures leave nothing behind');
// ---------------------------------------------------------------------------

$bad = cpt_pack($run . 'x', array('entries' => array(1 => array('set' => array('prv_price_type' => 'monthly')))));
list($plan, $error) = cpt_apply(cpt_zip($bad, $files));
check($error !== null && strpos($error, 'not a price type') !== false, 'a refusal by a model mid-apply fails the apply', (string)$error);
check(cpt_product_by_link('cpt-hosted-' . $run . 'x') === null, 'and the product it created first is rolled back');
check(get_setting_raw('store_domain_registration_product_id') === (string)$pid, 'and the setting is untouched');
$failed = $db->query("SELECT cpa_status, cpa_error FROM cpa_content_pack_applies WHERE cpa_pack_name = 'cpt-" . $run . "x' ORDER BY cpa_content_pack_apply_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check($failed && $failed['cpa_status'] === 'failed' && $failed['cpa_error'] !== '', 'the apply record says it failed and why');

$stale_pack = cpt_pack($run, array('entries' => array(0 => array('match' => array('pro_link' => 'cpt-renamed-' . $run),
	'set' => array('pro_name' => 'CPT Stale ' . $run)))));
list($plan, $error) = cpt_apply(cpt_zip($stale_pack, $files), null, function () use ($pid) {
	$p = new Product($pid, TRUE);
	$p->set('pro_short_description', 'Changed between check and apply');
	$p->save();
});
check($error !== null && strpos($error, 'changed since') !== false, 'a plan that went stale is refused', (string)$error);
check((new Product($pid, TRUE))->get('pro_name') !== 'CPT Stale ' . $run, 'and nothing is written');

// ---------------------------------------------------------------------------
section('Ambiguity and permission');
// ---------------------------------------------------------------------------

$twin = new ProductVersion(NULL);
$twin->set('prv_pro_product_id', $pid);
$twin->set('prv_version_name', 'Hosting');
$twin->set('prv_version_price', '12.99');
$twin->set('prv_price_type', 'month');
$twin->set('prv_status', 0);
$twin->save();
list($apply_id, $error) = cpt_check(cpt_zip($renamed, $files));
check($apply_id === null && strpos((string)$error, 'more than one row') !== false, 'two rows for one match refuse the pack', (string)$error);
$twin->permanent_delete();

list($apply_id, $plan) = cpt_check(cpt_zip($renamed, $files));
$_SESSION['permission'] = 5;
$result = content_pack_apply_logic(array('apply_id' => $apply_id, 'plan_hash' => $plan['hash'], 'ticks' => array()));
check($result->error !== null && strpos($result->error, 'superadmin') !== false, 'only a superadmin applies a pack', (string)$result->error);
$_SESSION['permission'] = 10;

harness_finish();
