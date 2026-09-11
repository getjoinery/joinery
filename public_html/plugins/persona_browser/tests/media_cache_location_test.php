<?php
/** @joinery-test
 * name: persona_media_cache_location
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The feed images this plugin downloads are data, and they live outside the code.
 *
 * FetchFeedTask fetches images from a stranger's server and writes them to disk.
 * They used to land in plugins/persona_browser/media_cache — a directory the web
 * server drops remote bytes into, sitting inside the directory the web server
 * runs. That is the shape specs/read_only_tree.md removes: whatever else has to
 * go wrong for one of those files to be executed, none of it can matter if the
 * file is not in the tree.
 *
 * They live in {site}/cache/persona_browser now, served by the plugin's own
 * streamer through readfile() behind a session, never by Apache.
 *
 * Run: php plugins/persona_browser/tests/media_cache_location_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

section('The cache is outside the code tree');

$dir  = PersonaFeedItem::media_cache_dir();
$tree = PathHelper::getRootDir();

check(strpos($dir, rtrim($tree, '/') . '/') !== 0,
	'the cache directory is not under public_html', $dir);
check(strpos($dir, PathHelper::getSiteRoot() . '/cache/') === 0,
	'it is under the site cache directory', $dir);
check(is_dir($dir), 'and it exists (created on first use)');

section('Every caller asks the one accessor');

// Three places touch these bytes — the fetch task that writes them, the sweep
// that expires them, and the view that streams them. A second literal path in
// any of them is a second answer to where the cache lives, and the one that
// gets missed is the one still writing into the tree.
$files = array(
	'tasks/FetchFeedTask.php',
	'data/persona_feed_items_class.php',
	'views/profile/media.php',
);
foreach ($files as $rel) {
	$src = (string)file_get_contents(PathHelper::getIncludePath('plugins/persona_browser/' . $rel));
	check(strpos($src, "plugins/persona_browser/media_cache") === false,
		$rel . ' names no in-tree cache path');
}

$task = (string)file_get_contents(PathHelper::getIncludePath('plugins/persona_browser/tasks/FetchFeedTask.php'));
check(strpos($task, 'PersonaFeedItem::media_cache_dir()') !== false,
	'the fetch task asks the accessor');
$view = (string)file_get_contents(PathHelper::getIncludePath('plugins/persona_browser/views/profile/media.php'));
check(strpos($view, 'PersonaFeedItem::media_cache_dir()') !== false,
	'so does the streamer');

section('Downloaded bytes are not left writable by the whole machine');

// These files are fetched from a remote server and streamed back to members. A
// world-writable cache lets any local account replace one after it is fetched.
check(preg_match('/chmod\s*\([^)]*0666/', $task) !== 1,
	'the fetch task chmods nothing to 0666');

section('The in-tree cache is carried across, not dropped');

// The rows already in the database name these files by basename, and nothing
// re-downloads a file whose row still records it — the heal path in
// FetchFeedTask only fires for a row with no media at all. So a release that
// repointed the directory without moving the bytes would 404 every image on the
// members' page until each post aged out.
$mover = PathHelper::getIncludePath('plugins/persona_browser/provisioning/relocate_media_cache.sh');
check(is_file($mover), 'a relocation step ships with the plugin');
$mv_src = (string)file_get_contents($mover);
check(strpos($mv_src, 'mv -f') !== false && strpos($mv_src, 'rmdir') !== false,
	'it moves the files and removes the old directory only when it is empty');
check(strpos($mv_src, 'id -u') !== false,
	'and does nothing without root, because the tree is not the web user\'s to change');

$manifest = json_decode((string)file_get_contents(
	PathHelper::getIncludePath('plugins/persona_browser/plugin.json')), true);
check(is_array($manifest), 'plugin.json parses');
check(($manifest['host_installer'] ?? '') === 'provisioning/relocate_media_cache.sh',
	'and declares it as the plugin\'s host installer, so a root moment runs it');

harness_finish();
