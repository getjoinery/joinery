<?php
/**
 * UpgradeRetention — decides which published core upgrade archives may be
 * removed from disk, and removes them.
 *
 * Only the archive file is deleted. The upg_upgrades row survives, so the
 * release history (version, date, notes, component state) stays complete
 * forever while the multi-megabyte tarballs are reclaimed.
 *
 * An archive is protected when any of these hold:
 *   - it is one of the newest N releases (N = server_manager_upgrade_retention_count)
 *   - its row is flagged upg_keep
 *   - a managed node reports running that version — that archive is the node's
 *     rollback target, so removing it would strand the node
 *   - it is the version this management node is itself running
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('data/upgrades_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

class UpgradeRetention {

	/** Retention count from settings; 0 (or unset/invalid) means keep everything. */
	public static function getKeepCount() {
		$settings = Globalvars::get_instance();
		$raw = $settings->get_setting('server_manager_upgrade_retention_count');
		if ($raw === null || $raw === '' || !is_numeric($raw)) {
			return 0;
		}
		$n = (int)$raw;
		return $n > 0 ? $n : 0;
	}

	/** Directory holding the published archives ({site root}/static_files). */
	public static function getArchiveDir() {
		return rtrim(dirname(PathHelper::getIncludePath('')), '/') . '/static_files';
	}

	/** "0.8.174" for an Upgrade row. */
	public static function versionString($upgrade) {
		return $upgrade->get('upg_major_version') . '.'
			. $upgrade->get('upg_minor_version') . '.'
			. $upgrade->get('upg_patch_version');
	}

	/**
	 * Versions that must never be pruned because something is running them:
	 * every non-deleted managed node's reported version, plus this control
	 * plane's own version. Returned as a version-string => label map.
	 */
	public static function getInUseVersions() {
		$in_use = [];

		try {
			$nodes = new MultiManagedNode(['deleted' => false], ['mgn_id' => 'ASC'], 1000, 0);
			$nodes->load();
			foreach ($nodes as $node) {
				$v = trim((string)$node->get('mgn_joinery_version'));
				if ($v === '') continue;
				$name = trim((string)$node->get('mgn_name'));
				if ($name === '') $name = trim((string)$node->get('mgn_slug'));
				if (!isset($in_use[$v])) $in_use[$v] = [];
				$in_use[$v][] = $name;
			}
		} catch (Exception $e) {
			// A node-table failure must never cause deletion. Signal "protect
			// everything" by returning a marker the caller treats as fatal.
			throw new RuntimeException('Could not read managed node versions: ' . $e->getMessage());
		}

		$own = LibraryFunctions::get_joinery_version();
		if ($own !== '') {
			if (!isset($in_use[$own])) $in_use[$own] = [];
			$in_use[$own][] = 'this management node';
		}

		$out = [];
		foreach ($in_use as $v => $who) {
			$out[$v] = implode(', ', array_unique($who));
		}
		return $out;
	}

	/**
	 * Classify every published upgrade.
	 *
	 * Returns a list of ['upgrade' => Upgrade, 'version' => string,
	 * 'archive_exists' => bool, 'bytes' => int, 'protected_by' => string|null].
	 * protected_by is null only when the archive may be removed.
	 */
	public static function classify() {
		$keep_count  = self::getKeepCount();
		$archive_dir = self::getArchiveDir();
		$in_use      = self::getInUseVersions();

		$upgrades = new MultiUpgrade([], ['upgrade_id' => 'DESC'], 5000, 0);
		$upgrades->load();

		$rows  = [];
		$index = 0;
		foreach ($upgrades as $u) {
			$version = self::versionString($u);
			$path    = $archive_dir . '/' . $u->get('upg_name');
			$exists  = file_exists($path);

			$protected_by = null;
			if ($keep_count === 0) {
				$protected_by = 'retention set to keep all';
			} elseif ($index < $keep_count) {
				$protected_by = 'among the newest ' . $keep_count;
			} elseif ($u->get('upg_keep')) {
				$protected_by = 'marked Keep';
			} elseif (isset($in_use[$version])) {
				$protected_by = 'in use by ' . $in_use[$version];
			}

			$rows[] = [
				'upgrade'        => $u,
				'version'        => $version,
				'filename'       => $u->get('upg_name'),
				'path'           => $path,
				'archive_exists' => $exists,
				'bytes'          => $exists ? (int)filesize($path) : 0,
				'protected_by'   => $protected_by,
				'in_use_by'      => $in_use[$version] ?? null,
			];
			$index++;
		}

		return $rows;
	}

	/**
	 * Remove unprotected archive files.
	 *
	 * @param bool $dry_run When true, report what would go without deleting.
	 * @return array ['removed' => [...], 'failed' => [...], 'bytes' => int,
	 *                'kept' => int, 'keep_count' => int, 'dry_run' => bool]
	 */
	public static function prune($dry_run = false) {
		$keep_count = self::getKeepCount();
		$report = [
			'removed'    => [],
			'failed'     => [],
			'bytes'      => 0,
			'kept'       => 0,
			'keep_count' => $keep_count,
			'dry_run'    => (bool)$dry_run,
		];

		if ($keep_count === 0) {
			return $report;
		}

		foreach (self::classify() as $row) {
			if ($row['protected_by'] !== null) {
				$report['kept']++;
				continue;
			}
			if (!$row['archive_exists']) {
				continue; // already reclaimed; row stays as history
			}
			if ($dry_run) {
				$report['removed'][] = $row['version'];
				$report['bytes'] += $row['bytes'];
				continue;
			}
			$bytes = $row['bytes'];
			if (@unlink($row['path'])) {
				$report['removed'][] = $row['version'];
				$report['bytes'] += $bytes;
			} else {
				$report['failed'][] = $row['version'];
			}
		}

		return $report;
	}

	/** Human-readable byte size, matching the style used on the upgrades page. */
	public static function formatBytes($bytes) {
		if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . 'G';
		if ($bytes >= 1048576)    return round($bytes / 1048576, 1) . 'M';
		if ($bytes >= 1024)       return round($bytes / 1024, 1) . 'K';
		return $bytes . 'B';
	}
}
