<?php
/**
 * Test helpers for sandboxed plugins: a throwaway sandboxed plugin folder,
 * and a stand-in for what runs a plugin's code (SandboxHost::$runner).
 *
 *   $p = sandbox_fixture_plugin(array('models' => …, 'admin_pages' => …));
 *   PluginManager::getInstance()->install($p['name'], false);
 *   sandbox_approve($p['name']);                // activation needs it
 *   PluginManager::getInstance()->activate($p['name']);
 *   $host = sandbox_stand_in_runner(array('on_ui' => array('ok' => true, 'output' => array('message' => 'Hi'))));
 *   … SandboxHost::ui(…) …
 *   $host['requests']()    // the envelopes core handed over
 *
 * Both clean themselves up at harness_finish().
 *
 * @version 1.2 - the fixture is manifest.json and src/; the stand-in is a closure on SandboxHost::$runner
 * @version 1.1 - sandbox_fixture_plugin() switches sandboxed plugins on for the test (off by default)
 * @version 1.0
 */

/**
 * Write a sandboxed plugin folder (manifest.json + an empty src/) named
 * hts_<hex>, with $manifest merged over a minimal valid one. Removed, with
 * its tables, row, menus, settings, install row and logs, at teardown.
 *
 * @return array{name:string, dir:string}
 */
function sandbox_fixture_plugin(array $manifest = array()): array {
	// Off by default on every site (SandboxPlugins::enabled()); on for this test only.
	harness_set_setting_mem(SandboxPlugins::ENABLED_SETTING, '1');
	$name = 'hts_' . bin2hex(random_bytes(3));
	$dir = SandboxPlugins::dirFor($name);
	mkdir($dir, 0755);
	$data = array_merge(array('name' => $name, 'title' => 'HarnessTest ' . $name, 'version' => '1.0.0', 'platform' => 1), $manifest);
	file_put_contents($dir . '/manifest.json', json_encode($data, JSON_PRETTY_PRINT));
	mkdir($dir . '/' . SandboxPlugins::SOURCE_DIR, 0755);
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
		@unlink($dir . '/manifest.json');
		@rmdir($dir . '/' . SandboxPlugins::SOURCE_DIR);
		@rmdir($dir);
		SandboxPlugins::reset();
		SandboxModels::reset();
		SettingsDeclarations::reset();
	});
	return array('name' => $name, 'dir' => $dir);
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
 * Set SandboxHost::$runner to a stand-in that answers from $replies and
 * keeps every envelope it was handed. A reply is the answer a runner gives
 * ({ok, output, error, timed_out, duration_ms, calls}), by export name
 * ("on_ui", "on_signal", "on_schedule") or "*"; with none for an export the
 * answer is ok with no output. Cleared at harness_finish(), or by stop().
 *
 * @param array $replies export name or '*' => reply
 * @return array{set_replies:callable, requests:callable, stop:callable}
 */
function sandbox_stand_in_runner(array $replies): array {
	$state = new stdClass();
	$state->replies = $replies;
	$state->requests = array();
	SandboxHost::$runner = function (array $envelope) use ($state) {
		// Kept as a real runner would receive it: through JSON.
		$state->requests[] = json_decode(json_encode($envelope), true);
		$export = (string)($envelope['export'] ?? '');
		return $state->replies[$export] ?? ($state->replies['*'] ?? array('ok' => true, 'output' => null, 'calls' => array()));
	};
	$stop = function () {
		SandboxHost::$runner = null;
	};
	harness_defer($stop);

	return array(
		'set_replies' => function (array $replies) use ($state) {
			$state->replies = $replies;
		},
		'requests' => function () use ($state) {
			return $state->requests;
		},
		'stop' => $stop,
	);
}
