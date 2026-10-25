<?php
/** @joinery-test
 * name: api_content_models_levels
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Products and Events over /api/v1 with keys at several levels, under the
 * declared-permissions save check (spec declared_permissions). Each model's
 * class in permissions.json sets who writes it: Product is money (write 7),
 * Event is content (write 6). Both are public to read.
 *
 *   - anyone's key lists and reads them;
 *   - a key below the write level is refused a POST and a PUT, and the row is
 *     unchanged (the database is read, not only the status);
 *   - a key at the level creates and edits.
 *
 * Run: php tests/functional/api/content_models_levels_test.php [base_url] [origin_ip]
 *
 * @version 1.0
 */
require_once(__DIR__ . '/api_test_harness.php');
api_test_boot($argv);

$suffix = strtoupper(LibraryFunctions::random_string(6));

/** Keys for a user at $level. */
function acm_headers(string $label, int $level): array {
	$u = make_user($label, $level);
	$k = make_machine_key($u->key, 'acm-' . strtolower($label), 4);
	return key_headers($k['api_key']->get('apk_public_key'), $k['secret_key']);
}

/** The row's column straight from the table. */
function acm_value(string $table, string $pkey, int $id, string $col) {
	$q = DbConnector::get_instance()->get_db_link()->prepare("SELECT {$col} FROM {$table} WHERE {$pkey} = ?");
	$q->execute(array($id));
	return $q->fetchColumn();
}

/** The id a POST answered with, from the contract envelope. */
function acm_new_id(array $r, string $pkey): int {
	$body = json_decode((string)$r['raw'], true);
	foreach (array($body['data'][$pkey] ?? null, $body['data']['id'] ?? null, $body[$pkey] ?? null) as $v) {
		if ($v) return (int)$v;
	}
	return 0;
}

section('Setup');
$member = acm_headers($suffix . 'M', 0);
$six    = acm_headers($suffix . 'Six', 6);
$seven  = acm_headers($suffix . 'Sev', 7);

$product = new Product(NULL);
$product->set('pro_name', 'HarnessTest ACM Product ' . $suffix);
$product->set('pro_link', 'acm-product-' . strtolower($suffix));
$product->set('pro_is_active', false);
$product->save();
$product->load();
harness_register_row('pro_products', 'pro_product_id', $product->key);

$event = new Event(NULL);
$event->set('evt_name', 'HarnessTest ACM Event ' . $suffix);
$event->save();
$event->load();
harness_register_row('evt_events', 'evt_event_id', $event->key);

$cases = array(
	array('Product', 'Products', 'pro_products', 'pro_product_id', 'pro_name', $product->key, $six, $seven, '7 (money)',
		array('pro_name' => 'HarnessTest ACM New Product ' . $suffix, 'pro_link' => 'acm-new-' . strtolower($suffix))),
	array('Event', 'Events', 'evt_events', 'evt_event_id', 'evt_name', $event->key, $member, $six, '6 (content)',
		array('evt_name' => 'HarnessTest ACM New Event ' . $suffix)),
);

foreach ($cases as list($model, $plural, $table, $pkey, $name_col, $id, $below, $at, $level, $create)) {
	section("$model: write level $level");

	$r = api_request('GET', "/api/v1/$plural?numperpage=5", $member);
	check($r['status'] === 200, "a member's key lists $plural", $r['status'] . ' ' . substr((string)$r['raw'], 0, 200));
	$r = api_request('GET', "/api/v1/$model/$id", $member);
	check($r['status'] === 200, "and reads one", $r['status'] . ' ' . substr((string)$r['raw'], 0, 200));

	$was = acm_value($table, $pkey, $id, $name_col);
	$r = api_request('PUT', "/api/v1/$model/$id?$name_col=" . rawurlencode('HarnessTest Hijacked ' . $suffix), $below);
	check($r['status'] >= 400 && $r['status'] < 500, 'a key below the level is refused a PUT', $r['status'] . ' ' . substr((string)$r['raw'], 0, 200));
	check(acm_value($table, $pkey, $id, $name_col) === $was, 'and the row is unchanged');

	$r = api_request('POST', "/api/v1/$model", $below, $create, true);
	check($r['status'] >= 400 && $r['status'] < 500, 'and refused a POST', $r['status'] . ' ' . substr((string)$r['raw'], 0, 200));
	$q = DbConnector::get_instance()->get_db_link()->prepare("SELECT count(*) FROM {$table} WHERE {$name_col} = ?");
	$q->execute(array($create[$name_col]));
	check((int)$q->fetchColumn() === 0, 'which made no row');

	$r = api_request('PUT', "/api/v1/$model/$id?$name_col=" . rawurlencode('HarnessTest ACM Edited ' . $suffix), $at);
	check($r['status'] === 200, 'a key at the level edits', $r['status'] . ' ' . substr((string)$r['raw'], 0, 200));
	check(acm_value($table, $pkey, $id, $name_col) === 'HarnessTest ACM Edited ' . $suffix, 'and the row has the edit');

	$r = api_request('POST', "/api/v1/$model", $at, $create, true);
	$new_id = acm_new_id($r, $pkey);
	if (!$new_id) {
		$q->execute(array($create[$name_col]));
		$new_id = (int)DbConnector::get_instance()->get_db_link()
			->query("SELECT max({$pkey}) FROM {$table} WHERE {$name_col} = " . DbConnector::get_instance()->get_db_link()->quote($create[$name_col]))
			->fetchColumn();
	}
	if ($new_id) {
		harness_register_row($table, $pkey, $new_id);
	}
	check($r['status'] === 200 && $new_id > 0, 'and creates', $r['status'] . ' ' . substr((string)$r['raw'], 0, 300));
}

harness_finish();
