<?php
/** @joinery-test
 * name: rollback_previous_release
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * A rollback puts the whole previous release back (utils/upgrade.php 1.22,
 * DeploymentHelper 1.10), and what composer installs is moded like the tree
 * (ComposerValidator 1.5).
 *
 * A deploy replaces three things at the site root: public_html, the
 * maintenance_scripts it syncs in, and the signed RELEASE_MANIFEST(.sig). A
 * rollback that put back public_html alone left the new release's manifest
 * over the old code; the agent verifies every script it runs as root against
 * that manifest, so the restored upgrade.php no longer matched it and no later
 * apply could run (getjoinery-orgs, 0.8.495, job 2774). So the deploy keeps
 * the previous maintenance_scripts and manifest pair under
 * public_html_last/.release_last before touching either, and every rollback
 * restores them with the code.
 *
 * The same rollout found vendor/ files the pool owned or could write, written
 * by composer after the permission pass: composer now runs under umask 022 and
 * the pass runs again after the composer step.
 *
 * Run: php tests/run.php --only=tests/unit/rollback_previous_release_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$root = harness_scratch_dir('rollback_previous_release');
exec('rm -rf ' . escapeshellarg($root) . '/*');

/** A fake site root as the deploy finds it after the swap: old scripts and manifest at the root, an empty backup dir. */
function rpr_site($root, $name, $with_manifest = true) {
	$site = "$root/$name";
	@mkdir("$site/maintenance_scripts/install_tools", 0770, true);
	@mkdir("$site/maintenance_scripts/local", 0770, true);
	@mkdir("$site/public_html", 0770, true);
	@mkdir("$site/public_html_last", 0770, true);
	file_put_contents("$site/maintenance_scripts/install_tools/fix_permissions.sh", "OLD fix\n");
	chmod("$site/maintenance_scripts/install_tools/fix_permissions.sh", 0755);
	file_put_contents("$site/maintenance_scripts/local/mine.sh", "a node's own script\n");
	if ($with_manifest) {
		file_put_contents("$site/RELEASE_MANIFEST", "OLD manifest\n");
		file_put_contents("$site/RELEASE_MANIFEST.sig", "OLD sig\n");
	}
	return $site;
}
/** What the deploy then does to the site root: new script bytes, a script the new release adds, the new manifest. */
function rpr_deploy_new_release($site) {
	file_put_contents("$site/maintenance_scripts/install_tools/fix_permissions.sh", "NEW fix\n");
	file_put_contents("$site/maintenance_scripts/install_tools/added_by_new.sh", "NEW only\n");
	file_put_contents("$site/RELEASE_MANIFEST", "NEW manifest\n");
	file_put_contents("$site/RELEASE_MANIFEST.sig", "NEW sig\n");
}

// ---------------------------------------------------------------------------
section('The deploy keeps the previous release beside the rollback backup');

$site = rpr_site($root, 'kept');
$k = DeploymentHelper::keepPreviousRelease($site, "$site/public_html_last");
ok('scripts and the manifest pair are kept, nothing failed',
	$k['kept'] === array('maintenance_scripts', 'RELEASE_MANIFEST', 'RELEASE_MANIFEST.sig') && $k['failed'] === array(), json_encode($k));
$keep = "$site/public_html_last/" . DeploymentHelper::PREVIOUS_RELEASE_DIR;
ok('kept under public_html_last/.release_last, so a backup never archives it and a completed upgrade removes it',
	DeploymentHelper::PREVIOUS_RELEASE_DIR === '.release_last' && is_dir("$keep/maintenance_scripts/install_tools"));
ok('the kept script is the old bytes with its mode',
	file_get_contents("$keep/maintenance_scripts/install_tools/fix_permissions.sh") === "OLD fix\n"
	&& (fileperms("$keep/maintenance_scripts/install_tools/fix_permissions.sh") & 0111) !== 0);
ok('the kept manifest pair is the old bytes',
	file_get_contents("$keep/RELEASE_MANIFEST") === "OLD manifest\n" && file_get_contents("$keep/RELEASE_MANIFEST.sig") === "OLD sig\n");

$k2 = DeploymentHelper::keepPreviousRelease($site, "$site/public_html_last");
ok('keeping again starts clean (a stale kept copy is never mixed with a fresh one)', $k2 === $k);

$bare = rpr_site($root, 'nomanifest', false);
$k3 = DeploymentHelper::keepPreviousRelease($bare, "$bare/public_html_last");
ok('a site with no manifest keeps its scripts and reports no failure for what it never had',
	$k3['kept'] === array('maintenance_scripts') && $k3['failed'] === array(), json_encode($k3));

// ---------------------------------------------------------------------------
section('A rollback puts the previous release back as one unit');

rpr_deploy_new_release($site);
$r = DeploymentHelper::restorePreviousRelease($site, "$site/public_html_last");
ok('everything kept is reported restored, nothing failed',
	$r['restored'] === array('maintenance_scripts', 'RELEASE_MANIFEST', 'RELEASE_MANIFEST.sig') && $r['failed'] === array(), json_encode($r));
