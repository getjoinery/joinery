<?php
/** @joinery-test
 * name: publish_promises
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * A publish states two things it has historically failed to deliver.
 *
 * It says a fresh install will come up with the default bundle — and shipped
 * releases whose plugins the release site did not carry, so every install came
 * up without them. And it stamps a version number — while auto-bumping from
 * whichever site happened to run the command, so two sites minted the same
 * number from different trees, one with a security fix and one without.
 *
 * Both are silent failures by nature: nothing downstream can tell that a
 * promise was broken, because the promise and the delivery were never compared.
 * These checks pin the comparisons that now happen.
 *
 * See specs/publish_delivers_what_it_promises.md.
 *
 * Run:  php tests/unit/publish_promises_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');

harness_boot();

$root      = dirname(__DIR__, 2);            // public_html
$site_root = dirname($root);                 // the deployment root

$publisher   = $root . '/plugins/server_manager/includes/publish_upgrade.php';
$upgrader    = $root . '/utils/upgrade.php';
$bundle_tool = $site_root . '/maintenance_scripts/sysadmin_tools/install_bundle.php';
$helper      = $root . '/includes/DeploymentHelper.php';

$publisher_src   = is_file($publisher)   ? file_get_contents($publisher)   : '';
$upgrader_src    = is_file($upgrader)    ? file_get_contents($upgrader)    : '';
$bundle_tool_src = is_file($bundle_tool) ? file_get_contents($bundle_tool) : '';
$helper_src      = is_file($helper)      ? file_get_contents($helper)      : '';


section('A fresh install can obtain the plugins its bundle names');

check($bundle_tool_src !== '', 'the bundle tool exists', $bundle_tool);

// The core archive ships an empty plugins directory, so activating what is
// already on disk activates nothing. The files have to be fetched first.
check(strpos($bundle_tool_src, 'ib_fetch_missing') !== false,
    'the bundle tool fetches plugins that are not on disk',
    'a published core archive ships no plugin files at all');
check(strpos($bundle_tool_src, 'serve-upgrade=1') !== false,
    'and gets them from the published-archives manifest',
    'the same source an upgrade uses, and anonymous like the core fetch');
check(strpos($bundle_tool_src, 'upgrade_source') !== false,
    'resolving the release site from upgrade_source');

// One implementation of download-and-unpack, not two that drift.
check(strpos($helper_src, 'function downloadAndExtract') !== false,
    'download-and-unpack lives in DeploymentHelper',
    'upgrade.php cannot be included to borrow it — requiring it runs an upgrade');
check(strpos($bundle_tool_src, 'DeploymentHelper::downloadAndExtract') !== false
    && strpos($upgrader_src, 'DeploymentHelper::downloadAndExtract') !== false,
    'and both callers use it');
check(!preg_match('/function\s+download_and_extract\s*\(/', $upgrader_src),
    'upgrade.php no longer carries its own copy');


section('A publish refuses to promise a bundle it cannot deliver');

check(strpos($publisher_src, 'install_bundles.json') !== false,
    'the publisher reads the bundle declaration');
check(strpos($publisher_src, 'a default bundle names a plugin this release cannot carry') !== false,
    'and refuses to publish when a named plugin cannot be carried',
    'a warning would scroll past; this is the whole failure mode');

// It tests the same three conditions the archive loop uses to decide whether an
// archive gets built, so the guard predicts the outcome rather than guessing.
check(strpos($publisher_src, 'included_in_publish') !== false
    && strpos($publisher_src, 'is marked deprecated') !== false,
    'checking presence, included_in_publish and deprecated');

// Placement is the point: refusing after the archives are written leaves a
// bumped VERSION and a release row behind, so the guard runs where the license
// check runs — before anything is written.
$license_at = strpos($publisher_src, 'LICENSE.md is missing or empty');
$bundle_at  = strpos($publisher_src, 'a default bundle names a plugin this release cannot carry');
$version_at = strpos($publisher_src, 'Wrote version');
check($license_at !== false && $bundle_at !== false && $version_at !== false
    && $bundle_at > $license_at && $bundle_at < $version_at,
    'and it refuses before the first write, beside the license check',
    'aborting later would leave a bumped VERSION and a saved release row');
check(preg_match('/a default bundle names a plugin this release cannot carry.*?Nothing has been written/s', $publisher_src) === 1,
    'saying plainly that nothing has been written');


section('A version number identifies one build');

check(strpos($upgrader_src, 'upgrade_received_version') !== false,
    'an upgrade records the version it received',
    'without it, a relay cannot tell authored code from delivered code');
check(strpos($publisher_src, 'upgrade_received_version') !== false,
    'and the publisher reads it back');

// The bug: auto-bump reads the local VERSION, every site runs the same code,
// so two sites eventually mint the same number from different trees.
check(preg_match('/\$received !== .. && \$current !== .. && \$received === \$current/', $publisher_src) === 1,
    'a tree matching what upstream delivered republishes that number',
    'rather than minting one for code it did not author');
check(strpos($publisher_src, 'Republishing') !== false,
    'and says so, rather than silently choosing');

// upgrade_source records where a site was installed from, not who authors its
// code — dev's points at getjoinery. Anything keying off it is wrong.
check(!preg_match("/upgrade_source[^\n]*\?\?[^\n]*relay|relay[^\n]*upgrade_source/i", $publisher_src),
    'role is not inferred from upgrade_source',
    "dev's upgrade_source is getjoinery, so it cannot carry this");


harness_finish();
