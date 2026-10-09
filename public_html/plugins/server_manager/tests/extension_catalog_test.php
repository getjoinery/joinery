<?php
/** @joinery-test
 * name: extension_catalog
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The extension catalog (publish_theme ?list=) offers only what its download
 * (?download=) serves, at the version it serves. Every site's Marketplace and
 * every fresh install read the list and then download by name; when the list
 * named the live directory's version, an extension bumped since the last
 * release was offered everywhere and refused at install ("not in the upgrade
 * source's catalog").
 *
 * Run over HTTP against this site's own catalog. Each listed item is fetched
 * and its served filename must carry the listed version.
 *
 * Run: php plugins/server_manager/tests/extension_catalog_test.php [base_url] [origin_ip]
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/http.php');
harness_http_boot($argv);
harness_boot();

foreach (array('plugins' => 'plugin', 'themes' => 'theme') as $list => $type) {
	section('Every listed ' . $type . ' downloads at the listed version');

	$r = harness_request('GET', '/admin/server_manager/publish_theme?list=' . $list, array('accept' => null));
	$items = $r['json'][$list] ?? null;
	check($r['status'] === 200 && is_array($items), 'the ' . $type . ' catalog answers', 'status ' . $r['status']);
	if (!is_array($items)) {
		continue;
	}
	if (!$items) {
		harness_skip('this site has published no ' . $type);
		continue;
	}
	foreach ($items as $item) {
		$name = (string)($item['directory_name'] ?? $item['name'] ?? '');
		$version = (string)($item['version'] ?? '');
		$d = harness_request('GET', '/admin/server_manager/publish_theme?download=' . urlencode($name)
			. ($type === 'plugin' ? '&type=plugin' : ''), array('accept' => null));
		$served = harness_header_matches($d['headers'], '/^Content-Disposition:.*filename="' . preg_quote($name . '-' . $version . '.tar.gz', '/') . '"/i');
		check($d['status'] === 200 && $served, $type . ' ' . $name . ' ' . $version . ' is served as listed',
			'status ' . $d['status'] . (is_array($d['json']) ? ' ' . ($d['json']['error'] ?? '') : ''));
	}
}

harness_finish();
