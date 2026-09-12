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
 * NOTHING GOES INTO THE TREE UNVERIFIED. Both forms hand the package to
 * PackageSignature::verify() — the by-name form after the download, the staged
 * form after the copy into a root-owned working directory and before anything
 * in it is read — and only `signed` installs (specs/package_signing.md WP2).
 * Any other verdict is printed as one line, `verdict: <name>: <why>`, and the
 * run exits EXIT_UNVERIFIED with the staged bytes left where they are, so the
 * page that asked can show the warning and the operator can look.
 *
 * THE ONE WAY PAST THAT is --acknowledged: the owner has read the warning and
 * said install anyway. The root request dispatcher passes it only after
 * PackageAcknowledgement::check() stood; an operator at a shell passes it by
 * hand. An acknowledged unsigned package installs under the UNSIGNED
 * RESTRICTIONS (specs/package_signing.md WP3): its database half — table
 * creation and migrations, which is plugin code — runs as the web user, not
 * root (this script re-executes itself with --register under runuser); its
 * row records plg_trust/thm_trust = 'unsigned', set by root afterwards; every
 * superadmin is emailed; and the event log has a row. The marketplace form
 * has no acknowledgement path: an unverified archive from a first-party
 * source is a publishing defect, not a choice.
 *
 *   php utils/install_extension.php plugin|theme --staged=<dir> [--replace] [--acknowledged]
 *   php utils/install_extension.php plugin|theme <name> --register [--uploaded]   (the database half alone)
 *
 * @version 1.4 - A theme keeps receiving upgrades unless it was uploaded:
 *                only the staged form is a local fork (review round 2, R2).
 * @version 1.3 - The acknowledged path and the unsigned restrictions
 *                (specs/package_signing.md WP3): --acknowledged, --register,
 *                plg_trust/thm_trust, the superadmin email and the event log.
 * @version 1.2 - Verifies the package before it moves it (specs/package_signing.md
 *                WP2): the by-name download and the staged copy both go through
 *                PackageSignature, and anything but `signed` exits 3. The
 *                by-name form no longer downloads twice.
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
$acknowledged = false;
$register_only = false;
$uploaded = false;
$approved_by = 0;
$approved_ip = '';
$approved_at = 0;
foreach (array_slice($argv, 2) as $arg) {
	if (strpos($arg, '--staged=') === 0) {
		$staged = substr($arg, strlen('--staged='));
	} elseif ($arg === '--replace') {
		$replace = true;
	} elseif ($arg === '--acknowledged') {
		$acknowledged = true;
	} elseif ($arg === '--register') {
		$register_only = true;
	} elseif ($arg === '--uploaded') {
		$uploaded = true;
	} elseif (strpos($arg, '--approved-by=') === 0) {
		$approved_by = (int)substr($arg, strlen('--approved-by='));
	} elseif (strpos($arg, '--approved-ip=') === 0) {
		$approved_ip = substr($arg, strlen('--approved-ip='));
	} elseif (strpos($arg, '--approved-at=') === 0) {
		$approved_at = (int)substr($arg, strlen('--approved-at='));
	} elseif ($arg !== '' && $arg[0] !== '-') {
		$name = $arg;
	}
}

if (!in_array($type, array('plugin', 'theme'), true) || ($name === '' && $staged === '')) {
	fwrite(STDERR, "Usage: install_extension.php plugin|theme <name> [--register [--uploaded]]\n"
		. "       install_extension.php plugin|theme --staged=<dir> [--replace] [--acknowledged]\n");
	exit(2);
}

/**
 * The exit code for a package that did not verify. Distinct from 1 (something
 * failed) and 2 (bad usage) because the page that queued the request reads
 * it: this is the one outcome that has a next step for the operator.
 */
const EXIT_UNVERIFIED = 3;

$manager = ($type === 'theme') ? new ThemeManager() : new PluginManager();
$dest_parent = PathHelper::getAbsolutePath($type === 'theme' ? 'theme' : 'plugins');
$tree_rel = ($type === 'theme' ? 'public_html/theme/' : 'public_html/plugins/');

/**
 * Verify a package that is about to go into the tree, and say so either way.
 *
 * The verdict line is the transcript's record and what the admin page reads
 * back. A signed package must also describe the directory it is about to
 * become: a theme archive verified as a plugin is still not a plugin.
 */
