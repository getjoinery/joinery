<?php
/** @joinery-test
 * name: plugin_vendor_loader
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 * covers: [includes/ClassAutoloader.php]
 */
/**
 * ClassAutoloader::loadPluginVendor(): the libraries a plugin ships in its
 * own vendor/ (specs/plugin_own_vendor.md WP2), on fixture plugins under this
 * run's scratch tree, never the site's plugins.
 *
 * One fixture is a real composer build (a path repository, offline); the
 * rest are hand-written trees of the same shape (autoload.php returning a
 * loader with loadClass() and unregister()), each counting how often its
 * autoload.php ran.
 *
 * Run: php tests/run.php --filter=plugin_vendor_loader
 *
 * @version 1.2 - a files entry using another plugin's class while the require phase runs (reviewer2 C10)
 * @version 1.1 - a class with a library parent, across plugins too; requirePluginVendor(); a throwing files
 *   entry leaves no loader in the stack (reviewer2 C1-C3)
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getComposerAutoloadPath());

$ROOT = harness_scratch_dir('plugin_vendor_loader');
$PLUGINS = $ROOT . '/plugins';
@mkdir($PLUGINS, 0755, true);
$GLOBALS['pv_required'] = array();

/**
 * A hand-written vendor/ for $plugin: autoload.php counts itself and returns
 * a loader mapping namespace $ns to vendor/src. $classes: short name => body.
 */
function pv_fixture(string $plugin, string $ns, array $classes, string $autoload_extra = ''): void {
	global $PLUGINS;
	$vendor = $PLUGINS . '/' . $plugin . '/vendor';
	@mkdir($vendor . '/src', 0755, true);
	@mkdir($vendor . '/composer', 0755, true);
	foreach ($classes as $short => $body) {
		file_put_contents($vendor . '/src/' . $short . '.php',
			"<?php\nnamespace " . $ns . ";\nclass " . $short . " { " . $body . " }\n");
	}
	$loader_class = 'PvFixtureLoader_' . $plugin;
	file_put_contents($vendor . '/autoload.php', "<?php\n"
		. "\$GLOBALS['pv_required']['" . $plugin . "'] = (\$GLOBALS['pv_required']['" . $plugin . "'] ?? 0) + 1;\n"
		. $autoload_extra
		. "if (!class_exists('" . $loader_class . "', false)) {\n"
		. "  class " . $loader_class . " {\n"
		. "    public \$asked = array(); public \$unregistered = false;\n"
		. "    public function loadClass(\$c) { \$this->asked[] = \$c;\n"
		. "      \$p = '" . addslashes($ns) . "\\\\';\n"
		. "      if (strpos(\$c, \$p) !== 0) { return null; }\n"
		. "      \$f = __DIR__ . '/src/' . substr(\$c, strlen(\$p)) . '.php';\n"
		. "      if (is_file(\$f)) { require \$f; return true; } return null; }\n"
		. "    public function unregister() { \$this->unregistered = true; }\n"
		. "  }\n"
		. "}\n"
		. "return new " . $loader_class . "();\n");
}

function pv_setup($eligible, array $sandboxed = array()): void {
	global $PLUGINS;
	ClassAutoloader::usePluginVendorForTest(array('plugins_dir' => $PLUGINS, 'eligible' => $eligible, 'sandboxed' => $sandboxed));
}

function pv_loaders(): array {
	return (new ReflectionProperty('ClassAutoloader', 'vendor_loaders'))->getValue();
}

function pv_count(string $plugin): int {
	return (int)($GLOBALS['pv_required'][$plugin] ?? 0);
}

harness_defer(function () { ClassAutoloader::usePluginVendorForTest(null); });

$log = tempnam(sys_get_temp_dir(), 'pvlog_');
$old_log = ini_get('error_log');
ini_set('error_log', $log);
harness_defer(function () use ($log, $old_log) { ini_set('error_log', $old_log); @unlink($log); });
function pv_logged(string $needle): bool {
	global $log;
	return strpos((string)@file_get_contents($log), $needle) !== false;
}

section('Nothing is required until a namespaced miss');

