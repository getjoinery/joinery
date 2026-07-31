<?php
/** @joinery-test
 * name: deploy_bootstrap
 * tier: deploy
 * env: any
 * needs: []
 * timeout: 60
 */
/**
 * The deployed code can actually start on this machine.
 *
 * A file compiling says its syntax is valid. It does not say the pieces still
 * fit: a class that moved between core and a plugin, a require pointing at a
 * path this release removed, a settings key the new code reads and the archive
 * forgot to seed. Those surface the moment something tries to boot, which on a
 * live site means the first visitor.
 *
 * Everything here is a read. No writes, no sends, no migrations — this runs on
 * production nodes, after a swap, with a rollback hanging on the result, so a
 * false failure costs a working deploy and a side effect costs more than that.
 *
 * Deliberately makes no claim about the shape of the tree. A deployed site
 * carries the plugins it uses and no others, keeps its own themes, and has no
 * repository around it. Assertions about a first-party plugin set or a
 * components manifest belong in the development tiers.
 *
 * Run: php tests/deploy/bootstrap_test.php
 *
 * @version 1.0.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

// ---------------------------------------------------------------------------
section('Core classes load');
// ---------------------------------------------------------------------------

// harness_boot() already pulled in PathHelper, Globalvars, SessionControl,
// DbConnector and LibraryFunctions — reaching this line at all proves those
// five. The rest are the classes every request touches.
foreach (array('PathHelper', 'Globalvars', 'SessionControl', 'DbConnector', 'LibraryFunctions') as $class) {
	check(class_exists($class, false), "$class is loaded");
}

$core_includes = array(
	'includes/PluginManager.php'  => 'PluginManager',
	'includes/ThemeHelper.php'    => 'ThemeHelper',
	'includes/PluginHelper.php'   => 'PluginHelper',
	'includes/RouteHelper.php'    => 'RouteHelper',
	'includes/LogicResult.php'    => 'LogicResult',
	'includes/FormWriterV2HTML5.php' => 'FormWriterV2HTML5',
	'includes/EmailSender.php'    => 'EmailSender',
	'data/users_class.php'        => 'User',
);
foreach ($core_includes as $file => $class) {
	$path = PathHelper::getIncludePath($file);
	if (!file_exists($path)) {
		check(false, "$file ships in this release", $path);
		continue;
	}
	try {
		require_once($path);
		check(class_exists($class, false), "$file defines $class");
	} catch (\Throwable $e) {
		check(false, "$file loads", get_class($e) . ': ' . $e->getMessage());
	}
}

// ---------------------------------------------------------------------------
section('The database is reachable and current');
// ---------------------------------------------------------------------------

$dblink = null;
try {
	$dblink = DbConnector::get_instance()->get_db_link();
	check($dblink instanceof PDO, 'the site connects to its database');
} catch (\Throwable $e) {
	check(false, 'the site connects to its database', $e->getMessage());
}

if ($dblink instanceof PDO) {
	// The tables every request reads. Not an exhaustive schema check — that is
	// what update_database is for, and it has already run by the time this does.
	foreach (array('usr_users', 'stg_settings') as $table) {
		try {
			$stmt = $dblink->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
			check($stmt !== false, "$table is queryable");
		} catch (\Throwable $e) {
			check(false, "$table is queryable", $e->getMessage());
		}
	}
}

$settings = Globalvars::get_instance();
check(trim((string)$settings->get_setting('site_template')) !== '',
	'the site knows its own directory name');

// ---------------------------------------------------------------------------
section('The release is internally consistent');
// ---------------------------------------------------------------------------

$version_file = PathHelper::getIncludePath('VERSION');
$file_version = file_exists($version_file) ? trim((string)file_get_contents($version_file)) : '';
check($file_version !== '', 'the deployed tree carries a VERSION file', $version_file);

$db_version = trim((string)$settings->get_setting('system_version'));
check($db_version !== '', 'the database records a system version');

// The two version numbers are reported, deliberately not asserted equal.
//
// A mismatch is worth seeing — every later diagnosis starts from a version
// number, and reading the wrong one wastes the first hour. But it is bookkeeping,
// not a broken release, and a failure here reverts a deploy that works. The
// numbers also legitimately disagree for a moment depending on where in the
// pipeline this runs, and rolling back on a race would be the worst outcome
// available.
check(true, 'version numbers recorded for the upgrade log',
	'VERSION=' . ($file_version !== '' ? $file_version : '(none)')
	. ' system_version=' . ($db_version !== '' ? $db_version : '(none)')
	. ($file_version !== '' && $db_version !== '' && $file_version !== $db_version
		? ' — these disagree; worth a look, not a rollback' : ''));

// Declarative manifests are read on every update_database run and on every
// settings save. Invalid JSON here is a release that cannot be maintained.
foreach (array('settings.json', 'admin_menus.json', 'install_bundles.json') as $manifest) {
	$path = PathHelper::getIncludePath($manifest);
	if (!file_exists($path)) {
		check(false, "$manifest ships in this release", $path);
		continue;
	}
	json_decode((string)file_get_contents($path), true);
	check(json_last_error() === JSON_ERROR_NONE, "$manifest is valid JSON", json_last_error_msg());
}

// The licence has to reach the people running the code. Two legitimate homes:
// a deployed site gets it inside public_html, because upgrade.php swaps that
// directory alone and a copy beside it would install once and never refresh; a
// development checkout keeps it at the repo root, which is where
// publish_upgrade.php reads it from. Either is correct — its absence is not.
$licence_paths = array(
	PathHelper::getIncludePath('LICENSE.md'),
	dirname(PathHelper::getRootDir()) . '/LICENSE.md',
);
$licence_found = '';
foreach ($licence_paths as $candidate) {
	if (file_exists($candidate) && trim((string)file_get_contents($candidate)) !== '') {
		$licence_found = $candidate;
		break;
	}
}
check($licence_found !== '', 'the release carries its licence',
	$licence_found !== '' ? $licence_found : 'looked in ' . implode(' and ', $licence_paths));

harness_finish();
