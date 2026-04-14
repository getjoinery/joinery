<?php
/**
 * JobResultProcessor - Parses completed job output into structured data.
 *
 * Called when a job transitions to 'completed'. Extracts meaningful data
 * from raw command output and updates related records.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));

class JobResultProcessor {

	/**
	 * Process a completed job. Dispatches to type-specific handler if one exists.
	 */
	public static function process($job) {
		$type = $job->get('mjb_job_type');
		$method = 'process_' . $type;
		if (method_exists(self::class, $method)) {
			self::$method($job);
		}
	}

	/**
	 * Parse check_status output into structured data and update the node record.
	 */
	private static function process_check_status($job) {
		$output = $job->get('mjb_output') ?: '';
		$result = [];

		// Parse disk usage from df output
		if (preg_match('/(\d+)%\s+\/\s*$/m', $output, $m)) {
			$result['disk_usage_percent'] = intval($m[1]);
		}
		if (preg_match('/(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\d+)%\s+\/\s*$/m', $output, $m)) {
			$result['disk_total'] = $m[2];
			$result['disk_used'] = $m[3];
			$result['disk_available'] = $m[4];
		}

		// Parse memory from free output
		if (preg_match('/Mem:\s+(\d+)\s+(\d+)\s+(\d+)/m', $output, $m)) {
			$result['memory_total_mb'] = intval($m[1]);
			$result['memory_used_mb'] = intval($m[2]);
			$result['memory_free_mb'] = intval($m[3]);
		}

		// Parse uptime
		if (preg_match('/up\s+(.+?),\s+\d+\s+user/m', $output, $m)) {
			$result['uptime'] = trim($m[1]);
		}

		// Parse load averages
		if (preg_match('/load average:\s+([\d.]+),\s+([\d.]+),\s+([\d.]+)/m', $output, $m)) {
			$result['load_1m'] = floatval($m[1]);
			$result['load_5m'] = floatval($m[2]);
			$result['load_15m'] = floatval($m[3]);
		}

		// Parse PostgreSQL status
		if (preg_match('/accepting connections/i', $output)) {
			$result['postgres_status'] = 'accepting connections';
		} elseif (preg_match('/no response|not accepting/i', $output)) {
			$result['postgres_status'] = 'not responding';
		}

		// Parse version
		$version = null;
		if (preg_match("/VERSION\s*=\s*['\"]?([^'\";\s]+)/", $output, $m)) {
			$version = trim($m[1]);
			$result['joinery_version'] = $version;
		}

		// Parse database list (bare-metal nodes only — Docker nodes skip this step)
		if (preg_match('/^CURRENT_DB=(\S+)$/m', $output, $m)) {
			$result['current_db'] = trim($m[1]);
		}
		if (preg_match_all('/^DB:(\S+)$/m', $output, $m)) {
			$result['db_list'] = $m[1];
		}

		// Update the node record
		$node_id = $job->get('mjb_mgn_node_id');
		if ($node_id) {
			$node = new ManagedNode($node_id, TRUE);
			$node->set('mgn_last_status_check', gmdate('Y-m-d H:i:s'));
			$node->set('mgn_last_status_data', json_encode($result));
			if ($version) {
				$node->set('mgn_joinery_version', $version);
			}
			$node->save();
		}

		// Save structured result on the job
		$job->set('mjb_result', json_encode($result));
		$job->save();
	}

	/**
	 * Parse backup output to extract file path and size.
	 */
	private static function process_backup_database($job) {
		$output = $job->get('mjb_output') ?: '';
		$result = [];

		// Look for backup file path in output (.sql.gz, .sql.gz.enc, or .tar.gz)
		if (preg_match('/(\/\S+\.(?:sql\.gz(?:\.enc)?|tar\.gz))\b/', $output, $m)) {
			$result['backup_file'] = $m[1];
		}

		// Look for file size from ls output
		if (preg_match('/(\d+(?:\.\d+)?[KMGT]?)\s+.*\.(?:sql\.gz|tar\.gz)/', $output, $m)) {
			$result['backup_size'] = $m[1];
		}

		if (!empty($result)) {
			$job->set('mjb_result', json_encode($result));
			$job->save();
		}
	}

	/**
	 * Parse backup_project output.
	 */
	private static function process_backup_project($job) {
		self::process_backup_database($job);
	}

	/**
	 * Post-process install_node: mark the node online on success or install_failed on failure.
	 * Runs for both 'completed' and 'failed' terminal states.
	 */
	private static function process_install_node($job) {
		$node_id = $job->get('mjb_mgn_node_id');
		if (!$node_id) return;

		try {
			$node = new ManagedNode($node_id, TRUE);
		} catch (Exception $e) { return; }

		$status = $job->get('mjb_status');
		$output = $job->get('mjb_output') ?: '';

		if ($status === 'completed' && strpos($output, 'INSTALL_SUCCESS') !== false) {
			$node->set('mgn_install_state', null);
		} else {
			$node->set('mgn_install_state', 'install_failed');
		}
		$node->save();

		$job->set('mjb_result', json_encode([
			'install_state' => $node->get('mgn_install_state'),
		]));
		$job->save();
	}

	/**
	 * Parse discover_nodes output into structured instance data.
	 */
	private static function process_discover_nodes($job) {
		$output = $job->get('mjb_output') ?: '';
		$params = $job->get('mjb_parameters');
		$params = is_string($params) ? json_decode($params, true) : $params;

		$result = [
			'host' => $params['host'] ?? '',
			'ssh_user' => $params['ssh_user'] ?? 'root',
			'ssh_key_path' => $params['ssh_key_path'] ?? '',
			'ssh_port' => intval($params['ssh_port'] ?? 22),
			'hostname' => '',
			'has_docker' => true,
			'instances' => [],
		];

		// Parse hostname from connection test
		if (preg_match('/CONNECT_OK\s*\n\s*(.+)/m', $output, $m)) {
			$result['hostname'] = trim($m[1]);
		}

		// Check for NO_DOCKER
		if (strpos($output, 'NO_DOCKER') !== false) {
			$result['has_docker'] = false;
		}

		// Parse JOINERY_INSTANCE lines
		// Format: JOINERY_INSTANCE|type|name|web_root|domain|db_name|version
		//         m[1]=type, m[2]=name, m[3]=web_root, m[4]=domain, m[5]=db_name, m[6]=version
		if (preg_match_all('/JOINERY_INSTANCE\|([^|]*)\|([^|]*)\|([^|]*)\|([^|]*)\|([^|]*)\|(.*)$/m', $output, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $m) {
				$name = trim($m[2]);
				$domain = trim($m[4]);
				$instance = [
					'type' => trim($m[1]),
					'container_name' => (trim($m[1]) === 'docker') ? $name : '',
					'name' => ucwords(str_replace(['-', '_'], ' ', $name)),
					'slug' => strtolower($name),
					'web_root' => trim($m[3]),
					'site_url' => $domain ? 'https://' . $domain : '',
					'db_name' => trim($m[5]),
					'version' => trim($m[6]),
				];
				$result['instances'][] = $instance;
			}
		}

		// Check which slugs already exist as nodes
		$existing_slugs = [];
		$existing_nodes = new MultiManagedNode(['deleted' => false]);
		$existing_nodes->load();
		foreach ($existing_nodes as $en) {
			$existing_slugs[] = $en->get('mgn_slug');
		}
		foreach ($result['instances'] as &$inst) {
			$inst['already_added'] = in_array($inst['slug'], $existing_slugs);
		}
		unset($inst);

		$job->set('mjb_result', json_encode($result));
		$job->save();
	}

	/**
	 * Parse list_backups output into structured file list.
	 * Stores result on both the job and the node record for caching.
	 *
	 * Local output format: LOCAL|size_bytes|mtime_epoch|filepath
	 * Cloud output: provider-specific (B2 --long or aws s3 ls)
	 */
	private static function process_list_backups($job) {
		$output = $job->get('mjb_output') ?: '';
		$files = [];
		$local_files = [];
		$cloud_files = [];

		// Parse local file lines: LOCAL|size|mtime|path
		if (preg_match_all('/^LOCAL\|(\d+)\|(\d+)\|(.+)$/m', $output, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $m) {
				$path = trim($m[3]);
				$filename = basename($path);
				$size_bytes = intval($m[1]);
				$mtime = intval($m[2]);
				$local_files[$filename] = [
					'filename' => $filename,
					'size' => self::format_size($size_bytes),
					'size_bytes' => $size_bytes,
					'date' => gmdate('Y-m-d', $mtime),
					'mtime' => $mtime,
					'local_path' => $path,
					'cloud_path' => null,
					'location' => 'local',
				];
			}
		}

		// Parse cloud file lines
		// AWS S3 ls format: "2026-04-11 16:30:00    2684354560 prefix/slug/filename.sql.gz"
		if (preg_match_all('/^(\d{4}-\d{2}-\d{2})\s+\S+\s+(\d+)\s+(.+)$/m', $output, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $m) {
				$cloud_path = trim($m[3]);
				$filename = basename($cloud_path);
				$cloud_files[$filename] = [
					'size' => self::format_size(intval($m[2])),
					'size_bytes' => intval($m[2]),
					'date' => $m[1],
					'cloud_path' => $cloud_path,
				];
			}
		}

		// B2 ls --long format: "4_zBucketId_... upload 2026-04-11 ... 2684354560 prefix/slug/filename.sql.gz"
		// Simplified: look for lines with a path that contains our backup extensions
		if (preg_match_all('/(\d{4}-\d{2}-\d{2})\s+\S+\s+(\d+)\s+\S+\s+(\S+\.(?:sql\.gz(?:\.enc)?|tar\.gz))$/m', $output, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $m) {
				$cloud_path = trim($m[3]);
				$filename = basename($cloud_path);
				if (!isset($cloud_files[$filename])) {
					$cloud_files[$filename] = [
						'size' => self::format_size(intval($m[2])),
						'size_bytes' => intval($m[2]),
						'date' => $m[1],
						'cloud_path' => $cloud_path,
					];
				}
			}
		}

		// Merge local and cloud into unified list
		$all_filenames = array_unique(array_merge(array_keys($local_files), array_keys($cloud_files)));
		foreach ($all_filenames as $fn) {
			$has_local = isset($local_files[$fn]);
			$has_cloud = isset($cloud_files[$fn]);

			if ($has_local && $has_cloud) {
				$entry = $local_files[$fn];
				$entry['cloud_path'] = $cloud_files[$fn]['cloud_path'];
				$entry['location'] = 'both';
			} elseif ($has_local) {
				$entry = $local_files[$fn];
			} else {
				$entry = [
					'filename' => $fn,
					'size' => $cloud_files[$fn]['size'],
					'size_bytes' => $cloud_files[$fn]['size_bytes'],
					'date' => $cloud_files[$fn]['date'],
					'mtime' => 0,
					'local_path' => null,
					'cloud_path' => $cloud_files[$fn]['cloud_path'],
					'location' => 'cloud',
				];
			}
			$files[] = $entry;
		}

		// Sort by date descending
		usort($files, function($a, $b) {
			return ($b['mtime'] ?? 0) - ($a['mtime'] ?? 0) ?: strcmp($b['date'] ?? '', $a['date'] ?? '');
		});

		$result = ['files' => $files];

		// Store on job
		$job->set('mjb_result', json_encode($result));
		$job->save();

		// Cache on node record
		$node_id = $job->get('mjb_mgn_node_id');
		if ($node_id) {
			$node = new ManagedNode($node_id, TRUE);
			$node->set('mgn_last_backup_list', json_encode($result));
			$node->set('mgn_last_backup_list_time', gmdate('Y-m-d H:i:s'));
			$node->save();
		}
	}

	/**
	 * Process delete_backup result.
	 */
	private static function process_delete_backup($job) {
		$output = $job->get('mjb_output') ?: '';
		$result = [
			'local_deleted' => strpos($output, 'LOCAL_DELETE_OK') !== false,
			'cloud_deleted' => strpos($output, 'CLOUD_DELETE_OK') !== false,
		];

		$job->set('mjb_result', json_encode($result));
		$job->save();
	}

	/**
	 * Format bytes into human-readable size.
	 */
	private static function format_size($bytes) {
		if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . 'G';
		if ($bytes >= 1048576) return round($bytes / 1048576, 1) . 'M';
		if ($bytes >= 1024) return round($bytes / 1024, 1) . 'K';
		return $bytes . 'B';
	}
}
?>