function install_extension_verify(string $dir, string $expected_rel): PackageVerdict {
	$verdict = PackageSignature::verify($dir);
	if ($verdict->signed() && $verdict->root !== $expected_rel) {
		$verdict = new PackageVerdict(PackageSignature::UNREADABLE,
			'the manifest describes ' . $verdict->root . ', not ' . $expected_rel, $verdict->root, $verdict->key);
	}
	echo 'verdict: ' . $verdict->line() . "\n";
	return $verdict;
}

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

/**
 * The database half of an install: the plugin's tables, migrations and row,
 * or the theme's registration. Plugin migrations are the plugin's own code,
 * so for an unsigned package this runs as the web user (see
 * install_extension_register_as_web_user), never as root.
 */
function install_extension_register(string $type, string $name, $manager, bool $uploaded = false): void {
	if ($type === 'plugin') {
		// $refresh_files is always false here: the by-name form fetched and
		// verified the files already, and a staged install's files are the ones
		// the operator uploaded. install() would otherwise re-run
		// refreshFromUpstream, which does NOT skip an existing directory — so
		// uploading a local fork of a plugin that also exists in the marketplace
		// downloaded upstream over the fork, seconds after putting it there.
		$result = $manager->install($name, false);
		if (is_array($result) && !empty($result['warnings'])) {
			foreach ($result['warnings'] as $w) {
				fwrite(STDERR, 'warning: ' . $w . "\n");
			}
		}
	} else {
		// A theme is registered by the sync that reads theme.json off disk.
		$manager->sync();
		// An UPLOADED theme is a local fork: the upgrade must not replace it.
		// A theme fetched by name from the marketplace is ours and keeps
		// receiving upgrades like any other.
		if ($uploaded) {
			$theme = Theme::get_by_theme_name($name);
			if ($theme) {
				$theme->set('thm_receives_upgrades', false);
				$theme->save();
			}
		}
	}
}

/**
 * Run the database half as the web user. An unsigned plugin's migrations are
 * code nobody we know wrote; root runs none of it. Only root can switch
 * accounts, and a run that is not root is already not root, so it registers
 * inline. Refuses rather than falling back to root when the switch cannot be
 * made: that would be the restriction quietly not applying.
 */
function install_extension_register_as_web_user(string $type, string $name, bool $uploaded): void {
	if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
		return;                     // not root: the caller registers inline
	}
	$web_user = 'www-data';
	if (!function_exists('posix_getpwnam') || posix_getpwnam($web_user) === false) {
		fwrite(STDERR, "install_extension: no '$web_user' account to run the unsigned $type's migrations as; refusing to run them as root\n");
		exit(1);
	}
	$self = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
		. ' ' . $type . ' ' . escapeshellarg($name) . ' --register' . ($uploaded ? ' --uploaded' : '');
	$runuser = trim((string)shell_exec('command -v runuser 2>/dev/null'));
	$su = trim((string)shell_exec('command -v su 2>/dev/null'));
	if ($runuser !== '') {
		$cmd = escapeshellarg($runuser) . ' -u ' . $web_user . ' -- ' . $self;
	} elseif ($su !== '') {
		$cmd = escapeshellarg($su) . ' -s /bin/sh ' . $web_user . ' -c ' . escapeshellarg($self);
	} else {
		fwrite(STDERR, "install_extension: neither runuser nor su is available to run the unsigned $type's migrations as $web_user; refusing to run them as root\n");
		exit(1);
	}
	echo "unsigned $type: running its database half as $web_user\n";
	passthru('cd / && ' . $cmd, $rc);
	if ((int)$rc !== 0) {
		fwrite(STDERR, "install_extension: the $type's database half failed as $web_user (exit $rc)\n");
		exit((int)$rc);
	}
}

/**
 * Who approved an unsigned install, for the email and the event log: the
 * superadmin whose acknowledgement the dispatcher checked, or the operator at
 * the shell that ran this by hand.
 */
function install_extension_approver(int $approved_by, string $approved_ip, int $approved_at): array {
	if ($approved_by > 0) {
		$user = new User($approved_by, TRUE);
		$who = $user->key
			? trim((string)$user->get('usr_first_name') . ' ' . (string)$user->get('usr_last_name'))
				. ' (' . (string)$user->get('usr_email') . ', user ' . $approved_by . ')'
			: 'user ' . $approved_by;
		return array('user_id' => $approved_by, 'who' => $who,
			'ip' => $approved_ip !== '' ? $approved_ip : 'unknown',
			'at' => $approved_at > 0 ? gmdate('Y-m-d H:i:s', $approved_at) . ' UTC' : 'unknown',
			'how' => 'the warning page, after a second-factor confirmation');
	}
	$account = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '';
	return array('user_id' => 0, 'who' => 'an operator at a shell' . ($account !== '' ? " (account $account)" : ''),
		'ip' => 'local shell', 'at' => gmdate('Y-m-d H:i:s') . ' UTC',
		'how' => 'php utils/install_extension.php --acknowledged');
}

