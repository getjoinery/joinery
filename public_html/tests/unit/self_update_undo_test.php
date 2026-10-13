<?php
/** @joinery-test
 * name: self_update_undo
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The self-update undo (utils/upgrade.php 1.15, DeploymentHelper 1.5).
 *
 * A self-update copies the release's deployment files over the live ones before
 * anything else is checked. A run that then stops short of a deploy leaves the
 * old release with new deployment files in it; they no longer match the old
 * release's signed manifest, and the node's agent refuses every later apply as
 * a modified file, which no release can repair. So the files must go back
 * byte for byte whenever the live VERSION is still the old one.
 *
 * Run: php tests/unit/self_update_undo_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$root = harness_scratch_dir('self_update_undo');
exec('rm -rf ' . escapeshellarg($root) . '/*');

/** A fresh fake site: live tree on $version, a staged copy of the next release, and what the self-update would replace. */
function sud_site($root, $name, $version = '0.8.475') {
	$live = "$root/$name/public_html";
	$stage = "$root/$name/upgrades";
	@mkdir("$live/utils", 0770, true);
	@mkdir("$live/includes", 0770, true);
	@mkdir("$stage/public_html", 0770, true);
	file_put_contents("$live/VERSION", "$version\n");
	file_put_contents("$live/utils/upgrade.php", "OLD upgrade\n");
	file_put_contents("$live/includes/DeploymentHelper.php", "OLD helper\n");
	chmod("$live/utils/upgrade.php", 0640);
	return array($live, $stage, "$root/$name/.upgrade.lock");
}
/** What the self-update does to the tree: new bytes in, plus a file that did not exist. */
function sud_selfupdate($live, $stage) {
	file_put_contents("$live/utils/upgrade.php", "NEW upgrade\n");
	file_put_contents("$live/includes/DeploymentHelper.php", "NEW helper\n");
	file_put_contents("$live/includes/PackageSignature.php", "NEW signature\n");
	file_put_contents("$stage/public_html/VERSION", "0.8.477\n");
}
const SUD_FILES = array('utils/upgrade.php', 'includes/DeploymentHelper.php', 'includes/PackageSignature.php');

// ---------------------------------------------------------------------------
section('A run that stops without deploying puts the files back');

list($live, $stage, $lock) = sud_site($root, 'stopped');
$kept = DeploymentHelper::keepSelfUpdateOriginals($live, $lock, SUD_FILES);
ok('the live VERSION is recorded with the bytes', $kept['version'] === '0.8.475' && $kept['files']['includes/PackageSignature.php'] === null);
sud_selfupdate($live, $stage);
$r = DeploymentHelper::restoreSelfUpdateFiles($kept, false, $stage);
ok('the restore reports it restored', $r['status'] === 'restored' && $r['failed'] === array(), json_encode($r));
ok('upgrade.php is byte-identical to before', file_get_contents("$live/utils/upgrade.php") === "OLD upgrade\n");
ok('DeploymentHelper.php is byte-identical to before', file_get_contents("$live/includes/DeploymentHelper.php") === "OLD helper\n");
ok('a file the self-update created is removed', !file_exists("$live/includes/PackageSignature.php"));
ok('the file mode is kept', (fileperms("$live/utils/upgrade.php") & 0777) === 0640);
ok('no temporary file is left beside them', glob("$live/*/*.restore*") === array());
ok('staging is emptied, so the restored old script cannot resume into it', glob("$stage/*") === array() && !file_exists("$stage/public_html/VERSION"));

// ---------------------------------------------------------------------------
section('The decision is the VERSION, never the exit code');

// restoreSelfUpdateFiles() takes no exit status at all: a stop that exits 0
// (a failed backup clear, an extract failure) restores exactly like a crash.
$params = (new ReflectionMethod('DeploymentHelper', 'restoreSelfUpdateFiles'))->getParameters();
ok('the restore is not given the exit code', !in_array('exit_code', array_map(function ($p) { return $p->getName(); }, $params), true));

list($live, $stage, $lock) = sud_site($root, 'deployed');
$kept = DeploymentHelper::keepSelfUpdateOriginals($live, $lock, SUD_FILES);
sud_selfupdate($live, $stage);
file_put_contents("$live/VERSION", "0.8.477\n");   // the release went in
$r = DeploymentHelper::restoreSelfUpdateFiles($kept, false, $stage);
ok('a deployed release is left alone', $r['status'] === 'deployed'
	&& file_get_contents("$live/utils/upgrade.php") === "NEW upgrade\n" && file_exists("$stage/public_html/VERSION"));

list($live, $stage, $lock) = sud_site($root, 'unknown');
file_put_contents("$live/VERSION", "not a version\n");
$kept = DeploymentHelper::keepSelfUpdateOriginals($live, $lock, SUD_FILES);
sud_selfupdate($live, $stage);
$r = DeploymentHelper::restoreSelfUpdateFiles($kept, false, $stage);
ok('an unreadable VERSION restores nothing (never write into an unknown tree)', $r['status'] === 'unknown_version'
	&& file_get_contents("$live/utils/upgrade.php") === "NEW upgrade\n");

list($live, $stage, $lock) = sud_site($root, 'midmove');
$kept = DeploymentHelper::keepSelfUpdateOriginals($live, $lock, SUD_FILES);
sud_selfupdate($live, $stage);
unlink("$live/VERSION");   // a deploy caught mid-move
$r = DeploymentHelper::restoreSelfUpdateFiles($kept, false, $stage);
ok('a tree with no VERSION is left alone and reported as unreadable, not deployed', $r['status'] === 'version_unreadable'
	&& file_get_contents("$live/utils/upgrade.php") === "NEW upgrade\n");