pv_fixture('pvquiet', 'PvQuiet', array('Thing' => ''));
pv_setup(array('pvquiet'));
class_exists('PvNoSuchFlatClass');
check(pv_count('pvquiet') === 0, 'a miss on a flat class name requires no plugin vendor/');
check(class_exists('PvQuiet\\Thing'), 'the first namespaced miss loads the plugin\'s library');
check(pv_count('pvquiet') === 1, 'its autoload.php ran once');
class_exists('PvQuiet\\Missing');
class_exists('PvQuiet\\Missing2');
check(pv_count('pvquiet') === 1, 'later misses never require it again');
check(!empty(pv_loaders()['pvquiet']->unregistered), 'the loader composer returned is unregistered, asked only from here');

section('Which plugins: active or allowed in, never inactive or sandboxed');

pv_fixture('pvon', 'PvOn', array('Thing' => ''));
pv_fixture('pvoff', 'PvOff', array('Thing' => ''));
pv_fixture('pvsand', 'PvSand', array('Thing' => ''));
pv_setup(array('pvon', 'pvsand'), array('pvsand'));
check(class_exists('PvOn\\Thing'), 'an active plugin\'s library resolves');
check(!class_exists('PvOff\\Thing') && pv_count('pvoff') === 0, 'an inactive plugin\'s does not, and its autoload.php never runs');
check(!class_exists('PvSand\\Thing') && pv_count('pvsand') === 0, 'a sandboxed plugin\'s vendor/ is never loaded by the site');

section('Core\'s copy of a library always wins');

pv_fixture('pvcore', 'GuzzleHttp\\Cookie', array('SetCookie' => 'const FROM_PLUGIN = true;'));
pv_setup(array('pvcore'));
check(!class_exists('GuzzleHttp\\Cookie\\SetCookie', false), 'the shared class is not loaded yet');
check(class_exists('GuzzleHttp\\Cookie\\SetCookie'), 'the shared class resolves');
$file = (string)(new ReflectionClass('GuzzleHttp\\Cookie\\SetCookie'))->getFileName();
check(strpos($file, (string)realpath(PathHelper::getComposerVendorPath()) . '/') === 0 && !defined('GuzzleHttp\\Cookie\\SetCookie::FROM_PLUGIN'),
	'it comes from core\'s vendor/, not the plugin\'s', $file);

section('A failing plugin is skipped, the next still loads');

pv_fixture('pva_throws', 'PvThrows', array('Thing' => ''), "throw new RuntimeException('library refused');\n");
pv_fixture('pvb_after', 'PvAfter', array('Thing' => ''));
pv_setup(array('pva_throws', 'pvb_after'));
check(!class_exists('PvThrows\\Thing'), 'the throwing plugin\'s class does not resolve');
check(class_exists('PvAfter\\Thing'), 'the next plugin\'s class still resolves');
check(pv_logged('plugin pva_throws: loading vendor/ failed (RuntimeException: library refused); skipped'), 'the failure is logged');
class_exists('PvThrows\\Other');
check(pv_count('pva_throws') === 1, 'a failed plugin is not retried on every miss');

section('An init class some other file declared: skipped, never declared twice');

pv_fixture('pvdup', 'PvDup', array('Thing' => ''));
eval('class ComposerAutoloaderInitJoineryPlugin_pvdup {}');
pv_setup(array('pvdup'));
check(!class_exists('PvDup\\Thing') && pv_count('pvdup') === 0, 'its autoload.php is not required');
check(pv_logged('plugin pvdup: ComposerAutoloaderInitJoineryPlugin_pvdup is already declared by another file'), 'the collision is logged');

section('Only the plugin\'s own vendor/autoload.php, never a link');

pv_fixture('pvreal', 'PvLinked', array('Thing' => ''));
@mkdir($PLUGINS . '/pvlinkdir', 0755, true);
symlink($PLUGINS . '/pvreal/vendor', $PLUGINS . '/pvlinkdir/vendor');
@mkdir($PLUGINS . '/pvlinkfile/vendor', 0755, true);
symlink($PLUGINS . '/pvreal/vendor/autoload.php', $PLUGINS . '/pvlinkfile/vendor/autoload.php');
pv_setup(array('pvlinkdir', 'pvlinkfile'));
check(!class_exists('PvLinked\\Thing') && pv_count('pvreal') === 0, 'a linked vendor/ or autoload.php is never required');
check(pv_logged('plugin pvlinkdir: vendor/autoload.php is not the plugin\'s own file')
	&& pv_logged('plugin pvlinkfile: vendor/autoload.php is not the plugin\'s own file'), 'both are logged');

section('Eligibility is asked on every miss');

