<?php
/** @joinery-test
 * name: admin_menu_id
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 * covers: [public_html/**.php, public_html/admin_menus.json, public_html/plugins/**plugin.json]
 */
/**
 * Every admin page's 'menu-id' names a menu item that exists.
 *
 * The sidebar opens the section holding the item whose slug the page names.
 * A name no item carries opens nothing, so the menu collapses on that page
 * (the Mailbox pages named 'incoming' long after the item became
 * 'mailbox-reader'). Slugs come from admin_menus.json and every plugin's
 * adminMenu, profileMenu and settingsMenu.
 *
 * UNLISTED names pages that have no menu item of their own; the menu
 * collapses there by design until one is given a home.
 *
 * Run: php tests/run.php safe --filter=admin_menu_id
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$root = dirname(__DIR__, 2);

const UNLISTED = array(
	'system-passkey-lab', 'sealed-secrets', 'conversations', 'ab_tests',
	'email-tools', 'blog-items', 'items-list',
);

$slugs = array();
$walk = function ($node) use (&$walk, &$slugs) {
	if (!is_array($node)) {
		return;
	}
	if (isset($node['slug']) && is_string($node['slug'])) {
		$slugs[$node['slug']] = true;
	}
	foreach ($node as $child) {
		$walk($child);
	}
};
$walk(json_decode((string)file_get_contents($root . '/admin_menus.json'), true));
foreach (glob($root . '/plugins/*/plugin.json') as $manifest) {
	$json = json_decode((string)file_get_contents($manifest), true);
	foreach (array('adminMenu', 'profileMenu', 'settingsMenu') as $key) {
		if (isset($json[$key])) {
			$walk($json[$key]);
		}
	}
}

section('Menu slugs');
check(count($slugs) > 50, 'menu slugs were read (' . count($slugs) . ')');

section('Every menu-id names a menu item');
$bad = array();
$seen = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
	$path = $file->getPathname();
	if (substr($path, -4) !== '.php' || preg_match('#/(vendor|specs|node_modules)/#', $path)
			|| strpos($path, '/includes/scaffold/templates/') !== false) {
		continue;
	}
	$src = (string)file_get_contents($path);
	if (strpos($src, 'menu-id') === false) {
		continue;
	}
	if (!preg_match_all('/[\'"]menu-id[\'"]\s*=>\s*[\'"]([a-z0-9_-]+)[\'"]\s*[,)\n]/', $src, $m)) {
		continue;
	}
	foreach ($m[1] as $slug) {
		$seen++;
		if (!isset($slugs[$slug]) && !in_array($slug, UNLISTED, true)) {
			$bad[] = substr($path, strlen($root) + 1) . ' names ' . $slug;
		}
	}
}
check($seen > 100, 'menu-id declarations were found (' . $seen . ')');
check(empty($bad), 'no page names a menu item that does not exist'
	. (empty($bad) ? '' : ': ' . implode('; ', $bad)));

section('UNLISTED stays honest');
foreach (UNLISTED as $slug) {
	check(!isset($slugs[$slug]), $slug . ' still has no menu item (drop it from UNLISTED once it does)');
}

harness_finish();
