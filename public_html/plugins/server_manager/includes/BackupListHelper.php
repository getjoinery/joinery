<?php
/**
 * BackupListHelper — produce a merged local+cloud backup-file list for a node.
 *
 * Local files come from the most recent completed `list_backups` job's result
 * (which parses `ls /backups/` on the node). Cloud files come from a live
 * S3Signer::list() of each storage space that holds the node's backups. The two are merged
 * by filename so that a file present in both locations reports `location: both`.
 *
 * Chain artifacts are deliberately absent: a chain is one restore point made of
 * many files, and BackupChainListHelper lists those as chains.
 *
 * @version 1.5 - the cloud listing covers every live storage space of the node; each file names its space_id
 *                (specs/storage_targets.md WP4)
 * @version 1.4 - the cloud listing is S3Signer::list() of the node's named target (switched off included) under the node's own folder, so a node past the
 *                first 500 objects of the whole target is listed
 * @version 1.3 - format_size() is BackupRunner::human(): decimal units, one format for every backup size
 * @version 1.2 - cloud listing resolves backup storage via JobCommandBuilder::get_target(), so a node that
 *                names no target still has its remote backups listed (from the sole enabled backup storage)
 *                instead of showing local files only
 * @version 1.1 - chain artifacts are excluded from the flat list
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_jobs_class.php'));
require_once(PathHelper::getIncludePath('data/backup_targets_class.php'));
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
			['mjb_management_job_id' => 'DESC'],
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

		// Live cloud listing of every space that still holds the node's backups
		// (switched-off targets included), each capped so a huge bucket cannot
		// stall the page. A file names the space it is in, so a download or a
		// delete reaches the right bucket. The newest space's copy wins a name
		// found in two.
		$cloud_files = [];
		$cloud_errors = [];
		foreach (array_reverse(StorageSpace::of_owner(StorageSpace::OWNER_NODE, (int)$node->key)) as $space) {
			try {
				list($target, $creds, $bucket) = $space->reach();
				$node_prefix = $space->base();
				// Listed under the node's own folder: listing the whole target and filtering
				// afterwards showed nothing for a node past the first 500 objects.
				$listing = S3Signer::list($creds, $bucket, $node_prefix, 500);
				foreach ($listing as $f) {
					if (!$space->holds_key((string)$f['key'])) continue;
					// A chain's artifacts are not standalone backups. Listed
					// flat they invite a restore of one incremental with no
					// full under it, which restores nothing at all — chains
					// are offered as chains, by BackupChainListHelper.
					if (BackupChainListHelper::is_chain_object($f['key'])) continue;
					$filename = basename($f['key']);
					$mtime = $f['last_modified'] ? strtotime($f['last_modified']) : 0;
					$cloud_files[$filename] = [
						'filename' => $filename,
						'size' => self::format_size($f['size']),
						'size_bytes' => $f['size'],
						'date' => $mtime ? gmdate('Y-m-d', $mtime) : '',
						'mtime' => $mtime,
						'local_path' => null,
						'cloud_path' => $f['key'],
						'space_id' => (int)$space->key,
						'target_name' => (string)$target->get('bkt_name'),
						'location' => 'cloud',
					];
				}
			} catch (Exception $e) {
				$cloud_errors[] = $space->describe() . ': ' . $e->getMessage();
			}
		}
		$cloud_error = $cloud_errors ? implode('; ', $cloud_errors) : null;

		// Merge by filename
		$merged = [];
		$all = array_unique(array_merge(array_keys($local_files), array_keys($cloud_files)));
		foreach ($all as $fn) {
			$has_local = isset($local_files[$fn]);
			$has_cloud = isset($cloud_files[$fn]);
			if ($has_local && $has_cloud) {
				$entry = $local_files[$fn];
				$entry['cloud_path'] = $cloud_files[$fn]['cloud_path'];
				$entry['space_id'] = $cloud_files[$fn]['space_id'];
				$entry['target_name'] = $cloud_files[$fn]['target_name'];
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
		return BackupRunner::human((int)$bytes);
	}
}
?>
