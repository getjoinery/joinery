<?php
/**
 * Test helpers for sandboxed plugins: a throwaway sandboxed plugin folder,
 * and a stand-in plugin host (tests/lib/fake_sandbox_host.php).
 *
 *   $p = sandbox_fixture_plugin(array('models' => …, 'admin_pages' => …));
 *   PluginManager::getInstance()->install($p['name'], false);
 *   sandbox_approve($p['name']);                // activation needs it
 *   PluginManager::getInstance()->activate($p['name']);
 *   $host = fake_sandbox_host_start(array('on_ui' => array('ok' => true, 'output' => array('message' => 'Hi'))));
 *   … SandboxHost::ui(…) …
 *   $host['requests']()    // what core sent, decoded
 *
 * Both clean themselves up at harness_finish().
 *
 * @version 1.0
 */

/**
 * Write a sandboxed plugin folder (manifest.json + module.wasm) named
 * hts_<hex>, with $manifest merged over a minimal valid one. Removed, with
 * its tables, row, menus, settings, install row and logs, at teardown.
 *
 * @return array{name:string, dir:string}
 */
function sandbox_fixture_plugin(array $manifest = array(), ?string $wasm = null): array {
	$wasm = $wasm ?? sandbox_minimal_wasm();
	$name = 'hts_' . bin2hex(random_bytes(3));
	$dir = SandboxPlugins::dirFor($name);
	mkdir($dir, 0755);
	$data = array_merge(array('name' => $name, 'title' => 'HarnessTest ' . $name, 'version' => '1.0.0', 'platform' => 1), $manifest);
	file_put_contents($dir . '/manifest.json', json_encode($data, JSON_PRETTY_PRINT));
	file_put_contents($dir . '/module.wasm', $wasm);
	SandboxPlugins::reset();
	SandboxModels::reset();
	SettingsDeclarations::reset();

	harness_defer(function () use ($name, $dir) {
		$db = DbConnector::get_instance()->get_db_link();
		try {
			foreach (SandboxModels::classesFor($name) as $class) {
				$db->exec('DROP TABLE IF EXISTS ' . $class::$tablename . ' CASCADE');
			}
		} catch (Throwable $e) {
		}
		$db->prepare('DELETE FROM amu_admin_menus WHERE amu_slug LIKE ?')->execute(array('sbx-' . $name . '%'));
		$db->prepare('DELETE FROM stg_settings WHERE stg_name LIKE ?')->execute(array('sbx\\_' . $name . '\\_%'));
		$db->prepare('DELETE FROM sbl_sandbox_logs WHERE sbl_plugin = ?')->execute(array($name));
		foreach (new MultiSandboxInstall(array('plugin' => $name)) as $install) {
			if ($install->get('sbi_apk_api_key_id')) {
				$key = new ApiKey((int)$install->get('sbi_apk_api_key_id'), TRUE);
				if ($key->key) {
					$key->permanent_delete();
				}
			}
			$install->permanent_delete();
		}
		foreach (new MultiScheduledTask(array('plugin_name' => $name)) as $task) {
			$task->permanent_delete();
		}
		foreach (new MultiSignalReceiver(array()) as $receiver) {
			if ($receiver->get('sgr_plugin') === $name) {
				$receiver->permanent_delete();
			}
		}
		$db->prepare('DELETE FROM plg_plugins WHERE plg_name = ?')->execute(array($name));
		foreach (glob($dir . '/*') ?: array() as $file) {
			@unlink($file);
		}
		@rmdir($dir);
		SandboxPlugins::reset();
		SandboxModels::reset();
		SettingsDeclarations::reset();
	});
	return array('name' => $name, 'dir' => $dir);
}

/**
 * The smallest WebAssembly module that exports $exports, each a function
 * returning 0: enough for a manifest's checks (SandboxModule) against a
 * stand-in host that never runs it.
 */
function sandbox_minimal_wasm(array $exports = array('on_ui', 'on_signal', 'on_schedule')): string {
	$section = function (int $id, string $content): string {
		return chr($id) . chr(strlen($content)) . $content;
	};
	$n = count($exports);
	$functions = chr($n) . str_repeat("\0", $n);
	$export = chr($n);
	$code = chr($n);
	foreach (array_values($exports) as $i => $name) {
		$export .= chr(strlen($name)) . $name . "\0" . chr($i);
		$code .= "\x04\x00\x41\x00\x0b";
	}
	return "\0asm\x01\0\0\0" . $section(1, "\x01\x60\x00\x01\x7f") . $section(3, $functions)
		. $section(7, $export) . $section(10, $code);
}

/**
 * Approve everything a sandboxed plugin asks for, as $by or a new superadmin,
 * so it can be activated. The approval's credential is revoked at teardown
 * before the approver is deleted (a live plugin key holds its owner).
 */
function sandbox_approve(string $name, ?User $by = null): User {
	$by = $by ?: make_user('SbxApprover', 10);
	SandboxConsent::approve($name, $by, SandboxConsent::reviewDigest(SandboxPlugins::manifest($name)));
	harness_defer(function () use ($name) {
		try {
			SandboxCredentials::revoke($name);
		} catch (Throwable $e) {
		}
	});
	return $by;
}

/**
 * Start a stand-in host and point SandboxHost at it.
 *
 * @param array $replies export name or '*' => reply (see fake_sandbox_host.php)
 * @return array{socket:string, set_replies:callable, requests:callable, stop:callable}
 */
function fake_sandbox_host_start(array $replies): array {
	$base = sys_get_temp_dir() . '/sbx_' . getmypid() . '_' . bin2hex(random_bytes(2));
	$socket = $base . '.sock';
	$replies_file = $base . '.replies.json';
	$log_file = $base . '.requests.log';
	file_put_contents($replies_file, json_encode($replies));
	touch($log_file);
	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fake_sandbox_host.php') . ' '
		. escapeshellarg($socket) . ' ' . escapeshellarg($replies_file) . ' ' . escapeshellarg($log_file);
	$proc = proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', $base . '.err', 'w')), $pipes);
	for ($i = 0; $i < 50 && !file_exists($socket); $i++) {
		usleep(50000);
	}
	SandboxHost::$socket_override = $socket;

	$stop = function () use ($socket, $proc, $base, $replies_file, $log_file) {
		@touch($socket . '.stop');
		for ($i = 0; $i < 40 && file_exists($socket); $i++) {
			usleep(50000);
		}
		if (is_resource($proc)) {
			@proc_terminate($proc);
			@proc_close($proc);
		}
		@unlink($socket);
		@unlink($socket . '.stop');
		@unlink($replies_file);
		@unlink($log_file);
		@unlink($base . '.err');
		SandboxHost::$socket_override = null;
	};
	harness_defer($stop);

	return array(
		'socket' => $socket,
		'set_replies' => function (array $replies) use ($replies_file) {
			file_put_contents($replies_file, json_encode($replies));
		},
		'requests' => function () use ($log_file) {
			$out = array();
			foreach (file($log_file, FILE_IGNORE_NEW_LINES) ?: array() as $line) {
				$decoded = json_decode($line, true);
				if (is_array($decoded)) {
					$out[] = $decoded;
				}
			}
			return $out;
		},
		'stop' => $stop,
	);
}
