<?php
/**
 * install_extension.php — put a plugin or theme into the tree, as root.
 *
 * The tree belongs to root and the PHP pool cannot write it
 * (specs/read_only_tree.md), so installing an extension — which is nothing but
 * writing code into the tree — stopped being something a web request can do.
 * It is a root request, and this is what carries it out.
 *
 *   php utils/install_extension.php plugin|theme <name>
 *   php utils/install_extension.php plugin|theme --staged=<dir>
 *
 * The first form fetches from the upgrade source, which is what the marketplace
 * install does. The second takes a directory the web side already unpacked and
 * checked under uploads/staging — root never opens a stranger's archive. A zip
 * bomb, a path escape or a symlink fails as the web user, in staging, where it
 * can do nothing.
 *
 * @version 1.1 - A staged directory is copied into a root-owned working
 *                directory before anything in it is read. uploads/staging is
 *                www-data's, so checking it and then installing from it leaves
 *                a window in which a symlink can appear between the two.
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo "CLI only\n";
	exit(2);
}

require_once(__DIR__ . '/../includes/PathHelper.php');

$type = isset($argv[1]) ? (string)$argv[1] : '';
$name = '';
$staged = '';
$replace = false;
foreach (array_slice($argv, 2) as $arg) {
	if (strpos($arg, '--staged=') === 0) {
		$staged = substr($arg, strlen('--staged='));
	} elseif ($arg === '--replace') {
		$replace = true;
	} elseif ($arg !== '' && $arg[0] !== '-') {
		$name = $arg;
	}
}

if (!in_array($type, array('plugin', 'theme'), true) || ($name === '' && $staged === '')) {
	fwrite(STDERR, "Usage: install_extension.php plugin|theme <name>\n"
		. "       install_extension.php plugin|theme --staged=<dir> [--replace]\n");
	exit(2);
}

$manager = ($type === 'theme') ? new ThemeManager() : new PluginManager();
$dest_parent = PathHelper::getAbsolutePath($type === 'theme' ? 'theme' : 'plugins');

/**
 * Copy a directory tree. rename() cannot be used: uploads/ and the code tree are
 * separate Docker volumes on every containerised site, and rename() across a
 * mount point fails with EXDEV — which is every container, not an edge case.
 */
function install_extension_copy_tree(string $from, string $to): bool {
	if (!is_dir($from)) {
		return false;
	}
	if (!is_dir($to) && !@mkdir($to, 0755, true)) {
		return false;
	}
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST);
	foreach ($it as $item) {
		$dest = $to . '/' . $it->getSubPathName();
		if ($item->isLink()) {
			// Checked in staging already; refused again here because this is
			// the step that would put it in the tree.
			return false;
		}
		if ($item->isDir()) {
			if (!is_dir($dest) && !@mkdir($dest, 0755, true)) {
				return false;
			}
		} elseif (!@copy($item->getPathname(), $dest)) {
			return false;
		}
	}
	return true;
}

/** Remove a directory tree. Used to clear staging, and to clean up a half-copy. */
function install_extension_rmtree(string $path): void {
	if (!is_dir($path)) {
		@unlink($path);
		return;
	}
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($it as $item) {
		$item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
	}
	@rmdir($path);
}

/**
 * Keep the most recent replaced copy and remove the rest.
 *
 * Without this, every re-install leaves another `<name>.replaced.<time>` in
 * plugins/ forever — directories full of PHP that nothing loads but everything
 * scanning the tree has to walk past.
 */
function install_extension_prune_replaced(string $parent, string $name): void {
	$kept = glob($parent . '/' . $name . '.replaced.*') ?: array();
	if (count($kept) <= 1) {
		return;
	}
	sort($kept);
	array_pop($kept);           // the newest stays
	foreach ($kept as $old) {
		install_extension_rmtree($old);
		echo 'removed an older replaced copy: ' . basename($old) . "\n";
	}
}

/** Give a path the tree's owner: root on a node, the developer's account on dev. */
function install_extension_own(string $path): void {
	$tree = PathHelper::getRootDir();
	$uid = @fileowner($tree);
	$gid = @filegroup($tree);
	if ($uid === false || $gid === false || !file_exists($path)) {
		return;
	}
	$apply = function ($p, $is_dir) use ($uid, $gid) {
		@chown($p, $uid);
		@chgrp($p, $gid);
		@chmod($p, $is_dir ? 0755 : 0644);
	};
	$apply($path, is_dir($path));
	if (!is_dir($path)) {
		return;
	}
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST);
	foreach ($it as $p) {
		$apply($p->getPathname(), $p->isDir());
	}
}

