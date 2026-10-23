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
 * A management node also keeps the archive of each release it upgrades to
 * (keep_applied_archive), pruned by the same rules (prune_kept).
 *
 * @version 1.1 - keep_applied_archive and prune_kept: a management node keeps the release archives it
 *                applies, so a copy of one of its sites onto a host can install that release (B55)
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('data/upgrades_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_nodes_class.php'));
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

	/** A test's own archive directory in place of the site's. */
	public static $archive_dir = null;

	/** Directory holding the published archives ({site root}/static_files). */
	public static function getArchiveDir() {
		if (self::$archive_dir !== null) {
			return self::$archive_dir;
		}
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
	 * every non-deleted managed node's reported version, plus this management
	 * node's own version. Returned as a version-string => label map.
	 */
	public static function getInUseVersions() {
		$in_use = [];

		try {
			$nodes = new MultiManagedNode(['deleted' => false], ['mgn_managed_node_id' => 'ASC'], 1000, 0);
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

	/**
	 * A management node keeps the signed core archive of each release it
	 * upgrades to, as received, where it keeps the ones it publishes: a copy
	 * of one of its sites onto a host installs that site's own release from
	 * here (SiteCopyRunner::release_carries, ReleaseManifestSource), and a
	 * management node that does not publish holds no other. A publisher holds
	 * the archive under that name already and keeps its own.
	 *
	 * @return string the path kept, or '' when nothing was kept
	 */
	public static function keep_applied_archive(string $downloaded, string $version): string {
		if (!preg_match('/^\d+\.\d+\.\d+$/', $version) || !is_file($downloaded) || is_link($downloaded)) {
			return '';
		}
		$dir = self::getArchiveDir();
		$dest = $dir . '/joinery-core-' . $version . '.tar.gz';
		if (file_exists($dest) || (!is_dir($dir) && !@mkdir($dir, 0755, true))) {
			return '';
		}
		$part = $dest . '.part';
		if (!@copy($downloaded, $part) || !@chmod($part, 0644) || !@rename($part, $dest)) {
			@unlink($part);
			return '';
		}
		return $dest;
	}

	/**
	 * Remove kept archives (keep_applied_archive) by the rules published ones
	 * follow: the newest N stay, and so does any release this management node
	 * or one of its nodes runs. An archive a published release names is
	 * prune()'s, never this.
	 *
	 * @return array ['removed' => [...versions], 'failed' => [...versions]]
	 */
	public static function prune_kept(?int $keep_count = null): array {
		$report = ['removed' => [], 'failed' => []];
		$keep_count = $keep_count ?? self::getKeepCount();
		if ($keep_count === 0) {
			return $report;
		}
		$published = [];
		foreach (new MultiUpgrade([], ['upgrade_id' => 'DESC'], 5000, 0) as $u) {
			$published[(string)$u->get('upg_name')] = true;
		}
		$in_use = self::getInUseVersions();
		$kept = [];
		foreach (glob(self::getArchiveDir() . '/joinery-core-*.tar.gz') ?: [] as $path) {
			if (preg_match('/^joinery-core-(\d+\.\d+\.\d+)\.tar\.gz$/', basename($path), $m) && !isset($published[basename($path)])) {
				$kept[$m[1]] = $path;
			}
		}
		uksort($kept, function ($a, $b) { return version_compare($b, $a); });
		$index = 0;
		foreach ($kept as $version => $path) {
			if ($index++ < $keep_count || isset($in_use[$version])) {
				continue;
			}
			if (@unlink($path)) {
				$report['removed'][] = $version;
			} else {
				$report['failed'][] = $version;
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
