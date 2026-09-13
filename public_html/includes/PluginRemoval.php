<?php
/**
 * PluginRemoval — root's half of a plugin uninstall: the checks before a
 * plugin directory is deleted, and the deletion.
 *
 * The web side cannot delete anything under plugins/ (the tree is root's,
 * specs/implemented/read_only_tree.md), so PluginManager::uninstall() records
 * the uninstall on the row and queues a `remove_plugin` root request. The
 * request names a plugin; it proves nothing about who asked, because the queue
 * is writable by the web user. So root does not trust it: before deleting,
 * it checks that the name is a plugin name, that the directory is directly
 * under plugins/ and is a real directory, that the plugin's own manifest does
 * not say is_system, and that the database row says `uninstalled` and not
 * active. Any check failing leaves the directory and says why.
 *
 * Every check takes the plugins directory as an argument so the whole thing
 * is exercised against a scratch tree in tests/unit/root_request_test.php.
 *
 * @version 1.0 - specs/post_release_fleet_defects.md B1
 */
class PluginRemoval {

	/**
	 * Why root must not remove plugins/<name>, or '' when it may.
	 *
	 * @param string      $name        The plugin name from the request
	 * @param string      $plugins_dir The plugins directory to check under
	 * @param Plugin|null $row         The plugin's database row, null when there is none
	 * @return string '' or the refusal
	 */
	public static function refusal(string $name, string $plugins_dir, ?Plugin $row): string {
		if ($name === '' || !Plugin::is_valid_plugin_name($name) || preg_match('/^[a-zA-Z]/', $name) !== 1) {
			return "'$name' is not a plugin name";
		}
		$dir = rtrim($plugins_dir, '/') . '/' . $name;
		if (is_link($dir)) {
			return "plugins/$name is a symlink, not a plugin directory";
		}
		if (!is_dir($dir)) {
			return "plugins/$name does not exist";
		}
		$real_dir = realpath($dir);
		$real_parent = realpath($plugins_dir);
		if ($real_dir === false || $real_parent === false || dirname($real_dir) !== $real_parent) {
			return "plugins/$name is not directly under the plugins directory";
		}
		$manifest_path = $dir . '/plugin.json';
		if (is_file($manifest_path)) {
			$manifest = json_decode((string)file_get_contents($manifest_path), true);
			if (is_array($manifest) && !empty($manifest['is_system'])) {
				return "plugin '$name' is marked is_system in its manifest; this node cannot be without it";
			}
		}
		if ($row === null) {
			return "plugin '$name' has no database row; only a row in the uninstalled state is removed";
		}
		if (!$row->is_uninstalled()) {
			return "plugin '$name' is '" . (string)$row->get('plg_status') . "', not uninstalled";
		}
		if ((int)$row->get('plg_active') === 1) {
			return "plugin '$name' is still flagged active";
		}
		if ((bool)$row->get('plg_is_system')) {
			return "plugin '$name' is marked is_system on its row; this node cannot be without it";
		}
		return '';
	}

	/**
	 * Remove plugins/<name>. Never follows a symlink: a link inside the plugin
	 * is unlinked, not descended, so nothing outside the directory is touched.
	 *
	 * @return int Files and directories removed
	 * @throws RuntimeException when something could not be removed
	 */
	public static function remove(string $name, string $plugins_dir): int {
		$dir = rtrim($plugins_dir, '/') . '/' . $name;
		$removed = 0;
		self::remove_tree($dir, $removed);
		if (file_exists($dir) || is_link($dir)) {
			throw new RuntimeException("plugins/$name is still there after removal");
		}
		return $removed;
	}

	private static function remove_tree(string $path, int &$removed): void {
		if (is_link($path) || !is_dir($path)) {
			if (!@unlink($path)) {
				throw new RuntimeException("could not remove $path");
			}
			$removed++;
			return;
		}
		$entries = @scandir($path);
		if ($entries === false) {
			throw new RuntimeException("could not read $path");
		}
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			self::remove_tree($path . '/' . $entry, $removed);
		}
		if (!@rmdir($path)) {
			throw new RuntimeException("could not remove directory $path");
		}
		$removed++;
	}
}
