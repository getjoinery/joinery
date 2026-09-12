<?php
/** @joinery-test
 * name: extension_staging
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Uploading an extension: unpacked and checked by the web user, verified and
 * installed by root.
 *
 * The web user cannot write the code tree (specs/read_only_tree.md). The queue
 * and the staging area are both web-writable, so a request to install a staged
 * directory proves nothing about who asked — which is why root verifies the
 * package against the release key before it moves anything, and installs an
 * unverified one only on the owner's acknowledgement, under the unsigned
 * restrictions (specs/package_signing.md). What the web side does is open the
 * archive somewhere it can do no harm and refuse everything wrong with it.
 *
 * Run: php tests/unit/extension_staging_test.php
 *
 * @version 1.2 - only an uploaded theme is preserved on deploy (review round 2, R2)
 * @version 1.1 - pins the verification step (specs/package_signing.md WP2)
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$work = harness_scratch_dir('extension_staging');
$made = array();

/** Build a zip from [entry => bytes]. */
$zip_of = function (string $name, array $entries) use ($work, &$made): string {
	$path = $work . '/' . $name;
	@unlink($path);
	$z = new ZipArchive();
	$z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
	foreach ($entries as $entry => $bytes) {
		$z->addFromString($entry, $bytes);
	}
	$z->close();
	$made[] = $path;
	return $path;
};

$manifest = json_encode(array('name' => 'stagetest', 'version' => '1.0.0'));
$rmtree = function ($p) use (&$rmtree) {
	foreach (glob(rtrim($p, '/') . '/*') ?: array() as $f) {
		is_dir($f) ? $rmtree($f) : @unlink($f);
	}
	@rmdir($p);
};

$manager = new PluginManager();

section('What is staged is named after the extension');

// Both archive shapes have to land at <id>/<name>/. Staging straight into <id>/
// meant an archive with its manifest at the top level installed as
// `plugin_3f9a1c…` — which validateName accepts, and which is not what the page
// told the operator it was installing.
foreach (array(
	'flat'   => array('plugin.json' => $manifest, 'index.php' => "<?php\n"),
	'nested' => array('inner/plugin.json' => $manifest, 'inner/index.php' => "<?php\n"),
) as $shape => $entries) {
	$staged = $manager->stage($zip_of($shape . '.zip', $entries));
	check(basename($staged['dir']) === $staged['name'],
		"a $shape archive stages under its own name",
		'dir ' . basename($staged['dir']) . ', name ' . $staged['name']);
	check(strpos($staged['dir'], PathHelper::getSiteRoot() . '/uploads/staging/') === 0,
		"and under uploads/staging, outside the tree", $staged['dir']);
	check(strpos($staged['dir'], PathHelper::getRootDir()) !== 0,
		'never inside public_html',
		'an archive opened inside the tree is an archive that has already arrived');
	$rmtree(dirname($staged['dir']));
}

section('Everything wrong with an archive fails here, as the web user');

// Each of these would be a file in the tree if it got past staging.
$hostile = array(
	'a path that escapes'      => array('../../evil.php' => "<?php\n", 'plugin.json' => $manifest),
	'an absolute path'         => array('/etc/evil.php' => "<?php\n", 'plugin.json' => $manifest),
	'no manifest at all'       => array('index.php' => "<?php\n"),
);
foreach ($hostile as $label => $entries) {
	$threw = false;
	try {
		$staged = $manager->stage($zip_of('hostile.zip', $entries));
		$rmtree(dirname($staged['dir']));
	} catch (Exception $e) {
		$threw = true;
	}
	check($threw, "$label is refused");
}

// A staging failure must not leave the unpacked bytes lying around.
$before = count(glob(PathHelper::getSiteRoot() . '/uploads/staging/*') ?: array());
try { $manager->stage($zip_of('hostile.zip', array('index.php' => "<?php\n"))); } catch (Exception $e) {}
check(count(glob(PathHelper::getSiteRoot() . '/uploads/staging/*') ?: array()) === $before,
	'a refused archive leaves nothing in staging');