pv_fixture('pvlate', 'PvLate', array('Thing' => ''));
$active_now = array();
pv_setup(function () use (&$active_now) { return $active_now; });
check(!class_exists('PvLate\\Thing'), 'while the active set reads empty, nothing loads');
$active_now = array('pvlate');
check(class_exists('PvLate\\Thing'), 'once it reads the plugin, the same process loads it: "none" was never remembered');

section('allowPlugin() mid-process, forgetPlugin() parks');

pv_fixture('pvinstall', 'PvInstall', array('First' => '', 'Second' => '', 'Third' => ''));
$active_now = array();
pv_setup(function () use (&$active_now) { return $active_now; });
class_exists('PvSomething\\Else');
ClassAutoloader::allowPlugin('pvinstall');
check(class_exists('PvInstall\\First'), 'a plugin allowed in after a miss still loads on the next one');
ClassAutoloader::forgetPlugin('pvinstall');
check(!class_exists('PvInstall\\Second'), 'after forgetPlugin() a new class from it does not resolve');
$active_now = array('pvinstall');
check(class_exists('PvInstall\\Third'), 'activated in the same process: its parked loader answers');
check(pv_count('pvinstall') === 1, 'without a second require');

section('A real composer build');

$lib = $ROOT . '/libsrc';
@mkdir($lib . '/src', 0755, true);
file_put_contents($lib . '/composer.json', json_encode(array(
	'name' => 'pvfixture/lib', 'version' => '1.0.0',
	'autoload' => array('psr-4' => array('PvFixtureLib\\' => 'src/')),
)));
file_put_contents($lib . '/src/Widget.php', "<?php\nnamespace PvFixtureLib;\nclass Widget { const OK = 1; }\n");
$built = array();
foreach (array('pvreal_one', 'pvreal_two') as $plugin) {
	$dir = $PLUGINS . '/' . $plugin;
	@mkdir($dir, 0755, true);
	// Identical but for the suffix, which the spec requires per plugin.
	file_put_contents($dir . '/composer.json', json_encode(array(
		'name' => 'joinery-plugin/fixture', 'version' => '1.0.0',
		'repositories' => array(array('type' => 'path', 'url' => $lib, 'options' => array('symlink' => false))),
		'require' => array('pvfixture/lib' => '1.0.0'),
		'config' => array('platform-check' => false, 'autoloader-suffix' => 'JoineryPlugin_' . $plugin, 'optimize-autoloader' => true),
	)));
	$o = array(); $rc = 0;
	exec('cd ' . escapeshellarg($dir) . ' && COMPOSER_HOME=' . escapeshellarg($ROOT . '/composer_home')
		. ' composer install --no-dev --no-interaction --no-plugins -q 2>&1', $o, $rc);
	$built[$plugin] = ($rc === 0 && is_file($dir . '/vendor/autoload.php'));
}
if (!$built['pvreal_one'] || !$built['pvreal_two']) {
	harness_skip('real composer builds', 'composer could not build the fixture offline');
} else {
	check(!is_file($PLUGINS . '/pvreal_one/vendor/composer/platform_check.php'), 'platform-check false leaves no platform_check.php');
	pv_setup(array('pvreal_one', 'pvreal_two'));
	check(class_exists('PvFixtureLib\\Widget'), 'a class from a real composer-built plugin vendor/ resolves');
	$loaders = pv_loaders();
	check(isset($loaders['pvreal_one'], $loaders['pvreal_two']),
		'two plugins with identical composer.json but their own suffixes both load, with no collision');
	check(class_exists('ComposerAutoloaderInitJoineryPlugin_pvreal_one', false)
		&& class_exists('ComposerAutoloaderInitJoineryPlugin_pvreal_two', false), 'each declared its own init class');
	$still_registered = false;
	foreach (spl_autoload_functions() as $fn) {
		if (is_array($fn) && is_object($fn[0]) && in_array($fn[0], $loaders, true)) {
			$still_registered = true;
		}
	}
	check(!$still_registered, 'neither composer loader is left in the autoload stack, ahead of core\'s');
	$file = (string)(new ReflectionClass('PvFixtureLib\\Widget'))->getFileName();
	check(strpos($file, $PLUGINS . '/pvreal_one/vendor/') === 0, 'plugins are asked in name order', $file);
}

section('Real composer builds: parents, functions, a throwing files entry (reviewer2 C1-C3)');

