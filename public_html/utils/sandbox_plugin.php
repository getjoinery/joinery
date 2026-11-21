<?php
/**
 * sandbox_plugin.php — the sandboxed plugin author's command (plugin_tiers.md WP8).
 *
 *   php utils/sandbox_plugin.php validate PATH
 *       PATH is a plugin folder, its manifest.json, or a zip of it. Checks the
 *       folder's shape, the manifest, and whether this site has everything it
 *       asks for (SandboxCompatibility). Exit 0 when it would install, 1 with
 *       each problem named.
 *
 *   php utils/sandbox_plugin.php load DIR [--approve-as=USER_ID]      (a dev site only)
 *       Copies the plugin folder DIR into plugins/, installs or updates it,
 *       approves what it asks for as USER_ID (default: the first superadmin)
 *       and activates it. Run it again after each change.
 *
 *   php utils/sandbox_plugin.php call PLUGIN ui HANDLER [VALUES_JSON]     (a dev site only)
 *   php utils/sandbox_plugin.php call PLUGIN signal SIGNAL [PAYLOAD_JSON]
 *   php utils/sandbox_plugin.php call PLUGIN page SLUG [QUERY_JSON] [--kind=admin|member] [--as=USER_ID]
 *   php utils/sandbox_plugin.php call PLUGIN ui HANDLER [VALUES_JSON] --from=frame --page=SLUG   as a framed page's script
 *   php utils/sandbox_plugin.php call PLUGIN schedule NAME
 *       Makes one call as core would (SandboxHost, which runs the plugin in
 *       the fence), and prints what came back and what the plugin asked of
 *       each door. Where the fence cannot run it yet, the call comes back
 *       unavailable and says why.
 *
 *   php utils/sandbox_plugin.php log PLUGIN [COUNT]
 *       The plugin's last calls (SandboxLog), newest first.
 *
 * "A dev site" is one with the debug setting on: load writes into plugins/,
 * which the installer does everywhere else, and call fires real calls.
 *
 * @version 1.6 - log PLUGIN shows this install's calls only
 * @version 1.5 - call ... ui --from=frame --page=SLUG: a framed page's handler, as its script calls it
 * @version 1.4 - call ... page: a page the plugin's view draws, and its body as core would show it; --as for the viewer
 * @version 1.3 - load and call say why a call would be unavailable (SandboxRunner::unavailable)
 * @version 1.2 - the folder is manifest.json and src/; load copies src/ whole, links refused
 * @version 1.1 - sbx_fail() is declared never; call picks its host with a match
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

function sbx_fail(string $line, int $code = 1): never {
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
		sbx_fail('The zip holds no folder of exactly manifest.json and a src folder.');
	}
	if (is_file($path) && basename($path) === SandboxManifest::FILE) {
		$path = dirname($path);
	}
	if (!is_dir($path)) {
		sbx_fail("$path is not a plugin folder, a manifest.json or a zip.");
	}
	return array(rtrim($path, '/'), function () {});
}

/**
 * Copy a folder of regular files and folders. A link, or anything else that
 * is neither, stops the copy.
 *
 * @return string|null what stopped it, or null when the whole tree was copied
 */
function sbx_copy_tree(string $from, string $to): ?string {
	if (is_link($from) || !is_dir($from) || !@mkdir($to, 0755)) {
		return basename($from) . ' is not a folder that can be copied';
	}
	foreach (array_diff(scandir($from) ?: array(), array('.', '..')) as $entry) {
		$src = $from . '/' . $entry;
		if (is_link($src)) {
			return "$entry is a link";
		}
		if (is_dir($src)) {
			$why = sbx_copy_tree($src, $to . '/' . $entry);
			if ($why !== null) {
				return $why;
			}
		} elseif (!is_file($src) || !copy($src, $to . '/' . $entry)) {
			return "$entry could not be copied";
		}
	}
	return null;
}

