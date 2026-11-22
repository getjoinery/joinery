<?php
/** @joinery-test
 * name: plugin_vendor_checks
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 * covers: [includes/ComposerValidator.php, includes/PluginManager.php, plugins/server_manager/includes/publish_upgrade.php]
 */
/**
 * A plugin's own vendor/ (specs/plugin_own_vendor.md WP3), on fixture plugin
 * trees in scratch.
 *
 * The section 1 refusals: version, platform-check false and the plugin's
 * own autoloader suffix in its composer.json; no platform_check.php; the
 * plugin's own init class in autoload_real.php. Freshness against its lock
 * (packages only, never packages-dev). Platform needs read as data. And the
 * clash rule: one library at two builds (version, or the same version with
 * another dist reference) is refused against core and between plugins; the
 * same build is allowed. Then that activation, publish and validate() ask.
 *
 * Run: php tests/run.php --filter=plugin_vendor_checks
 *
 * @version 1.1 - library files git ignores under a plugin's vendor/
 * @version 1.1 - spaced operators, hyphen ranges, provided extensions, composer's extension names (reviewer2 C4-C6)
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

/** A package entry as composer writes it. */
function pv_pkg(string $name, string $version, string $ref = 'aaaa', array $require = array()): array {
	return array('name' => $name, 'version' => $version,
		'dist' => array('type' => 'zip', 'reference' => $ref, 'shasum' => ''),
		'require' => $require);
}

/**
 * A fixture plugin with a vendor/ built as required; $tweak changes one
 * thing so each refusal can be shown on its own.
 */
function pv_plugin(string $name, array $packages, array $tweak = array()): string {
	static $n = 0;
	$dir = harness_scratch_dir('plugin_vendor_checks') . '/t' . (++$n) . '/plugins/' . $name;
	mkdir($dir . '/vendor/composer', 0755, true);
	$json = array(
		'name' => 'joinery-plugin/' . $name,
		'version' => '1.0.0',
		'require' => array(),
		'config' => array('platform-check' => false, 'autoloader-suffix' => 'JoineryPlugin_' . $name),
	);
	foreach ($tweak['json_unset'] ?? array() as $path) {
		if ($path === 'version') { unset($json['version']); }
		else { unset($json['config'][$path]); }
	}
	file_put_contents($dir . '/composer.json', json_encode($json));
	$lock = array('packages' => $tweak['lock_packages'] ?? $packages, 'packages-dev' => $tweak['lock_dev'] ?? array());
	file_put_contents($dir . '/composer.lock', json_encode($lock));
	file_put_contents($dir . '/vendor/composer/installed.json', json_encode(array('packages' => $packages)));
	$class = $tweak['init_class'] ?? ('ComposerAutoloaderInitJoineryPlugin_' . $name);
	file_put_contents($dir . '/vendor/composer/autoload_real.php', "<?php\nclass " . $class . "\n{\n}\n");
	if (!empty($tweak['platform_check'])) {
		file_put_contents($dir . '/vendor/composer/platform_check.php', "<?php\n");
	}
	return $dir;
}

function pv_has(array $lines, string $needle): bool {
	foreach ($lines as $l) { if (strpos($l, $needle) !== false) { return true; } }
	return false;
}

$guzzle = pv_pkg('guzzlehttp/guzzle', '7.10.0', 'ref-core');
$core = array('guzzlehttp/guzzle' => array('version' => '7.10.0', 'reference' => 'ref-core', 'shasum' => '', 'require' => array()));

section('A plugin built as required passes');

$good = pv_plugin('good_one', array(pv_pkg('acme/lib', '1.2.0')));
check(ComposerValidator::pluginVendorProblems($good) === array(), 'a plugin built as the spec says has no problems',
	json_encode(ComposerValidator::pluginVendorProblems($good)));
check(ComposerValidator::pluginVendorProblems(dirname($good) . '/none') === array(), 'a plugin with no vendor/ is not asked about');

section('The section 1 refusals, each on its own');

$cases = array(
	'version'           => array(array('json_unset' => array('version')), 'sets no "version"'),
	'platform-check'    => array(array('json_unset' => array('platform-check')), '"platform-check": false'),
	'autoloader-suffix' => array(array('json_unset' => array('autoloader-suffix')), '"autoloader-suffix": "JoineryPlugin_p_case"'),
	'platform_check.php'=> array(array('platform_check' => true), 'platform_check.php exists'),
	'init class'        => array(array('init_class' => 'ComposerAutoloaderInitabc123'), 'declares ComposerAutoloaderInitabc123'),
);
foreach ($cases as $label => list($tweak, $needle)) {
	$dir = pv_plugin('p_case', array(pv_pkg('acme/lib', '1.2.0')), $tweak);
	$problems = ComposerValidator::pluginVendorProblems($dir);
	check(count($problems) === 1 && pv_has($problems, $needle), 'refused, and only for it: ' . $label, json_encode($problems));
}

