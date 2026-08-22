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
 * @version 1.2
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


section('Only the site that authored the code may mint a version');

// The rule itself, exhaustively — the site running these tests authors its own
// code, so the refusal never happens here and would otherwise go unexercised.
// The question is local: is the running tree exactly what upstream delivered?
check(DeploymentHelper::mintingAllowed('', '0.8.319'),
    'a site that has never received an upgrade mints',
    'it authored what it is running; this is every independent deployment');
check(!DeploymentHelper::mintingAllowed('0.8.319', '0.8.319'),
    'a site running exactly what upstream delivered does not',
    'publishing there means serve what I received, under the number it already has');
check(DeploymentHelper::mintingAllowed('0.8.319', '0.8.320'),
    'a site whose tree has moved past what it received mints again',
    'the difference is local authorship, which is the thing being asked about');
check(!DeploymentHelper::mintingAllowed(' 0.8.319 ', '0.8.319'),
    'whitespace in a stored version does not read as new work');
check(!DeploymentHelper::mintingAllowed('0.8.319', ''),
    'a site that received something but cannot read its running version does not mint',
    'it cannot show authorship, and minting is the unrecoverable direction');
check(DeploymentHelper::mintingAllowed('', ''),
    'a site that never received anything mints even with no readable version',
    'there is nothing it could be republishing');

// The rule needs no configuration on a node, deliberately. Requiring
// root_node there would mean every node had to be told which estate it is
// in, and on 2026-08-22 seven of nine production nodes had not been — being
// told is a thing that can be missed, and a missed answer reads as
// permission to mint. The one topological read is the origin escape, which
// costs nodes nothing: their root_node is empty, isOriginNode() is false,
// and the local rule decides. On the origin it means minting does not hang
// on never having received an upgrade.
$mint_start = strpos($helper_src, 'function mayMintReleaseVersion');
$mint_end   = strpos($helper_src, 'function mintingAllowed');
$mint_src   = ($mint_start === false || $mint_end === false) ? ''
    : substr($helper_src, $mint_start, $mint_end - $mint_start);
check($mint_src !== '', 'the minting rule is readable');
check(strpos($mint_src, 'root_node') === false,
    'the refusal side of the rule is local, not topological',
    'a rule that needs configuring on every node fails wherever it was not');
check(strpos($mint_src, 'upgrade_received_version') !== false,
    'and reads what upstream last delivered here');
check(strpos($mint_src, 'isOriginNode') !== false,
    'the origin always mints, whatever it has received',
    'so a restore or an accidental self-upgrade cannot silently strand dev');

// Placement is the whole point. An explicitly supplied version skips the
// auto-detect block, so a check living inside it protects nothing — which is
// exactly how a relay came to publish a number the origin had not reached.
$mint_at   = strpos($publisher_src, 'mayMintReleaseVersion');
$detect_at = strpos($publisher_src, 'Auto-detect next version if not specified');
check($mint_at !== false, 'the publisher asks whether it may mint at all');
check($mint_at !== false && $detect_at !== false && $mint_at < $detect_at,
    'and asks before the auto-detect block, which an explicit version skips');
check(substr_count($publisher_src, 'mayMintReleaseVersion') >= 3,
    'on the CLI path, the web handler, and the form that offers the number',
    'the dashboard is the documented publish route, so a CLI-only guard is half a guard');
check(strpos($publisher_src, 'Nothing has been written') !== false,
    'and it refuses before writing anything');


section('The self-updating deployment files call only each other');

// upgrade.php, update_database.php, DatabaseUpdater.php and DeploymentHelper.php
// replace themselves AHEAD of the rest of a release, so on that pass they run
// against the site's OLD core. A call to anything a release adds to a core class
// is an undefined method there — and it aborts the upgrade before the release
// that would fix it can land, which takes hand-editing a file on every node to
// recover. Verified the expensive way on 2026-08-22.
$deployment_set = array(
    'utils/upgrade.php',
    'utils/update_database.php',
    'includes/DatabaseUpdater.php',
    'includes/DeploymentHelper.php',
);

check(strpos($upgrader_src, "'utils/upgrade.php',") !== false
    && strpos($upgrader_src, "'includes/DeploymentHelper.php',") !== false,
    'the self-update list still names the set this check assumes');

// MarketplaceClient is core and does NOT self-update, so it is the specific
// class this went wrong with; the origin predicate lives on DeploymentHelper
// for exactly that reason.
foreach ($deployment_set as $rel) {
    $path = PathHelper::getIncludePath($rel);
    $src  = is_file($path) ? (string)file_get_contents($path) : '';
    check($src !== '', "$rel is readable", $path);
    check(strpos($src, 'MarketplaceClient::') === false,
        "$rel does not call MarketplaceClient",
        'it is core, it does not travel with these files, and the call fatals mid-upgrade');
}

check(strpos($helper_src, 'function isOriginNode') !== false,
    'DeploymentHelper owns the origin predicate, so upgrade.php can ask it');


harness_finish();
