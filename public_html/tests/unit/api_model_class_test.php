<?php
/** @joinery-test
 * name: api_model_class
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * The REST API's lookup of the one model a URL names.
 *
 * apiv1.php asks LibraryFunctions::api_model_class() whether a name is a
 * model it serves, in place of building the whole list with
 * discover_model_classes() on every request (spec sandboxed_plugin_ask_speed,
 * WP1). The two must agree on every class, or an endpoint appears or
 * disappears: every class discovery returns over core and the active plugins
 * is found by its own name, and every other class this process has loaded,
 * every class of an inactive plugin, and every respelling is not. Reads only.
 * Activation and deactivation of a sandboxed plugin are held by
 * tests/functional/sandbox/sandbox_data_test.php.
 *
 * Run: php tests/unit/api_model_class_test.php
 *
 * @version 1.1 - a cold lookup (fresh process) and the autoloader map agree with discovery (reviewer2 N1)
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$discovered = LibraryFunctions::discover_model_classes(array('include_plugins' => true, 'plugin_status' => 'active'));
$is_discovered = array_flip($discovered);

section('Every discovered model is found by its name');
check(count($discovered) > 50, 'Discovery returns the site\'s models', (string)count($discovered));
$missed = array();
foreach ($discovered as $class) {
	if (LibraryFunctions::api_model_class($class) !== $class) {
		$missed[] = $class;
	}
}
check($missed === array(), 'Each one, ' . count($discovered) . ' in all', implode(', ', $missed));

section('Each is found from a cold start');
// The API asks in a process that has loaded no model, so what it finds is
// what the autoloader's map resolves. Discovery above already loaded them
// all here; this holds the map to it: every file-backed model is mapped to a
// *_class.php in core data/ or a plugin's data/.
$map = ClassAutoloader::map();
$unmapped = array();
foreach ($discovered as $class) {
	if (preg_match(SandboxModels::CLASS_PATTERN, $class)) {
		continue;
	}
	$path = $map[$class] ?? null;
	if (!is_string($path) || !preg_match('~(^|/)data/[^/]+_class\.php$~', $path)) {
		$unmapped[] = $class . ' => ' . json_encode($path);
	}
}
check($unmapped === array(), 'Every file-backed model is in the autoloader map at a data/*_class.php', implode(', ', array_slice($unmapped, 0, 10)));
$cold = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
	'chdir(' . var_export(PathHelper::getBasePath(), true) . '); require "includes/PathHelper.php";'
	. ' require_once PathHelper::getIncludePath("includes/Globalvars.php");'
	. ' $miss = array(); foreach (json_decode($argv[1], true) as $c) { if (LibraryFunctions::api_model_class($c) !== $c) { $miss[] = $c; } }'
	. ' echo json_encode($miss);'
) . ' ' . escapeshellarg(json_encode($discovered)) . ' 2>/dev/null');
$cold_missed = json_decode((string)$cold, true);
check(is_array($cold_missed) && $cold_missed === array(), 'and by a fresh process that has run no discovery', is_array($cold_missed) ? implode(', ', $cold_missed) : substr((string)$cold, 0, 300));

section('No other loaded class is');
// Discovery required every model file, so this holds each Multi class, each
// helper class a data file declares, and every includes/ class loaded so far.
$others = 0;
$wrongly = array();
foreach (get_declared_classes() as $class) {
	if (isset($is_discovered[$class]) || !(new ReflectionClass($class))->isUserDefined()) {
		continue;
	}
	$others++;
	if (LibraryFunctions::api_model_class($class) !== null) {
		$wrongly[] = $class;
	}
}
check($others > 50, 'Other classes are loaded to ask about', (string)$others);
check($wrongly === array(), 'None of them', implode(', ', $wrongly));
check(LibraryFunctions::api_model_class('SystemBase') === null, 'SystemBase itself is not a model');
check(LibraryFunctions::api_model_class('stdClass') === null, 'A built-in class is not');

section('An inactive plugin\'s models are not');
$base = PathHelper::getBasePath();
$inactive_classes = array();
foreach (LibraryFunctions::list_plugins() as $plugin) {
	if (PluginHelper::isActiveOfEitherKind($plugin)) {
		continue;
	}
	foreach (glob($base . '/plugins/' . $plugin . '/data/*_class.php') ?: array() as $file) {
		$tokens = token_get_all((string)file_get_contents($file));
		for ($i = 0; $i + 2 < count($tokens); $i++) {
			if (is_array($tokens[$i]) && $tokens[$i][0] === T_CLASS && is_array($tokens[$i + 2]) && $tokens[$i + 2][0] === T_STRING) {
				$inactive_classes[] = $tokens[$i + 2][1];
			}
		}
	}
}
$found = array();
foreach ($inactive_classes as $class) {
	if (!isset($is_discovered[$class]) && LibraryFunctions::api_model_class($class) !== null) {
		$found[] = $class;
	}
}
check($found === array(), count($inactive_classes) . ' classes of inactive plugins', implode(', ', $found));

section('A respelling or a non-name is not');
$respelled = array();
foreach ($discovered as $class) {
	foreach (array(strtolower($class), strtoupper($class), ucfirst(strtolower($class))) as $variant) {
		if ($variant !== $class && !isset($is_discovered[$variant]) && LibraryFunctions::api_model_class($variant) !== null) {
			$respelled[] = $variant;
		}
	}
}
check($respelled === array(), 'No model answers to another case', implode(', ', array_slice($respelled, 0, 10)));
foreach (array('', ' User', 'User ', '../User', 'User/1', 'Sbx_nosuchplugin_Thing', 'MultiUser', 'NoSuchModelHere') as $name) {
	check(LibraryFunctions::api_model_class($name) === null, 'Not a model: ' . json_encode($name));
}

harness_finish();