/**
 * Build $plugin's vendor/ with real composer from a path library $lib holding
 * $files (relative path => PHP source), autoloading $psr4 namespaces, with
 * $autoload_files run on require. Returns whether composer built it.
 */
function pv_real(string $plugin, string $lib, array $psr4, array $files, array $autoload_files = array()): bool {
	global $PLUGINS, $ROOT;
	$libdir = $ROOT . '/lib_' . $lib;
	foreach ($files as $rel => $src) {
		@mkdir(dirname($libdir . '/' . $rel), 0755, true);
		file_put_contents($libdir . '/' . $rel, $src);
	}
	$autoload = array('psr-4' => $psr4);
	if ($autoload_files) { $autoload['files'] = $autoload_files; }
	file_put_contents($libdir . '/composer.json', json_encode(array(
		'name' => 'pvfixture/' . $lib, 'version' => '1.0.0', 'autoload' => $autoload)));
	$dir = $PLUGINS . '/' . $plugin;
	@mkdir($dir, 0755, true);
	file_put_contents($dir . '/composer.json', json_encode(array(
		'name' => 'joinery-plugin/' . $plugin, 'version' => '1.0.0',
		'repositories' => array(array('type' => 'path', 'url' => $libdir, 'options' => array('symlink' => false))),
		'require' => array('pvfixture/' . $lib => '1.0.0'),
		'config' => array('platform-check' => false, 'autoloader-suffix' => 'JoineryPlugin_' . $plugin, 'optimize-autoloader' => true),
	)));
	$o = array(); $rc = 0;
	exec('cd ' . escapeshellarg($dir) . ' && COMPOSER_HOME=' . escapeshellarg($ROOT . '/composer_home')
		. ' composer install --no-dev --no-interaction --no-plugins -q 2>&1', $o, $rc);
	return $rc === 0 && is_file($dir . '/vendor/autoload.php');
}

/** Whether a loader composer registered for $plugin's vendor/ is still in the autoload stack. */
function pv_composer_loader_registered(string $plugin): bool {
	global $PLUGINS;
	$real = realpath($PLUGINS . '/' . $plugin . '/vendor');
	foreach (spl_autoload_functions() as $fn) {
		if (is_array($fn) && is_object($fn[0]) && get_class($fn[0]) === 'Composer\\Autoload\\ClassLoader') {
			$prefixes = $fn[0]->getPrefixesPsr4() + $fn[0]->getClassMap();
			foreach ($prefixes as $dirs) {
				foreach ((array)$dirs as $d) {
					if ($real !== false && strpos((string)realpath($d), $real . '/') === 0) { return true; }
				}
			}
		}
	}
	return false;
}

$ok = pv_real('pvprobe', 'probe', array('PvProbe\\Lib\\' => 'src/'), array(
	'src/Thing.php' => "<?php\nnamespace PvProbe\\Lib;\ninterface Thing {}\n",
	'src/Client.php' => "<?php\nnamespace PvProbe\\Lib;\nclass Client implements Thing {}\n",
));
$ok = pv_real('pvx_a', 'xbase', array('PvCross\\' => 'src/'), array(
	'src/Base.php' => "<?php\nnamespace PvCross;\nclass Base {}\n",
)) && $ok;
$ok = pv_real('pvx_b', 'xchild', array('PvCrossChild\\' => 'src/'), array(
	'src/Child.php' => "<?php\nnamespace PvCrossChild;\nclass Child extends \\PvCross\\Base {}\n",
)) && $ok;
$ok = pv_real('pvfn', 'fn', array('GuzzleHttp\\Cookie\\' => 'src/'), array(
	'src/SetCookie.php' => "<?php\nnamespace GuzzleHttp\\Cookie;\nclass SetCookie { const FROM_PLUGIN = true; }\n",
	'functions.php' => "<?php\nfunction pv_fixture_fn_loaded() { return true; }\n",
), array('functions.php')) && $ok;
$ok = pv_real('pvthrowfiles', 'throwfiles', array('PvThrowFiles\\' => 'src/'), array(
	'src/Thing.php' => "<?php\nnamespace PvThrowFiles;\nclass Thing {}\n",
	'boom.php' => "<?php\nthrow new RuntimeException('files entry refused');\n",
), array('boom.php')) && $ok;

