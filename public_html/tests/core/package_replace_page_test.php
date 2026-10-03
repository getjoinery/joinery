<?php
/** @joinery-test
 * name: package_replace_page
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The replace panel's half of replacing an installed package
 * (specs/package_replace_on_upload.md WP2).
 *
 * An upload whose name is already installed is staged and held: nothing is
 * queued for root until the operator says Replace. These checks stage real
 * ZIPs against a fixture theme written under theme/ for the run, read the
 * panel's facts, discard, and watch the root request queue stay exactly as
 * it was. Replace itself is not pressed here: it would queue a request the
 * host's root actor carries out, and the arguments it queues are pinned by
 * package_replace_test instead.
 *
 * Run: php tests/run.php --only=tests/core/package_replace_page_test.php
 *
 * @version 1.0
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

if (!class_exists('ZipArchive')) {
	harness_skip('ZipArchive is not available here');
	harness_finish();
}

const PRP_FIXTURE = 'zzreplacepage';
$theme_dir = PathHelper::getAbsolutePath('theme') . '/' . PRP_FIXTURE;
$staging = PathHelper::getSiteRoot() . '/uploads/staging';
$scratch = harness_scratch_dir('package_replace_page') . '/run-' . getmypid();

$rmtree = function ($p) use (&$rmtree) {
	if (is_link($p) || !is_dir($p)) { @unlink($p); return; }
	foreach (scandir($p) ?: array() as $e) {
		if ($e === '.' || $e === '..') continue;
		$rmtree($p . '/' . $e);
	}
	@rmdir($p);
};
if (!is_dir($staging) || !is_writable($staging)) {
	harness_skip('uploads/staging is not writable by this account');
	harness_finish();
}
$rmtree($scratch);
@mkdir($scratch, 0770, true);
$staging_before = scandir($staging);
harness_defer(function () use ($theme_dir, $scratch, $staging, $staging_before, $rmtree) {
	$rmtree($theme_dir);
	$rmtree($scratch);
	// Anything this run left in staging goes; what was there before stays.
	foreach (scandir($staging) ?: array() as $e) {
		if ($e === '.' || $e === '..' || in_array($e, $staging_before, true)) continue;
		$rmtree($staging . '/' . $e);
	}
});

/** A ZIP holding <name>/theme.json and <name>/style.css. */
$zip_of = function (string $name, array $manifest) use ($scratch): string {
	$path = $scratch . '/' . $name . '-' . bin2hex(random_bytes(3)) . '.zip';
	$zip = new ZipArchive();
	$zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
	// A style theme's manifest names its stylesheets (ThemeHelper::styleThemeRefusal).
	$zip->addFromString($name . '/theme.json', json_encode($manifest + array('styles' => array('style.css'))));
	$zip->addFromString($name . '/style.css', ":root { --fixture: 1; }\n");
	$zip->close();
	return $path;
};
$staged_entries = function () use ($staging): array {
	return array_values(array_diff(scandir($staging) ?: array(), array('.', '..')));
};

// The installed copy: a style theme at 1.0.0 whose manifest says upgrades on.
@mkdir($theme_dir, 0755, true);
file_put_contents($theme_dir . '/theme.json', json_encode(array(
	'name' => PRP_FIXTURE, 'version' => '1.0.0', 'author' => 'Fixture', 'receives_upgrades' => true,
	'styles' => array('style.css'),
)));
file_put_contents($theme_dir . '/style.css', ":root { --fixture: 0; }\n");

$queued_before = count(RootRequest::pending());

section('An upload of an installed name is held, not queued');

$outcome = PackageInstallPage::upload('theme', $zip_of(PRP_FIXTURE, array(
	'name' => PRP_FIXTURE, 'version' => '1.1.0', 'author' => 'Fixture Two')), 1);
check(($outcome['outcome'] ?? '') === 'pending_replace', 'the outcome is pending_replace', json_encode($outcome));
check(($outcome['name'] ?? '') === PRP_FIXTURE, 'and names the package');
$staged_dir = (string)($outcome['staged_dir'] ?? '');
check($staged_dir !== '' && PackageInstallPage::stagedAbsolute($staged_dir) !== null,
	'the staged directory is still under uploads/staging', $staged_dir);
check(count(RootRequest::pending()) === $queued_before, 'no root request was queued');

section('The panel\'s facts come from the two directories');

