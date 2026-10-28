<?php
/**
 * The content packs a plugin ships, applied when it is installed.
 *
 * A plugin keeps its packs in plugins/{name}/content_packs/{pack}/, each a
 * folder holding pack.json and the text files it names. After a plugin's
 * activation commits, and on every sync, each of its packs that has not been
 * applied since the plugin was last installed is applied with its plan's
 * default ticks (ContentPack::apply_defaults): new rows are created and fields
 * that still hold a pack's value are kept current, while a field edited on
 * the site is left alone. So a pack lands once per install. A pack the plugin
 * adds in a later version lands at the next sync, and one that failed is tried
 * again there.
 *
 * It runs once the plugin is active because only then do its own classes
 * load. A failure never undoes the activation: it is recorded on the Content
 * Packs page, logged, and retried at the next sync.
 *
 * See specs/content_pack_feature.md §7 and docs/content_packs.md.
 *
 * @version 1.0
 */
class PluginContentPacks {

	const FOLDER = 'content_packs';

	/** The apply record's source for a plugin's pack folder. */
	public static function source(string $plugin, string $folder): string {
		return 'plugin:' . $plugin . '/' . $folder;
	}

	/** Folder name => path of each pack the plugin ships, in name order. */
	public static function packs(string $plugin, ?string $root = null): array {
		$root = $root ?? PathHelper::getAbsolutePath('plugins/' . $plugin . '/' . self::FOLDER);
		if (!is_dir($root)) {
			return array();
		}
		$packs = array();
		foreach (scandir($root) as $folder) {
			if ($folder[0] === '.' || !is_dir($root . '/' . $folder)) {
				continue;
			}
			$packs[$folder] = $root . '/' . $folder;
		}
		ksort($packs);
		return $packs;
	}

	/** Whether this pack has been applied since the plugin was last installed. */
	public static function applied_since_install(string $plugin, string $folder): bool {
		$model = Plugin::get_by_plugin_name($plugin);
		$installed = $model ? (string)$model->get('plg_installed_time') : '';
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT 1 FROM cpa_content_pack_applies
			 WHERE cpa_source = ? AND cpa_status = ? AND cpa_applied_time >= ?::timestamp LIMIT 1");
		$stmt->execute(array(self::source($plugin, $folder), ContentPackApply::STATUS_APPLIED,
			$installed !== '' ? $installed : '1970-01-01 00:00:00'));
		return (bool)$stmt->fetchColumn();
	}

	/**
	 * Apply each of the plugin's packs not yet applied since its install.
	 *
	 * @param string $plugin The plugin, active.
	 * @param string|null $root Where its packs are; the plugin's own folder unless a test says.
	 * @return string[] One line per pack applied or failed.
	 */
	public static function apply_pending(string $plugin, ?string $root = null): array {
		$messages = array();
		foreach (self::packs($plugin, $root) as $folder => $dir) {
			if (self::applied_since_install($plugin, $folder)) {
				continue;
			}
			$messages[] = self::apply_one($plugin, $folder, $dir);
		}
		return $messages;
	}

	private static function apply_one(string $plugin, string $folder, string $dir): string {
		$session = SessionControl::get_instance();
		$apply = new ContentPackApply(NULL);
		$apply->set('cpa_usr_user_id', (int)$session->get_user_id() ?: NULL);
		$apply->set('cpa_source', self::source($plugin, $folder));
		$apply->set('cpa_pack_name', $folder);
		$apply->set('cpa_status', ContentPackApply::STATUS_CHECKED);
		try {
			$members = ContentPack::dir_members($dir);
			$snapshot = json_encode($members, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$apply->set('cpa_file', base64_encode($snapshot));
			$apply->set('cpa_file_sha256', hash('sha256', $snapshot));
			$pack = ContentPack::read_dir($dir);
			$apply->set('cpa_pack_name', $pack->name());
			$apply->set('cpa_pack_version', $pack->version());
			SystemBase::server_initiated_write(function () use ($apply) { $apply->save(); $apply->load(); });
			$written = $pack->apply_defaults($apply);
			return 'content pack ' . $folder . ' applied: ' . count($written) . ' written';
		} catch (Throwable $e) {
			$message = 'content pack ' . $folder . ' failed: ' . $e->getMessage();
			error_log('PluginContentPacks: plugin ' . $plugin . ': ' . $message);
			try {
				$failed = $apply->key ? new ContentPackApply($apply->key, TRUE) : $apply;
				if (!$failed->get('cpa_file')) {
					// The folder could not be read at all: record that it was empty-handed.
					$failed->set('cpa_file', base64_encode('{}'));
					$failed->set('cpa_file_sha256', hash('sha256', '{}'));
				}
				$failed->set('cpa_status', ContentPackApply::STATUS_FAILED);
				$failed->set('cpa_error', $e->getMessage());
				SystemBase::server_initiated_write(function () use ($failed) { $failed->save(); });
			} catch (Throwable $record_error) {
				error_log('PluginContentPacks: could not record the failure: ' . $record_error->getMessage());
			}
			return $message;
		}
	}
}
