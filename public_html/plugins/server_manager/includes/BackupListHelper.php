<?php
/**
 * BackupListHelper — produce a merged local+cloud backup-file list for a node.
 *
 * Local files come from the most recent completed `list_backups` job's result
 * (which parses `ls /backups/` on the node). Cloud files come from a live
 * TargetLister call against the configured BackupTarget. The two are merged
 * by filename so that a file present in both locations reports `location: both`.
 *
 * Chain artifacts are deliberately absent: a chain is one restore point made of
 * many files, and BackupChainListHelper lists those as chains.
 *
 * @version 1.2 - cloud listing resolves the shelf via JobCommandBuilder::get_target(), so a node that
 *                names no target still has its remote backups listed (from the sole enabled shelf)
 *                instead of showing local files only
 * @version 1.1 - chain artifacts are excluded from the flat list
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('data/backup_target_class.php'));
require_once(PathHelper::getIncludePath('includes/TargetLister.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/BackupChainListHelper.php'));

class BackupListHelper {

	/**
	 * Return ['files' => [...], 'last_scan' => string|null, 'cloud_error' => ?string].
	 * Each file: filename, size, size_bytes, date, mtime, local_path, cloud_path, location.
	 */
	public static function get_for_node($node) {
		$local_files = [];
		$last_scan = null;

		// Most recent completed list_backups job for this node
		$latest = new MultiManagementJob(
			['node_id' => $node->key, 'job_type' => 'list_backups', 'status' => 'completed', 'deleted' => false],
			['mjb_id' => 'DESC'],
			1
		);
		$latest->load();
		if ($latest->count() > 0) {
			$job = $latest->get(0);
			$last_scan = $job->get('mjb_completed_time');
			$result = $job->get('mjb_result');
			if (is_string($result)) $result = json_decode($result, true);
			if (is_array($result) && !empty($result['files'])) {
				foreach ($result['files'] as $f) {
					if (!empty($f['filename'])) {
						$local_files[$f['filename']] = $f;
					}
				}
			}
		}

		// Live cloud listing via TargetLister.
		//
		// The shelf is resolved the SAME way the job builder resolves it — a node
		// that names no target still backs up to the sole enabled one, so its
		// cloud archives have to be listed here too rather than being invisible
		// because mgn_bkt_backup_target_id happens to be blank. Reading the raw
		// column left every such node's remote backups off this list. get_target
		// already returns only an enabled target (or null), so there is no
		// separate bkt_enabled check.
		$cloud_files = [];
		$cloud_error = null;
		$target = JobCommandBuilder::get_target($node);
		if ($target) {
			try {
				$listing = TargetLister::list_files($target, 500);
				if ($listing['success']) {
					$slug = $node->get('mgn_slug');
					$prefix = rtrim($target->get('bkt_path_prefix') ?: 'joinery-backups', '/') . '/';
					$node_prefix = $prefix . $slug . '/';
					foreach ($listing['files'] as $f) {
						// Only include files under this node's slug.
						if (strpos($f['key'], $node_prefix) !== 0) continue;
						// A chain's artifacts are not standalone backups. Listed
						// flat they invite a restore of one incremental with no
						// full under it, which restores nothing at all — chains
						// are offered as chains, by BackupChainListHelper.
						if (BackupChainListHelper::is_chain_object($f['key'])) continue;
						$filename = basename($f['key']);
						$mtime = $f['modified'] ? strtotime($f['modified']) : 0;
						$cloud_files[$filename] = [
							'filename' => $filename,
							'size' => self::format_size($f['size']),
							'size_bytes' => $f['size'],
							'date' => $mtime ? gmdate('Y-m-d', $mtime) : '',
							'mtime' => $mtime,
							'local_path' => null,
							'cloud_path' => $f['key'],
							'location' => 'cloud',
						];
					}
				} else {
					$cloud_error = $listing['error'] ?? 'unknown error';
				}
			} catch (Exception $e) {
				$cloud_error = $e->getMessage();
			}
		}

		// Merge by filename
		$merged = [];
		$all = array_unique(array_merge(array_keys($local_files), array_keys($cloud_files)));
		foreach ($all as $fn) {
			$has_local = isset($local_files[$fn]);
			$has_cloud = isset($cloud_files[$fn]);
			if ($has_local && $has_cloud) {
				$entry = $local_files[$fn];
				$entry['cloud_path'] = $cloud_files[$fn]['cloud_path'];
				$entry['location'] = 'both';
			} elseif ($has_local) {
				$entry = $local_files[$fn];
			} else {
				$entry = $cloud_files[$fn];
			}
			$merged[] = $entry;
		}

		usort($merged, function($a, $b) {
			$mt = ($b['mtime'] ?? 0) - ($a['mtime'] ?? 0);
			if ($mt !== 0) return $mt;
			return strcmp($b['date'] ?? '', $a['date'] ?? '');
		});

		return [
			'files' => $merged,
			'last_scan' => $last_scan,
			'cloud_error' => $cloud_error,
		];
	}

	private static function format_size($bytes) {
		if ($bytes < 1024) return $bytes . ' B';
		if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
		if ($bytes < 1024 * 1024 * 1024) return round($bytes / 1024 / 1024, 1) . ' MB';
		return round($bytes / 1024 / 1024 / 1024, 2) . ' GB';
	}
}
?>
