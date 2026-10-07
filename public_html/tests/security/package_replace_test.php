<?php
/** @joinery-test
 * name: package_replace
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * Replacing an installed package from an upload, and the fork model
 * (specs/package_replace_on_upload.md WP1, WP3).
 *
 * The dispatcher and the installer run as root, from a request file the web
 * user wrote, so what they will and will not do on the strength of that file
 * is pinned here by reading their source — the same way root_request_test
 * pins the acknowledgement. The fork mark and the refusals are exercised on
 * the managers against the tree this box runs.
 *
 * Run: php tests/run.php --only=tests/security/package_replace_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$dispatcher = (string)file_get_contents(PathHelper::getIncludePath('utils/root_request.php'));
$installer  = (string)file_get_contents(PathHelper::getIncludePath('utils/install_extension.php'));
$managers   = (string)file_get_contents(PathHelper::getIncludePath('includes/AbstractExtensionManager.php'));
$page       = (string)file_get_contents(PathHelper::getIncludePath('includes/PackageInstallPage.php'));

section('The dispatcher passes --replace only for a literal true');

$package_case = substr($dispatcher, strpos($dispatcher, "case 'install_package'"));
$package_case = substr($package_case, 0, strpos($package_case, "\tcase '"));
check(strpos($package_case, "(\$args['replace'] ?? false) === true") !== false,
	'the request\'s replace argument counts only when it is the boolean true');
check(strpos($package_case, "\$cmd .= ' --replace'") !== false,
	'and then the installer is handed --replace and nothing else from it');
check(substr_count($package_case, "' --replace'") === 1, 'there is one place --replace is added');

section('The installer sets a live copy aside only after the verdict and the acknowledgement');

$verify_at   = strpos($installer, 'install_extension_verify($dir, $tree_rel . $staged_name, true)');
$refuse_at   = strpos($installer, 'exit(EXIT_UNVERIFIED);');
$aside_at    = strpos($installer, "\$keep = \$target . '.replaced.' . gmdate('YmdHis');");
$rename_at   = strpos($installer, '@rename($target, $keep)');
$copy_at     = strpos($installer, 'install_extension_copy_tree($dir, $target)');
check($verify_at !== false && $refuse_at !== false && $aside_at !== false && $verify_at < $refuse_at && $refuse_at < $aside_at,
	'the verdict is reached and an unacknowledged refusal exits before any live directory is moved');
check($rename_at !== false && $copy_at !== false && $rename_at < $copy_at,
	'the live copy is set aside, then the verified copy is placed');
check(strpos($installer, "if (!\$replace) {") !== false && strpos($installer, 'exit(2);') !== false,
	'without --replace an installed name is refused with exit 2');
check(strpos($installer, '$manager->isSystemExtension($staged_name)') !== false,
	'a system extension is refused rather than replaced');
check(strpos($installer, "@rename(\$dest_parent . '/' . \$replaced['kept'], \$target)") !== false,
	'a copy that fails part way puts the set-aside copy back');

section('An uploaded package is a local fork, in the manifest, before the database half');

$mark_at     = strpos($installer, 'install_extension_mark_fork($type, $name, $manager, $target);');
$register_at = strpos($installer, '// ---- the database half');
check($mark_at !== false && $copy_at < $mark_at && $register_at !== false && $mark_at < $register_at,
	'the fork mark is written after the files are in place and before anything registers them');
check(strpos($installer, '$manager->writeManifestReceivesUpgrades($name, false)') !== false,
	'the mark is receives_upgrades: false in the live manifest');
check(strpos($installer, "\$arg === '--uploaded'") === false && strpos($installer, "thm_receives_upgrades") === false,
	'the row is never written directly: the sync carries the manifest\'s value onto it (B6)');
check(strpos($installer, "'package_replaced'") !== false && strpos($installer, 'replaced $type $name') !== false,
	'a replacement is said on the transcript and recorded in the event log');
check(strpos($installer, 'ClassAutoloader::flush()') !== false,
	'the class map is flushed after a plugin\'s files change');

section('The marketplace refuses to put the catalog copy over a fork');

$refresh = substr($managers, strpos($managers, 'public function refreshFromUpstream'));
$refresh = substr($refresh, 0, strpos($refresh, 'protected static function refuse_from_web'));
$fork_check_at = strpos($refresh, '$this->isLocalFork($name)');
$fetch_at      = strpos($refresh, 'curl_init()');
check($fork_check_at !== false && $fetch_at !== false && $fork_check_at < $fetch_at,
	'refreshFromUpstream() asks whether the name is a fork before it fetches anything');
check(strpos($managers, "throw new Exception(\$this->localForkRefusal(\$extension_name));") !== false,
	'installFromTarGz() refuses the same way');

$plugins = new PluginManager();
$themes = new ThemeManager();
$sentence = $plugins->localForkRefusal('x');
check(strpos($sentence, 'Allow upgrade') !== false && strpos($sentence, 'Plugins page') !== false,
	'the refusal names Allow upgrade and the page it is on', $sentence);
check(strpos($themes->localForkRefusal('x'), 'Themes page') !== false, 'for a theme, the Themes page');

section('The managers read the mark from the live manifest');

// Against this box's own tree: every shipped manifest says true, and the
// three system themes say is_system.
$shipped_plugin = is_dir(PathHelper::getAbsolutePath('plugins/vault')) ? 'vault' : null;
if ($shipped_plugin !== null) {
	check($plugins->isLocalFork($shipped_plugin) === false, "a shipped plugin ($shipped_plugin) is not a fork");
	check(is_array($plugins->liveManifest($shipped_plugin)), 'and its live manifest is read');
}
check($plugins->isLocalFork('no_such_plugin_zz') === false, 'an absent plugin is not a fork');
check($plugins->isSystemExtension('no_such_plugin_zz') === false, 'nor a system extension');
if (is_dir(PathHelper::getAbsolutePath('theme/default'))) {
	check($themes->isSystemExtension('default') === true, 'the default theme is a system extension');
}
if (is_dir(PathHelper::getAbsolutePath('theme/getjoinery'))) {
	check($themes->isSystemExtension('getjoinery') === false, 'a page theme that is not system is not');
}

section('The page carries replace through the acknowledgement and never decides the verdict');

check(strpos($page, "'replace'    => (((\$args['replace'] ?? false) === true)") !== false
	|| strpos($page, "'replace'    => ((\$args['replace'] ?? false) === true)") !== false,
	'a refused request remembers whether it was a replacement');
check(strpos($page, "if (\$refused['replace']) {") !== false && strpos($page, "\$args['replace'] = true;") !== false,
	'and Install anyway re-submits it as one, so an acknowledged replacement is not refused as already installed');
$args = PackageInstallPage::replaceArgs('theme', 'theme_abc/oceanlook');
check($args === array('type' => 'theme', 'staged_dir' => 'theme_abc/oceanlook', 'replace' => true),
	'Replace queues type, staged_dir and replace: true, and nothing about trust');
check(strpos($page, '$manager->isSystemExtension($name)') !== false,
	'an upload naming a system extension is refused on the page');

harness_finish();
