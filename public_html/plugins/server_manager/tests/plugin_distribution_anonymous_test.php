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
 * Only publish builds an archive: a version with none is a 404, and asking
 * never cuts one from the live directory. The fixture's archive is written
 * here and removed with it.
 *
 * Declared tier db (not safe): it writes a fixture theme into the live tree.
 *
 * Run: php plugins/server_manager/tests/plugin_distribution_anonymous_test.php
 *
 * @version 1.4.0 - the fixture is published before the listing checks: the catalog lists only published
 *                 extensions, so its audience is what is under test
 * @version 1.3.0 - a download serves only the published archive; the fixture archive is cleaned from the
 *                 directory the endpoint reads, not the static_files_dir setting
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
}
if (isset($by_name['mailbox'])) {
	check(empty($by_name['mailbox']['requires_entitlement']), 'free plugin carries no entitlement flag');
}

// A maturity badge reaches the catalog from the manifest. Derived rather than
// named: re-badging a plugin should be a manifest edit, not a test edit. It
// has to be a plugin badged something other than stable, or the check passes
// on the ?? 'stable' default and proves nothing.
$non_stable = null;
foreach (glob(PathHelper::getAbsolutePath('plugins') . '/*/plugin.json') as $manifest_file) {
	$manifest = json_decode((string)file_get_contents($manifest_file), true);
	$declared = is_array($manifest) ? ($manifest['status'] ?? null) : null;
	if ($declared !== null && $declared !== 'stable' && isset($by_name[basename(dirname($manifest_file))])) {
		$non_stable = array(basename(dirname($manifest_file)), $declared);
		break;
	}
}
if ($non_stable === null) {
	check(true, 'no plugin is badged below stable, so the badge path has nothing to carry');
} else {
	list($plugin_name, $declared) = $non_stable;
	check(($by_name[$plugin_name]['status'] ?? null) === $declared,
		'a manifest maturity badge reaches the catalog',
		$plugin_name . ' declares ' . $declared . ', catalog says '
			. var_export($by_name[$plugin_name]['status'] ?? null, true));
}

// ---------------------------------------------------------------------------
section('Audience keeps a theme out of the public catalog');
// ---------------------------------------------------------------------------

// A theme built for one customer's site should not be advertised to every
// other Joinery site. The fixture stands in for one: it declares an audience,
// so it is listed only for a caller claiming a site that audience names.
$fixture_dir = PathHelper::getAbsolutePath('theme') . '/audience_fixture_theme';
$fixture_manifest = $fixture_dir . '/theme.json';
$fixture_made = false;

if (!is_dir($fixture_dir)) {
	@mkdir($fixture_dir, 0777, true);
	$fixture_made = is_dir($fixture_dir);
}
if ($fixture_made) {
	// Remove the fixture however this test ends — a stray theme directory
	// would show up on the admin Themes page long after the run, and the
	// archive written below would stay fetchable on its own once the
	// directory is gone. The endpoint reads archives from the site root's
	// static_files/, so that is where it goes.
	$fixture_archive = PathHelper::getSiteRoot() . '/static_files/themes/audience_fixture_theme-1.0.0.tar.gz';
	register_shutdown_function(function () use ($fixture_dir, $fixture_manifest, $fixture_archive) {
		@unlink($fixture_manifest);
		@rmdir($fixture_dir);
		@unlink($fixture_archive);
	});
	file_put_contents($fixture_manifest, json_encode(array(
		'name' => 'Audience Fixture',
		'version' => '1.0.0',
		'description' => 'Temporary fixture for the audience catalog test.',
		'audience' => array('audience-fixture.example'),
	), JSON_PRETTY_PRINT));
	@chmod($fixture_manifest, 0666);
}

check($fixture_made, 'fixture theme directory created', $fixture_dir);

