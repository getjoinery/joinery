<?php
/**
 * JobResultProcessor - Parses completed job output into structured data.
 *
 * Called when a job transitions to 'completed'. Extracts meaningful data
 * from raw command output and updates related records.
 *
 * @version 1.2
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
	 *
	 * Handles both transports:
	 *   - API path: output is a JSON envelope {api_version,data:{...}} — extract data.
	 *   - SSH path: output is concatenated command output — parse with regexes.
	 *
	 * SSL detection uses two paths:
	 *   1. SSH cert token: Let's Encrypt cert file found on disk → 'letsencrypt'
	 *   2. HTTPS probe fallback: curl HEAD to https://domain/ — catches Cloudflare/edge SSL
	 * The API path implicitly proves HTTPS works (no separate probe needed; handled in
	 * fetch_status_via_api). For job-based API output, we probe explicitly here.
	 */
	private static function process_check_status($job) {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));

		$output = $job->get('mjb_output') ?: '';

		$api_data = self::extract_api_envelope_data($output);
		if (is_array($api_data) && !empty($api_data)) {
			$result = $api_data;
		} else {
			$result = self::parse_check_status_ssh_output($output);
		}
		$version     = $result['joinery_version'] ?? null;
		$is_api_path = ($api_data !== null);

		// Load node early — needed for HTTPS probe (mgn_site_url, mgn_tls_insecure)
		$node_id = $job->get('mjb_mgn_node_id');
		$node    = null;
		if ($node_id) {
			try { $node = new ManagedNode($node_id, TRUE); } catch (Exception $e) {}
		}

		// SSL detection
		$ssl_token     = self::parse_ssl_tokens($output);
		$ssl_new_state = null;  // null = no explicit state change from detection

		if ($ssl_token !== null) {
			// SSH path: explicit cert check result from the job steps
			$result['ssl_domain']   = $ssl_token['domain'];
			$result['ssl_le_cert']  = $ssl_token['found'];
			if ($ssl_token['found']) {
				$result['ssl_state']            = 'active';
				$result['ssl_detection_method'] = 'letsencrypt';
				$ssl_new_state = 'active';
				if (!empty($ssl_token['expiry_raw'])) {
					$result['ssl_expiry_raw'] = $ssl_token['expiry_raw'];
					$ts = strtotime($ssl_token['expiry_raw']);
					if ($ts) $result['ssl_expiry_ts'] = $ts;
				}
			} else {
				// No LE cert on disk — probe HTTPS to catch Cloudflare / other edge SSL
				$probe = JobCommandBuilder::probe_https($ssl_token['domain']);
				$result['ssl_https_probe'] = $probe['ok'];
				if ($probe['ok']) {
					$result['ssl_state']            = 'active';
					$result['ssl_detection_method'] = 'https_probe';
					$ssl_new_state = 'active';
				} else {
					$result['ssl_state'] = null;
				}
			}
		} elseif ($is_api_path && $node) {
			// API path: the Go agent called the API via HTTPS; probe to confirm valid cert
			$domain = parse_url($node->get('mgn_site_url') ?: '', PHP_URL_HOST) ?: '';
			if ($domain && !filter_var($domain, FILTER_VALIDATE_IP)
					&& $domain !== 'localhost' && !$node->get('mgn_tls_insecure')) {
				$probe = JobCommandBuilder::probe_https($domain);
				$result['ssl_https_probe'] = $probe['ok'];
				if ($probe['ok']) {
					$result['ssl_state']            = 'active';
					$result['ssl_domain']           = $domain;
					$result['ssl_detection_method'] = 'https_probe';
					$ssl_new_state = 'active';
				}
			}
		}

		if ($node) {
			$node->set('mgn_last_status_check', gmdate('Y-m-d H:i:s'));
			$node->set('mgn_last_status_data', json_encode($result));
			if ($version) {
				$node->set('mgn_joinery_version', $version);
			}
			if ($ssl_new_state !== null) {
				$node->set('mgn_ssl_state', $ssl_new_state);
			} elseif ($ssl_token !== null && !$ssl_token['found']) {
				// SSH cert missing AND HTTPS probe failed — cert disappeared from active node
				if ($node->get('mgn_ssl_state') === 'active') {
					$node->set('mgn_ssl_state', 'failed');
				}
			}
			$node->save();
		}

		// Save structured result on the job
		$job->set('mjb_result', json_encode($result));
		$job->save();
	}

	/**
	 * Pull the `data` field out of an api_success-style JSON envelope that the
	 * agent appended to mjb_output. The envelope is wrapped in step-header text
	 * ("=== [Step 1/1] ... ==="), so we scan for the first "{" and parse from
	 * there. Returns the decoded data array on success, null otherwise.
	 */
	private static function extract_api_envelope_data($output) {
		$start = strpos($output, '{');
		if ($start === false) return null;
		$candidate = substr($output, $start);
		// Trim trailing step-footer text ("[Step 1/1 OK ...") if present.
		$decoded = json_decode($candidate, true);
		if (is_array($decoded) && isset($decoded['api_version'], $decoded['data'])) {
			return is_array($decoded['data']) ? $decoded['data'] : null;
		}
		// Try progressively shorter prefixes — agents may append bytes after the JSON.
		$end = strrpos($candidate, '}');
		while ($end !== false && $end > 0) {
			$decoded = json_decode(substr($candidate, 0, $end + 1), true);
			if (is_array($decoded) && isset($decoded['api_version'], $decoded['data'])) {
				return is_array($decoded['data']) ? $decoded['data'] : null;
			}
			$end = strrpos(substr($candidate, 0, $end), '}');
		}
		return null;
	}

	/**
	 * Parse the multi-command SSH output into the structured result array.
	 */
	private static function parse_check_status_ssh_output($output) {
		$result = [];

		if (preg_match('/(\d+)%\s+\/\s*$/m', $output, $m)) {
			$result['disk_usage_percent'] = intval($m[1]);
		}
		if (preg_match('/(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\d+)%\s+\/\s*$/m', $output, $m)) {
			$result['disk_total']     = $m[2];
			$result['disk_used']      = $m[3];
			$result['disk_available'] = $m[4];
		}

		if (preg_match('/Mem:\s+(\d+)\s+(\d+)\s+(\d+)/m', $output, $m)) {
			$result['memory_total_mb'] = intval($m[1]);
			$result['memory_used_mb']  = intval($m[2]);
			$result['memory_free_mb']  = intval($m[3]);
		}

		if (preg_match('/up\s+(.+?),\s+\d+\s+user/m', $output, $m)) {
			$result['uptime'] = trim($m[1]);
		}

		if (preg_match('/load average:\s+([\d.]+),\s+([\d.]+),\s+([\d.]+)/m', $output, $m)) {
			$result['load_1m']  = floatval($m[1]);
			$result['load_5m']  = floatval($m[2]);
			$result['load_15m'] = floatval($m[3]);
		}

		if (preg_match('/accepting connections/i', $output)) {
			$result['postgres_status'] = 'accepting connections';
		} elseif (preg_match('/no response|not accepting/i', $output)) {
			$result['postgres_status'] = 'not responding';
		}

		if (preg_match("/VERSION\s*=\s*['\"]?([^'\";\s]+)/", $output, $m)) {
			$result['joinery_version'] = trim($m[1]);
		}

		if (preg_match('/^CURRENT_DB=(\S+)$/m', $output, $m)) {
			$result['current_db'] = trim($m[1]);
		}
		if (preg_match_all('/^DB:(\S+)$/m', $output, $m)) {
			$result['db_list'] = $m[1];
		}

		return $result;
	}

	/**
	 * Scan raw job output for SSL_CERT_FOUND / SSL_CERT_MISSING tokens emitted by
	 * the check_status SSL step. Returns an array with 'found', 'domain', and
	 * (when found) 'expiry_raw', or null if no token is present.
	 */
	private static function parse_ssl_tokens($output) {
		if (preg_match('/SSL_CERT_FOUND domain=(\S+) expiry=(.+)$/m', $output, $m)) {
			return ['found' => true, 'domain' => $m[1], 'expiry_raw' => trim($m[2])];
		}
		if (preg_match('/SSL_CERT_MISSING domain=(\S+)/m', $output, $m)) {
			return ['found' => false, 'domain' => $m[1]];
		}
		return null;
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
	 * Post-process apply_update: on successful completion, auto-enqueue a
	 * check_status job so the dashboard's badge/version/last-check reflect the
	 * post-upgrade state without requiring the user to click Check Status.
	 * build_check_status picks the API transport when available, SSH otherwise.
	 */
	private static function process_apply_update($job) {
		if ($job->get('mjb_status') !== 'completed') return;
		$node_id = $job->get('mjb_mgn_node_id');
		if (!$node_id) return;

		try {
			$node = new ManagedNode($node_id, TRUE);
		} catch (Exception $e) { return; }

		// Don't chain against soft-deleted nodes — the host may be gone.
		if ($node->get('mgn_delete_time')) return;

		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
		$chained_id = null;
		try {
			$steps = JobCommandBuilder::build_check_status($node);
			$chained = ManagementJob::createJob($node->key, 'check_status', $steps, null, $job->get('mjb_created_by'));
			$chained_id = $chained ? $chained->key : null;
		} catch (Exception $e) {
			// node has neither API nor SSH configured — record the skip and move on
		}

		$job->set('mjb_result', json_encode([
			'chained_check_status_job_id' => $chained_id,
		]));
		$job->save();
	}

	/**
	 * Post-process install_node: mark the node online on success or install_failed on failure.
	 * For auto-provisioned nodes (mjb_external_order_item_id is set): also sets ssl_state=pending
	 * and sends the welcome email via getjoinery's QueuedEmail API.
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
			// Auto-provisioned nodes: mark SSL pending so ProvisionPendingSsl picks them up
			if ($job->get('mjb_external_order_item_id') && $node->get('mgn_ssl_state') !== 'active') {
				$node->set('mgn_ssl_state', 'pending');
			}
			$node->save();
			// Send welcome email for auto-provisioned orders
			if ($job->get('mjb_external_order_item_id')) {
				self::send_provisioning_welcome_email($job, $node);
			}
		} else {
			$node->set('mgn_install_state', 'install_failed');
			$node->save();
		}

		$job->set('mjb_result', json_encode([
			'install_state' => $node->get('mgn_install_state'),
			'ssl_state'     => $node->get('mgn_ssl_state'),
		]));
		$job->save();
	}

	/**
	 * Send the post-provisioning welcome email to the customer via getjoinery's
	 * QueuedEmail API. Reads credentials from Server Manager plugin settings.
	 * Silently returns on any failure — email delivery is best-effort.
	 */
	private static function send_provisioning_welcome_email($job, $node) {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/GetJoineryApiClient.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_host_class.php'));

		$settings   = Globalvars::get_instance();
		$api_url    = $settings->get_setting('server_manager_getjoinery_api_url');
		$pub_key    = $settings->get_setting('server_manager_getjoinery_api_public_key');
		$sec_key    = $settings->get_setting('server_manager_getjoinery_api_secret_key');
		$from_email = $settings->get_setting('server_manager_provisioning_welcome_from_email') ?: 'support@getjoinery.com';
		$from_name  = $settings->get_setting('server_manager_provisioning_welcome_from_name')  ?: 'Get Joinery Support';

		if (!$api_url || !$pub_key || !$sec_key) return;

		$params = $job->get('mjb_parameters');
		$params = is_string($params) ? json_decode($params, true) : $params;
		$params = is_array($params) ? $params : [];

		$domain      = $params['domain'] ?? '';
		$admin_email = $params['admin_email'] ?? '';
		$user_name   = $params['user_name'] ?? 'Customer';

		if (!$admin_email || !$domain) return;

		// Resolve host IP for the DNS A-record instruction
		$host_ip = '';
		$host_id = $node->get('mgn_mgh_host_id');
		if ($host_id) {
			try {
				$host    = new ManagedHost($host_id, true);
				$host_ip = $host->get('mgh_host');
			} catch (Exception $e) {}
		}

		$client = new GetJoineryApiClient($api_url, $pub_key, $sec_key);
		$client->post('QueuedEmail', [
			'equ_from'      => $from_email,
			'equ_from_name' => $from_name,
			'equ_to'        => $admin_email,
			'equ_to_name'   => $user_name,
			'equ_subject'   => 'Your site is ready: ' . $domain,
			'equ_body'      => self::build_welcome_email_body($domain, $host_ip, $user_name),
			'equ_status'    => 2, // READY_TO_SEND
		]);
	}

	private static function build_welcome_email_body($domain, $host_ip, $user_name) {
		$name      = htmlspecialchars($user_name);
		$dom       = htmlspecialchars($domain);
		$ip        = htmlspecialchars($host_ip);
		$login_url = htmlspecialchars('https://' . $domain . '/admin');

		return <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#333">
<h2 style="color:#1a1a1a">Your site is ready!</h2>
<p>Hi {$name},</p>
<p>Your Joinery site for <strong>{$dom}</strong> has been installed successfully.</p>

<h3>Next step: point your DNS</h3>
<p>Add an <strong>A record</strong> for <code>{$dom}</code> pointing to:</p>
<p style="font-size:1.5em;text-align:center;font-weight:bold;letter-spacing:.05em;background:#f4f4f4;padding:12px;border-radius:4px">{$ip}</p>
<p>DNS changes typically propagate in a few minutes to a few hours. Once your domain resolves to that IP, HTTPS will be provisioned automatically — no action needed on your part.</p>

<h3>Log in</h3>
<p>After DNS resolves, your admin panel is at:<br>
<a href="{$login_url}">{$login_url}</a></p>

<p style="color:#666;font-size:.9em">Questions? Reply to this email or contact support@getjoinery.com.</p>
<p>— The Get Joinery Team</p>
</body></html>
HTML;
	}

	/**
	 * On provision_ssl success, flip mgn_ssl_state to active.
	 * Failure tracking and the 16h give-up logic live in ProvisionPendingSsl.
	 */
	private static function process_provision_ssl($job) {
		$node_id = $job->get('mjb_mgn_node_id');
		if (!$node_id) return;

		try {
			$node = new ManagedNode($node_id, TRUE);
		} catch (Exception $e) { return; }

		if ($job->get('mjb_status') === 'completed') {
			$node->set('mgn_ssl_state', 'active');
			$node->save();
		}

		$job->set('mjb_result', json_encode(['ssl_state' => $node->get('mgn_ssl_state')]));
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
	 * Parse list_backups output into a local-file list. Cloud listings are
	 * fetched web-server-side at display time via TargetLister, merged by
	 * BackupListHelper.
	 *
	 * Handles both transports:
	 *   - API path: JSON envelope with data.files[] (already structured).
	 *   - SSH path: LOCAL|size_bytes|mtime_epoch|filepath lines.
	 */
	private static function process_list_backups($job) {
		$output = $job->get('mjb_output') ?: '';
		$files = [];

		$api_data = self::extract_api_envelope_data($output);
		if (is_array($api_data) && isset($api_data['files']) && is_array($api_data['files'])) {
			$files = $api_data['files'];
		} elseif (preg_match_all('/^LOCAL\|(\d+)\|(\d+)\|(.+)$/m', $output, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $m) {
				$path = trim($m[3]);
				$filename = basename($path);
				$size_bytes = intval($m[1]);
				$mtime = intval($m[2]);
				$files[] = [
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

		usort($files, function($a, $b) { return ($b['mtime'] ?? 0) - ($a['mtime'] ?? 0); });

		$job->set('mjb_result', json_encode(['files' => $files]));
		$job->save();
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
