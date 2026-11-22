<?php
/** @joinery-test
 * name: plugin_composer_tier_removed
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * The plugin composer tier is gone (specs/plugin_own_vendor.md WP4).
 *
 * A plugin ships its PHP libraries in its own vendor/, which its signature
 * covers. Nothing may add a plugin's library to the site's shared
 * composer.json, composer.lock or vendor/: those files are signed, so a
 * plugin that did would make the code check report core as tampered. This
 * reads the tree for any name of the old path, and checks activation
 * refuses a plugin.json that still asks for it.
 *
 * Run: php tests/run.php --filter=plugin_composer_tier_removed
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$NAMES = array('reconcilePluginPackages', 'pluginPackagesPresent', 'collectPluginComposerRequires',
	'PluginComposerNeedsRootException', 'reconcile_composer', 'joinery-plugin-packages');

$base = rtrim(PathHelper::getBasePath(), '/');
$site = dirname($base);
$hits = array();
$files = 0;
foreach (array($base, $site . '/maintenance_scripts') as $root) {
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		$path = $f->getPathname();
		if (strpos($path, $base . '/specs/') === 0 || strpos($path, '/vendor/') !== false
				|| strpos($path, '/.git/') !== false || strpos($path, '/node_modules/') !== false) {
			continue;
		}
		if (!preg_match('/\.(php|sh|md|json)$/', $path) || realpath($path) === realpath(__FILE__)) {
			continue;
		}
		$files++;
		$src = (string)@file_get_contents($path);
		foreach ($NAMES as $name) {
			if (strpos($src, $name) === false) { continue; }
			// A version line recording the removal is history, not a use.
			$uses = 0;
			foreach (explode("\n", $src) as $line) {
				if (strpos($line, $name) !== false && !preg_match('/@version|# Version:|reconcile_composer is gone|reconcilePluginPackages\(\) can work|!in_array\(.reconcile_composer./', $line)) {
					$uses++;
				}
			}
			if ($uses > 0) { $hits[] = $name . ' in ' . substr($path, strlen($site) + 1); }
		}
	}
}

section('Nothing names the old plugin composer path');
check($files > 500, 'the scan read the tree', $files . ' files');
check(empty($hits), 'no file outside specs/ uses a removed name', implode('; ', $hits));

section('What took its place');
check(!in_array('reconcile_composer', RootRequest::KINDS, true), 'RootRequest has no reconcile_composer kind');

$src = file_get_contents(PathHelper::getIncludePath('includes/PluginManager.php'));
check(strpos($src, "isset(\$manifest['requires']['composer'])") !== false
	&& strpos($src, "ship the library in the plugin's own vendor/") !== false,
	'validatePlugin() refuses a plugin.json with requires.composer, saying what to do instead');

$cv = file_get_contents(PathHelper::getIncludePath('includes/ComposerValidator.php'));
check(strpos($cv, 'composer require') === false, 'ComposerValidator never runs composer require');

harness_finish();
