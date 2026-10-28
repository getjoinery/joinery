<?php
/** @joinery-test
 * name: plugin_content_packs
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */

/**
 * The content packs a plugin ships land once per install
 * (PluginContentPacks, specs/content_pack_feature.md §7):
 *   - a pack folder is applied with its default ticks, as the platform's
 *     write, whoever started the activation;
 *   - it is not applied again until the plugin is installed again;
 *   - on the next install a field edited on the site is left alone;
 *   - a broken pack is recorded as failed, never fatal, and tried again;
 *   - folders read like zips: only files, UTF-8, no folders.
 *
 * The packs live in a scratch folder handed to apply_pending() in place of
 * the plugin's own content_packs/, under the store plugin's name.
 *
 * Run: php tests/core/plugin_content_packs_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

$run = substr(md5(uniqid('pcp', true)), 0, 8);
$db = DbConnector::get_instance()->get_db_link();
$link = 'pcp-defaults-' . $run;

// Whoever activates a plugin need not be a superadmin: an admin at 8 may.
$admin = make_user('PcpAdmin' . $run, 8);
$_SESSION['loggedin'] = 1;
$_SESSION['usr_user_id'] = (int)$admin->key;
$_SESSION['permission'] = 8;

$store = Plugin::get_by_plugin_name('store');
$installed_was = $store ? $store->get('plg_installed_time') : null;
harness_defer(function () use ($db, $installed_was, $link, $run) {
	$db->prepare("UPDATE plg_plugins SET plg_installed_time = ? WHERE plg_name = 'store'")->execute(array($installed_was));
	foreach ($db->query("SELECT pro_product_id FROM pro_products WHERE pro_link LIKE 'pcp-%-" . $run . "'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
		$db->prepare("DELETE FROM cpv_content_pack_values WHERE cpv_target = ?")->execute(array('Product:' . (int)$id));
		try { (new Product((int)$id, TRUE))->permanent_delete(); } catch (\Throwable $e) {}
	}
	$db->prepare("DELETE FROM cpa_content_pack_applies WHERE cpa_source LIKE ?")->execute(array('plugin:store/%' . $run));
});

$root = harness_scratch_dir('pcp');
$good = 'a-defaults-' . $run;
mkdir($root . '/' . $good);
file_put_contents($root . '/' . $good . '/pack.json', json_encode(array(
	'pack' => 'store-defaults', 'version' => '1',
	'requires' => array('plugins' => array('store')),
	'entries' => array(array('model' => 'Product', 'match' => array('pro_link' => $link),
		'set' => array('pro_name' => 'PCP Default ' . $run, 'pro_description' => array('file' => 'description.txt')),
		'on_create' => array('pro_is_active' => false))),
)));
file_put_contents($root . '/' . $good . '/description.txt', 'A default product.');

/** Rows recorded for this run's packs, newest first. */
$applies = function ($folder) use ($db) {
	$stmt = $db->prepare("SELECT cpa_status, cpa_error, cpa_usr_user_id, cpa_pack_name FROM cpa_content_pack_applies
		WHERE cpa_source = ? ORDER BY cpa_content_pack_apply_id DESC");
	$stmt->execute(array(PluginContentPacks::source('store', $folder)));
	return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

// ---------------------------------------------------------------------------
section('A plugin\'s pack lands once per install');
// ---------------------------------------------------------------------------

$db->exec("UPDATE plg_plugins SET plg_installed_time = now() - interval '1 minute' WHERE plg_name = 'store'");
$messages = PluginContentPacks::apply_pending('store', $root);
check(count($messages) === 1 && strpos($messages[0], 'applied') !== false, 'the pack is applied', implode(' | ', $messages));
$product = null;
foreach (new MultiProduct(array('pro_link' => $link)) as $p) { $product = $p; }
check($product && $product->get('pro_name') === 'PCP Default ' . $run && $product->get('pro_description') === 'A default product.'
	&& !$product->get('pro_is_active'), 'its product is created as the pack says');
$rows = $applies($good);
check($rows && $rows[0]['cpa_status'] === 'applied' && $rows[0]['cpa_pack_name'] === 'store-defaults'
	&& (int)$rows[0]['cpa_usr_user_id'] === (int)$admin->key,
	'the apply is recorded under the plugin, with who activated it', json_encode($rows[0] ?? null));

check(PluginContentPacks::apply_pending('store', $root) === array(), 'it is not applied again before the next install');

// ---------------------------------------------------------------------------
section('The next install leaves edits alone');
// ---------------------------------------------------------------------------

$product->set('pro_name', 'Renamed by the owner');
$product->save();
$db->exec("UPDATE plg_plugins SET plg_installed_time = now() + interval '1 second' WHERE plg_name = 'store'");
sleep(2);
$messages = PluginContentPacks::apply_pending('store', $root);
check(count($messages) === 1 && strpos($messages[0], 'applied') !== false, 'a reinstall applies the pack again', implode(' | ', $messages));
check((new Product($product->key, TRUE))->get('pro_name') === 'Renamed by the owner', 'the field edited on the site is left alone');
$count = (int)$db->query("SELECT COUNT(*) FROM pro_products WHERE pro_link = " . $db->quote($link))->fetchColumn();
check($count === 1, 'and the product is not created twice', (string)$count);

// ---------------------------------------------------------------------------
section('A broken pack is recorded, never fatal, and tried again');
// ---------------------------------------------------------------------------

$bad = 'b-broken-' . $run;
mkdir($root . '/' . $bad);
file_put_contents($root . '/' . $bad . '/pack.json', json_encode(array('pack' => 'broken', 'entries' => array(), 'extra' => 1)));
$messages = PluginContentPacks::apply_pending('store', $root);
check(count($messages) === 1 && strpos($messages[0], 'failed') !== false && strpos($messages[0], 'unknown key') !== false,
	'the broken pack fails by name, and the good one is not run again', implode(' | ', $messages));
$rows = $applies($bad);
check($rows && $rows[0]['cpa_status'] === 'failed' && strpos((string)$rows[0]['cpa_error'], 'unknown key') !== false,
	'the failure is recorded on the Content Packs page');
$messages = PluginContentPacks::apply_pending('store', $root);
check(count($messages) === 1 && strpos($messages[0], 'failed') !== false, 'and is tried again at the next sync');

$nested = 'c-nested-' . $run;
mkdir($root . '/' . $nested);
mkdir($root . '/' . $nested . '/sub');
file_put_contents($root . '/' . $nested . '/pack.json', '{}');
$messages = PluginContentPacks::apply_pending('store', $root);
check((bool)array_filter($messages, function ($m) use ($nested) { return strpos($m, $nested) !== false && strpos($m, 'not a file') !== false; }),
	'a pack folder holding a folder is refused', implode(' | ', $messages));

check(PluginContentPacks::packs('store', $root . '/nowhere') === array(), 'a plugin with no content_packs folder has no packs');

harness_finish();
