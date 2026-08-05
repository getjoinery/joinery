<?php
/** @joinery-test
 * name: composer_validator
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * ComposerValidator reads installed state from the vendor tree itself
 * (vendor/composer/installed.json), never from composer.lock. Regression for
 * the getjoinery OAuth outage: a hand-assembled vendor tree (autoload.php
 * present, most packages absent) passed validation for months because the
 * validator compared composer.json against the shipped composer.lock — two
 * files that travel together with the source and always agree.
 *
 * Presence is also not enough on its own: a vendor tree can carry every required
 * package name at a version the deployed source cannot run against, so an
 * installed version that differs from composer.lock is a failure too.
 *
 * @version 1.1 - Covers the version-mismatch check
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/ComposerValidator.php'));

// ---------------------------------------------------------------------------
section('Deployed vendor tree validates');
// ---------------------------------------------------------------------------

$live = new ComposerValidator();
check($live->validate() === true,
	'the environment vendor tree passes validation',
	implode(' | ', $live->getErrors()));

// ---------------------------------------------------------------------------
// Scratch vendor trees
// ---------------------------------------------------------------------------

$scratch = rtrim(sys_get_temp_dir(), '/') . '/cvt_' . substr(md5(uniqid('', true)), 0, 8);
mkdir($scratch . '/composer', 0777, true);
file_put_contents($scratch . '/autoload.php', "<?php\n");
harness_defer(function () use ($scratch) {
	@unlink($scratch . '/composer/installed.json');
	@unlink($scratch . '/autoload.php');
	@rmdir($scratch . '/composer');
	@rmdir($scratch);
});

// ---------------------------------------------------------------------------
section('Hand-assembled vendor tree (no installed.json) is rejected');
// ---------------------------------------------------------------------------

// The getjoinery failure shape: autoload.php exists, composer.json and
// composer.lock exist in the source tree, but composer never ran here.
$bare = new ComposerValidator($scratch . '/');
check($bare->validate() === false,
	'a vendor tree without composer/installed.json fails validation');
$bare_errors = implode(' | ', $bare->getErrors());
check(strpos($bare_errors, 'Missing required packages') !== false,
	'the failure is reported as missing packages (install-fixable)',
	$bare_errors);

// ---------------------------------------------------------------------------
section('Partially installed vendor tree is rejected');
// ---------------------------------------------------------------------------

// installed.json present but listing only a subset of composer.json requires.
file_put_contents($scratch . '/composer/installed.json', json_encode(array(
	'packages' => array(
		array('name' => 'guzzlehttp/guzzle', 'version' => '7.9.0'),
	),
)));
$partial = new ComposerValidator($scratch . '/');
check($partial->validate() === false,
	'a vendor tree missing required packages fails validation');
$partial_errors = implode(' | ', $partial->getErrors());
check(strpos($partial_errors, 'stripe/stripe-php') !== false,
	'a specific absent package is named in the errors',
	$partial_errors);
check(strpos($partial_errors, 'guzzlehttp/guzzle') === false,
	'packages present in installed.json are not reported missing',
	$partial_errors);

// ---------------------------------------------------------------------------
section('Vendor tree at the wrong version is rejected');
// ---------------------------------------------------------------------------

// Every required package present, but one sits at a version the lock does not
// pin. This is the deploy shape a presence-only check misses: the node passes
// validation, skips composer install, and fatals at runtime on a namespace the
// installed major does not have.
$lock = json_decode(file_get_contents(PathHelper::getBasePath() . '/composer.lock'), true);
$locked_packages = array();
foreach ($lock['packages'] as $p) {
	$locked_packages[strtolower($p['name'])] = $p['version'];
}

$as_locked = array();
foreach ($locked_packages as $name => $version) {
	$as_locked[] = array('name' => $name, 'version' => $version);
}
file_put_contents($scratch . '/composer/installed.json', json_encode(array('packages' => $as_locked)));
$matching = new ComposerValidator($scratch . '/');
check($matching->validate() === true,
	'a vendor tree matching the lock passes',
	implode(' | ', $matching->getErrors()));

// Drift exactly one package, leaving every name present. It has to be a package
// composer.json requires directly — the check walks the require list, so
// drifting a transitive dependency proves nothing.
$root_require = json_decode(file_get_contents(PathHelper::getBasePath() . '/composer.json'), true)['require'];
$drift_target = NULL;
foreach (array_keys($root_require) as $name) {
	$name = strtolower($name);
	if ($name === 'php' || strpos($name, 'ext-') === 0 || strpos($name, 'lib-') === 0) {
		continue;
	}
	if (isset($locked_packages[$name])) {
		$drift_target = $name;
		break;
	}
}
check($drift_target !== NULL, 'a directly-required package was found to drift');

$drifted = array();
foreach ($locked_packages as $name => $version) {
	if ($name === $drift_target) {
		$version = 'v0.0.1-not-the-locked-version';
	}
	$drifted[] = array('name' => $name, 'version' => $version);
}
file_put_contents($scratch . '/composer/installed.json', json_encode(array('packages' => $drifted)));
$stale = new ComposerValidator($scratch . '/');
check($stale->validate() === false,
	'a vendor tree carrying a package at the wrong version fails validation');
$stale_errors = implode(' | ', $stale->getErrors());
check(strpos($stale_errors, 'Package version mismatch') !== false,
	'the failure is reported as a version mismatch (install-fixable)',
	$stale_errors);
check(strpos($stale_errors, 'Missing required packages') === false,
	'it is not misreported as a missing package',
	$stale_errors);
check(strpos($stale_errors, 'v0.0.1-not-the-locked-version') !== false,
	'the installed version is named so the drift is diagnosable',
	$stale_errors);

harness_finish();
