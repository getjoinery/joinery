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
 * @version 1.0
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

harness_finish();
