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
 * @version 1.5 - and every method or constant they name on such a class is in the previous release, or guarded
 * @version 1.4 - the pre-swap deployment files name only classes every site has, or guard them
 * @version 1.3 - a site others upgrade from carries every package it serves
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


section('A publish converges the management node itself');

// The plane's own tree is the source and is never upgraded, so the host
// installers a release ships (the parser jail launcher, the agent artifact)
// would only reach this machine by hand. A publish queues the same
// run_plugin_installers job every other node gets from its upgrade, on this
// site's own paired agent. The job, not an inline exec: the agent verifies
// what it runs, and a job row is how work reaches root on a node.
check(strpos($publisher_src, 'ManagedNode::self_node()') !== false,
    'the publisher finds this site\'s own node record');
check(strpos($publisher_src, "JobCommandBuilder::build_run_plugin_installers(\$self_node)") !== false
    && strpos($publisher_src, "createFromBuild(\$self_node->key, 'run_plugin_installers'") !== false,
    'and queues run_plugin_installers on it after the archives are written');
check(preg_match('/^[^*\n]*_plugin_installers_start\.sh/m', $publisher_src) === 1
    && strpos($publisher_src, 'no paired agent of its own') !== false,
    'a site with no agent of its own is told the one command instead');

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


section('A site others upgrade from carries every package it serves');

// A release-serving site (getjoinery) republishes what is on its own disk. When
// its upgrade fetched only the plugins it had installed, every other plugin
// fell out of the releases it served, and each site running one kept its old
// copy against the new core — messenger 1.0.19 under 0.8.492 named a File
// constant that release had moved, and vault unlock fataled on that site.
check(strpos($helper_src, 'function servesReleases') !== false,
    'DeploymentHelper says whether this site serves releases',
    'upgrade.php asks it, and it travels with the self-updating set');
check(preg_match("/method_exists\('DeploymentHelper',\s*'servesReleases'\)\s*&&\s*DeploymentHelper::servesReleases\(\)/", $upgrader_src) === 1,
    'upgrade.php asks it, guarded for the pass that runs against old core');
check(preg_match('/array_diff\(\$source_published_plugin_names,\s*\$plugins_to_download\)/', $upgrader_src) === 1
    && preg_match('/array_diff\(\$source_published_theme_names,\s*\$themes_to_download\)/', $upgrader_src) === 1,
    'and such a site takes every published plugin and theme, not only the ones it has');


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

// The same rule for every class, not just the one it went wrong with first.
// These three run before the swap, against the site's OLD core: a class they
// name must be one every running site has, or be asked for with class_exists()
// first. SandboxPlugins, new in 0.8.493 and named unguarded from
// DeploymentHelper::preserveReason(), stopped getjoinery's upgrade from 0.8.492
// on the re-run after the self-update. update_database.php and
// DatabaseUpdater.php run after the swap, against the new core.
// Adding a name here asserts every site still running has the class.
$every_site_has = array(
    'PathHelper', 'Globalvars', 'DbConnector', 'LibraryFunctions', 'SessionControl', 'MultiUpgrade',
    'ClassAutoloader', 'PluginHelper', 'ThemeHelper', 'TransparencyProof', 'HostRestrictions',
);
$pre_swap = array('utils/upgrade.php', 'includes/DeploymentHelper.php', 'includes/PackageSignature.php');
$set_classes = array();
$named = array();
foreach ($deployment_set + array(99 => 'includes/PackageSignature.php') as $rel) {
    $tokens = token_get_all((string)@file_get_contents(PathHelper::getIncludePath($rel)));
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i])) continue;
        $prev = $i - 1;
        while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) $prev--;
        $next = $i + 1;
        while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) $next++;
        if (in_array($tokens[$i][0], array(T_CLASS, T_INTERFACE), true)
            && $next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
            $set_classes[$tokens[$next][1]] = true;
        }
        if (!in_array($rel, $pre_swap, true)) continue;
        if (!in_array($tokens[$i][0], array(T_STRING, T_NAME_FULLY_QUALIFIED), true)) continue;
        $is_static = $next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_DOUBLE_COLON;
        $is_new = $prev >= 0 && is_array($tokens[$prev]) && in_array($tokens[$prev][0], array(T_NEW, T_INSTANCEOF), true);
        if ($is_static || $is_new) {
            $named[ltrim($tokens[$i][1], '\\')][$rel] = true;
        }
    }
}
$unguarded = array();
foreach ($named as $class => $files) {
    if (in_array(strtolower($class), array('self', 'static', 'parent'), true)) continue;
    if (isset($set_classes[$class]) || in_array($class, $every_site_has, true)) continue;
    if (class_exists($class, false) || interface_exists($class, false)) {
        $reflect = new ReflectionClass($class);
        if ($reflect->isInternal()) continue;
    }
    foreach (array_keys($files) as $rel) {
        if (strpos((string)file_get_contents(PathHelper::getIncludePath($rel)), "class_exists('" . $class . "'") === false) {
            $unguarded[] = $class . ' in ' . $rel;
        }
    }
}
check($unguarded === array(),
    'the pre-swap deployment files name only classes every site has, or ask class_exists() first',
    $unguarded ? 'unguarded: ' . implode('; ', $unguarded) : '');