section('Installing a staged package is root\'s to do, from its own copy');

$installer = (string)file_get_contents(PathHelper::getIncludePath('utils/install_extension.php'));

// rename() across a mount point is EXDEV, and uploads/ and the code tree are
// separate volumes on every containerised site — so this is every container,
// not an edge case.
check(strpos($installer, 'install_extension_copy_tree') !== false,
	'the staged directory is copied into the tree, not renamed',
	'uploads/ and the code are separate volumes in a container; rename() is EXDEV there');
check(preg_match('/if \(!rename\(\$dir, \$target\)\)/', $installer) !== 1,
	'and the rename that could not work is gone');

// Replacing an installed extension is a different act from installing one.
check(strpos($installer, '--replace') !== false,
	'replacing an installed extension takes --replace');
check(strpos($installer, 'install_extension_prune_replaced') !== false,
	'and the copies it sets aside do not accumulate forever');

// refreshFromUpstream() does not skip an existing directory, so running it after
// a staged install downloaded upstream over the operator's own upload — and the
// by-name form has already fetched and verified by the time install() runs, so
// a second fetch there was a second download whose files nobody re-owned.
check(strpos($installer, '$manager->install($name, false)') !== false,
	'the database half never re-fetches the plugin from upstream',
	'that clobbered a local fork of a marketplace plugin seconds after installing it');
$pm = (string)file_get_contents(PathHelper::getIncludePath('includes/PluginManager.php'));
check(preg_match('/function install\(\$name, \$refresh_files = true\)/', $pm) === 1,
	'install() takes the refresh as a parameter rather than always doing it');
// The fetch is the base class's, so a theme is fetched and verified exactly
// as a plugin is (WP4).
$pm = (string)file_get_contents(PathHelper::getIncludePath('includes/AbstractExtensionManager.php'));

section('Root verifies the package before it moves it (specs/package_signing.md WP2)');

// The copy into root's working directory is the last thing that happens before
// the question "who built this?" is asked, and the answer decides whether the
// bytes go anywhere at all.
$verify_at = strpos($installer, 'install_extension_verify($dir, $tree_rel . $staged_name)');
$move_at   = strpos($installer, 'install_extension_copy_tree($dir, $target)');
check($verify_at !== false && $copy_at !== false && $copy_at < $verify_at,
	'the staged copy is verified after it is copied out of staging');
check($move_at !== false && $verify_at < $move_at,
	'and before it is copied into the tree');
check(strpos($installer, 'exit(EXIT_UNVERIFIED)') !== false && strpos($installer, 'const EXIT_UNVERIFIED = 3') !== false,
	'anything but `signed` exits 3, the code the page reads as "not ours"');
check(strpos($installer, "echo 'verdict: ' . \$verdict->line()") !== false,
	'and the verdict is one line in the transcript');
// An uploaded theme is a local fork the upgrade must not replace; a theme
// fetched by name from the marketplace is ours and keeps receiving upgrades.
$register_fn = substr($installer, strpos($installer, 'function install_extension_register('));
$register_fn = substr($register_fn, 0, strpos($register_fn, "\n}\n"));
check(strpos($register_fn, "if (\$uploaded) {") !== false
	&& strpos($register_fn, "\$theme->set('thm_receives_upgrades', false)") !== false
	&& strpos($register_fn, "if (\$uploaded) {") < strpos($register_fn, "thm_receives_upgrades"),
	'only an uploaded theme is marked preserved on deploy');
check(substr_count($installer, "install_extension_register(\$type, \$name, \$manager, \$staged !== '')") === 2
	&& strpos($installer, "install_extension_register_as_web_user(\$type, \$name, \$staged !== '')") !== false,
	'and "uploaded" means the staged form, nothing else');
