<?php
/** @joinery-test
 * name: plugin_dependents
 * tier: db
 * env: any
 * needs: []
 * covers: [public_html/includes/PluginManager.php]
 */
/**
 * Which plugins keep another one switched on (PluginManager::getDependents()).
 *
 * The answer comes from each installed plugin's live plugin.json. It once came
 * from rows written only when a plugin was validated, so a depends added after
 * a plugin's activation was never recorded and the store could be switched off
 * under event_manager (spec plugin_roles B1). Deactivation counts only active
 * dependents; uninstall counts every installed one (B2). What this pins,
 * against the plugin registry as it stands, with no writes:
 *   - every installed plugin whose manifest depends on X is a dependent of X
 *   - with active_only, exactly the active ones are
 *   - nothing else is, and an uninstalled row never is
 *
 * Run:  php tests/functional/plugins/plugin_dependents_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();

$pm = PluginManager::getInstance();
$get_dependents = new ReflectionMethod($pm, 'getDependents');
$get_dependents->setAccessible(true);

// What the manifests say: dependency name => [dependent => active?].
$declared = array();
$uninstalled = array();
foreach (new MultiPlugin() as $row) {
	$name = (string)$row->get('plg_name');
	if ($row->is_uninstalled()) {
		$uninstalled[] = $name;
		continue;
	}
	$manifest = $pm->liveManifest($name);
	if (!is_array($manifest) || empty($manifest['depends']) || !is_array($manifest['depends'])) {
		continue;
	}
	foreach (array_keys($manifest['depends']) as $dep) {
		$declared[$dep][$name] = $row->is_active();
	}
}

section('The registry has something to check');
check(!empty($declared), 'at least one installed plugin declares a depends', 'no depends found: the checks below would pass vacuously');

foreach ($declared as $dep => $dependents) {
	section("Dependents of $dep");
	$all = $get_dependents->invoke($pm, $dep);
	$active = $get_dependents->invoke($pm, $dep, true);
	sort($all);
	sort($active);

	$expected_all = array_keys($dependents);
	$expected_active = array_keys(array_filter($dependents));
	sort($expected_all);
	sort($expected_active);

	check($all === $expected_all, "every installed plugin that depends on $dep, and nothing else",
		'got [' . implode(', ', $all) . '], manifests say [' . implode(', ', $expected_all) . ']');
	check($active === $expected_active, "active_only gives just the active ones",
		'got [' . implode(', ', $active) . '], manifests say [' . implode(', ', $expected_active) . ']');
	check(empty(array_intersect($all, $uninstalled)), 'no uninstalled plugin is counted');
}

section('A plugin nothing depends on');
$free = null;
foreach (new MultiPlugin() as $row) {
	$name = (string)$row->get('plg_name');
	if (!isset($declared[$name]) && !$row->is_uninstalled()) {
		$free = $name;
		break;
	}
}
if ($free !== null) {
	check($get_dependents->invoke($pm, $free) === array(), "$free has no dependents");
}

harness_finish();