ok('the replaced script is the old bytes again', file_get_contents("$site/maintenance_scripts/install_tools/fix_permissions.sh") === "OLD fix\n");
ok("a node's own script is untouched", file_get_contents("$site/maintenance_scripts/local/mine.sh") === "a node's own script\n");
ok('a script the failed release added stays, inert (no deletion, as the deploy syncs)',
	file_get_contents("$site/maintenance_scripts/install_tools/added_by_new.sh") === "NEW only\n");
ok('the manifest pair at the site root is the old release\'s: the restored code matches it',
	file_get_contents("$site/RELEASE_MANIFEST") === "OLD manifest\n" && file_get_contents("$site/RELEASE_MANIFEST.sig") === "OLD sig\n");

$fresh = rpr_site($root, 'nothingkept');
rpr_deploy_new_release($fresh);
$r2 = DeploymentHelper::restorePreviousRelease($fresh, "$fresh/public_html_last");
ok('nothing kept (a backup from before this release) is a quiet no-op',
	$r2 === array('restored' => array(), 'failed' => array())
	&& file_get_contents("$fresh/RELEASE_MANIFEST") === "NEW manifest\n");

// ---------------------------------------------------------------------------
section('Where upgrade.php and performRollback apply it');

$src = file_get_contents(PathHelper::getIncludePath('utils/upgrade.php'));
$keep_at = strpos($src, 'DeploymentHelper::keepPreviousRelease(');
$scripts_at = strpos($src, "Deploying maintenance_scripts/");
$manifest_at = strpos($src, "foreach (array('RELEASE_MANIFEST', 'RELEASE_MANIFEST.sig') as \$mf)");
$swap_at = strpos($src, 'DeploymentHelper::swapLiveTree($live_directory');
ok('the previous release is kept after the code swap and before maintenance_scripts or the manifest are replaced',
	$swap_at !== false && $keep_at !== false && $scripts_at !== false && $manifest_at !== false
	&& $swap_at < $keep_at && $keep_at < $scripts_at && $keep_at < $manifest_at);
ok('a keep that fails rolls back and stops (a deploy that cannot be undone is not carried on with)',
	preg_match('/keepPreviousRelease\(.*?\n(.*?)exit\(1\);/s', $src, $m) === 1 && strpos($m[1], 'performRollback') !== false
	&& strpos($m[1], "apply_result_rolled_back('keep_previous_release'") !== false);
ok('every rollback goes through performRollback, which restores it',
	substr_count($src, 'DeploymentHelper::performRollback(') >= 4);

$dh = file_get_contents(PathHelper::getIncludePath('includes/DeploymentHelper.php'));
$rollback_fn = strpos($dh, 'public static function performRollback(');
$rsync_at = strpos($dh, "rsync -a --delete --exclude=/%s", $rollback_fn);
$restore_at = strpos($dh, 'self::restorePreviousRelease(', $rollback_fn);
$perms_at = strpos($dh, 'self::fixPermissions($target_site)', $rollback_fn);
ok('performRollback leaves the kept copy out of public_html and restores it before the permission pass',
	$rsync_at !== false && $restore_at !== false && $perms_at !== false && $rsync_at < $restore_at && $restore_at < $perms_at);
// A backup holding only the upgrade's holding script (a run killed mid-swap
// and run again) is refused before anything is touched: restored with
// --delete it would leave the site with no code.
$guard_at = strpos($dh, 'if (!self::backupIsRelease($backup_dir))', $rollback_fn);
$preserve_at = strpos($dh, 'Step 1 — best-effort preserve', $rollback_fn);
ok('performRollback refuses a backup that holds no release before it touches public_html',
	$guard_at !== false && $preserve_at !== false && $guard_at < $preserve_at && $guard_at < $rsync_at);

// ---------------------------------------------------------------------------
section('What composer installs is moded like the tree');

$composer_step = strpos($src, "out_step('Validating Composer Dependencies')");
$first_fix = strpos($src, 'upgrade_fix_permissions($full_site_dir, $site_template, $root_prefix, $verbose);');
$second_fix = strpos($src, 'upgrade_fix_permissions($full_site_dir, $site_template, $root_prefix, $verbose);', $first_fix + 1);
$tier_at = strpos($src, "' deploy 2>&1'");
ok('the permission pass runs after the swap and again after the composer step, before the deploy tier',
	$composer_step !== false && $first_fix !== false && $second_fix !== false && $tier_at !== false
	&& $first_fix < $composer_step && $composer_step < $second_fix && $second_fix < $tier_at);
ok('there is no chmod fallback in the pass', preg_match('/function upgrade_fix_permissions\(.*?\n\t}\n/s', $src, $fm) === 1 && strpos($fm[0], 'chmod') === false);

$cv = new ReflectionMethod('ComposerValidator', 'composerEnvPrefix');
$cv->setAccessible(true);
$prefix = $cv->invoke(new ComposerValidator());
ok('composer runs under umask 022, whatever the caller\'s umask', strpos($prefix, 'umask 022 && ') === 0, $prefix);
ok('and still with its own COMPOSER_HOME', strpos($prefix, 'COMPOSER_HOME=') !== false);

exec('rm -rf ' . escapeshellarg($root) . '/*');
harness_finish();
