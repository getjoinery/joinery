<?php
/**
 * root_request.php — the root half of a root request.
 *
 * The PHP pool cannot write the code tree (specs/read_only_tree.md), so the
 * operator actions that used to do so from inside a web request are queued as
 * requests and carried out here, by the host converger, as root.
 *
 * Called by _plugin_installers_start.sh, never by hand and never by the web
 * side:
 *
 *   php utils/root_request.php <kind> --request=<id>
 *
 * The request file holds the kind's arguments. They are read from the file
 * rather than the command line so nothing a web request wrote is ever a shell
 * word, and they are validated HERE — the queue carries a name, and the thing
 * that knows what a valid argument for that name looks like is the code that
 * acts on it.
 *
 * Every kind is idempotent enough to be retried: the runner may be killed
 * between starting a request and recording its outcome, and the honest
 * response to that is to run it again.
 *
 * Exit 0 = done. Anything else is recorded against the request and shown to
 * whoever submitted it.
 *
 * @version 1.1 - install_package: verify a staged upload, or install it under
 *                the unsigned restrictions on the owner's acknowledgement
 *                (specs/package_signing.md WP3). install_plugin's refusal of
 *                an unverified download is install_extension.php's.
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo "CLI only\n";
	exit(2);
}

require_once(__DIR__ . '/../includes/PathHelper.php');

$kind = isset($argv[1]) ? (string)$argv[1] : '';
$request_id = '';
foreach (array_slice($argv, 2) as $arg) {
	if (strpos($arg, '--request=') === 0) {
		$request_id = substr($arg, strlen('--request='));
	}
}

if ($kind === '' || $request_id === '') {
	fwrite(STDERR, "Usage: root_request.php <kind> --request=<id>\n");
	exit(2);
}

if (!in_array($kind, RootRequest::KINDS, true)) {
	fwrite(STDERR, "root_request: unknown kind '$kind'\n");
	exit(2);
}

// The runner has already moved the file into running/ by the time this starts.
$site_root = PathHelper::getSiteRoot();
$request_path = $site_root . '/' . RootRequest::QUEUE_DIR . '/running/' . $request_id . '.json';
if (!is_file($request_path)) {
	fwrite(STDERR, "root_request: no request at $request_path\n");
	exit(2);
}

$request = json_decode((string)file_get_contents($request_path), true);
if (!is_array($request) || ($request['kind'] ?? '') !== $kind) {
	fwrite(STDERR, "root_request: $request_id is not a $kind request\n");
	exit(2);
}
$args = isset($request['args']) && is_array($request['args']) ? $request['args'] : array();

/** Everything a kind writes into the tree takes the tree's owner. */
function root_request_own(string $path): void {
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

echo "root request $request_id: $kind\n";

switch ($kind) {

	case 'upgrade':
		// Exactly the command the agent runs on a managed node.
		$cmd = escapeshellarg(PHP_BINARY) . ' '
			. escapeshellarg(PathHelper::getIncludePath('utils/upgrade.php')) . ' --verbose';
		passthru($cmd, $code);
		exit((int)$code);

	case 'install_plugin':
	case 'install_theme':
		// By name only, from the configured upgrade source. The download is
		// verified in a root-owned working directory before it replaces
		// anything; the marketplace is first-party, so an unverified archive
		// there is refused outright (exit 3, verdict in the transcript).
		$cmd = escapeshellarg(PHP_BINARY) . ' '
			. escapeshellarg(PathHelper::getIncludePath('utils/install_extension.php'))
			. ($kind === 'install_theme' ? ' theme ' : ' plugin ') . escapeshellarg((string)($args['name'] ?? ''));
		passthru($cmd, $code);
		exit((int)$code);

	case 'install_package':
		// A staged upload. Root verifies it before it moves it (the reasoning
		// is on RootRequest::PACKAGE_KIND); install_extension.php does the
		// verifying and the moving. What is decided HERE is whether the
		// request carries the owner's acknowledgement of an unsigned package,
		// because that is read from the database and the request, and the
		// installer should never have to trust a flag on its command line
		// from anything but this dispatcher or an operator at a shell.
		$type = (string)($args['type'] ?? '');
		$staged = (string)($args['staged_dir'] ?? '');
		if (!in_array($type, array('plugin', 'theme'), true) || $staged === '') {
			fwrite(STDERR, "root_request: install_package needs type (plugin|theme) and staged_dir\n");
			exit(2);
		}
		// The directory is named relative to uploads/staging and may not
		// leave it. install_extension.php checks the resolved path again.
		if (preg_match('~^[A-Za-z0-9_][A-Za-z0-9_.-]*/[A-Za-z0-9_][A-Za-z0-9_-]*$~', $staged) !== 1) {
			fwrite(STDERR, "root_request: staged_dir is not <staging id>/<name>\n");
			exit(2);
		}
		$staged_path = $site_root . '/uploads/staging/' . $staged;

		$cmd = escapeshellarg(PHP_BINARY) . ' '
			. escapeshellarg(PathHelper::getIncludePath('utils/install_extension.php'))
			. ' ' . $type . ' --staged=' . escapeshellarg($staged_path);

		if (isset($args['unsigned_ack'])) {
			$ack = is_array($args['unsigned_ack']) ? $args['unsigned_ack'] : array();
			$requested_by = (int)($request['requested_by'] ?? 0);
			$refusal = PackageAcknowledgement::check($ack, $requested_by);
			if ($refusal !== '') {
				fwrite(STDERR, "root_request: the acknowledgement does not stand: $refusal\n");
				exit(2);
			}
			echo "acknowledgement: stands (user $requested_by, marker " . (int)$ack['marker'] . ")\n";
			$cmd .= ' --acknowledged'
				. ' --approved-by=' . escapeshellarg((string)$requested_by)
				. ' --approved-ip=' . escapeshellarg((string)($ack['ip'] ?? ''))
				. ' --approved-at=' . escapeshellarg((string)(int)($ack['at'] ?? 0));
		}
		passthru($cmd, $code);
		exit((int)$code);

	case 'reconcile_composer':
		$plugin = (string)($args['plugin'] ?? '');
		$manager = new PluginManager();
		if ($plugin !== '' && !$manager->validateName($plugin)) {
			fwrite(STDERR, "root_request: invalid plugin name\n");
			exit(2);
		}
		$validator = new ComposerValidator();
		$ok = $validator->reconcilePluginPackages($plugin !== '' ? array($plugin) : null);

		// composer writes vendor/, which is code the pool executes: give it
		// back to the tree's owner before anything runs out of it. Done whether
		// or not the reconcile succeeded — a partial install still left files.
		root_request_own(PathHelper::getSiteRoot() . '/vendor');

		if (!$ok) {
			foreach ($validator->getErrors() as $line) {
				fwrite(STDERR, $line . "\n");
			}
			exit(1);
		}
		echo "composer packages reconciled" . ($plugin !== '' ? " for $plugin" : '') . "\n";
		exit(0);

	case 'write_agent_files':
		// Every DB-managed file, each to each of its declared targets. The
		// drift guard stays where it is, inside write_to_disk(): a target
		// edited out of band is refused here exactly as it was refused on the
		// admin page, and is reported rather than overwritten.
		// One file when the page named one, every written file otherwise (the
		// update_database pass). `force` carries the operator's answer to the
		// drift prompt: without it a target edited on disk is refused, which is
		// the same answer the admin page gave before this became a request.
		$project_root = rtrim(AgentFile::get_project_root(), '/');
		$force = !empty($args['force']);
		$one = isset($args['agent_file_id']) ? (int)$args['agent_file_id'] : 0;
		$files = $one > 0
			? array(new AgentFile($one, TRUE))
			: new MultiAgentFile(array('deleted' => FALSE, 'written' => TRUE));

		$written = 0;
		$refused = 0;
		foreach ($files as $file) {
			try {
				$file->write_to_disk($force);
			} catch (AgentFileDriftException $e) {
				fwrite(STDERR, 'refused: ' . $e->getMessage() . "\n");
				$refused++;
				continue;
			}
			foreach ($file->get_target_filenames_array() as $target) {
				$path = $project_root . '/' . ltrim((string)$target, '/');
				root_request_own($path);
				echo "wrote $target\n";
				$written++;
			}
		}
		echo "$written file(s) written" . ($refused ? ", $refused refused for on-disk edits" : '') . "\n";
		exit($refused > 0 ? 1 : 0);

	case 'save_doc':
		// The starting hash travels with the request: an edit that landed in
		// the checkout while this waited in the queue is not clobbered by a
		// draft written against the older text. save_doc() returns '' on
		// success and the reason otherwise.
		$key = (string)($args['key'] ?? '');
		$content = (string)($args['content'] ?? '');
		$expected = (string)($args['expected_hash'] ?? '');

		// The docs directory is derived from the key, never taken from the
		// request. A key of the form plugin/<name>/... is that plugin's docs/;
		// anything else is core's docs/. A request that names a directory is a
		// request that gets to choose where root writes, and the queue is
		// www-data-writable - so the request names the document and nothing else.
		$docs_dir = PathHelper::getIncludePath('docs');
		if (strpos($key, 'plugin/') === 0) {
			$segments = explode('/', $key);
			$docs_dir = PathHelper::getIncludePath('plugins/' . ($segments[1] ?? '') . '/docs');
		}

		$error = DocsScanner::save_doc($key, $docs_dir, $content, $expected);
		if ((string)$error !== '') {
			fwrite(STDERR, 'root_request: ' . (string)$error . "\n");
			exit(1);
		}
		root_request_own($docs_dir);
		echo "saved $key\n";
		exit(0);

	case 'set_receives_upgrades':
		// The Themes page's Mark Preserved / Mark Upgradable buttons. The flag
		// lives in the on-disk manifest because that is where the upgrade reads
		// it, which makes setting it a tree write.
		$type = (string)($args['type'] ?? 'theme');
		$name = (string)($args['name'] ?? '');
		$value = !empty($args['value']);
		if ($type !== 'theme') {
			fwrite(STDERR, "root_request: set_receives_upgrades handles themes only\n");
			exit(2);
		}
		$manager = new ThemeManager();
		if (!$manager->validateName($name)) {
			fwrite(STDERR, "root_request: invalid theme name\n");
			exit(2);
		}
		if ($manager->writeManifestReceivesUpgrades($name, $value) === false) {
			fwrite(STDERR, "root_request: could not write the manifest for $name\n");
			exit(1);
		}
		root_request_own(PathHelper::getAbsolutePath('theme/' . $name));
		echo "theme $name receives_upgrades=" . ($value ? 'true' : 'false') . "\n";
		exit(0);
}

fwrite(STDERR, "root_request: kind '$kind' has no handler\n");
exit(2);
