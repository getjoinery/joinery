<?php
/**
 * Test helpers for sandboxed plugins: a throwaway sandboxed plugin folder,
 * and a stand-in for what runs a plugin's code (SandboxHost::$runner).
 *
 *   $p = sandbox_fixture_plugin(array('admin_pages' => …),
 *       array('files' => array('data/notes_class.php' => sandbox_model_file('Note', 'nte', array('text' => array('type' => 'text'))))));
 *   PluginManager::getInstance()->install($p['name'], false);
 *   sandbox_approve($p['name']);                // activation needs it
 *   PluginManager::getInstance()->activate($p['name']);
 *   $host = sandbox_stand_in_runner(array('on_ui' => array('ok' => true, 'output' => array('message' => 'Hi'))));
 *   … SandboxHost::ui(…) …
 *   $host['requests']()    // the envelopes core handed over
 *
 * Both clean themselves up at harness_finish().
 *
 * @version 1.4 - sandbox_model_file(): a model file's text; teardown drops the fixture's tables by their pattern and
 *                the delete rules that name them
 * @version 1.3 - sandbox_fixture_plugin() takes a name, files for src/, and keep_folder
 * @version 1.2 - the fixture is manifest.json and src/; the stand-in is a closure on SandboxHost::$runner
 * @version 1.1 - sandbox_fixture_plugin() switches sandboxed plugins on for the test (off by default)
 * @version 1.0
 */

/**
 * Write a sandboxed plugin folder (manifest.json + src/) named hts_<hex>, or
 * $opts['name'], with $manifest merged over a minimal valid one, and
 * $opts['files'] (path under src/ => contents) in src/. Removed, with its
 * tables, row, menus, settings, install row and logs, at teardown; with
 * $opts['keep_folder'] the folder stays on disk (so the root step can make
 * the plugin's user for a later run) and only its rows go.
 *
 * @return array{name:string, dir:string}
 */
function sandbox_fixture_plugin(array $manifest = array(), array $opts = array()): array {
	// Off by default on every site (SandboxPlugins::enabled()); on for this test only.
	harness_set_setting_mem(SandboxPlugins::ENABLED_SETTING, '1');
	$name = $opts['name'] ?? ('hts_' . bin2hex(random_bytes(3)));
	$keep = !empty($opts['keep_folder']);
	$dir = SandboxPlugins::dirFor($name);
	if (is_dir($dir . '/' . SandboxPlugins::SOURCE_DIR)) {
		harness_remove_tree($dir . '/' . SandboxPlugins::SOURCE_DIR);
	}
	if (!is_dir($dir)) {
		mkdir($dir, 0755);
	}
	// The same id for every version of one fixture (an upgrade), its own for each name.
	$data = array_merge(array('name' => $name, 'id' => md5('fixture:' . $name), 'title' => 'HarnessTest ' . $name, 'version' => '1.0.0', 'platform' => 1), $manifest);
	file_put_contents($dir . '/manifest.json', json_encode($data, JSON_PRETTY_PRINT));
	mkdir($dir . '/' . SandboxPlugins::SOURCE_DIR, 0755);
	foreach ($opts['files'] ?? array() as $path => $contents) {
		$file = $dir . '/' . SandboxPlugins::SOURCE_DIR . '/' . $path;
		if (!is_dir(dirname($file))) {
			mkdir(dirname($file), 0755, true);
		}
		file_put_contents($file, $contents);
	}
	SandboxPlugins::reset();
	SandboxModels::reset();
	SettingsDeclarations::reset();

	harness_defer(function () use ($name, $dir, $keep) {
		$db = DbConnector::get_instance()->get_db_link();
		// Its tables, by the pattern only its own can have, and every delete
		// rule naming one: a rule about a table that is gone fails every
		// delete of the record it guards, a user's among them.
		$like = 'sbx\\_' . str_replace('_', '\\_', $name) . '\\_\\_%';
		try {
			$q = $db->prepare("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE ?");
			$q->execute(array($like));
			foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $table) {
				if (preg_match('~^sbx_' . preg_quote($name, '~') . '__[a-z0-9_]+$~D', (string)$table)) {
					$db->exec('DROP TABLE IF EXISTS ' . $table . ' CASCADE');
				}
			}
			$q = $db->prepare("SELECT sequencename FROM pg_sequences WHERE schemaname = 'public' AND sequencename LIKE ?");
			$q->execute(array($like));
			foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $sequence) {
				if (preg_match('~^sbx_' . preg_quote($name, '~') . '__[a-z0-9_]+$~D', (string)$sequence)) {
					$db->exec('DROP SEQUENCE IF EXISTS ' . $sequence);
				}
			}
		} catch (Throwable $e) {
		}
		$db->prepare('DELETE FROM del_deletion_rules WHERE del_target_table LIKE ? OR del_source_table LIKE ?')->execute(array($like, $like));
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
		$db->prepare('DELETE FROM pil_plugin_install_ledger WHERE pil_plugin = ?')->execute(array($name));
		$db->prepare('DELETE FROM plg_plugins WHERE plg_name = ?')->execute(array($name));
		if (!$keep) {
			@unlink($dir . '/manifest.json');
			harness_remove_tree($dir . '/' . SandboxPlugins::SOURCE_DIR);
			@rmdir($dir);
		}
		SandboxPlugins::reset();
		SandboxModels::reset();
		SettingsDeclarations::reset();
	});
	return array('name' => $name, 'dir' => $dir);
}

/**
 * The text of a model file for a fixture (spec sandboxed_plugin_model_files):
 * class $class extends SystemBase with $prefix, the table
 * {prefix}_{class in snake case}s, the key {prefix}_{snake}_id, the columns
 * given (by their names without the prefix => their specifications), then
 * {prefix}_create_time and {prefix}_delete_time; its Multi class. $statics
 * are more static properties (name => value, written out with var_export;
 * a 'protected:' prefix on the name makes it protected); $body is more of
 * the class, as written.
 */
function sandbox_model_file(string $class, string $prefix, array $columns = array(), array $statics = array(), string $body = ''): string {
	$snake = strtolower(preg_replace('~(?<!^)[A-Z]~', '_$0', $class));
	$specs = array($prefix . '_' . $snake . '_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true));
	foreach ($columns as $column => $spec) {
		$specs[$prefix . '_' . $column] = $spec;
	}
	$specs += array(
		$prefix . '_create_time' => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
		$prefix . '_delete_time' => array('type' => 'timestamp(6)', 'is_nullable' => true),
	);
	$statics = array_merge(array(
		'prefix'               => $prefix,
		'tablename'            => $prefix . '_' . $snake . 's',
		'pkey_column'          => $prefix . '_' . $snake . '_id',
		'access_class'         => 'content',
		'field_specifications' => $specs,
	), $statics);
	$text = "<?php\nclass $class extends SystemBase {\n";
	foreach ($statics as $static => $value) {
		$visibility = 'public';
		if (strpos($static, 'protected:') === 0) {
			$visibility = 'protected';
			$static = substr($static, 10);
		}
		if ($value === '__absent__') {
			continue;
		}
		$text .= "\t$visibility static \$$static = " . var_export($value, true) . ";\n";
	}
	return $text . $body . "\n}\n\nclass Multi$class extends SystemMultiBase {\n\tprotected static \$model_class = '$class';\n}\n";
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