if (!$ok) {
	harness_skip('real composer builds for C1-C3', 'composer could not build the fixtures offline');
} else {
	pv_setup(array('pvprobe', 'pvx_a', 'pvx_b'));
	$err = '';
	try { $found = class_exists('PvProbe\\Lib\\Client'); } catch (Throwable $e) { $found = false; $err = $e->getMessage(); }
	check($found, 'a library class that implements a library interface loads, asked first (C1)', $err);
	$err = '';
	try { $found = class_exists('PvCrossChild\\Child'); } catch (Throwable $e) { $found = false; $err = $e->getMessage(); }
	check($found, 'a class in one plugin\'s vendor/ extending one in another plugin\'s loads (C1)', $err);

	pv_setup(array('pvfn'));
	check(ClassAutoloader::requirePluginVendor('pvfn') === true, 'requirePluginVendor() loads a plugin\'s libraries on request (C2)');
	check(function_exists('pv_fixture_fn_loaded'), 'its files entries ran, so a function-only library is usable');
	check(!pv_composer_loader_registered('pvfn'), 'composer\'s loader is not left first in line');
	check(class_exists('GuzzleHttp\\Cookie\\SetCookie') && !defined('GuzzleHttp\\Cookie\\SetCookie::FROM_PLUGIN'),
		'after it, a class core and the plugin both carry resolves to core\'s copy');
	pv_setup(array('pvon'));
	check(ClassAutoloader::requirePluginVendor('pvfn') === false, 'requirePluginVendor() refuses a plugin that is not eligible');

	pv_setup(array('pvthrowfiles'));
	check(!class_exists('PvThrowFiles\\Thing'), 'a plugin whose files entry throws is skipped');
	check(!pv_composer_loader_registered('pvthrowfiles'),
		'and the loader composer registered before running it is taken out of the stack (C3)');
}

section('A files entry that needs another plugin\'s class (reviewer2 C10)');

$okc = pv_real('pvc10_a', 'c10a', array('PvC10A\\' => 'src/'), array(
	'src/Helper.php' => "<?php\nnamespace PvC10A;\nclass Helper { const OK = 1; }\n",
));
$okc = pv_real('pvc10_b', 'c10b', array('PvC10B\\' => 'src/'), array(
	'src/Thing.php' => "<?php\nnamespace PvC10B;\nclass Thing {}\n",
	'init.php' => "<?php\n\$GLOBALS['pv_c10_saw'] = class_exists('PvC10A\\\\Helper');\n",
), array('init.php')) && $okc;
if (!$okc) {
	harness_skip('C10 fixture', 'composer could not build the fixtures offline');
} else {
	pv_setup(array('pvc10_a', 'pvc10_b'));
	$GLOBALS['pv_c10_saw'] = null;
	check(class_exists('PvC10B\\Thing'), 'plugin B loads');
	check($GLOBALS['pv_c10_saw'] === true, 'its files entry, run while B was being required, found a class from A\'s vendor/');
}

section('restrictToCore(): nothing is required');

pv_fixture('pvcoreonly', 'PvCoreOnly', array('Thing' => ''));
$script = $ROOT . '/core_only.php';
file_put_contents($script, "<?php\n"
	. "require " . var_export(PathHelper::getIncludePath('includes/PathHelper.php'), true) . ";\n"
	. "ClassAutoloader::restrictToCore();\n"
	. "ClassAutoloader::usePluginVendorForTest(array('plugins_dir' => " . var_export($PLUGINS, true) . ", 'eligible' => array('pvcoreonly')));\n"
	. "echo class_exists('PvCoreOnly\\\\Thing') ? 'loaded' : 'not loaded';\n"
	. "echo ' required=' . (int)(\$GLOBALS['pv_required']['pvcoreonly'] ?? 0);\n");
$out = trim((string)shell_exec('php ' . escapeshellarg($script) . ' 2>&1'));
check($out === 'not loaded required=0', 'under restrictToCore() no plugin vendor/ is required', $out);

section('The site\'s own path');

ClassAutoloader::usePluginVendorForTest(null);
$src = file_get_contents(PathHelper::getIncludePath('includes/ClassAutoloader.php'));
check(strpos($src, "spl_autoload_register(array(__CLASS__, 'load'));\n\t\t// After load()") !== false
	&& strpos($src, "spl_autoload_register(array(__CLASS__, 'loadPluginVendor'));") !== false,
	'loadPluginVendor() is registered after load()');
check(strpos($src, 'self::active_plugins($complete)') !== false && strpos($src, 'isSandboxedDir(') !== false,
	'the site path reads the active set each miss and asks SandboxPlugins');

harness_finish();
