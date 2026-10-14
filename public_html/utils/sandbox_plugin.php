<?php
/**
 * sandbox_plugin.php — the sandboxed plugin author's command (specs/plugin_tiers.md WP8).
 *
 *   php utils/sandbox_plugin.php validate PATH
 *       PATH is a plugin folder, its manifest.json, or a zip of it. Checks the
 *       manifest, the module against it (SandboxModule), and whether this site
 *       has everything it asks for (SandboxCompatibility). Exit 0 when it would
 *       install, 1 with each problem named.
 *
 *   php utils/sandbox_plugin.php load DIR [--approve-as=USER_ID]      (a dev site only)
 *       Copies the plugin folder DIR into plugins/, installs or updates it,
 *       approves what it asks for as USER_ID (default: the first superadmin)
 *       and activates it. Run it again after each build.
 *
 *   php utils/sandbox_plugin.php call PLUGIN ui HANDLER [VALUES_JSON]     (a dev site only)
 *   php utils/sandbox_plugin.php call PLUGIN signal SIGNAL [PAYLOAD_JSON]
 *   php utils/sandbox_plugin.php call PLUGIN schedule NAME
 *       Makes one call through the plugin host, as core would, and prints what
 *       came back and what the module asked of each door.
 *
 *   php utils/sandbox_plugin.php log PLUGIN [COUNT]
 *       The plugin's last calls (SandboxLog), newest first.
 *
 * "A dev site" is one with the debug setting on: load writes into plugins/,
 * which the installer does everywhere else, and call fires real calls.
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') {
	exit(1);
}
require_once(__DIR__ . '/../includes/PathHelper.php');

$args = array_slice($argv, 1);
$command = array_shift($args) ?? '';
$options = array();
$args = array_values(array_filter($args, function ($a) use (&$options) {
	if (preg_match('~^--([a-z-]+)=(.*)$~', $a, $m)) {
		$options[$m[1]] = $m[2];
		return false;
	}
	return true;
}));

function sbx_out(string $line): void {
	echo $line . "\n";
}

function sbx_fail(string $line, int $code = 1): void {
	fwrite(STDERR, $line . "\n");
	exit($code);
}

function sbx_dev_only(): void {
	if (!Globalvars::get_instance()->get_setting('debug', false, true)) {
		sbx_fail('This works on a dev site only (the debug setting on).', 2);
	}
}

/** A plugin folder from PATH (a folder, its manifest.json, or a zip), and a cleanup. */
function sbx_folder(string $path): array {
	if (is_file($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'zip') {
		$tmp = sys_get_temp_dir() . '/sbx_validate_' . bin2hex(random_bytes(4));
		mkdir($tmp, 0700);
		$zip = new ZipArchive();
		if ($zip->open($path) !== true || !$zip->extractTo($tmp)) {
			sbx_fail("$path is not a zip that can be opened.");
		}
		$zip->close();
		$cleanup = function () use ($tmp) {
			exec('rm -rf ' . escapeshellarg($tmp));
		};
		foreach (array_merge(array($tmp), glob($tmp . '/*', GLOB_ONLYDIR) ?: array()) as $candidate) {
			if (SandboxPlugins::isSandboxedDir($candidate)) {
				return array($candidate, $cleanup);
			}
		}
		$cleanup();
		sbx_fail('The zip holds no folder of exactly manifest.json and one .wasm.');
	}
	if (is_file($path) && basename($path) === SandboxManifest::FILE) {
		$path = dirname($path);
	}
	if (!is_dir($path)) {
		sbx_fail("$path is not a plugin folder, a manifest.json or a zip.");
	}
	return array(rtrim($path, '/'), function () {});
}

switch ($command) {
	case 'validate':
		if (!isset($args[0])) {
			sbx_fail('Usage: sandbox_plugin.php validate PATH', 2);
		}
		list($dir, $cleanup) = sbx_folder($args[0]);
		$problems = array();
		if (!SandboxPlugins::isSandboxedDir($dir)) {
			$problems[] = 'The folder must hold exactly manifest.json and one .wasm, and nothing else.';
		}
		try {
			$manifest = SandboxManifest::fromFile($dir . '/' . SandboxManifest::FILE, basename($dir));
		} catch (SandboxManifestException $e) {
			$cleanup();
			sbx_out('Refused: ' . $e->getMessage());
			exit(1);
		}
		$wasm = glob($dir . '/*.wasm') ?: array();
		if (count($wasm) === 1) {
			try {
				$problems = array_merge($problems, SandboxModule::fromFile($wasm[0])->problems($manifest));
			} catch (SandboxModuleException $e) {
				$problems[] = $e->getMessage();
			}
		}
		foreach (SandboxCompatibility::problems($manifest) as $p) {
			$problems[] = 'On this site: ' . $p;
		}
		$cleanup();
		if ($problems) {
			foreach ($problems as $p) {
				sbx_out('- ' . $p);
			}
			exit(1);
		}
		sbx_out($manifest->title() . ' ' . $manifest->version() . ' (' . $manifest->name() . ') would install here.');
		foreach (SandboxConsent::describe(SandboxConsent::grantsOf($manifest)) as $heading => $lines) {
			sbx_out($heading . ':');
			foreach ($lines as $line) {
				sbx_out('  ' . $line);
			}
		}
		exit(0);

	case 'load':
		sbx_dev_only();
		if (!isset($args[0]) || !is_dir($args[0])) {
			sbx_fail('Usage: sandbox_plugin.php load DIR [--approve-as=USER_ID]', 2);
		}
		$source = rtrim($args[0], '/');
		if (!SandboxPlugins::isSandboxedDir($source)) {
			sbx_fail('The folder must hold exactly manifest.json and one .wasm, and nothing else.');
		}
		try {
			$manifest = SandboxManifest::fromFile($source . '/' . SandboxManifest::FILE);
		} catch (SandboxManifestException $e) {
			sbx_fail('Refused: ' . $e->getMessage());
		}
		$name = $manifest->name();
		$target = SandboxPlugins::dirFor($name);
		if (is_dir($target) && !SandboxPlugins::isSandboxedDir($target)) {
			sbx_fail("plugins/$name is a native plugin; a sandboxed one cannot replace it.");
		}
		if (!is_dir($target) && !@mkdir($target, 0755)) {
			sbx_fail("Could not make plugins/$name.");
		}
		// The new files land beside the old, then take their place at once: a
		// call arriving meanwhile finds one whole plugin or the other.
		$incoming = dirname($target) . '/.' . $name . '.incoming.' . getmypid();
		@mkdir($incoming, 0755);
		foreach (glob($source . '/*') ?: array() as $file) {
			if (!copy($file, $incoming . '/' . basename($file))) {
				exec('rm -rf ' . escapeshellarg($incoming));
				sbx_fail('Could not copy ' . basename($file) . " into plugins/$name.");
			}
		}
		$outgoing = dirname($target) . '/.' . $name . '.outgoing.' . getmypid();
		if (!@rename($target, $outgoing) || !@rename($incoming, $target)) {
			@rename($outgoing, $target);
			exec('rm -rf ' . escapeshellarg($incoming));
			sbx_fail("Could not put the new files in place in plugins/$name.");
		}
		exec('rm -rf ' . escapeshellarg($outgoing));
		SandboxPlugins::reset();
		$approver = isset($options['approve-as']) ? new User((int)$options['approve-as'], TRUE) : null;
		if ($approver === null) {
			foreach (new MultiUser(array('usr_permission' => 10, 'deleted' => false), array('usr_user_id' => 'ASC'), 1) as $u) {
				$approver = $u;
			}
		}
		if (!$approver || !$approver->key || (int)$approver->get('usr_permission') < SandboxConsent::APPROVER_PERMISSION) {
			sbx_fail('No superadmin to approve it as; pass --approve-as=USER_ID.');
		}
		try {
			$pm = PluginManager::getInstance();
			$pm->install($name, false);
			// What this command approves is what it just showed (validate's
			// list) and copied: the manifest it loaded.
			SandboxConsent::approve($name, $approver, SandboxConsent::reviewDigest($manifest));
			$row = Plugin::get_by_plugin_name($name);
			if ($row && $row->is_active()) {
				SandboxPluginManager::reconcile($name);
				$pm->syncMenus($name, SandboxPluginManager::menus($name));
			} else {
				$pm->activate($name);
			}
		} catch (Throwable $e) {
			sbx_fail('Could not load it: ' . $e->getMessage());
		}
		sbx_out("Loaded $name " . $manifest->version() . ', approved as ' . $approver->display_name() . ', active.');
		foreach ($manifest->adminPages() as $page) {
			sbx_out('  admin page: ' . SandboxPluginManager::adminPageUrl($name, $page['slug']));
		}
		foreach ($manifest->profilePages() as $page) {
			sbx_out('  member page: ' . SandboxPages::profilePageUrl($name, $page['slug']));
		}
		if (!SandboxHost::isRunning()) {
			sbx_out('The plugin host is not running yet; the host converger starts it within a minute.');
		}
		exit(0);

	case 'call':
		sbx_dev_only();
		list($plugin, $kind, $what) = array($args[0] ?? '', $args[1] ?? '', $args[2] ?? '');
		$json = isset($args[3]) ? json_decode($args[3], true) : array();
		if (!is_array($json)) {
			sbx_fail('The last argument must be a JSON object.', 2);
		}
		if (!SandboxPlugins::isActive($plugin)) {
			sbx_fail("$plugin is not an active sandboxed plugin.");
		}
		switch ($kind) {
			case 'ui':
				$result = SandboxHost::ui($plugin, $what, $json, null);
				break;
			case 'signal':
				$result = SandboxHost::signal($plugin, $what, $json, 'msg_dev_' . bin2hex(random_bytes(6)), gmdate('Y-m-d\TH:i:s\Z'));
				break;
			case 'schedule':
				$result = SandboxHost::schedule($plugin, $what);
				break;
			default:
				sbx_fail('Usage: sandbox_plugin.php call PLUGIN ui|signal|schedule NAME [JSON]', 2);
		}
		sbx_out('status: ' . $result['status'] . ($result['duration_ms'] !== null ? ' (' . $result['duration_ms'] . ' ms)' : ''));
		if ($result['message'] !== '') {
			sbx_out('message: ' . $result['message']);
		}
		sbx_out('output: ' . json_encode($result['output'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		foreach ($result['calls'] as $call) {
			sbx_out('  ' . ($call['door'] ?? '') . ' ' . ($call['target'] ?? '') . ': ' . ($call['status'] ?? '') . ' ' . ($call['detail'] ?? ''));
		}
		exit($result['status'] === SandboxLog::STATUS_OK ? 0 : 1);

	case 'log':
		$plugin = $args[0] ?? '';
		if (!preg_match(SandboxManifest::NAME_PATTERN, $plugin)) {
			sbx_fail('Usage: sandbox_plugin.php log PLUGIN [COUNT]', 2);
		}
		foreach (new MultiSandboxLog(array('plugin' => $plugin), array('sbl_sandbox_log_id' => 'DESC'), max(1, (int)($args[1] ?? 20))) as $log) {
			sbx_out($log->get('sbl_create_time') . '  ' . $log->get('sbl_kind') . ' ' . $log->get('sbl_name') . '  '
				. $log->get('sbl_status') . ($log->get('sbl_duration_ms') !== null ? ' ' . $log->get('sbl_duration_ms') . 'ms' : '')
				. ($log->get('sbl_message') ? '  ' . $log->get('sbl_message') : ''));
			foreach ((array)$log->get_json_decoded('sbl_calls') as $call) {
				sbx_out('    ' . ($call['door'] ?? '') . ' ' . ($call['target'] ?? '') . ': ' . ($call['status'] ?? '') . ' ' . ($call['detail'] ?? ''));
			}
		}
		exit(0);

	default:
		sbx_fail("Usage: sandbox_plugin.php validate|load|call|log …  (see the top of this file)", 2);
}