section('Freshness against the plugin\'s own lock');

$stale = pv_plugin('stale_one', array(pv_pkg('acme/lib', '1.2.0')),
	array('lock_packages' => array(pv_pkg('acme/lib', '1.3.0'))));
$problems = ComposerValidator::pluginVendorProblems($stale, false);
check(pv_has($problems, 'does not match composer.lock (acme/lib)') && pv_has($problems, 'composer install --no-dev in plugins/stale_one'),
	'a vendor/ older than its lock is refused, saying how to fix it', json_encode($problems));

$dev = pv_plugin('dev_one', array(pv_pkg('acme/lib', '1.2.0')),
	array('lock_dev' => array(pv_pkg('phpunit/phpunit', '11.0.0'))));
check(ComposerValidator::pluginVendorProblems($dev, false) === array(), 'a packages-dev entry not installed is ignored');

section('Platform needs, read as data');

$needs_ext = pv_plugin('ext_one', array(pv_pkg('acme/lib', '1.2.0', 'aaaa', array('ext-joinerynonexistent' => '*'))));
$problems = ComposerValidator::pluginVendorProblems($needs_ext, true);
check(pv_has($problems, 'needs the joinerynonexistent extension'), 'a package needing an extension this PHP lacks is refused, naming it', json_encode($problems));
check(ComposerValidator::pluginVendorProblems($needs_ext, false) === array(), 'publish does not ask about this box\'s PHP (that is the node\'s business)');

$pkgs = array('a/b' => array('require' => array('php' => '>=99.0')));
check(pv_has(ComposerValidator::platformShortfalls($pkgs, '8.5.1'), 'a/b needs PHP >=99.0; this is 8.5.1'), 'a PHP version need this PHP does not meet is named');

$constraints = array(
	array('8.5.1', '*', true), array('8.5.1', '>=8.1', true), array('8.5.1', '>=8.6', false),
	array('8.5.1', '^8.1', true), array('8.5.1', '^7.4', false), array('8.5.1', '^7.4 || ^8.0', true),
	array('8.5.1', '~8.1.0', false), array('8.5.1', '~8.5.0', true), array('8.5.1', '~8.1', true),
	array('8.5.1', '>=8.1 <9.0', true), array('8.5.1', '>=8.1,<8.4', false), array('8.5.1', '8.5.*', true),
	array('8.5.1-1ubuntu1', '>=8.5.1', true), array('0.3.4', '^0.3', true), array('0.4.0', '^0.3', false),
	// reviewer2 C4: an operator written apart from its version, and hyphen ranges.
	array('8.5.2', '>= 8.1', true), array('8.5.2', '>= 9', false), array('8.5.2', '8.1 - 8.5', true),
	array('8.6.0', '8.1 - 8.5', false), array('8.5.2', '8.1 - 8.5.1', false), array('8.5.2', '~8.4.0', false),
);
foreach ($constraints as list($v, $c, $want)) {
	check(ComposerValidator::satisfiesConstraint($v, $c) === $want, $v . ' against ' . $c . ' is ' . ($want ? 'met' : 'not met'));
}

// reviewer2 C5: a package that stands in for an extension meets the requirement.
$poly = array('acme/lib' => array('require' => array('ext-joinerynonexistent' => '*')),
	'acme/polyfill' => array('provide' => array('ext-joinerynonexistent' => '*')));
check(ComposerValidator::platformShortfalls($poly) === array(), 'an ext-* need a package in the same tree provides is met');
// reviewer2 C6: an extension matched as composer names it.
$named = array('acme/lib' => array('require' => array('ext-' . str_replace(' ', '-', strtolower(get_loaded_extensions()[0])) => '*')));
check(ComposerValidator::platformShortfalls($named) === array(), 'an extension named as composer writes it (lowercase, spaces as dashes) is found');

section('The clash rule: one library at two builds');

$same = pv_plugin('same_build', array($guzzle));
check(ComposerValidator::vendorOverlaps($same, array('core' => $core)) === array(), 'a plugin with core\'s build of a package is allowed');

$other_version = pv_plugin('other_version', array(pv_pkg('guzzlehttp/guzzle', '7.15.2', 'ref-new')));
$clash = ComposerValidator::vendorOverlaps($other_version, array('core' => $core));
check(count($clash) === 1 && pv_has($clash, 'guzzlehttp/guzzle: other_version has 7.15.2') && pv_has($clash, 'core has 7.10.0'),
	'another version is refused, naming both builds', json_encode($clash));