// The by-name form fetches into a working directory and verifies THERE; the
// live plugins/<name> is replaced only by a package that said `signed`.
check(strpos($pm, 'PackageSignature::verify($fetched)') !== false
	&& strpos($pm, 'throw new PackageUnverifiedException($verdict)') !== false,
	'a marketplace download is verified in a working directory and refused outright when it is not ours');
check(strpos($pm, '$phar->extractTo($plugins_root') === false,
	'nothing is ever extracted straight over the live plugin directory');
// A run killed between setting the old copy aside and removing it must not
// leave a plugin-shaped directory a filesystem sync would register.
check(strpos($pm, "\$plugins_root . '/.refresh-' . \$name . '-' . getmypid()") !== false,
	'the old copy is set aside under a dot-prefixed name');
check(strpos($pm, "glob(\$plugins_root . '/.refresh-*')") !== false,
	'and stale set-aside copies are swept at the start of the next refresh');
check(strpos($pm, "'.refresh.'") === false, 'no plugin-shaped set-aside name remains');

section('A zip bomb is refused on the archive\'s own numbers');

// A few hundred kilobytes of zeros unpacks to gigabytes and fills the volume
// the site's uploads, database WAL and logs all share. The entry sizes come out
// of the archive directory, so the refusal costs nothing and happens before a
// byte is written.
check(is_int(AbstractExtensionManager::MAX_UNPACKED_BYTES)
	&& AbstractExtensionManager::MAX_UNPACKED_BYTES > 0,
	'the cap is a named constant, not a number in the middle of a method',
	'MAX_UNPACKED_BYTES = ' . AbstractExtensionManager::MAX_UNPACKED_BYTES);

$bomb = $work . '/bomb.zip';
@unlink($bomb);
$z = new ZipArchive();
$z->open($bomb, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$z->addFromString('bomb/plugin.json', $manifest);
// Zeros compress to almost nothing, which is the whole trick: this file is a
// few kilobytes on disk and over the cap when unpacked.
$z->addFromString('bomb/payload.bin',
	str_repeat("\0", AbstractExtensionManager::MAX_UNPACKED_BYTES + 1024));
$z->close();
$made[] = $bomb;

check(filesize($bomb) < 2 * 1024 * 1024,
	'the archive itself is small', filesize($bomb) . ' bytes on disk');

$refused = '';
$staged_dir = '';
try {
	$r = $manager->stage($bomb);
	$staged_dir = (string)($r['dir'] ?? '');
} catch (Exception $e) {
	$refused = $e->getMessage();
}
check(stripos($refused, 'over the') !== false && stripos($refused, 'limit') !== false,
	'and it is refused for what it unpacks to', $refused);
check($staged_dir === '' || !is_dir($staged_dir),
	'nothing is left staged');
// Not merely refused — never written. Anything under uploads/staging carrying
// the payload would mean the disk filled before the check ran.
$leftover = glob(PathHelper::getSiteRoot() . '/uploads/staging/plugin_*/_unpacked/bomb/payload.bin') ?: array();
check($leftover === array(), 'and the payload never reached the disk');

section('Root reads its own copy, never the web user\'s');

// uploads/staging belongs to www-data, which is the account this whole spec
// assumes can be made to write a file. Checking the staged directory and then
// installing from it leaves a window in between: the check passes, a symlink
// appears, and the install follows it.
check(strpos($installer, 'cp -a --no-dereference') !== false,
	'the staged tree is copied out of staging before anything in it is read',
	'and as symlinks, not through them, so the check below sees what the archive carried');
check(strpos($installer, "shell_exec('mktemp -d") !== false,
	'into a directory created 0700 in one step');
$copy_at  = strpos($installer, 'cp -a --no-dereference');
$link_at  = strpos($installer, "staged tree contains a symlink");
check($copy_at !== false && $link_at !== false && $copy_at < $link_at,
	'and the symlink check runs on the copy, not on staging');
check(strpos($installer, 'install_extension_rmtree($staging_dir)') !== false,
	'staging is cleared once the install is done');

foreach ($made as $f) { @unlink($f); }
@rmdir($work);

harness_finish();