$why = '';
$pending = PackageInstallPage::pending('theme', $staged_dir, $why);
check(is_array($pending), 'pending() reads the staged upload', $why);
if (is_array($pending)) {
	check($pending['installed']['version'] === '1.0.0' && $pending['uploaded']['version'] === '1.1.0',
		'both versions', json_encode(array($pending['installed']['version'], $pending['uploaded']['version'])));
	check($pending['installed']['author'] === 'Fixture' && $pending['uploaded']['author'] === 'Fixture Two', 'both authors');
	check($pending['installed']['files'] === 2 && $pending['uploaded']['files'] === 2, 'both file counts');
	check($pending['installed']['kind'] === 'Styling' && $pending['uploaded']['kind'] === 'Styling', 'both kinds');
	check($pending['older'] === false, 'a newer upload is not called older');
	check($pending['in_use'] === '', 'a theme in neither slot is not in use', $pending['in_use']);
	check($pending['becomes_fork'] === true, 'a copy whose manifest says upgrades on becomes a fork');
	check($pending['staged_dir'] === $staged_dir && $pending['type'] === 'theme', 'the panel carries what the buttons need');
}

// The installed copy is already a fork: the panel does not say it becomes one.
$manifest = json_decode(file_get_contents($theme_dir . '/theme.json'), true);
$manifest['receives_upgrades'] = false;
file_put_contents($theme_dir . '/theme.json', json_encode($manifest));
$pending = PackageInstallPage::pending('theme', $staged_dir, $why);
check(is_array($pending) && $pending['becomes_fork'] === false, 'an installed fork is not said to become one');
$manifest['receives_upgrades'] = true;
file_put_contents($theme_dir . '/theme.json', json_encode($manifest));

section('Discard removes the staged upload and its wrapper');

$wrapper = dirname((string)PackageInstallPage::stagedAbsolute($staged_dir));
$name = PackageInstallPage::discard('theme', $staged_dir);
check($name === PRP_FIXTURE, 'Discard names what it discarded');
check(!is_dir($wrapper), 'the <staging id>/ wrapper is gone with it', $wrapper);
check(PackageInstallPage::pending('theme', $staged_dir, $why) === null && $why !== '', 'pending() on it now says why', $why);

section('An older upload is said to be older');

$outcome = PackageInstallPage::upload('theme', $zip_of(PRP_FIXTURE, array('name' => PRP_FIXTURE, 'version' => '0.9.0')), 1);
$pending = PackageInstallPage::pending('theme', (string)$outcome['staged_dir'], $why);
check(is_array($pending) && $pending['older'] === true, 'version 0.9.0 over 1.0.0 is older');
PackageInstallPage::discard('theme', (string)$outcome['staged_dir']);

section('A system extension is refused, and leaves nothing in staging');

$entries = $staged_entries();
$threw = '';
try {
	PackageInstallPage::upload('theme', $zip_of('default', array('name' => 'default', 'version' => '9.9.9')), 1);
} catch (Exception $e) {
	$threw = $e->getMessage();
}
check(strpos($threw, 'system theme') !== false, 'uploading the default theme\'s name is refused as a system theme', $threw);
check($staged_entries() === $entries, 'and the staged upload was discarded');
check(count(RootRequest::pending()) === $queued_before, 'still nothing queued');

section('Staging directories older than a day are swept on upload');

$old = $staging . '/theme_oldfixture' . bin2hex(random_bytes(2));
@mkdir($old . '/' . PRP_FIXTURE, 0770, true);
file_put_contents($old . '/' . PRP_FIXTURE . '/theme.json', '{}');
touch($old, time() - PackageInstallPage::STAGING_MAX_AGE - 60);
$fresh = $staging . '/theme_freshfixture' . bin2hex(random_bytes(2));
@mkdir($fresh, 0770, true);
$outcome = PackageInstallPage::upload('theme', $zip_of(PRP_FIXTURE, array('name' => PRP_FIXTURE, 'version' => '1.2.0')), 1);
check(!is_dir($old), 'the day-old staging directory is gone');
check(is_dir($fresh), 'a fresh one is left alone');
$rmtree($fresh);
PackageInstallPage::discard('theme', (string)$outcome['staged_dir']);

section('A staged directory name from a request is checked, never trusted');

check(PackageInstallPage::pending('theme', '../../etc', $why) === null, 'a path that leaves staging is refused');
check(PackageInstallPage::pending('theme', 'theme_nothere/zz', $why) === null && $why !== '', 'a directory that is not there says why');
$threw = false;
try { PackageInstallPage::discard('theme', 'theme_nothere/zz'); } catch (Exception $e) { $threw = true; }
check($threw, 'Discard of a directory that is not there throws');

check(count(RootRequest::pending()) === $queued_before, 'the root request queue is as it was when this started');

harness_finish();