try {

	if ($staged !== '') {
		// ---- from a directory the web side unpacked and checked ------------
		$dir = realpath($staged);
		$staging_root = realpath(PathHelper::getSiteRoot() . '/uploads/staging');
		if ($dir === false || $staging_root === false
			|| strpos($dir, $staging_root . '/') !== 0 || !is_dir($dir)) {
			fwrite(STDERR, "install_extension: staged path is not under uploads/staging\n");
			exit(2);
		}

		// Themes and plugins are addressed on disk by directory name, which is
		// what the web side's stage() resolved the manifest against; the
		// manifest's display name is a label, not an address.
		$staged_name = basename($dir);
		if (!$manager->validateName($staged_name)) {
			fwrite(STDERR, "install_extension: invalid $type name '$staged_name'\n");
			exit(2);
		}

		// Take a private copy BEFORE looking at anything in it.
		//
		// uploads/staging is www-data's, and www-data is the account this whole
		// spec assumes can be made to write a file. Checking the staged directory
		// and then installing from it leaves a window between the two in which a
		// symlink can appear — the check passes, the install follows the link, and
		// root writes wherever it points. Copy first, into a root-owned directory
		// nothing else can write, then check the copy and install the copy.
		//
		// cp -a --no-dereference copies a symlink AS a symlink rather than
		// following it, so the check below sees what the archive actually carried.
		// mktemp -d, not a name this script picks: it creates the directory
		// 0700 in one step, so there is no moment at which the path exists and
		// is not yet ours.
		$work = trim((string)shell_exec('mktemp -d 2>/dev/null'));
		if ($work === '' || !is_dir($work)) {
			fwrite(STDERR, "install_extension: could not make a working directory\n");
			exit(1);
		}
		$staging_dir = $dir;
		$dir = $work . '/' . $staged_name;

		$cp_out = array();
		$cp_rc = 0;
		exec('cp -a --no-dereference ' . escapeshellarg($staging_dir) . ' '
			. escapeshellarg($dir) . ' 2>&1', $cp_out, $cp_rc);
		if ($cp_rc !== 0 || !is_dir($dir)) {
			fwrite(STDERR, "install_extension: could not copy the staged $type out of staging\n"
				. implode("\n", array_slice($cp_out, -5)) . "\n");
			install_extension_rmtree($work);
			exit(1);
		}

		// The manifest is read again here rather than trusted from the request:
		// what names the extension is what is in the directory root is about to
		// move, not what a web request said about it.
		$manifest_file = $dir . '/' . ($type === 'theme' ? 'theme.json' : 'plugin.json');
		if (!is_file($manifest_file)) {
			fwrite(STDERR, "install_extension: no " . basename($manifest_file) . " in the staged directory\n");
			install_extension_rmtree($work);
			exit(2);
		}

		// A symlink anywhere in what is about to be moved into the tree is a
		// symlink the archive's author chose the target of. The web side checks
		// this too; root checks it again, on its own copy, because root is the one
		// moving it.
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST);
		foreach ($it as $p) {
			if ($p->isLink()) {
				fwrite(STDERR, "install_extension: staged tree contains a symlink: " . $p->getPathname() . "\n");
				install_extension_rmtree($work);
				exit(2);
			}
		}

		$target = $dest_parent . '/' . $staged_name;
		if (is_dir($target)) {
			// The old ZIP path refused rather than replacing, and that refusal
			// is worth keeping: replacing an installed extension in place is a
			// different act from installing one, and doing it by accident is
			// how a local fork disappears.
			if (!$replace) {
				fwrite(STDERR, "install_extension: $type '$staged_name' is already installed at $target.\n"
					. "Pass --replace to overwrite it (the current copy is kept beside it), "
					. "or uninstall it first.\n");
				install_extension_rmtree($work);
				exit(2);
			}
			$keep = $target . '.replaced.' . gmdate('YmdHis');
			if (!@rename($target, $keep)) {
				fwrite(STDERR, "install_extension: could not move the existing $type aside\n");
				install_extension_rmtree($work);
				exit(1);
			}
			echo "existing $type kept at " . basename($keep) . "\n";
			install_extension_prune_replaced($dest_parent, $staged_name);
		}

		// Copy, not rename: the working directory and the code tree may be
		// separate volumes in a container, and rename() across a mount point is
		// EXDEV. A failure part way leaves the bytes in staging, where they can
		// be looked at.
		if (!install_extension_copy_tree($dir, $target)) {
			fwrite(STDERR, "install_extension: could not copy the staged $type into place\n");
			install_extension_rmtree($target);
			install_extension_rmtree($work);
			exit(1);
		}
		install_extension_rmtree($work);
		install_extension_rmtree($staging_dir);
		install_extension_own($target);
		echo "installed $type files: $staged_name\n";
		$name = $staged_name;

	} else {
		// ---- by name, from the upgrade source ------------------------------
		if (!$manager->validateName($name)) {
			fwrite(STDERR, "install_extension: invalid $type name '$name'\n");
			exit(2);
		}
		if ($type === 'plugin') {
			// What the marketplace install used to do from a web request.
			$manager->refreshFromUpstream($name);
			install_extension_own($dest_parent . '/' . $name);
		}
	}

	// ---- the database half -------------------------------------------------
	// Files are in place and owned; this is the part that was never the
	// problem, and it is unchanged from what the admin page used to call.
	if ($type === 'plugin') {
		// $refresh_files is false for a staged install. install() otherwise
		// re-runs refreshFromUpstream, which does NOT skip an existing
		// directory — so uploading a local fork of a plugin that also exists in
		// the marketplace downloaded upstream over the fork, seconds after
		// putting it there. The old ZIP path never called install() at all.
		$result = $manager->install($name, $staged === '');
		if (is_array($result) && !empty($result['warnings'])) {
			foreach ($result['warnings'] as $w) {
				fwrite(STDERR, 'warning: ' . $w . "\n");
			}
		}
	} else {
		// A theme is registered by the sync that reads theme.json off disk.
		$manager->sync();
		// An uploaded theme is a local fork: the upgrade must not replace it.
		$theme = Theme::get_by_theme_name($name);
		if ($theme) {
			$theme->set('thm_receives_upgrades', false);
			$theme->save();
		}
	}
	echo "installed $type: $name\n";
	exit(0);

} catch (Exception $e) {
	fwrite(STDERR, 'install_extension: ' . $e->getMessage() . "\n");
	exit(1);
}