$other_ref = pv_plugin('other_ref', array(pv_pkg('guzzlehttp/guzzle', '7.10.0', 'ref-rebuilt')));
check(count(ComposerValidator::vendorOverlaps($other_ref, array('core' => $core))) === 1,
	'the same version with another dist reference is refused');

$a = pv_plugin('plug_a', array(pv_pkg('acme/shared', '2.0.0', 'r1')));
$b = pv_plugin('plug_b', array(pv_pkg('acme/shared', '2.1.0', 'r2')));
$between = ComposerValidator::vendorOverlaps($b, array('plugin plug_a' => ComposerValidator::vendorPackages($a . '/vendor')));
check(count($between) === 1 && pv_has($between, 'plugin plug_a has 2.0.0'), 'two plugins with different builds of one package are refused', json_encode($between));

$c = pv_plugin('plug_c', array(pv_pkg('acme/shared', '2.0.0', 'r1')));
check(ComposerValidator::vendorOverlaps($c, array('plugin plug_a' => ComposerValidator::vendorPackages($a . '/vendor'))) === array(),
	'two plugins with the same build of one package are allowed');

check(ComposerValidator::lockPackages(PathHelper::getBasePath() . '/composer.lock') !== null, 'core\'s composer.lock reads as a package map');

section('Library files git ignores');

$repo = harness_scratch_dir('plugin_vendor_checks') . '/repo';
mkdir($repo . '/public_html/plugins/gi_one/vendor/a', 0755, true);
exec('git -C ' . escapeshellarg($repo) . ' init -q 2>&1');
file_put_contents($repo . '/public_html/plugins/gi_one/vendor/a/.gitignore', "*.dist\n");
file_put_contents($repo . '/public_html/plugins/gi_one/vendor/a/config.dist', "x\n");
file_put_contents($repo . '/public_html/plugins/gi_one/vendor/a/Lib.php', "<?php\n");
$ig = ComposerValidator::ignoredVendorFiles($repo, 'gi_one');
check($ig === array('public_html/plugins/gi_one/vendor/a/config.dist'),
	'a file a library\'s own .gitignore hides under vendor/ is found', json_encode($ig));
unlink($repo . '/public_html/plugins/gi_one/vendor/a/.gitignore');
check(ComposerValidator::ignoredVendorFiles($repo, 'gi_one') === array(), 'with nothing ignored, nothing is listed');
check(ComposerValidator::ignoredVendorFiles(dirname($repo) . '/not_a_repo', 'gi_one') === null, 'a folder that is not a git checkout is not asked');
check(ComposerValidator::ignoredVendorFiles($repo, '../x') === null, 'a name that is not a plugin folder name is refused');

section('Who asks');

$pm = file_get_contents(PathHelper::getIncludePath('includes/PluginManager.php'));
$act = substr($pm, (int)strpos($pm, 'protected function onActivate('));
$act = substr($act, 0, (int)strpos($act, 'runPluginTablesOnly'));
check(strpos($act, 'ComposerValidator::pluginVendorProblems($plugin_dir, true)') !== false
	&& strpos($act, 'pluginActivationClashes($plugin_dir)') !== false,
	'activation asks both, with this PHP\'s platform, before the plugin\'s tables are touched');

$pub = file_get_contents(PathHelper::getIncludePath('plugins/server_manager/includes/publish_upgrade.php'));
$call = strpos($pub, '$vendor_refusals = publish_plugin_vendor_refusals($build_root);');
$call = strpos($pub, '$vendor_refusals = publish_plugin_vendor_refusals($build_root, $full_site_dir);');
check(strpos($pub, 'ComposerValidator::ignoredVendorFiles($repo, $name)') !== false,
	'publish asks git about ignored library files in the working repository');
check($call !== false && $call < strpos($pub, 'Creating individual plugin archives'),
	'publish asks before it writes any archive');
check(strpos($pub, 'ComposerValidator::pluginVendorProblems($dir, false)') !== false
	&& strpos($pub, "ComposerValidator::lockPackages(\$base . '/composer.lock')") !== false
	&& strpos($pub, 'ComposerValidator::vendorOverlaps($dir, $others)') !== false,
	'publish checks refusals and freshness, and clashes against core\'s lock and each other plugin');

$cv = file_get_contents(PathHelper::getIncludePath('includes/ComposerValidator.php'));
check(strpos($cv, '$this->warnings[] = "Plugin library clash: "') !== false
	&& strpos($cv, '$this->errors[] = "Plugin library clash') === false,
	'validate() reports an active plugin\'s clash as a warning, never an error');

harness_finish();