/**
 * The unsigned restrictions that are a record rather than a limit: the row
 * says 'unsigned' for good, every superadmin is told, and the event log has
 * it. Root does this after the web user's half, so the trust value is root's
 * word and not the migration's.
 */
function install_extension_record_unsigned(string $type, string $name, string $version, string $verdict_line,
		array $approver, string $trust): void {
	if ($type === 'plugin') {
		$row = Plugin::get_by_plugin_name($name);
		$column = 'plg_trust';
	} else {
		$row = Theme::get_by_theme_name($name);
		$column = 'thm_trust';
	}
	if ($row) {
		$row->set($column, $trust);
		$row->save();
		echo "$type $name: $column = $trust\n";
	} else {
		fwrite(STDERR, "warning: no database row for $type '$name' to record $column on\n");
	}
	if ($trust !== 'unsigned') {
		return;
	}

	$site = (string)Globalvars::get_instance()->get_setting('webDir');
	$note = "type=$type name=$name version=$version approved_by=" . $approver['who']
		. ' ip=' . $approver['ip'] . ' at=' . $approver['at'] . ' verdict=' . $verdict_line;
	try {
		$log = new EventLog(NULL);
		$log->set('evl_event', 'unsigned_package_installed');
		$log->set('evl_usr_user_id', $approver['user_id'] > 0 ? $approver['user_id'] : null);
		$log->set('evl_was_success', true);
		$log->set('evl_note', $note);
		$log->save();
		echo "event log: unsigned_package_installed\n";
	} catch (Throwable $e) {
		fwrite(STDERR, 'warning: could not write the event log row: ' . $e->getMessage() . "\n");
	}

	$subject = "An unsigned $type was installed on $site: $name $version";
	$body = "An unsigned $type was installed on $site.\n\n"
		. "$type: $name\nversion: $version\napproved by: " . $approver['who']
		. "\nhow: " . $approver['how'] . "\nwhen: " . $approver['at'] . "\nfrom: " . $approver['ip']
		. "\nverdict: $verdict_line\n\n"
		. PackageAcknowledgement::warning() . "\n\n"
		. "It is listed with an Unsigned badge on the admin " . ($type === 'plugin' ? 'Plugins' : 'Themes')
		. " page. If nobody you know approved this, deactivate it and change every superadmin's password.\n";
	$sent = 0;
	foreach (new MultiUser(array('permission_range' => array(10, 10), 'deleted' => FALSE)) as $admin) {
		$to = trim((string)$admin->get('usr_email'));
		if ($to === '' || $admin->get('usr_is_disabled') || $admin->get('usr_is_admin_disabled')) {
			continue;
		}
		try {
			EmailSender::quickSend($to, $subject, $body);
			$sent++;
		} catch (Throwable $e) {
			fwrite(STDERR, "warning: could not email $to: " . $e->getMessage() . "\n");
		}
	}
	echo "email: $sent superadmin(s) told\n";
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

	if ($register_only) {
		// The database half alone, for files already in place. This is what
		// root re-executes as the web user for an unsigned package; it can be
		// run by hand too. It records nothing about trust — that is root's
		// word, written by the caller afterwards.
		if (!$manager->validateName($name)) {
			fwrite(STDERR, "install_extension: invalid $type name '$name'\n");
			exit(2);
		}
		if (!is_dir($dest_parent . '/' . $name)) {
			fwrite(STDERR, "install_extension: $type '$name' is not on disk\n");
			exit(1);
		}
		install_extension_register($type, $name, $manager, $uploaded);
		echo "registered $type: $name\n";
		exit(0);
	}

	// What root records on the row when the files are in place: 'signed' from
	// the verifier, 'unsigned' from the owner's acknowledgement.
	$trust = 'signed';
	$verdict_line = '';

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

		// Root's own copy, checked and not yet read for anything but its
		// manifest name: this is the moment to ask who built it.
		$verdict = install_extension_verify($dir, $tree_rel . $staged_name);
		$verdict_line = $verdict->line();
		if (!$verdict->signed()) {
			if (!$acknowledged) {
				fwrite(STDERR, "install_extension: refusing to install an unverified $type ($verdict->verdict).\n"
					. "This package was not built by Joinery. " . PackageAcknowledgement::warning() . "\n"
					. "To install it anyway, answer the warning on the admin page, or re-run this command with --acknowledged.\n");
				install_extension_rmtree($work);
				exit(EXIT_UNVERIFIED);
			}
			$trust = 'unsigned';
			echo "unsigned $type: installing on the owner's acknowledgement, under the unsigned restrictions\n";
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
		{
			// The marketplace is first-party only, so an archive from it that
			// does not verify is a publishing defect, and is refused outright:
			// there is no acknowledgement path by name.
			// refreshFromUpstream() downloads into a root-owned working
			// directory, verifies there, and only then replaces the live
			// directory. It throws on anything but `signed`; the on-disk copy
			// is untouched. Themes and plugins alike.
			try {
				$refreshed = $manager->refreshFromUpstream($name);
			} catch (PackageUnverifiedException $e) {
				echo 'verdict: ' . $e->verdict->line() . "\n";
				fwrite(STDERR, "install_extension: refusing to install an unverified $type (" . $e->getMessage() . ")\n");
				exit(EXIT_UNVERIFIED);
			}
			if ($refreshed) {
				echo "verdict: signed: fetched from the upgrade source and verified\n";
				$verdict_line = 'signed: fetched from the upgrade source and verified';
				install_extension_own($dest_parent . '/' . $name);
			} else {
				// Not in the catalog: the files already on disk are what will be
				// installed, and they are held to the same question. The
				// publishing box trusts its own tree — every plugin here is the
				// source the archives are built from, and its live manifests
				// are stale the moment a file is edited.
				if (!is_dir($dest_parent . '/' . $name)) {
					fwrite(STDERR, "install_extension: $type '$name' is not in the upgrade source's catalog and is not on disk\n");
					exit(1);
				}
				if (!PackageSignature::publisherBox()) {
					$verdict = install_extension_verify($dest_parent . '/' . $name, $tree_rel . $name);
					$verdict_line = $verdict->line();
					if (!$verdict->signed()) {
						// A row that already says 'unsigned' is a package the
						// owner acknowledged once, still on disk as root left
						// it (a repair, a reinstall after an uninstall that kept
						// the files). It stays under the unsigned restrictions.
						$existing = $type === 'plugin' ? Plugin::get_by_plugin_name($name) : Theme::get_by_theme_name($name);
						$trust_column = $type === 'plugin' ? 'plg_trust' : 'thm_trust';
						if ($existing && (string)$existing->get($trust_column) === 'unsigned') {
							$trust = 'unsigned';
							echo "unsigned $type: previously acknowledged; installing under the unsigned restrictions\n";
						} else {
							fwrite(STDERR, "install_extension: refusing to install an unverified $type from disk ($verdict->verdict). "
								. "A package that is not ours is installed by uploading it, where the warning can be answered.\n");
							exit(EXIT_UNVERIFIED);
						}
					}
				} else {
					echo "verdict: signed: this is the publishing box, which trusts its own tree\n";
					$verdict_line = 'signed: this is the publishing box, which trusts its own tree';
				}
			}
		}
	}

	// ---- the database half -------------------------------------------------
	// Files are in place, verified and owned. For a signed package this is the
	// part that was never the problem; for an unsigned one it is plugin code
	// (migrations), and root does not run it.
	if ($trust === 'unsigned') {
		install_extension_register_as_web_user($type, $name, $staged !== '');
		if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
			install_extension_register($type, $name, $manager, $staged !== '');
		}
	} else {
		install_extension_register($type, $name, $manager, $staged !== '');
	}

	// Root's word on who built it, and the record of an unsigned install.
	$manifest_path = $dest_parent . '/' . $name . '/' . ($type === 'theme' ? 'theme.json' : 'plugin.json');
	$manifest = json_decode((string)@file_get_contents($manifest_path), true);
	$version = is_array($manifest) ? (string)($manifest['version'] ?? '') : '';
	install_extension_record_unsigned($type, $name, $version, $verdict_line,
		install_extension_approver($approved_by, $approved_ip, $approved_at), $trust);

	echo "installed $type: $name\n";
	exit(0);

} catch (Exception $e) {
	fwrite(STDERR, 'install_extension: ' . $e->getMessage() . "\n");
	exit(1);
}
