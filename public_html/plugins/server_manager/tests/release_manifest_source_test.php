<?php
/** @joinery-test
 * name: release_manifest_source
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Reading a signed release manifest back out of a published archive.
 *
 * This is the plane's half of manifest recovery. A node whose own
 * RELEASE_MANIFEST has become unusable refuses every script primitive it has,
 * apply_update among them, so it cannot repair itself through the agent — the
 * upgrade that would fix it is refused by the check that is failing. Serving the
 * signed manifest over the channel is what lets it out, and it is safe for this
 * plane to serve because the signature was made by a key this plane does not
 * hold and is checked by one compiled into the node's own binary.
 *
 * Two things are pinned.
 *
 * PATH SAFETY, because this is a root-adjacent endpoint that takes a name from
 * the network. The node names an artifact and a version; every path is built
 * here. Nothing a node can send may select a file outside the published
 * archives, and the checks are asserted directly rather than inferred from a
 * NULL that might be NULL for some other reason.
 *
 * THE BYTES, because a manifest that does not verify is worse than none: it
 * would be fetched, refused, and refetched. What comes out of the archive must
 * be byte-identical to what was signed, which is checked here against the
 * release public key this plane publishes alongside the agent.
 *
 * Run: php plugins/server_manager/tests/release_manifest_source_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ReleaseManifestSource.php'));

// ---------------------------------------------------------------------------
section('What counts as an artifact this plane will resolve');

check(ReleaseManifestSource::valid_owner('') === true, 'the empty owner is the core release');
check(ReleaseManifestSource::valid_owner('public_html/plugins/server_manager') === true,
	'a plugin is a valid owner');
check(ReleaseManifestSource::valid_owner('public_html/theme/canvas') === true,
	'a theme is a valid owner');

// Everything below is a name a hostile or broken node could send. None may
// resolve to anything: an owner is a plugin or a theme, and nothing else.
foreach ([
	'../../etc',
	'public_html/plugins/../../../etc',
	'public_html/plugins/server_manager/../../..',
	'/etc/passwd',
	'public_html/utils',
	'public_html/plugins',
	'public_html/plugins/',
	'public_html/plugins/a/b',
	'public_html/theme/../plugins/x',
	'config',
	'public_html/plugins/' . str_repeat('a', 65),   // 64 is the cap, 65 is over it
] as $bad) {
	check(ReleaseManifestSource::valid_owner($bad) === false,
		'refused as an owner: ' . $bad);
}

check(ReleaseManifestSource::valid_version('0.8.370') === true, 'a core version is a version');
check(ReleaseManifestSource::valid_version('1.21.6') === true, 'a plugin version is a version');
check(ReleaseManifestSource::valid_version('1.2') === true, 'a two-part version is a version');

foreach ([
	'', '0.8.370/../../etc', '../0.8.370', '0.8.370; rm -rf /', 'latest',
	'0.8.370.1.2.3', 'v0.8.370', '0.8.370-beta', '*',
] as $bad) {
	check(ReleaseManifestSource::valid_version($bad) === false,
		'refused as a version: ' . var_export($bad, true));
}

// A traversal that somehow passed the owner check must still not produce a
// path. Belt and braces on purpose: this is the one place where a name from the
// network meets the filesystem.
check(ReleaseManifestSource::read('../../etc', '0.8.370') === null,
	'a traversal owner reads nothing');
check(ReleaseManifestSource::read('', '../../../etc/passwd') === null,
	'a traversal version reads nothing');

// ---------------------------------------------------------------------------
section('A version this plane does not have');

check(ReleaseManifestSource::read('', '9.99.999') === null,
	'an unpublished core version is nothing to offer, not an error');
check(ReleaseManifestSource::read('public_html/plugins/server_manager', '99.99.99') === null,
	'an unpublished plugin version is nothing to offer either');
check(ReleaseManifestSource::read('public_html/plugins/no_such_plugin_here', '1.0.0') === null,
	'a plugin this plane never published is nothing to offer');

// ---------------------------------------------------------------------------
section('The bytes that come back');

// Whatever this plane has most recently published. Written against the archives
// actually on disk rather than a pinned version, so the test keeps working
// across releases and still fails if extraction breaks.
$static = PathHelper::getSiteRoot() . '/static_files';
$cores  = glob($static . '/joinery-core-*.tar.gz') ?: [];
usort($cores, function ($a, $b) { return filemtime($a) <=> filemtime($b); });
$newest = $cores ? basename(end($cores)) : '';
$core_version = preg_match('/joinery-core-(.+)\.tar\.gz$/', $newest, $m) ? $m[1] : '';

if ($core_version === '') {
	check(true, 'no core archive on this box — extraction not exercised (skipped)');
} else {
	$pair = ReleaseManifestSource::read('', $core_version);
	check(is_array($pair), 'the newest published core release yields a manifest pair', $newest);

	if (is_array($pair)) {
		check(strlen($pair['manifest']) > 1000,
			'the manifest has the bulk of a real one', strlen($pair['manifest']));
		check(strpos($pair['manifest'], 'Joinery release manifest') !== false,
			'and is the release manifest, not some other member of the archive');
		check(strlen(trim($pair['signature'])) > 40 && strlen(trim($pair['signature'])) < 200,
			'the signature is one detached Ed25519 signature', strlen(trim($pair['signature'])));

		// The proof that matters. A manifest extracted with one byte wrong
		// verifies against nothing, and a node would refuse it forever.
		$dist = PathHelper::getIncludePath('agent_dist');
		$dm   = json_decode((string)@file_get_contents($dist . '/manifest.json'), true);
		$pub  = (string)($dm['signing_public_key'] ?? '');
		if ($pub === '') {
			check(true, 'this plane publishes no agent signing key — signature not checked (skipped)');
		} else {
			$ok = sodium_crypto_sign_verify_detached(
				base64_decode(trim($pair['signature'])), $pair['manifest'], base64_decode($pub));
			check($ok === true,
				'the extracted manifest verifies against the release key, byte for byte');
		}

		// Extraction must not depend on where in the archive the member sits.
		$again = ReleaseManifestSource::read('', $core_version);
		check($again['manifest'] === $pair['manifest'], 'extraction is stable across calls');
	}
}

// A plugin archive keeps its pair under the component's own directory rather
// than at the top level; getting that wrong yields NULL for every plugin.
$plugs = glob($static . '/plugins/server_manager-*.tar.gz') ?: [];
usort($plugs, function ($a, $b) { return filemtime($a) <=> filemtime($b); });
$pv = $plugs && preg_match('/server_manager-(.+)\.tar\.gz$/', basename(end($plugs)), $m) ? $m[1] : '';
if ($pv === '') {
	check(true, 'no server_manager archive on this box — plugin extraction not exercised (skipped)');
} else {
	$pp = ReleaseManifestSource::read('public_html/plugins/server_manager', $pv);
	check(is_array($pp), 'a plugin archive yields its own manifest pair', $pv);
	if (is_array($pp)) {
		check(strpos($pp['manifest'], 'Joinery release manifest') !== false,
			'a plugin ships a manifest of the same shape');
		check($pp['manifest'] !== ($pair['manifest'] ?? ''),
			'and it is the plugin own manifest, not core one carried across');
	}
}

harness_finish();
