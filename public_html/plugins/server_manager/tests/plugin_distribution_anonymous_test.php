<?php
/** @joinery-test
 * name: plugin_distribution_anonymous
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Plugin distribution catalog and the deliberately ungated download.
 *
 * The catalog (?list=plugins) must carry the licensing metadata the
 * marketplace and installer read: license, maturity status, and
 * requires_entitlement.
 *
 * The download branch serving an entitlement-requiring plugin to an
 * anonymous caller is asserted ON PURPOSE. Selling is live but enforcement
 * is deferred (dev releases are delivered by pointing upgrade_source at dev,
 * and a gate would break every unlicensed site doing that), so today the
 * endpoint serves everything anonymously by design. When the entitlement
 * gate lands, this check is the one that must be flipped — the gate should
 * arrive as an intentional behavior change, not an accident.
 *
 * Declared tier db (not safe): a download regenerates the server-side
 * archive cache under static_files/ when the source tree is newer.
 *
 * Run: php plugins/server_manager/tests/plugin_distribution_anonymous_test.php
 *
 * @version 1.0.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/http.php');
harness_boot();

// ---------------------------------------------------------------------------
section('Catalog metadata');
// ---------------------------------------------------------------------------

$r = harness_request('GET', '/admin/server_manager/publish_theme?list=plugins');
check($r['status'] === 200, 'plugin catalog answers anonymously', 'status ' . $r['status']);
check(!empty($r['json']['success']), 'catalog reports success');

$by_name = array();
foreach (($r['json']['plugins'] ?? array()) as $entry) {
	$by_name[$entry['name']] = $entry;
}
check(isset($by_name['store']), 'store is in the published catalog');

if (isset($by_name['store'])) {
	check(($by_name['store']['license'] ?? null) === 'Joinery-Commercial',
		'store lists its commercial license');
	check(!empty($by_name['store']['requires_entitlement']),
		'store declares requires_entitlement in the catalog');
	check(($by_name['store']['status'] ?? null) === 'stable',
		'absent manifest status reads as stable');
}
if (isset($by_name['mailbox'])) {
	check(($by_name['mailbox']['status'] ?? null) === 'beta', 'mailbox status flows into the catalog');
	check(empty($by_name['mailbox']['requires_entitlement']), 'free plugin carries no entitlement flag');
}

// ---------------------------------------------------------------------------
section('Download is deliberately ungated');
// ---------------------------------------------------------------------------

// DELIBERATE: no credentials, no key — and the entitled plugin still serves.
// Enforcement is deferred by owner decision (2026-07-30). When the gate is
// built, this becomes a 402 and these checks get rewritten with it.
$r = harness_request('GET', '/admin/server_manager/publish_theme?download=store&type=plugin',
	array('accept' => null, 'timeout' => 120));
check($r['status'] === 200, 'entitled plugin serves anonymously (deliberate — see header)',
	'status ' . $r['status']);
check(strpos((string)$r['content_type'], 'gzip') !== false,
	'download is a gzip archive', (string)$r['content_type']);

$r = harness_request('GET', '/admin/server_manager/publish_theme?download=event_manager&type=plugin',
	array('accept' => null, 'timeout' => 120));
check($r['status'] === 200, 'free plugin serves anonymously', 'status ' . $r['status']);

harness_finish();
