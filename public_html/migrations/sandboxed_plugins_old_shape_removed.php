<?php
/**
 * A sandboxed plugin is a folder of manifest.json and src/. Every sandboxed
 * plugin a site had before that shape was a folder of another shape, which
 * nothing recognises any more, so the Plugins page could no longer uninstall
 * it and what it owned would be stranded. Each one is uninstalled here: its
 * API key and approval, its signal receiver, its scheduled runs, its menus,
 * its settings, its own tables, and its registry row. Its folder is root's to
 * remove (site_housekeeping.sh).
 *
 * Which rows: plg_trust = 'sandboxed', or "sandboxed" set in plg_metadata
 * (the two ways the Plugins page tells one). A row whose folder is a
 * sandboxed plugin of the present shape, or an ordinary plugin's (plugin.json),
 * is left alone.
 *
 * The call log (sbl_sandbox_logs) is kept; its retention removes old rows.
 *
 * Idempotent: once the rows are gone there is nothing to find.
 */
function sandboxed_plugins_old_shape_removed() {
	$db = DbConnector::get_instance()->get_db_link();
	try {
		$rows = $db->query("SELECT plg_plugin_id, plg_name, plg_trust, plg_metadata FROM plg_plugins")->fetchAll(PDO::FETCH_ASSOC);
	} catch (Throwable $e) {
		return true;   // no plugin registry yet: nothing was ever installed
	}

	$ok = true;
	foreach ($rows as $row) {
		$name = (string)$row['plg_name'];
		$metadata = json_decode((string)$row['plg_metadata'], true);
		if ($row['plg_trust'] !== 'sandboxed' && empty(is_array($metadata) ? ($metadata['sandboxed'] ?? null) : null)) {
			continue;
		}
		if (!preg_match(SandboxManifest::NAME_PATTERN, $name) || SandboxPlugins::isSandboxed($name)) {
			continue;
		}
		if (is_file(PathHelper::getIncludePath('plugins/' . $name . '/plugin.json'))) {
			// The name is an ordinary plugin's today: what the row owns is that plugin's.
			echo "  Left {$name} alone: its folder is an ordinary plugin's.\n";
			continue;
		}
		try {
			// The credential and approval, the receiver with what it had
			// queued, and the tables its row recorded. Its settings are not
			// found this way (its manifest is no longer read), so they go
			// by name below.
			SandboxPluginManager::teardown($name);

			PluginManager::getInstance()->syncMenus($name, array('admin' => array(), 'profile' => array()));
			foreach (new MultiScheduledTask(array('plugin_name' => $name)) as $task) {
				$task->permanent_delete();
			}
			foreach (new MultiPluginVersion(array('plugin_name' => $name)) as $version) {
				$version->permanent_delete();
			}
			foreach (new MultiPluginDependency(array('plugin_name' => $name)) as $dependency) {
				$dependency->permanent_delete();
			}
			foreach (new MultiPluginMigration(array('plugin_name' => $name)) as $migration) {
				$migration->permanent_delete();
			}
			// A setting of a sandboxed plugin is named sbx_{plugin}__{name}.
			$db->prepare("DELETE FROM stg_settings WHERE stg_name LIKE ? ESCAPE '\\'")
				->execute(array('sbx\\_' . str_replace('_', '\\_', $name) . '\\_\\_%'));
			$db->prepare('DELETE FROM plg_plugins WHERE plg_plugin_id = ?')->execute(array((int)$row['plg_plugin_id']));
			echo "  Uninstalled the sandboxed plugin {$name}: its shape is no longer one this site runs.\n";
		} catch (Throwable $e) {
			echo "  Could not uninstall the sandboxed plugin {$name}: " . $e->getMessage() . "\n";
			$ok = false;
		}
	}
	if (class_exists('SettingsDeclarations', false)) {
		SettingsDeclarations::reset();
	}
	SandboxPlugins::reset();
	return $ok;
}
?>
