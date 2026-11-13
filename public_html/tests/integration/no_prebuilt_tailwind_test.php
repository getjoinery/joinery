<?php
/** @joinery-test
 * name: no_prebuilt_tailwind
 * tier: safe
 * env: any
 * needs: []
 * covers: [theme/**, plugins/**, assets/css/**]
 */
/**
 * Themes and plugins style their pages with plain CSS of their own. A
 * pre-built Tailwind stylesheet holds only the classes its last build found,
 * so a view that adds a class without a rebuild breaks its page: on
 * 2026-10-21 the xandyliberato.com home page video collapsed for exactly that
 * reason. This keeps one from coming back unnoticed: no stylesheet in the
 * tree carries Tailwind's own variables (--tw-) or its banner.
 *
 * Run: php tests/run.php --only=tests/integration/no_prebuilt_tailwind_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$root = rtrim(PathHelper::getAbsolutePath(''), '/');

section('No stylesheet is a Tailwind build');
$found = array();
$count = 0;
foreach (array('theme', 'plugins', 'assets') as $top) {
	$dir = $root . '/' . $top;
	if (!is_dir($dir)) continue;
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $file) {
		if (strtolower($file->getExtension()) !== 'css') continue;
		$count++;
		$css = (string)file_get_contents($file->getPathname());
		if (strpos($css, '--tw-') !== false || stripos($css, 'tailwindcss') !== false) {
			$found[] = substr($file->getPathname(), strlen($root) + 1);
		}
	}
}
check($count > 0, "stylesheets were read ($count)");
check($found === array(), 'no stylesheet carries --tw- variables or the tailwindcss banner', implode(', ', $found));

harness_finish();