// ---------------------------------------------------------------------------
section('Locking and the run that is still going');

list($live, $stage, $lock) = sud_site($root, 'locked');
$kept = DeploymentHelper::keepSelfUpdateOriginals($live, $lock, SUD_FILES);
sud_selfupdate($live, $stage);
$held = fopen($lock, 'c');
flock($held, LOCK_EX | LOCK_NB);
$r = DeploymentHelper::restoreSelfUpdateFiles($kept, false, $stage);
ok('another run holding the lock means nothing is written', $r['status'] === 'locked'
	&& file_get_contents("$live/utils/upgrade.php") === "NEW upgrade\n" && file_exists("$stage/public_html/VERSION"));
// The run that made the copy holds that lock itself and must not wait on it.
$r = DeploymentHelper::restoreSelfUpdateFiles($kept, true, $stage);
ok('the run that made the copy restores under its own lock', $r['status'] === 'restored'
	&& file_get_contents("$live/utils/upgrade.php") === "OLD upgrade\n");
ok('and leaves its staging alone', file_exists("$stage/public_html/VERSION"));
flock($held, LOCK_UN);
fclose($held);

// ---------------------------------------------------------------------------
section('The restore is wired into upgrade.php');

$src = file_get_contents(PathHelper::getIncludePath('utils/upgrade.php'));
ok('the copy is preceded by keeping the originals', strpos($src, 'DeploymentHelper::keepSelfUpdateOriginals(') !== false
	&& strpos($src, 'keepSelfUpdateOriginals(') < strpos($src, 'copy($staged_file, $live_file)'));
ok('the re-exec parent restores after the child ends, whatever its exit', (bool)preg_match(
	'/passthru\(\$cmd, \$exit_code\);\s*self_update_restore\(false, \$deployed_mark\);/', $src));
ok('the self-update check is outside the resume skip', strpos($src, '} // end if (!$resuming_after_self_update)') < strpos($src, '// SELF-UPDATE CHECK'));
// Staging another run left can hold theme and plugin archives merged in by a
// continuation that was killed; resumed, its core check refused every later
// run (gvisor_site_sandbox_trial B3). Only the re-exec'd run resumes.
$resume_at = strpos($src, '$resuming_after_self_update = true;');
$detect_at = strpos($src, '// SELF-UPDATE RESUME DETECTION');
$guard = $detect_at !== false && $resume_at !== false ? substr($src, $detect_at, $resume_at - $detect_at) : '';
ok('only the run a self-update re-execs resumes from staging', (bool)preg_match(
	"/if \\(getenv\\('JOINERY_UPGRADE_RERUN'\\) === '1'\\s*&&/", $guard));
ok('and the re-exec is what sets that mark', strpos($src, "\$cmd = 'JOINERY_UPGRADE_RERUN=1 ") !== false);
// A release reinstalled at its own version leaves VERSION as it was: the
// child's completed upgrade is marked for the parent, or the restore puts the
// files it deployed back.
$mark_clear = strpos($src, '@unlink($deployed_mark);');
$mark_pass  = strpos($src, "JOINERY_UPGRADE_DEPLOYED_MARK=' . escapeshellarg(\$deployed_mark)");
ok('the parent clears the mark, then hands its path to the child', $mark_clear !== false && $mark_pass !== false && $mark_clear < $mark_pass);
$restore_fn = substr($src, strpos($src, 'function self_update_restore('), 900);
ok('the restore leaves a completed upgrade alone, before it puts anything back',
	strpos($restore_fn, 'is_file($deployed_mark)') !== false
	&& strpos($restore_fn, 'is_file($deployed_mark)') < strpos($restore_fn, 'DeploymentHelper::restoreSelfUpdateFiles('));
$completed_at = strpos($src, "\$GLOBALS['APPLY_RESULT']['outcome'] = 'completed';");
$mark_write   = strpos($src, '@file_put_contents($deployed_mark,');
ok('the child writes it only where the upgrade completed', $completed_at !== false && $mark_write !== false
	&& $mark_write > $completed_at && $mark_write - $completed_at < 600
	&& substr_count($src, '@file_put_contents($deployed_mark,') === 1);
// An older upgrade.php (the agent puts it back after a killed run) resumes from
// any staging whose VERSION is the target: so staging carries VERSION only
// while it is the core archive alone.
$aside_at   = strpos($src, '!rename($staged_version_path, $staged_version_aside)');
$back_at    = strpos($src, '!rename($staged_version_aside, $staged_version_path)');
$check_at   = strpos($src, '// SELF-UPDATE CHECK');
$extras_at  = strpos($src, "out_step('Downloading Individual Themes and Plugins')");
$keep_at    = strpos($src, "out_step('Preserving Themes/Plugins')");
$deploy_at  = strpos($src, "out_step('Deploying Upgrade')");
ok('the staged VERSION is set aside after the self-update check, before any theme or plugin goes into staging',
	$aside_at !== false && $check_at < $aside_at && $aside_at < $extras_at);
ok('and put back after the live extensions are kept, before the deploy',
	$back_at !== false && $keep_at < $back_at && $back_at < $deploy_at);

harness_finish();