if ($fixture_made) {
	// The catalog lists only what has been published, so the fixture is
	// published first: what keeps it out of a listing below is its audience.
	$publish_fixture = function () use ($fixture_archive, $fixture_dir) {
		exec(sprintf('tar -czf %s -C %s audience_fixture_theme 2>&1', escapeshellarg($fixture_archive),
			escapeshellarg(dirname($fixture_dir))), $tar_out, $tar_exit);
		return $tar_exit === 0 && is_file($fixture_archive);
	};
	check($publish_fixture(), 'fixture archive written where publish puts archives');

	$r = harness_request('GET', '/admin/server_manager/publish_theme?list=themes');
	$anon_names = array_column($r['json']['themes'] ?? array(), 'directory_name');
	check(!in_array('audience_fixture_theme', $anon_names, true),
		'an audience-scoped theme is absent for a caller claiming no site');

	$r = harness_request('GET', '/admin/server_manager/publish_theme?list=themes&site=other.example');
	$other_names = array_column($r['json']['themes'] ?? array(), 'directory_name');
	check(!in_array('audience_fixture_theme', $other_names, true),
		'and absent for a site the audience does not name');
	check(in_array('joinery-system', $other_names, true),
		'while a theme with no audience stays listed for that same site');

	$r = harness_request('GET', '/admin/server_manager/publish_theme?list=themes&site=https://www.Audience-Fixture.example/');
	$named = array();
	foreach (($r['json']['themes'] ?? array()) as $entry) {
		$named[$entry['directory_name']] = $entry;
	}
	check(isset($named['audience_fixture_theme']),
		'a site the audience names sees it, however the domain is written');
	if (isset($named['audience_fixture_theme'])) {
		// The fixture manifest declares no status, so this is the defaulting
		// rule itself rather than a real extension that happens to be stable.
		check(($named['audience_fixture_theme']['status'] ?? null) === 'stable',
			'an absent manifest status reads as stable');
	}
	if (isset($named['audience_fixture_theme'])) {
		check(!empty($named['audience_fixture_theme']['unlisted']),
			'the catalog marks it unlisted so the marketplace can badge it');
		check(!isset($named['audience_fixture_theme']['audience']),
			'the catalog does not echo the audience list back to the caller');
	}

	// The root node is the origin these extensions are published from, so it
	// sees the whole catalog without every audience naming it. Without this,
	// each private theme would carry a line about the box the work is done
	// on, and forgetting that line would hide the theme from its own author.
	$root = MarketplaceClient::root_node();
	if ($root !== '') {
		$r = harness_request('GET', '/admin/server_manager/publish_theme?list=themes&site=' . urlencode($root));
		$root_names = array_column($r['json']['themes'] ?? array(), 'directory_name');
		check(in_array('audience_fixture_theme', $root_names, true),
			'the root node sees an audience-scoped theme its audience does not name',
			'root_node is ' . $root);
	} else {
		check(true, 'no root node named on this site — the origin rule is inert here');
	}

	// Only publish builds an archive. The fixture has never been published,
	// so it is not served, and asking must not tar the live directory into
	// static_files/ under its version's name.
	@unlink($fixture_archive);
	$r = harness_request('GET', '/admin/server_manager/publish_theme?download=audience_fixture_theme',
		array('accept' => null, 'timeout' => 120));
	check($r['status'] === 404, 'a version with no published archive is not served', 'status ' . $r['status']);
	check(!is_file($fixture_archive), 'and asking does not cut one from the live directory');

	// Listing visibility is not access control: once published, the download
	// stays open by name, which is what clone/restore reconciliation depends on.
	check($publish_fixture(), 'published again');
	$published_bytes = (string)@file_get_contents($fixture_archive);
	$r = harness_request('GET', '/admin/server_manager/publish_theme?download=audience_fixture_theme',
		array('accept' => null, 'timeout' => 120));
	check($r['status'] === 200, 'an unlisted theme still downloads by name (deliberate)',
		'status ' . $r['status']);
	check($r['body'] === $published_bytes, 'and what is served is the published archive, byte for byte');
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