// The same for a method or constant on one of those classes: a site has the
// class but not what a release added to it (MarketplaceClient::is_root, 0.8.318).
// Each one named here must exist in the previous release, or be asked for
// with method_exists() / defined() first. Needs the repository; a tree
// without one (a node, a release staging copy) skips this half.
$repo_top = trim((string)@shell_exec('git -C ' . escapeshellarg(PathHelper::getIncludePath('')) . ' rev-parse --show-toplevel 2>/dev/null'));
$head = trim((string)@shell_exec('git -C ' . escapeshellarg($repo_top) . ' rev-parse HEAD 2>/dev/null'));
$floor = '';
foreach (explode("\n", (string)@shell_exec('git -C ' . escapeshellarg($repo_top) . " log --format=%H --grep='^Release ' -n 2 2>/dev/null")) as $release_commit) {
    $release_commit = trim($release_commit);
    if ($release_commit !== '' && $release_commit !== $head) { $floor = $release_commit; break; }
}
if ($repo_top === '' || $floor === '') {
    check(true, 'methods and constants the pre-swap files name: skipped, no repository to compare a release against');
} else {
    $members = array();   // "Class::member" => array(kind, files)
    foreach ($pre_swap as $rel) {
        $tokens = token_get_all((string)@file_get_contents(PathHelper::getIncludePath($rel)));
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], array(T_STRING, T_NAME_FULLY_QUALIFIED), true)) continue;
            $class = ltrim($tokens[$i][1], '\\');
            if (in_array(strtolower($class), array('self', 'static', 'parent'), true) || isset($set_classes[$class])) continue;
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
            if (!($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_COLON)) continue;
            $j++;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
            if (!($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) || strtolower($tokens[$j][1]) === 'class') continue;
            $member = $tokens[$j][1];
            $k = $j + 1;
            while ($k < $count && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) $k++;
            $kind = ($k < $count && $tokens[$k] === '(') ? 'method' : 'constant';
            $members[$class . '::' . $member] = array('class' => $class, 'member' => $member, 'kind' => $kind,
                'files' => array_merge($members[$class . '::' . $member]['files'] ?? array(), array($rel => true)));
        }
    }
    $missing = array();
    $old_sources = array();
    foreach ($members as $name => $m) {
        if (!class_exists($m['class'])) continue;   // the class-level check above owns this
        $reflect = new ReflectionClass($m['class']);
        if ($reflect->isInternal()) continue;
        $guarded = true;
        foreach (array_keys($m['files']) as $rel) {
            $src = (string)file_get_contents(PathHelper::getIncludePath($rel));
            $guard = $m['kind'] === 'method'
                ? "method_exists('" . $m['class'] . "', '" . $m['member'] . "')"
                : "defined('" . $m['class'] . '::' . $m['member'] . "')";
            if (strpos($src, "class_exists('" . $m['class'] . "'") === false && strpos($src, $guard) === false) {
                $guarded = false;
            }
        }
        if ($guarded) continue;
        $file = (string)$reflect->getFileName();
        if (strpos($file, $repo_top . '/') !== 0) continue;
        $repo_rel = substr($file, strlen($repo_top) + 1);
        if (!isset($old_sources[$repo_rel])) {
            $old_sources[$repo_rel] = (string)@shell_exec('git -C ' . escapeshellarg($repo_top) . ' show '
                . escapeshellarg($floor . ':' . $repo_rel) . ' 2>/dev/null');
        }
        $pattern = $m['kind'] === 'method'
            ? '/function\s+&?\s*' . preg_quote($m['member'], '/') . '\s*\(/i'
            : '/const\s+(?:[A-Za-z_?][A-Za-z0-9_|?]*\s+)?' . preg_quote($m['member'], '/') . '\s*=/';
        if (!preg_match($pattern, $old_sources[$repo_rel])) {
            $missing[] = $name . ' (' . $m['kind'] . ', ' . implode(', ', array_keys($m['files'])) . ')';
        }
    }
    check($members !== array(), 'the scan finds the methods and constants the pre-swap files name');
    check($missing === array(),
        'every method and constant they name on a class outside the set is in the previous release ('
        . substr($floor, 0, 8) . '), or guarded first',
        $missing ? 'not in that release: ' . implode('; ', $missing) : '');
}


section('One publish goes from a committed tree to a release');

// The owner's work is checked before publish writes anything, and publish
// commits and pushes the files it writes itself. As first built, the check
// ran after the writes, so every release refused once on its own VERSION,
// install SQL and version bumps and needed a second run by hand.
$at = function ($needle) use ($publisher_src) { $p = strpos($publisher_src, $needle); return $p === false ? -1 : $p; };
$owner_check = $at('publish_require_committed(publish_release_repos($full_site_dir), $version, false)');
$own_commit  = $at("publish_require_committed(publish_release_repos(\$full_site_dir), \$version, true, \$base_commits)");
check($owner_check > 0 && $owner_check < $at('publish_output("Wrote version') && $owner_check < $at('AgentDistPublisher::publish($full_site_dir'),
    'the owner\'s work is checked before anything is written');
