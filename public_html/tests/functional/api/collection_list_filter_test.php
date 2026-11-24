<?php
/**
 * A collection filter with a list of values, over HTTP
 * (spec sandboxed_plugin_ask_speed WP2).
 *
 * GET /api/v1/{Class}s?column[]=a&column[]=b answers the rows whose column is
 * any of the values, as SystemBase::column_option_filters() reads a list on a
 * declared column. What is pinned here:
 *  - a list of ids reads exactly those rows, by the full column name or the
 *    name without the prefix, a missing id adding none; ids sent as text bind
 *    as numbers;
 *  - a list of texts matches any of them;
 *  - refused with a 400: a keyed list, a list in a list, more than 200
 *    values, a value the column cannot hold, a list on a column no answer
 *    shows (a password), and a list on a collection's own option, which
 *    takes one value.
 *
 * USAGE (CLI only):
 *   php tests/functional/api/collection_list_filter_test.php [base_url] [origin_ip]
 *
 * @version 1.0
 */

/** @joinery-test
 * name: api_collection_list_filter
 * tier: db
 * env: dev-only
 * needs: []
 */
require_once(__DIR__ . '/api_test_harness.php');
api_test_boot($argv);

/** The ids a collection answer holds, in order. */
function clf_ids(array $r, string $pkey): array {
	$ids = array();
	foreach ((array)($r['json']['data'] ?? array()) as $row) {
		$ids[] = (int)($row[$pkey] ?? 0);
	}
	sort($ids);
	return $ids;
}

try {
	if (!class_exists('Location')) {
		harness_skip('Lists of values over HTTP', 'the event_manager plugin is not active, so Location is not a record endpoint');
		harness_finish();
	}
	$suffix = strtoupper(LibraryFunctions::random_string(6));

	section('Setup');
	$admin = make_user($suffix . 'LF', 10);
	$key = make_machine_key($admin->key, 'listfilter-' . $suffix, 4);
	$h = key_headers($key['api_key']->get('apk_public_key'), $key['secret_key']);
	$ids = array();
	$names = array();
	foreach (array('a', 'b', 'c') as $letter) {
		$location = new Location(NULL);
		$location->set('loc_name', 'HarnessTest list ' . $suffix . ' ' . $letter);
		$location->set('loc_link', 'harnesstest-list-' . strtolower($suffix) . '-' . $letter);
		$location->save();
		$location->load();
		harness_register_model('Location', (int)$location->key);
		$ids[$letter] = (int)$location->key;
		$names[$letter] = $location->get('loc_name');
	}
	$want = array($ids['a'], $ids['c']);
	sort($want);

	section('A list of values reads any of them');
	$r = api_request('GET', '/api/v1/Locations?numperpage=50&loc_location_id[]=' . $ids['a'] . '&loc_location_id[]=' . $ids['c'] . '&loc_location_id[]=999999999', $h);
	check($r['status'] === 200 && clf_ids($r, 'loc_location_id') === $want && (int)($r['json']['num_results'] ?? -1) === 2,
		'A list of ids reads exactly those rows; a missing id adds none', $r['status'] . ' ' . substr($r['raw'], 0, 300));
	$r = api_request('GET', '/api/v1/Locations?numperpage=50&location_id[]=' . $ids['a'] . '&location_id[]=' . $ids['c'], $h);
	check($r['status'] === 200 && clf_ids($r, 'loc_location_id') === $want, 'and by the name without its prefix', $r['status'] . ' ' . substr($r['raw'], 0, 300));
	$r = api_request('GET', '/api/v1/Locations?numperpage=50&loc_name[]=' . rawurlencode($names['b']) . '&loc_name[]=' . rawurlencode('nothing like it ' . $suffix), $h);
	check($r['status'] === 200 && clf_ids($r, 'loc_location_id') === array($ids['b']), 'A list of texts matches any of them', $r['status'] . ' ' . substr($r['raw'], 0, 300));
	$r = api_request('GET', '/api/v1/Locations?numperpage=50&loc_location_id=' . $ids['b'], $h);
	check($r['status'] === 200 && clf_ids($r, 'loc_location_id') === array($ids['b']), 'One value is still one value', $r['status'] . ' ' . substr($r['raw'], 0, 300));

	section('Refused with a 400');
	$many = implode('&', array_map(function ($i) { return 'loc_location_id[]=' . $i; }, range(1, 201)));
	$refusals = array(
		'a keyed list'                  => '/api/v1/Locations?loc_location_id[x]=' . $ids['a'],
		'a list in a list'              => '/api/v1/Locations?loc_location_id[][]=' . $ids['a'],
		'more than 200 values'          => '/api/v1/Locations?' . $many,
		'a value the column cannot hold' => '/api/v1/Locations?loc_location_id[]=' . $ids['a'] . '&loc_location_id[]=not-a-number',
		'a list on a password'          => '/api/v1/Users?usr_password[]=x&usr_password[]=y',
		'a list on an option of its own' => '/api/v1/Users?user_id[]=' . $admin->key . '&user_id[]=1',
	);
	foreach ($refusals as $label => $path) {
		$r = api_request('GET', $path, $h);
		check($r['status'] === 400, 'Refused: ' . $label, $r['status'] . ' ' . substr($r['raw'], 0, 300));
	}
} catch (Throwable $e) {
	check(false, 'Unexpected ' . get_class($e), $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