switch ($command) {
	case 'validate':
		if (!isset($args[0])) {
			sbx_fail('Usage: sandbox_plugin.php validate PATH', 2);
		}
		list($dir, $cleanup) = sbx_folder($args[0]);
		$problems = array();
		if (!SandboxPlugins::isSandboxedDir($dir)) {
			$problems[] = 'The folder must hold exactly manifest.json and a src folder, and nothing else.';
		}
		try {
			$manifest = SandboxManifest::fromFile($dir . '/' . SandboxManifest::FILE, basename($dir));
		} catch (SandboxManifestException $e) {
			$cleanup();
			sbx_out('Refused: ' . $e->getMessage());
			exit(1);
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
			sbx_fail('The folder must hold exactly manifest.json and a src folder, and nothing else.');
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
		$copied = copy($source . '/' . SandboxManifest::FILE, $incoming . '/' . SandboxManifest::FILE)
			? sbx_copy_tree($source . '/' . SandboxPlugins::SOURCE_DIR, $incoming . '/' . SandboxPlugins::SOURCE_DIR)
			: SandboxManifest::FILE . ' could not be copied';
		if ($copied !== null) {
			exec('rm -rf ' . escapeshellarg($incoming));
			sbx_fail("Could not copy the plugin into plugins/$name: $copied.");
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
		if (!$approver || !$approver->key || !Access::allows(SandboxConsent::APPROVER_PERMISSION, (int)$approver->get('usr_permission'))) {
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
		$why = SandboxRunner::unavailable($name);
		if ($why !== null) {
			sbx_out('Calls to it come back unavailable for now: ' . $why);
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
		$as = isset($options['as']) ? new User((int)$options['as'], TRUE) : null;
		if ($as !== null && !$as->key) {
			sbx_fail('--as names no user.', 2);
		}
		$page_kind = ($options['kind'] ?? 'member') === 'admin' ? 'admin' : 'member';
		// As a framed page's script calls (--from=frame --page=SLUG): only a
		// handler that page lists, as the bridge's action allows.
		$from = array();
		if (($options['from'] ?? '') === 'frame') {
			$framed = null;
			foreach ($page_kind === 'admin' ? SandboxPlugins::manifest($plugin)->adminPages() : SandboxPlugins::manifest($plugin)->profilePages() as $p) {
				if ($p['slug'] === ($options['page'] ?? '') && SandboxPages::isFramed($p)) {
					$framed = $p;
				}
			}
			if ($framed === null) {
				sbx_fail('--page names no framed ' . $page_kind . ' page of ' . $plugin . '.', 2);
			}
			if (!in_array($what, (array)($framed['handlers'] ?? array()), true)) {
				sbx_fail('The page ' . $framed['slug'] . ' does not list the handler ' . $what . '.', 2);
			}
			$from = array('source' => 'frame', 'kind' => $page_kind, 'page' => $framed['slug']);
		}
		$result = match ($kind) {
			'ui'       => SandboxHost::ui($plugin, $what, $json, $as, $from),
			'page'     => SandboxHost::page($plugin, $page_kind, $what, 'GET', $json, array(), $as),
			'signal'   => SandboxHost::signal($plugin, $what, $json, 'msg_dev_' . bin2hex(random_bytes(6)), gmdate('Y-m-d\TH:i:s\Z')),
			'schedule' => SandboxHost::schedule($plugin, $what),
			default    => sbx_fail('Usage: sandbox_plugin.php call PLUGIN ui|page|signal|schedule NAME [JSON] [--as=USER_ID] [--kind=admin|member]', 2),
		};
		if ($kind === 'page' && is_array($result['output']) && is_string($result['output']['body'] ?? null)) {
			// What core would show of a plain page: the body, cleaned.
			$clean = DocumentText::parseWith('SandboxHtmlCleaner', $result['output']['body'], array(
				'page_url' => $page_kind === 'admin' ? SandboxPluginManager::adminPageUrl($plugin, $what) : SandboxPages::profilePageUrl($plugin, $what),
				'asset_url' => SandboxPages::assetUrl($plugin), 'image_hosts' => SandboxConsent::effective($plugin)['outbound_hosts'],
				'form_hidden' => array('plugin' => $plugin, 'page' => $what)));
			sbx_out('body, as core shows a plain page (' . $clean['status'] . '):');
			sbx_out($clean['text'] !== '' ? $clean['text'] : (string)$clean['detail']);
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
		foreach (new MultiSandboxLog(array('plugin' => $plugin, 'install_id' => (string)PluginInstallIdentity::idOf($plugin)), array('sbl_sandbox_log_id' => 'DESC'), max(1, (int)($args[1] ?? 20))) as $log) {
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