check($own_commit > $at('Updated on-disk install SQL') && $own_commit > $at('$component_plan = publish_plan_components('),
    'publish\'s own files are committed after the last of them is written');
check($own_commit < $at('Create CORE archive'), 'and before anything is built from the commit');
check(strpos($publisher_src, 'ReleaseCommit::commitRelease(') !== false, 'publish commits and pushes its own files');


section('A release is built from the commit, not the working tree');

// Another session's uncommitted edit in the shared tree is left out of the
// release instead of stopping it, so nothing that ships may be read from the
// working tree: every archive source is the build directory, a copy of the
// commit (ReleaseCommit::exportTree()).
$export_a = $at("publish_build_tree(\$full_site_dir, \$base_commits['core'], \$version)");
$export_b = $at("publish_build_tree(\$full_site_dir, \$release_commits['core'], \$version)");
check($export_a > $owner_check && $export_a < $at('a default bundle names a plugin this release cannot carry'),
    'the commit just checked is copied out before the bundle guard reads it');
check($export_a < $at('SupportBundlePublisher::publish(') && $export_a < $at('$component_plan = publish_plan_components('),
    'and the support bundle and the version decisions read that copy');
check(strpos($publisher_src, "SupportBundlePublisher::publish(\$full_site_dir, 'publish_output', \$build_root)") !== false,
    'the support bundle copies its scripts from the commit');
// A bundle carried forward ships the previous release's scripts; no bundle at
// all ships nothing for a relay or a machine with no site, and 0.8.496 did (B70).
$no_bundle = $at('it would ship no support bundle');
check($no_bundle > $at('SupportBundlePublisher::publish(') && $no_bundle < $at('publish_output("Wrote version')
    && strpos(substr($publisher_src, $at('SupportBundlePublisher::publish('), max(0, $no_bundle - $at('SupportBundlePublisher::publish('))),
        "if (SupportBundlePublisher::hasConsumer()\n\t\t\t\t&& (SupportBundlePublisher::info(\$bundle_dir) === null") !== false,
    'a release that would ship no support bundle refuses before anything is written, a republish too');
check($export_b > $own_commit && $export_b < $at('Create CORE archive'),
    'the release commit is copied out once publish has made it, before anything is built');
foreach (array("\$build_root . '/public_html/'", "\$build_root . '/public_html/theme'", "\$build_root . '/public_html/plugins'",
        "\$build_root . '/maintenance_scripts/'") as $source) {
    check(strpos($publisher_src, $source) !== false, 'the archives read ' . $source);
}
check(strpos($publisher_src, "escapeshellarg(\$full_site_dir . '/public_html/')") === false
    && strpos($publisher_src, "\$theme_base_dir = \$full_site_dir") === false
    && strpos($publisher_src, "\$plugin_base_dir = \$full_site_dir") === false,
    'and none reads the working tree');
check(strpos($publisher_src, "TreeManifestPublisher::write(\$build_root, \$build_root, \$manifest_authority['keys'], null, \$full_site_dir)") !== false,
    'this site\'s own live manifest lists what was released, so an uncommitted edit here does not verify',
    'signing the working tree would have this site\'s agent run an uncommitted edit as root');
check(strpos($publisher_src, 'a commit landed in the {$name} repository while this publish ran') !== false,
    'publish commits only onto the commits it was built from, in either repository');
check(strpos($publisher_src, "AgentDistPublisher::publish(\$full_site_dir, 'publish_output',\n\t\t\t\tarray('dir' => publish_agent_build_tree(\$base_commits['agent'], \$version), 'commit' => \$base_commits['agent']))") !== false,
    'the agent is built from a copy of the agent source commit just checked, not its working tree');
check(strpos($publisher_src, "\$name === 'agent' || !ReleaseCommit::heldPath(\$path)") !== false,
    'so uncommitted agent work is left out of the release, not in the way');

// The deploy tier tests the code that ships: on a box with a release staging
// site, the commit is copied there and the tier runs there, so a file another
// session left half-edited in the working tree neither refuses the release
// nor stands in for a file the commit lacks.
$staged_fill = $at('publish_fill_staging($build_root, $staging_dir, $version)');
$staged_gate = $at("publish_deploy_gate(\$staging_dir . '/public_html/tests/run.php'");
check($staged_fill > $export_a && $staged_gate > $staged_fill,
    'with a staging site, the commit is copied into it and the deploy tier runs there');
check($staged_gate < $at('a default bundle names a plugin this release cannot carry') && $staged_gate < $at('Wrote version'),
    'before anything is written');
check($at("publish_deploy_gate(\$full_site_dir . '/public_html/tests/run.php'") > 0
    && $at('if ($staging_dir === null) {') < $at("publish_deploy_gate(\$full_site_dir . '/public_html/tests/run.php'"),
    'without one, the tier runs on the working tree as before');
check(strpos($publisher_src, "is_file(\$dir . '/config/release_staging')") !== false,
    'a directory is the staging site only when setup_release_staging.sh marked it, never a site of its own');


harness_finish();
