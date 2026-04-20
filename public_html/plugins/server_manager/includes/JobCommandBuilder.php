<?php
/**
 * JobCommandBuilder - Generates step arrays for each job type.
 *
 * All job-type intelligence lives here. The Go agent is a generic executor
 * that reads these steps and runs them in order.
 *
 * @version 1.0
 */

class JobCommandBuilder {

	// ── Transport capability helpers ──
	//
	// Two orthogonal questions:
	//   1. Does the node HAVE the transport configured? (has_api_creds / has_ssh)
	//   2. Does the operation HAVE an implementation for a transport? (transports_for)
	// can_run() combines both: this node + this operation ⇒ can the builder build a job?
	// has_api() adds a live /health probe on top of has_api_creds (used at job-build time).

	public static function has_api_creds($node) {
		return !empty($node->get('mgn_api_public_key'))
			&& !empty($node->get('mgn_api_secret_key'))
			&& !empty($node->get('mgn_site_url'));
	}

	public static function has_ssh($node) {
		return !empty($node->get('mgn_host'))
			&& !empty($node->get('mgn_ssh_user'))
			&& !empty($node->get('mgn_ssh_key_path'));
	}

	/**
	 * Which transports does this operation have an implementation for?
	 * Looks for build_<op>_api and build_<op>_ssh methods.
	 */
	public static function transports_for($operation) {
		$transports = [];
		if (method_exists(static::class, "build_{$operation}_api")) {
			$transports[] = 'api';
		}
		if (method_exists(static::class, "build_{$operation}_ssh")) {
			$transports[] = 'ssh';
		}
		return $transports;
	}

	/**
	 * Optimistic: do we have at least one viable (transport, credentials) pair for this
	 * node + operation? Uses has_api_creds (config check, no probe) so the UI isn't
	 * gray-out-flickering on a transient endpoint hiccup.
	 */
	public static function can_run($node, $operation) {
		$op_transports = self::transports_for($operation);
		if (in_array('api', $op_transports) && self::has_api_creds($node)) return true;
		if (in_array('ssh', $op_transports) && self::has_ssh($node)) return true;
		return false;
	}

	/**
	 * Return a human-readable reason explaining why can_run() is false.
	 * Used for tooltips on disabled action buttons.
	 */
	public static function why_cannot_run($node, $operation) {
		$op_transports = self::transports_for($operation);
		if (empty($op_transports)) {
			return "Operation '{$operation}' has no implementation on the control plane.";
		}
		$parts = [];
		if (in_array('api', $op_transports) && !self::has_api_creds($node)) {
			$parts[] = 'no API credentials are configured';
		}
		if (in_array('ssh', $op_transports) && !self::has_ssh($node)) {
			$parts[] = 'SSH is not configured';
		}
		if (!in_array('api', $op_transports)) {
			$parts[] = 'no API implementation exists';
		}
		if (!in_array('ssh', $op_transports)) {
			$parts[] = 'no SSH implementation exists';
		}
		return "Cannot run '{$operation}' on this node: " . implode('; ', $parts) . '.';
	}

	/**
	 * Routing decision at job-build time: should the dispatcher emit API steps
	 * for this (node, operation) pair? True iff:
	 *   1. The node has API credentials configured.
	 *   2. build_<op>_api exists on this class.
	 *   3. A fresh GET /health probe against the node succeeds (1s timeout).
	 */
	public static function has_api($node, $operation) {
		if (!self::has_api_creds($node)) return false;
		if (!method_exists(static::class, "build_{$operation}_api")) return false;

		$probe = self::probe_api_health($node, 1);
		return !empty($probe['ok']);
	}

	/**
	 * Synchronously probe /api/v1/management/health on a node.
	 * Returns ['ok' => bool, 'elapsed_ms' => int, 'message' => string|null, 'reason' => string|null].
	 * Never throws — all failures come back as ok=false with a reason string.
	 */
	public static function probe_api_health($node, $timeout_seconds = 2) {
		$start = microtime(true);
		$site_url = rtrim((string)$node->get('mgn_site_url'), '/');
		$public_key = (string)$node->get('mgn_api_public_key');
		$secret_key = (string)$node->get('mgn_api_secret_key');

		if ($site_url === '' || $public_key === '' || $secret_key === '') {
			return [
				'ok' => false,
				'elapsed_ms' => 0,
				'message' => 'API credentials or site URL not configured',
				'reason' => 'config',
			];
		}

		$url = $site_url . '/api/v1/management/health';
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => $timeout_seconds,
			CURLOPT_TIMEOUT        => $timeout_seconds,
			CURLOPT_HTTPHEADER     => [
				'public_key: ' . $public_key,
				'secret_key: ' . $secret_key,
				'Accept: application/json',
			],
			CURLOPT_SSL_VERIFYPEER => $node->get('mgn_tls_insecure') ? false : true,
			CURLOPT_SSL_VERIFYHOST => $node->get('mgn_tls_insecure') ? 0 : 2,
			CURLOPT_FOLLOWLOCATION => false,
		]);
		$body = curl_exec($ch);
		$errno = curl_errno($ch);
		$errmsg = curl_error($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$elapsed_ms = intval(round((microtime(true) - $start) * 1000));

		if ($errno) {
			return [
				'ok' => false,
				'elapsed_ms' => $elapsed_ms,
				'message' => $errmsg ?: 'transport failure',
				'reason' => 'transport',
			];
		}
		if ($status === 401 || $status === 403) {
			return [
				'ok' => false,
				'elapsed_ms' => $elapsed_ms,
				'message' => 'authentication failed',
				'reason' => 'auth',
			];
		}
		if ($status !== 200) {
			return [
				'ok' => false,
				'elapsed_ms' => $elapsed_ms,
				'message' => 'HTTP ' . intval($status),
				'reason' => 'status',
			];
		}

		$decoded = json_decode((string)$body, true);
		if (!is_array($decoded) || empty($decoded['data']['ok'])) {
			return [
				'ok' => false,
				'elapsed_ms' => $elapsed_ms,
				'message' => 'unexpected response body',
				'reason' => 'body',
			];
		}

		return [
			'ok' => true,
			'elapsed_ms' => $elapsed_ms,
			'message' => null,
			'reason' => null,
		];
	}

	/**
	 * Get the path to Globalvars_site.php on a remote node.
	 * Config is one level up from web_root (public_html).
	 */
	private static function get_config_path($node) {
		$web_root = rtrim($node->get('mgn_web_root'), '/');
		return dirname($web_root) . '/config/Globalvars_site.php';
	}

	/**
	 * Build shell script snippet to extract DB credentials from remote config.
	 * Sets $DB_NAME, $DB_USER, and $PGPASSWORD variables in the shell context.
	 * PGPASSWORD is exported so psql picks it up automatically.
	 */
	private static function get_db_credentials_script($node) {
		$config = self::get_config_path($node);
		// Extract dbname, dbusername, and dbpassword from Globalvars_site.php
		// Pattern: grep the line, take text before semicolon, take value after =,
		// strip whitespace, strip surrounding single quotes via sed
		$extract = 'head -1 | cut -d";" -f1 | cut -d"=" -f2 | tr -d " " | sed s/^.// | sed s/.$//';
		return "DB_NAME=\$(grep dbname {$config} | {$extract}) && "
			 . "DB_USER=\$(grep dbusername {$config} | {$extract}) && "
			 . "export PGPASSWORD=\$(grep dbpassword {$config} | {$extract})";
	}

	/**
	 * Get the maintenance scripts path from the web root.
	 */
	private static function get_scripts_path($node) {
		$web_root = rtrim($node->get('mgn_web_root'), '/');
		return dirname($web_root) . '/maintenance_scripts';
	}

	/**
	 * Returns 'sudo ' when the node is bare-metal with a non-root SSH user,
	 * empty string for Docker nodes (commands run as root inside the container).
	 */
	private static function sudo_prefix($node) {
		$is_docker = (bool)$node->get('mgn_container_name');
		$ssh_user  = $node->get('mgn_ssh_user') ?: 'root';
		return (!$is_docker && $ssh_user !== 'root') ? 'sudo ' : '';
	}

	/**
	 * Check system health metrics on a node. Dispatches between API and SSH
	 * implementations based on has_api(). If API creds exist and /health probes
	 * green, the job runs as a single api step; otherwise it runs the six-ish
	 * SSH steps that have always been the default.
	 */
	public static function build_check_status($node) {
		if (self::has_api($node, 'check_status')) {
			return self::build_check_status_api($node);
		}
		if (self::has_ssh($node)) {
			return self::build_check_status_ssh($node);
		}
		throw new Exception(
			"Node '{$node->get('mgn_slug')}' cannot run check_status: "
			. "no API credentials (or health probe failed) and no SSH credentials configured."
		);
	}

	/**
	 * API path: a single GET to /api/v1/management/stats. The response JSON
	 * is parsed by JobResultProcessor::process_check_status into the same
	 * mgn_last_status_data shape the SSH path produces.
	 */
	public static function build_check_status_api($node) {
		return [
			['type' => 'api', 'label' => 'Fetch node stats', 'method' => 'GET', 'endpoint' => 'stats', 'timeout' => 30],
		];
	}

	/**
	 * Legacy SSH path — unchanged. Not called directly; the dispatcher above
	 * routes here when API isn't available.
	 */
	public static function build_check_status_ssh($node) {
		$web_root = $node->get('mgn_web_root');
		$skip_joinery = $node->get('mgn_skip_joinery_checks');

		$steps = [
			['type' => 'ssh', 'label' => 'Check disk usage', 'cmd' => 'df -h /'],
			['type' => 'ssh', 'label' => 'Check memory', 'cmd' => 'free -m'],
			['type' => 'ssh', 'label' => 'Check uptime', 'cmd' => 'uptime'],
		];

		if (!$skip_joinery) {
			$steps[] = ['type' => 'ssh', 'label' => 'Check PostgreSQL', 'cmd' => 'pg_isready'];
			$steps[] = ['type' => 'ssh', 'label' => 'Check Joinery version',
				'cmd' => self::get_db_credentials_script($node) . " && psql -U \"\$DB_USER\" -d \"\$DB_NAME\" -tAc \"SELECT 'VERSION=' || stg_value FROM stg_settings WHERE stg_name = 'system_version'\""];
			$steps[] = ['type' => 'ssh', 'label' => 'Recent errors',
				'cmd' => "grep -i 'fatal\\|error\\|exception' " . dirname($web_root) . "/logs/error.log | tail -20",
				'continue_on_error' => true];
		}

		if ($node->get('mgn_container_name')) {
			$container = $node->get('mgn_container_name');
			$steps[] = ['type' => 'ssh', 'label' => 'Container stats',
						'cmd' => "docker stats --no-stream {$container}", 'on_host' => true];
		}

		if (!$skip_joinery) {
			// List databases in this node's PostgreSQL instance for the Internal Copy dropdown.
			// For Docker this runs inside the container; for bare-metal on the host. Either way,
			// it returns the databases accessible to the node's DB user.
			$creds = self::get_db_credentials_script($node);
			$steps[] = ['type' => 'ssh', 'label' => 'List databases',
				'cmd' => "{$creds} && echo \"CURRENT_DB=\$DB_NAME\" && psql -U \"\$DB_USER\" -tAc \"SELECT 'DB:' || datname FROM pg_database WHERE datistemplate = false AND datname NOT IN ('postgres') ORDER BY datname\"",
				'continue_on_error' => true];
		}

		return $steps;
	}

	/**
	 * Backup a node's database using backup_database.sh.
	 * If the node has a cloud backup target, appends upload and optional cleanup steps.
	 */
	public static function build_backup_database($node, $params = []) {
		$scripts = self::get_scripts_path($node);
		$creds = self::get_db_credentials_script($node);

		// Force encryption whenever backups will be uploaded to a cloud target
		$target = self::get_target($node);
		if ($target) {
			$params['encryption'] = true;
		}

		// Script encrypts by default; pass --plaintext to disable
		$flags = '--non-interactive';
		if (empty($params['encryption'])) {
			$flags .= ' --plaintext';
		}

		$steps = [];

		// Ensure encryption key exists, auto-generate if missing
		if (!empty($params['encryption'])) {
			$steps[] = ['type' => 'ssh', 'label' => 'Ensure encryption key',
				'cmd' => 'if [ -f ~/.joinery_backup_key ]; then echo "ENCRYPTION_KEY_OK"; else openssl rand -base64 32 > ~/.joinery_backup_key && chmod 600 ~/.joinery_backup_key && echo "ENCRYPTION_KEY_GENERATED — retrieve it via SSH: cat ~/.joinery_backup_key"; fi'];
		}

		$sudo = self::sudo_prefix($node);
		$steps[] = ['type' => 'ssh', 'label' => 'Ensure backup directory',
			'cmd' => "{$sudo}mkdir -p /backups && {$sudo}chmod 777 /backups"];

		$steps[] = ['type' => 'ssh', 'label' => 'Run database backup',
			'cmd' => "{$creds} && cd /backups && bash {$scripts}/sysadmin_tools/backup_database.sh {$flags} \"\$DB_NAME\"",
			'timeout' => 3600];

		// Append upload step if node has a cloud target
		self::append_upload_steps($steps, $node);

		$steps[] = ['type' => 'ssh', 'label' => 'List backup files',
			'cmd' => "ls -lht /backups/*.sql.gz /backups/*.sql.gz.enc /backups/*.tar.gz 2>/dev/null | head -5",
			'continue_on_error' => true];

		return $steps;
	}

	/**
	 * Full project backup (DB + files + Apache config).
	 * If the node has a cloud backup target, appends upload and optional cleanup steps.
	 */
	public static function build_backup_project($node, $params = []) {
		$scripts = self::get_scripts_path($node);
		$web_root = rtrim($node->get('mgn_web_root'), '/');
		$project_root = dirname($web_root);
		// Extract project name from path: /var/www/html/empoweredhealthtn/public_html -> empoweredhealthtn
		$project_name = basename($project_root);

		// Force encryption whenever backups will be uploaded to a cloud target
		$target = self::get_target($node);
		if ($target) {
			$params['encryption'] = true;
		}

		// Script encrypts by default; pass --plaintext to disable
		$flags = '--non-interactive --output-dir /backups';
		if (empty($params['encryption'])) {
			$flags .= ' --plaintext';
		}

		$steps = [];

		if (!empty($params['encryption'])) {
			$steps[] = ['type' => 'ssh', 'label' => 'Ensure encryption key',
				'cmd' => 'if [ -f ~/.joinery_backup_key ]; then echo "ENCRYPTION_KEY_OK"; else openssl rand -base64 32 > ~/.joinery_backup_key && chmod 600 ~/.joinery_backup_key && echo "ENCRYPTION_KEY_GENERATED — retrieve it via SSH: cat ~/.joinery_backup_key"; fi'];
		}

		$sudo = self::sudo_prefix($node);
		$steps[] = ['type' => 'ssh', 'label' => 'Ensure backup directory',
			'cmd' => "{$sudo}mkdir -p /backups && {$sudo}chmod 777 /backups"];

		$steps[] = ['type' => 'ssh', 'label' => 'Run full project backup',
			'cmd' => "bash {$scripts}/sysadmin_tools/backup_project.sh {$project_name} {$flags}",
			'timeout' => 3600];

		self::append_upload_steps($steps, $node);

		$steps[] = ['type' => 'ssh', 'label' => 'List backup files',
			'cmd' => "ls -lht /backups/ 2>/dev/null | head -5",
			'continue_on_error' => true];

		return $steps;
	}

	/**
	 * Download a backup file from a node to the control plane. Dispatches
	 * between API (streaming HTTP GET) and SCP based on has_api().
	 */
	public static function build_fetch_backup($node, $params) {
		if (self::has_api($node, 'fetch_backup')) {
			return self::build_fetch_backup_api($node, $params);
		}
		if (self::has_ssh($node)) {
			return self::build_fetch_backup_ssh($node, $params);
		}
		throw new Exception(
			"Node '{$node->get('mgn_slug')}' cannot run fetch_backup: "
			. "no API credentials (or health probe failed) and no SSH credentials configured."
		);
	}

	public static function build_fetch_backup_api($node, $params) {
		$remote_path = $params['remote_path'];
		$filename = basename($remote_path);
		$local_path = "/tmp/fetched_{$filename}";

		return [
			['type' => 'api', 'label' => 'Download backup via API',
			 'method' => 'GET', 'endpoint' => 'backups/fetch',
			 'query' => ['path' => $remote_path],
			 'local_path' => $local_path,
			 // Large files — allow plenty of time for the stream. Matches old SCP default.
			 'timeout' => 3600],
		];
	}

	public static function build_fetch_backup_ssh($node, $params) {
		$remote_path = $params['remote_path'];
		$filename = basename($remote_path);
		$local_path = "/tmp/fetched_{$filename}";

		return [
			['type' => 'scp', 'label' => 'Download backup to control plane',
			 'direction' => 'download', 'remote_path' => $remote_path, 'local_path' => $local_path],
		];
	}

	/**
	 * Copy database from source node to target node.
	 * Auto-prepends a backup of the target before overwrite.
	 */
	public static function build_copy_database($source_node, $target_node, $params = []) {
		$source_creds = self::get_db_credentials_script($source_node);
		$target_creds = self::get_db_credentials_script($target_node);
		$target_config = self::get_config_path($target_node);

		$transfer_id = substr(md5(uniqid(mt_rand(), true)), 0, 12);
		$dump_file = "/tmp/copy_{$transfer_id}.sql.gz";

		$steps = [];

		$target_sudo = self::sudo_prefix($target_node);
		$source_sudo = self::sudo_prefix($source_node);

		// Safety: auto-backup target database first (ensure /backups exists)
		$steps[] = ['type' => 'ssh', 'label' => 'Auto-backup target database before overwrite',
			'cmd' => "{$target_sudo}mkdir -p /backups && {$target_creds} && pg_dump -U \"\$DB_USER\" \"\$DB_NAME\" | gzip > /backups/auto_pre_overwrite_\$(date +%Y%m%d_%H%M%S).sql.gz",
			'node_id' => $target_node->key,
			'timeout' => 3600];

		// Dump source — must run on source node
		$steps[] = ['type' => 'ssh', 'label' => 'Dump source database',
			'cmd' => "{$source_creds} && pg_dump -U \"\$DB_USER\" \"\$DB_NAME\" | gzip > {$dump_file}",
			'node_id' => $source_node->key,
			'timeout' => 3600];

		// Download from source to control plane — must pull from source node
		$steps[] = ['type' => 'scp', 'label' => 'Download dump from source',
			'direction' => 'download', 'remote_path' => $dump_file, 'local_path' => $dump_file,
			'node_id' => $source_node->key];

		// Upload to target host filesystem
		$steps[] = ['type' => 'scp', 'label' => 'Upload dump to target',
			'direction' => 'upload', 'local_path' => $dump_file, 'remote_path' => $dump_file,
			'node_id' => $target_node->key];

		// Docker targets: SCP lands on the host but docker exec runs inside the container.
		// Copy the dump file from host into the container so the restore step can read it.
		$target_container = $target_node->get('mgn_container_name');
		if ($target_container) {
			$tc = escapeshellarg($target_container);
			$df = escapeshellarg($dump_file);
			$steps[] = ['type' => 'ssh', 'label' => 'Copy dump into container',
				'cmd' => "docker cp {$dump_file} {$tc}:{$df}",
				'node_id' => $target_node->key, 'on_host' => true];
		}

		// Restore on target
		$steps[] = ['type' => 'ssh', 'label' => 'Restore database on target',
			'cmd' => "{$target_creds} && gunzip -c {$dump_file} | psql -U \"\$DB_USER\" \"\$DB_NAME\"",
			'node_id' => $target_node->key,
			'timeout' => 3600];

		// Cleanup steps (continue on error) — source cleanup on source node
		$steps[] = ['type' => 'ssh', 'label' => 'Clean up source dump',
			'cmd' => "rm -f {$dump_file}", 'node_id' => $source_node->key, 'continue_on_error' => true];
		// For Docker target: clean up both the copy inside container and the file on host
		if ($target_container) {
			$tc = escapeshellarg($target_container);
			$steps[] = ['type' => 'ssh', 'label' => 'Clean up dump in container',
				'cmd' => "docker exec {$tc} rm -f {$dump_file}", 'node_id' => $target_node->key,
				'on_host' => true, 'continue_on_error' => true];
			$steps[] = ['type' => 'ssh', 'label' => 'Clean up dump on target host',
				'cmd' => "rm -f {$dump_file}", 'node_id' => $target_node->key,
				'on_host' => true, 'continue_on_error' => true];
		} else {
			$steps[] = ['type' => 'ssh', 'label' => 'Clean up target dump',
				'cmd' => "rm -f {$dump_file}", 'node_id' => $target_node->key, 'continue_on_error' => true];
		}
		$steps[] = ['type' => 'local', 'label' => 'Clean up control plane',
			'cmd' => "rm -f {$dump_file}", 'continue_on_error' => true];

		return $steps;
	}

	/**
	 * Copy a database by name within the same PostgreSQL instance (bare-metal nodes).
	 * Source and target share the same DB user/credentials; no web-root lookup needed.
	 *
	 * Params:
	 *   source_db_name - name of the source database in the same PG instance
	 */
	public static function build_copy_database_by_name($node, $params = []) {
		$creds = self::get_db_credentials_script($node);
		$source_db = $params['source_db_name'];
		$transfer_id = substr(md5(uniqid(mt_rand(), true)), 0, 12);
		$dump_file = "/tmp/local_copy_{$transfer_id}.sql.gz";

		return [
			['type' => 'ssh', 'label' => 'Auto-backup target database before overwrite',
			 'cmd' => "{$creds} && pg_dump -U \"\$DB_USER\" \"\$DB_NAME\" | gzip > /backups/auto_pre_overwrite_\$(date +%Y%m%d_%H%M%S).sql.gz",
			 'timeout' => 3600],
			['type' => 'ssh', 'label' => "Dump source database ({$source_db})",
			 'cmd' => "{$creds} && pg_dump -U \"\$DB_USER\" " . escapeshellarg($source_db) . " | gzip > {$dump_file}",
			 'timeout' => 3600],
			['type' => 'ssh', 'label' => 'Restore to target',
			 'cmd' => "{$creds} && gunzip -c {$dump_file} | psql -U \"\$DB_USER\" \"\$DB_NAME\"",
			 'timeout' => 3600],
			['type' => 'ssh', 'label' => 'Clean up temp dump',
			 'cmd' => "rm -f {$dump_file}", 'continue_on_error' => true],
		];
	}

	/**
	 * Restore a database from a backup file (local or cloud).
	 * Auto-prepends a backup before overwrite.
	 * If the file is cloud-only, downloads it to /backups/ first.
	 *
	 * Params:
	 *   filename   - original filename (used for cloud download target)
	 *   local_path - path on server if file exists locally (may be null)
	 *   cloud_path - provider path if file exists in cloud (may be null)
	 */
	public static function build_restore_database($node, $params) {
		$creds      = self::get_db_credentials_script($node);
		$local_path = $params['local_path'] ?? $params['backup_path'] ?? null;
		$cloud_path = $params['cloud_path'] ?? null;
		$filename   = $params['filename'] ?? basename((string)($local_path ?: $cloud_path));

		$steps = [];

		// Auto-backup target before overwrite
		$steps[] = ['type' => 'ssh', 'label' => 'Auto-backup database before restore',
			'cmd' => "{$creds} && pg_dump -U \"\$DB_USER\" \"\$DB_NAME\" | gzip > /backups/auto_pre_restore_\$(date +%Y%m%d_%H%M%S).sql.gz",
			'timeout' => 3600];

		// If cloud-only: download to /backups/ on the remote server first
		if (!$local_path && $cloud_path) {
			$target = self::get_target($node);
			if ($target) {
				$cred_vals = $target->get_credentials();
				$bucket    = $target->get('bkt_bucket');
				$dl_path   = '/backups/' . basename($filename);

				$uploader_script = self::build_node_uploader_script($cred_vals, $bucket);
				$eof = '__JOINERY_UPLOADER_EOF__';
				$cp_arg = escapeshellarg($cloud_path);
				$dl_arg = escapeshellarg($dl_path);
				$dl_cmd = "php -- download {$cp_arg} {$dl_arg} <<'{$eof}'\n{$uploader_script}\n{$eof}";

				$steps[] = ['type' => 'ssh', 'label' => 'Download backup from cloud',
					'cmd' => $dl_cmd, 'timeout' => 3600];
				$local_path = $dl_path;
			}
		}

		$restore_path = escapeshellarg($local_path);

		// Restore
		$steps[] = ['type' => 'ssh', 'label' => 'Restore database from backup',
			'cmd' => "{$creds} && gunzip -c {$restore_path} | psql -U \"\$DB_USER\" \"\$DB_NAME\"",
			'timeout' => 3600];

		// Verify
		$steps[] = ['type' => 'ssh', 'label' => 'Verify restore',
			'cmd' => "{$creds} && psql -U \"\$DB_USER\" \"\$DB_NAME\" -c \"SELECT count(*) AS table_count FROM information_schema.tables WHERE table_schema = 'public'\""];

		return $steps;
	}

	/**
	 * Restore a full project backup (.tar.gz) onto an existing node.
	 *
	 * $params:
	 *   filename      - display name of the archive (for logging)
	 *   local_path    - /backups/*.tar.gz on the node, or null
	 *   cloud_path    - remote object key in the bucket, or null
	 *   skip_database - bool
	 *   skip_files    - bool
	 *   skip_apache   - bool
	 */
	public static function build_restore_project($node, $params) {
		$local_path = $params['local_path'] ?? null;
		$cloud_path = $params['cloud_path'] ?? null;
		$filename   = $params['filename'] ?? basename((string)($local_path ?: $cloud_path));

		$skip_db     = !empty($params['skip_database']);
		$skip_files  = !empty($params['skip_files']);
		$skip_apache = !empty($params['skip_apache']);

		if ($skip_db && $skip_files && $skip_apache) {
			throw new Exception('At least one of project files, database, or Apache config must be restored.');
		}

		$web_root    = rtrim($node->get('mgn_web_root'), '/');
		$project_dir = dirname($web_root);
		$project_name = basename($project_dir);

		$scripts = self::get_scripts_path($node);
		$creds   = self::get_db_credentials_script($node);
		$steps   = [];

		// 1. Download from cloud if the backup only exists remotely
		if (!$local_path && $cloud_path) {
			$target = self::get_target($node);
			if (!$target) {
				throw new Exception('Cannot restore cloud-only backup: node has no backup target configured.');
			}
			$cred_vals = $target->get_credentials();
			$bucket    = $target->get('bkt_bucket');
			$dl_path   = '/backups/' . basename($filename);

			$uploader_script = self::build_node_uploader_script($cred_vals, $bucket);
			$eof    = '__JOINERY_UPLOADER_EOF__';
			$cp_arg = escapeshellarg($cloud_path);
			$dl_arg = escapeshellarg($dl_path);
			$dl_cmd = "php -- download {$cp_arg} {$dl_arg} <<'{$eof}'\n{$uploader_script}\n{$eof}";

			$steps[] = ['type' => 'ssh', 'label' => 'Download backup from cloud',
				'cmd' => $dl_cmd, 'timeout' => 3600];
			$local_path = $dl_path;
		}

		$sudo = self::sudo_prefix($node);
		// 2. Auto-backup current DB before overwrite (plaintext — fast recovery, no key needed)
		if (!$skip_db) {
			$steps[] = ['type' => 'ssh', 'label' => 'Auto-backup database before restore',
				'cmd' => "{$sudo}mkdir -p /backups && {$creds} && pg_dump -U \"\$DB_USER\" \"\$DB_NAME\" | gzip > /backups/auto_pre_project_restore_\$(date +%Y%m%d_%H%M%S).sql.gz",
				'timeout' => 3600];
		}

		// 3. Auto-backup current project tree (no DB, no Apache — just the files)
		if (!$skip_files) {
			$parent = escapeshellarg(dirname($project_dir));
			$base   = escapeshellarg(basename($project_dir));
			$steps[] = ['type' => 'ssh', 'label' => 'Auto-backup project files before restore',
				'cmd' => "{$sudo}mkdir -p /backups && {$sudo}tar czf /backups/auto_pre_project_restore_\$(date +%Y%m%d_%H%M%S).tar.gz -C {$parent} {$base}",
				'timeout' => 3600];
		}

		// 4. Run restore_project.sh — --force activates non-interactive mode and
		// cascades --non-interactive into the inner restore_database.sh call.
		$skip_flags = '';
		if ($skip_db)     $skip_flags .= ' --skip-database';
		if ($skip_files)  $skip_flags .= ' --skip-files';
		if ($skip_apache) $skip_flags .= ' --skip-apache';

		$restore_cmd = "cd /backups && {$sudo}bash " . escapeshellarg("{$scripts}/sysadmin_tools/restore_project.sh")
			. ' ' . escapeshellarg($project_name)
			. ' ' . escapeshellarg($local_path)
			. ' --force' . $skip_flags;

		$steps[] = ['type' => 'ssh', 'label' => 'Run project restore', 'cmd' => $restore_cmd, 'timeout' => 3600];

		// 5. Verify
		$verify_cmd = "ls -la " . escapeshellarg($web_root) . " | head -8";
		if (!$skip_db) {
			$verify_cmd .= " && {$creds} && psql -U \"\$DB_USER\" \"\$DB_NAME\" -c \"SELECT count(*) AS table_count FROM information_schema.tables WHERE table_schema = 'public'\"";
		}
		$steps[] = ['type' => 'ssh', 'label' => 'Verify restore', 'cmd' => $verify_cmd];

		return $steps;
	}

	/**
	 * Apply a Joinery update on target via upgrade.php.
	 */
	public static function build_apply_update($node, $params = []) {
		$web_root = $node->get('mgn_web_root');
		$dry_run = !empty($params['dry_run']) ? ' --dry-run' : '';

		return [
			['type' => 'ssh', 'label' => 'Apply Joinery update',
			 'cmd' => "cd {$web_root} && php utils/upgrade.php --verbose{$dry_run}",
			 'timeout' => 3600],
		];
	}

	/**
	 * Refresh upgrade archives and apply on target.
	 */
	public static function build_refresh_archives($node, $params = []) {
		$web_root = $node->get('mgn_web_root');

		return [
			['type' => 'ssh', 'label' => 'Refresh archives and apply update',
			 'cmd' => "cd {$web_root} && php utils/upgrade.php --refresh-archives --verbose",
			 'timeout' => 3600],
		];
	}

	/**
	 * Publish a new upgrade from the control plane (runs locally).
	 * If major/minor/patch are in $params, passes them as an explicit version arg;
	 * otherwise the CLI auto-detects the next version.
	 */
	public static function build_publish_upgrade($params) {
		$notes = escapeshellarg($params['release_notes']);
		$version_arg = '';
		if (isset($params['major'], $params['minor'], $params['patch'])) {
			$version = intval($params['major']) . '.' . intval($params['minor']) . '.' . intval($params['patch']);
			$version_arg = escapeshellarg($version) . ' ';
		}
		return [
			['type' => 'local', 'label' => 'Publish upgrade',
			 'cmd' => "cd /var/www/html/joinerytest/public_html && php plugins/server_manager/includes/publish_upgrade.php {$version_arg}{$notes}"],
		];
	}

	/**
	 * Build an SSH command prefix for local-type steps that SSH to a remote host.
	 * Used by discover_nodes which runs before a node record exists.
	 */
	private static function ssh_prefix($host, $ssh_user, $ssh_key_path, $ssh_port = 22) {
		$port_flag = ($ssh_port != 22) ? "-p {$ssh_port} " : '';
		return "ssh -i " . escapeshellarg($ssh_key_path)
			 . " -o StrictHostKeyChecking=no -o ConnectTimeout=10 -o BatchMode=yes "
			 . $port_flag
			 . escapeshellarg("{$ssh_user}@{$host}");
	}

	/**
	 * Discover Joinery instances on a remote host.
	 * All steps are 'local' type — the agent runs SSH commands from the control plane.
	 * No node record is needed.
	 */
	public static function build_discover_nodes($params) {
		$host = $params['host'];
		$ssh_user = $params['ssh_user'] ?? 'root';
		$ssh_key_path = $params['ssh_key_path'];
		$ssh_port = intval($params['ssh_port'] ?? 22) ?: 22;

		$ssh = self::ssh_prefix($host, $ssh_user, $ssh_key_path, $ssh_port);

		$steps = [];

		// Step 1: Test connection and get hostname
		$steps[] = ['type' => 'local', 'label' => 'Test SSH connection',
			'cmd' => "{$ssh} 'echo CONNECT_OK && hostname'"];

		// Step 2: List Docker containers (continue on error — may not have Docker)
		$steps[] = ['type' => 'local', 'label' => 'List Docker containers',
			'cmd' => "{$ssh} 'docker ps --format \"{{.Names}}\" 2>/dev/null || echo NO_DOCKER'",
			'continue_on_error' => true];

		// Step 3: Write scan script to temp file and execute remotely via stdin
		$scan_script = self::get_discover_script();
		$script_path = '/tmp/joinery_discover_' . substr(md5(uniqid(mt_rand(), true)), 0, 8) . '.sh';

		$steps[] = ['type' => 'local', 'label' => 'Write scan script',
			'cmd' => "cat > {$script_path} << 'SCANEOF'\n{$scan_script}\nSCANEOF\nchmod +x {$script_path}"];

		$steps[] = ['type' => 'local', 'label' => 'Scan for Joinery instances',
			'cmd' => "{$ssh} 'bash -s' < {$script_path}",
			'timeout' => 120];

		$steps[] = ['type' => 'local', 'label' => 'Clean up scan script',
			'cmd' => "rm -f {$script_path}",
			'continue_on_error' => true];

		return $steps;
	}

	// ── Backup target helpers ──

	/**
	 * Load the backup target for a node, if configured.
	 * Returns BackupTarget or null.
	 */
	private static function get_target($node) {
		$target_id = $node->get('mgn_bkt_backup_target_id');
		if (!$target_id) return null;
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/backup_target_class.php'));
		try {
			$target = new BackupTarget($target_id, TRUE);
			if ($target->get('bkt_enabled')) {
				return $target;
			}
		} catch (Exception $e) {}
		return null;
	}

	/**
	 * Append upload (and optional local cleanup) steps to a steps array
	 * if the node has a cloud backup target configured.
	 *
	 * The upload command uses NEWEST_BACKUP shell variable which should be set
	 * by finding the most recently modified backup file.
	 */
	private static function append_upload_steps(&$steps, $node) {
		$target = self::get_target($node);
		if (!$target) return;

		$slug = $node->get('mgn_slug');
		$prefix = $target->get('bkt_path_prefix') ?: 'joinery-backups';
		$creds = $target->get_credentials();
		$bucket = $target->get('bkt_bucket');

		// Find the newest backup file
		$find_newest = 'NEWEST_BACKUP=$(ls -t /backups/*.sql.gz /backups/*.sql.gz.enc /backups/*.tar.gz 2>/dev/null | head -1)';
		$check = 'test -n "$NEWEST_BACKUP"';
		$remote_key = "REMOTE_KEY=\"{$prefix}/{$slug}/\$(basename \"\$NEWEST_BACKUP\")\"";

		$uploader_script = self::build_node_uploader_script($creds, $bucket);
		$eof = '__JOINERY_UPLOADER_EOF__';
		$upload_cmd = "php -- upload \"\$NEWEST_BACKUP\" \"\$REMOTE_KEY\" <<'{$eof}'\n{$uploader_script}\n{$eof}";

		// No continue_on_error: if upload fails, halt so (a) the local cleanup step below
		// does not delete the only surviving copy, and (b) the job is marked failed so the
		// failure is visible in the UI instead of silently being labeled "completed".
		$steps[] = [
			'type' => 'ssh',
			'label' => 'Upload backup to ' . $target->get('bkt_name'),
			'cmd' => "{$find_newest} && {$check} && {$remote_key} && {$upload_cmd}",
			'timeout' => 3600,
		];

		if ($node->get('mgn_delete_local_after_upload')) {
			$steps[] = [
				'type' => 'ssh',
				'label' => 'Clean up local backup',
				'cmd' => "{$find_newest} && {$check} && rm -f \"\$NEWEST_BACKUP\"",
				'continue_on_error' => true,
			];
		}
	}

	/**
	 * Assemble the standalone PHP uploader script that will be heredoc'd onto
	 * the node. Concatenates S3Signer.php + node_uploader.php + a credentials
	 * block. The result runs under `php -` on the node with no file deps.
	 */
	private static function build_node_uploader_script($creds, $bucket) {
		$signer_path = PathHelper::getIncludePath('plugins/server_manager/includes/S3Signer.php');
		$dispatcher_path = PathHelper::getIncludePath('plugins/server_manager/includes/node_uploader.php');

		$signer = self::strip_php_tags(file_get_contents($signer_path));
		$dispatcher = self::strip_php_tags(file_get_contents($dispatcher_path));

		$creds_block = '$creds = ' . var_export($creds, true) . ";\n"
		             . '$bucket = ' . var_export($bucket, true) . ";\n";

		return "<?php\n" . $signer . "\n" . $creds_block . "\n" . $dispatcher;
	}

	/**
	 * Strip opening and closing PHP tags so a file body can be concatenated
	 * inside another `<?php ... ?>` block.
	 */
	private static function strip_php_tags($code) {
		$code = preg_replace('/^\s*<\?php\s*/', '', $code);
		$code = preg_replace('/\?>\s*$/', '', $code);
		return $code;
	}

	/**
	 * List backup files on a node. Local only — cloud listings are done
	 * web-server-side via TargetLister when the Backups tab renders.
	 * Dispatches to API or SSH based on has_api().
	 */
	public static function build_list_backups($node) {
		if (self::has_api($node, 'list_backups')) {
			return self::build_list_backups_api($node);
		}
		if (self::has_ssh($node)) {
			return self::build_list_backups_ssh($node);
		}
		throw new Exception(
			"Node '{$node->get('mgn_slug')}' cannot run list_backups: "
			. "no API credentials (or health probe failed) and no SSH credentials configured."
		);
	}

	public static function build_list_backups_api($node) {
		return [
			['type' => 'api', 'label' => 'List local backups', 'method' => 'GET', 'endpoint' => 'backups/list', 'timeout' => 30],
		];
	}

	public static function build_list_backups_ssh($node) {
		return [
			['type' => 'ssh', 'label' => 'List local backups',
			 'cmd' => "for f in /backups/*.sql.gz /backups/*.sql.gz.enc /backups/*.tar.gz; do "
			        . "[ -f \"\$f\" ] && stat --format='LOCAL|%s|%Y|%n' \"\$f\"; "
			        . "done 2>/dev/null; echo 'LOCAL_LIST_DONE'",
			 'continue_on_error' => true],
		];
	}

	/**
	 * Delete a backup file from local, cloud, or both.
	 * $params: target ('local', 'cloud', 'both'), local_path, cloud_path, filename
	 */
	public static function build_delete_backup($node, $params) {
		$which = $params['target'] ?? 'local';
		$local_path = $params['local_path'] ?? '';
		$cloud_path = $params['cloud_path'] ?? '';
		$steps = [];

		if (($which === 'local' || $which === 'both') && $local_path) {
			$steps[] = [
				'type' => 'ssh', 'label' => 'Delete local backup',
				'cmd' => "rm -f " . escapeshellarg($local_path) . " && echo 'LOCAL_DELETE_OK'",
				'continue_on_error' => true,
			];
		}

		if (($which === 'cloud' || $which === 'both') && $cloud_path) {
			$target = self::get_target($node);
			if ($target) {
				$creds = $target->get_credentials();
				$bucket = $target->get('bkt_bucket');

				$uploader_script = self::build_node_uploader_script($creds, $bucket);
				$eof = '__JOINERY_UPLOADER_EOF__';
				$remote_key = escapeshellarg($cloud_path);
				$cmd = "php -- delete {$remote_key} <<'{$eof}'\n{$uploader_script}\n{$eof}";

				$steps[] = [
					'type' => 'ssh', 'label' => 'Delete cloud backup',
					'cmd' => $cmd,
					'continue_on_error' => true,
				];
			}
		}

		if (empty($steps)) {
			$steps[] = ['type' => 'ssh', 'label' => 'No files to delete', 'cmd' => 'echo "Nothing to delete"'];
		}

		return $steps;
	}

	/**
	 * Build a local shell command that updates a ManagedNode field in the control plane DB.
	 * Reads DB credentials from the control plane's Globalvars_site.php. Used during the
	 * install_node flow to switch mgn_ssh_user to 'user1' after install.sh server disables
	 * root SSH login.
	 */
	private static function _update_node_ssh_user_cmd($node, $new_user) {
		$node_id = intval($node->key);
		$new_user_esc = escapeshellarg($new_user);
		$cfg = escapeshellarg(PathHelper::getSiteRoot() . '/config/Globalvars_site.php');
		$extract = 'head -1 | cut -d";" -f1 | cut -d"=" -f2 | tr -d " " | sed s/^.// | sed s/.$//';
		return "CFG={$cfg} && "
		     . "DB_NAME=\$(grep dbname \$CFG | {$extract}) && "
		     . "DB_USER=\$(grep dbusername \$CFG | {$extract}) && "
		     . "export PGPASSWORD=\$(grep dbpassword \$CFG | {$extract}) && "
		     . "psql -U \"\$DB_USER\" -d \"\$DB_NAME\" -c \"UPDATE mgn_managed_nodes SET mgn_ssh_user = {$new_user_esc} WHERE mgn_id = {$node_id}\" && "
		     . "echo SSH_USER_UPDATED_TO_{$new_user}";
	}

	/**
	 * Build steps for one-click node install (fresh or from-backup).
	 *
	 * Target is assumed to be a bare Ubuntu 24.04 host with SSH root access.
	 * The flow bootstraps whichever prereqs (Docker or Apache/PHP/Postgres)
	 * are needed based on the admin's choice, then creates the site.
	 *
	 * $params:
	 *   mode           - 'fresh' or 'from_backup'
	 *   sitename       - site directory name (e.g. 'mysite' → /var/www/html/mysite)
	 *   domain         - primary domain (fresh) or source domain (from-backup)
	 *   docker_mode    - 'docker' or 'bare-metal' (required; no auto-detect)
	 *   port           - (docker only) host port, default 8080
	 *   source_node_id - (from-backup only) source node ID
	 *   backup_source  - (from-backup only) 'new' or 'existing'
	 *   db_backup_path / project_backup_path - (existing backup) remote paths on source
	 */
	public static function build_install_node($node, $params) {
		$mode      = $params['mode'] ?? 'fresh';
		$sitename  = $params['sitename'] ?? $node->get('mgn_slug');
		$domain    = $params['domain'] ?? '';
		$docker    = $params['docker_mode'] ?? '';
		$port      = intval($params['port'] ?? 8080) ?: 8080;

		if ($docker !== 'docker' && $docker !== 'bare-metal') {
			throw new Exception("install_node requires docker_mode = 'docker' or 'bare-metal' (got: " . var_export($docker, true) . ")");
		}

		$transfer_id = substr(md5(uniqid(mt_rand(), true)), 0, 12);
		$remote_install_dir = '/tmp/joinery_install';
		$remote_tools_dir = "{$remote_install_dir}/maintenance_scripts/install_tools";

		// Control plane URL — where the target fetches the Joinery release tarball from.
		// Uses the webDir config setting (our site's own hostname).
		$settings = Globalvars::get_instance();
		$webdir = $settings->get_setting('webDir') ?: $_SERVER['HTTP_HOST'] ?? 'joinerytest.site';
		$release_url = "https://{$webdir}/utils/latest_release";
		$release_url_esc = escapeshellarg($release_url);

		$sitename_esc = escapeshellarg($sitename);
		$domain_esc = escapeshellarg($domain);
		$mode_flag = ($docker === 'docker') ? ' --docker' : ' --bare-metal';
		$port_arg = ($docker === 'docker') ? ' ' . intval($port) : '';

		$steps = [];

		// 1. Pre-flight: verify the control plane is serving a release archive
		$steps[] = ['type' => 'local', 'label' => 'Pre-flight: check release archive is available',
			'cmd' => "CODE=\$(curl -sILo /dev/null -w '%{http_code}' {$release_url_esc}) && "
			       . "test \"\$CODE\" = '200' -o \"\$CODE\" = '302' || { echo \"Release URL {$release_url} returned HTTP \$CODE\"; exit 1; } && "
			       . "echo PREFLIGHT_OK"];

		// From-Backup: grab source backups BEFORE installing
		if ($mode === 'from_backup') {
			$source_node_id = intval($params['source_node_id'] ?? 0);
			if (!$source_node_id) {
				throw new Exception('From-Backup mode requires source_node_id.');
			}
			require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
			$source_node = new ManagedNode($source_node_id, TRUE);
			$source_scripts = self::get_scripts_path($source_node);
			$source_creds = self::get_db_credentials_script($source_node);
			$source_web_root = rtrim($source_node->get('mgn_web_root'), '/');
			$source_project = basename(dirname($source_web_root));

			$db_backup_remote = $params['db_backup_path'] ?? '';
			$project_backup_remote = $params['project_backup_path'] ?? '';

			if (($params['backup_source'] ?? 'new') === 'new') {
				$db_backup_remote = "/backups/install_{$transfer_id}.sql.gz";
				$project_backup_remote = "/backups/install_{$transfer_id}_project.tar.gz";

				$source_sudo = self::sudo_prefix($source_node);
				$steps[] = ['type' => 'ssh', 'label' => 'Ensure backup directory on source',
					'node_id' => $source_node_id, 'cmd' => "{$source_sudo}mkdir -p /backups && {$source_sudo}chmod 777 /backups"];
				$steps[] = ['type' => 'ssh', 'label' => 'Dump source database',
					'node_id' => $source_node_id,
					'cmd' => "{$source_creds} && pg_dump -U \"\$DB_USER\" \"\$DB_NAME\" | gzip > {$db_backup_remote}",
					'timeout' => 3600];
				$steps[] = ['type' => 'ssh', 'label' => 'Archive source project files',
					'node_id' => $source_node_id,
					'cmd' => "bash {$source_scripts}/sysadmin_tools/backup_project.sh {$source_project} --non-interactive --plaintext --output-dir /backups "
					       . "&& NEW_BK=\$(ls -t /backups/{$source_project}*.tar.gz 2>/dev/null | head -1) "
					       . "&& test -n \"\$NEW_BK\" && mv \"\$NEW_BK\" {$project_backup_remote}",
					'timeout' => 3600];
			} else {
				if (!$db_backup_remote || !$project_backup_remote) {
					throw new Exception('From-Backup with existing backup requires db_backup_path and project_backup_path.');
				}
			}

			$local_db_backup = "/tmp/install_{$transfer_id}.sql.gz";
			$local_project_backup = "/tmp/install_{$transfer_id}_project.tar.gz";

			// Docker source: files are inside the container; copy them to /tmp/ on the host
			// so that SCP (which reads from the host filesystem) can transfer them.
			$source_container = $source_node->get('mgn_container_name');
			$scp_db_remote  = $db_backup_remote;
			$scp_prj_remote = $project_backup_remote;
			if ($source_container) {
				$sc   = escapeshellarg($source_container);
				$db_r = escapeshellarg($db_backup_remote);
				$pr_r = escapeshellarg($project_backup_remote);
				// Stage to /tmp/ on the host (always writable by root)
				$scp_db_remote  = $local_db_backup;
				$scp_prj_remote = $local_project_backup;
				$db_host = escapeshellarg($local_db_backup);
				$pr_host = escapeshellarg($local_project_backup);
				$steps[] = ['type' => 'ssh', 'label' => 'Copy DB dump from container to host',
					'node_id' => $source_node_id, 'on_host' => true,
					'cmd' => "docker cp {$sc}:{$db_r} {$db_host}"];
				$steps[] = ['type' => 'ssh', 'label' => 'Copy project archive from container to host',
					'node_id' => $source_node_id, 'on_host' => true,
					'cmd' => "docker cp {$sc}:{$pr_r} {$pr_host}"];
			}

			$steps[] = ['type' => 'scp', 'label' => 'Fetch DB backup to control plane',
				'node_id' => $source_node_id, 'direction' => 'download',
				'remote_path' => $scp_db_remote, 'local_path' => $local_db_backup];
			$steps[] = ['type' => 'scp', 'label' => 'Fetch project backup to control plane',
				'node_id' => $source_node_id, 'direction' => 'download',
				'remote_path' => $scp_prj_remote, 'local_path' => $local_project_backup];
		}

		// 2. Fetch the Joinery release tarball on the target and extract it.
		// Target needs curl (usually present on Ubuntu; install if missing).
		// All commands sudo-wrapped so they work whether the agent connects as root or user1.
		$steps[] = ['type' => 'ssh', 'label' => 'Ensure curl is installed',
			'on_host' => true,
			'cmd' => "command -v curl >/dev/null || sudo bash -c 'apt-get update -qq && apt-get install -y -qq curl'"];

		$steps[] = ['type' => 'ssh', 'label' => 'Download and extract Joinery release',
			'on_host' => true,
			'cmd' => "sudo rm -rf {$remote_install_dir} && sudo mkdir -p {$remote_install_dir} && "
			       . "curl -sL {$release_url_esc} | sudo tar xz -C {$remote_install_dir} && "
			       . "sudo test -f {$remote_tools_dir}/install.sh && sudo chmod +x {$remote_tools_dir}/*.sh && "
			       . "echo RELEASE_EXTRACTED",
			'timeout' => 600];

		// 3. Install prereqs (Docker or bare-metal server setup)
		if ($docker === 'docker') {
			// install.sh docker is idempotent — short-circuits if Docker is already installed.
			// Docker subcommand does NOT harden SSH, so root access stays intact.
			$steps[] = ['type' => 'ssh', 'label' => 'Install Docker (if missing)',
				'on_host' => true,
				'cmd' => "cd {$remote_tools_dir} && ./install.sh -y -q docker",
				'timeout' => 1800];
		} else {
			// Bare-metal: install.sh server runs `PermitRootLogin no` + restarts sshd, locking
			// out our root-keyed agent. Before it runs, pre-stage user1 with root's authorized
			// keys and NOPASSWD sudo so the agent can keep talking to the target. After, we
			// switch the ManagedNode's ssh_user to user1 so subsequent steps (and future jobs)
			// connect as user1.
			// All commands prefixed with sudo — works as root (no-op) or as user1 (NOPASSWD sudo
			// already present from a prior successful run). On retry where we're already user1,
			// this step is effectively a no-op re-sync.
			$steps[] = ['type' => 'ssh', 'label' => 'Pre-stage user1 for managed access',
				'on_host' => true,
				'cmd' => "set -e; "
				       . "sudo test -s /root/.ssh/authorized_keys || { echo 'FATAL: /root/.ssh/authorized_keys is empty or missing — cannot pre-stage user1 safely. Aborting before install.sh server locks out root SSH.'; exit 1; }; "
				       . "id user1 >/dev/null 2>&1 || sudo useradd -m -s /bin/bash user1; "
				       . "sudo install -d -m 700 -o user1 -g user1 /home/user1/.ssh; "
				       . "sudo touch /home/user1/.ssh/authorized_keys; "
				       . "sudo bash -c 'cat /root/.ssh/authorized_keys >> /home/user1/.ssh/authorized_keys && sort -u /home/user1/.ssh/authorized_keys -o /home/user1/.ssh/authorized_keys'; "
				       . "sudo chmod 600 /home/user1/.ssh/authorized_keys; "
				       . "sudo chown user1:user1 /home/user1/.ssh/authorized_keys; "
				       . "echo 'user1 ALL=(ALL:ALL) NOPASSWD: ALL' | sudo tee /etc/sudoers.d/user1 >/dev/null; "
				       . "sudo chmod 440 /etc/sudoers.d/user1; "
				       . "echo USER1_READY"];

			// Switch the agent to user1 BEFORE running install.sh server (which disables
			// root login). The SSH pool re-creates its connection using the updated user
			// on the next step since install.sh server also restarts sshd.
			$steps[] = ['type' => 'local', 'label' => 'Switch SSH user to user1',
				'cmd' => self::_update_node_ssh_user_cmd($node, 'user1')];

			// Now as user1 (via sudo, NOPASSWD). Only run server setup if prereqs missing —
			// install.sh server resets the postgres role password and would break other sites.
			//
			// The password file at /root/.joinery_postgres_password is required by the site
			// creation step below (it uses --password-file to ensure the site's DB password
			// matches the postgres role password — _site_init.sh uses the site password as
			// PGPASSWORD for createdb -U postgres). If prereqs are already installed but the
			// file doesn't exist (host was set up manually), harvest the password from an
			// existing site's Globalvars_site.php.
			$steps[] = ['type' => 'ssh', 'label' => 'Install Apache/PHP/Postgres (if missing)',
				'on_host' => true,
				'cmd' => "cd {$remote_tools_dir} && "
				       . "if command -v apache2 >/dev/null && command -v psql >/dev/null && command -v php >/dev/null; then "
				       .   "echo 'PREREQS_ALREADY_INSTALLED — skipping install.sh server'; "
				       .   "if ! sudo test -s /root/.joinery_postgres_password; then "
				       .     "echo 'Harvesting postgres password from an existing site config...'; "
				       .     "EXISTING_CFG=\$(sudo find /var/www/html -maxdepth 3 -name Globalvars_site.php -path '*/config/*' 2>/dev/null | head -1); "
				       .     "if [ -z \"\$EXISTING_CFG\" ]; then "
				       .       "echo 'FATAL: prereqs installed but no postgres password available — cannot determine DB password. Manually create /root/.joinery_postgres_password containing the postgres role password.'; exit 1; "
				       .     "fi; "
				       .     "PW=\$(sudo grep dbpassword \"\$EXISTING_CFG\" | head -1 | cut -d\\; -f1 | cut -d= -f2 | tr -d ' ' | sed \"s/^.//;s/.$//\"); "
				       .     "test -n \"\$PW\" || { echo 'FATAL: could not extract dbpassword from existing config'; exit 1; }; "
				       .     "echo \"\$PW\" | sudo tee /root/.joinery_postgres_password >/dev/null && sudo chmod 600 /root/.joinery_postgres_password; "
				       .     "echo 'Password harvested from existing site config'; "
				       .   "fi; "
				       . "else "
				       .   "export POSTGRES_PASSWORD=\$(openssl rand -base64 18 | tr -d '/+=' | head -c 24) && "
				       .   "echo 'Auto-generated postgres password (recorded in /root/.joinery_postgres_password on target):' && "
				       .   "echo \"\$POSTGRES_PASSWORD\" | sudo tee /root/.joinery_postgres_password >/dev/null && sudo chmod 600 /root/.joinery_postgres_password && "
				       .   "sudo -E ./install.sh -y -q server; "
				       . "fi",
				'timeout' => 3600];
		}

		// 4. Create the site.
		// --no-ssl is always passed (DNS typically not yet pointing here).
		// Prefix with sudo so it works whether connecting as root or user1.
		//
		// Bare-metal: _site_init.sh uses $PASSWORD as PGPASSWORD for the `postgres` role when
		// running createdb, so the site's DB password MUST match the postgres role password
		// set by install.sh server (stored in /root/.joinery_postgres_password). Without this,
		// createdb auth-fails and the schema load skips silently. Passing `-` (auto-generate)
		// produces a mismatch — use --password-file instead.
		//
		// Docker mode runs Postgres inside the container with a fresh password, so `-` is fine.
		if ($docker === 'docker') {
			$pass_arg = ' -';
		} else {
			$pass_arg = ' --password-file=/root/.joinery_postgres_password';
		}
		$install_cmd = "cd {$remote_tools_dir} && sudo ./install.sh -y -q site{$mode_flag} {$sitename_esc}{$pass_arg} {$domain_esc}{$port_arg} --no-ssl";
		$steps[] = ['type' => 'ssh', 'label' => 'Create the site',
			'on_host' => true, 'cmd' => $install_cmd, 'timeout' => 3600];

		// Docker mode: record the container name in the control plane DB so future jobs
		// (backups, restores, status checks) correctly use docker exec to reach the site.
		if ($docker === 'docker') {
			$sitename_db_esc = str_replace("'", "''", $sitename);
			$node_id_int = intval($node->key);
			$cfg_esc = escapeshellarg(PathHelper::getSiteRoot() . '/config/Globalvars_site.php');
			$extr = 'head -1 | cut -d";" -f1 | cut -d"=" -f2 | tr -d " " | sed s/^.// | sed s/.$//';
			$update_cmd = "CFG={$cfg_esc} && "
			            . "DB_NAME=\$(grep dbname \$CFG | {$extr}) && "
			            . "DB_USER=\$(grep dbusername \$CFG | {$extr}) && "
			            . "export PGPASSWORD=\$(grep dbpassword \$CFG | {$extr}) && "
			            . "psql -U \"\$DB_USER\" -d \"\$DB_NAME\" -c \"UPDATE mgn_managed_nodes SET mgn_container_name = '{$sitename_db_esc}' WHERE mgn_id = {$node_id_int}\" && "
			            . "echo CONTAINER_NAME_UPDATED";
			$steps[] = ['type' => 'local', 'label' => 'Record container name in control plane',
				'cmd' => $update_cmd];
		}

		// Docker mode: set up an HTTP reverse proxy on the host so port 80 serves the site.
		// In docker mode, maintenance_scripts/ is baked into the container image — not on
		// the host — so we use the still-extracted copy under /tmp/joinery_install. This runs
		// before the cleanup step. manage_domain.sh auto-installs Apache + mod_proxy if
		// missing, writes {sitename}-proxy.conf, and reloads. Idempotent. SSL stays a
		// separate admin action after DNS cutover.
		// Skip for localhost / bare IP — a ServerName-based proxy needs a routable domain.
		$is_ip = (bool)preg_match('/^\d+\.\d+\.\d+\.\d+$/', $domain);
		if ($docker === 'docker' && $domain !== '' && $domain !== 'localhost' && !$is_ip) {
			$manage_domain = "{$remote_install_dir}/maintenance_scripts/sysadmin_tools/manage_domain.sh";
			$steps[] = ['type' => 'ssh', 'label' => 'Set up HTTP reverse proxy',
				'on_host' => true,
				'cmd' => "sudo bash {$manage_domain} set {$sitename_esc} {$domain_esc} --no-ssl",
				'timeout' => 300];
		}

		// From-Backup: restore DB + files onto freshly-installed site
		if ($mode === 'from_backup') {
			$target_config = "/var/www/html/{$sitename}/config/Globalvars_site.php";
			$remote_db_dump = "/tmp/joinery_restore_{$transfer_id}.sql.gz";
			$remote_project_tar = "/tmp/joinery_restore_{$transfer_id}_project.tar.gz";
			$local_db_backup = "/tmp/install_{$transfer_id}.sql.gz";
			$local_project_backup = "/tmp/install_{$transfer_id}_project.tar.gz";

			// SCP uploads to target: for Docker, files land on HOST /tmp/
			$steps[] = ['type' => 'scp', 'label' => 'Upload DB backup to target',
				'direction' => 'upload', 'local_path' => $local_db_backup, 'remote_path' => $remote_db_dump];
			$steps[] = ['type' => 'scp', 'label' => 'Upload project backup to target',
				'direction' => 'upload', 'local_path' => $local_project_backup, 'remote_path' => $remote_project_tar];

			// Docker target: SCP landed on host /tmp/ but restore runs inside the container —
			// copy files from host into the container so the restore steps can access them.
			// Use $docker/$sitename here (not mgn_container_name — it's blank until the post-install update step runs).
			$is_docker_install = ($docker === 'docker');
			$restore_on_host   = !$is_docker_install; // bare-metal: on_host=true; Docker: run inside container
			if ($is_docker_install) {
				$tc   = escapeshellarg($sitename);   // container name = sitename for new Docker installs
				$db_r = escapeshellarg($remote_db_dump);
				$pr_r = escapeshellarg($remote_project_tar);
				$steps[] = ['type' => 'ssh', 'label' => 'Copy DB dump into container',
					'on_host' => true,
					'cmd' => "docker cp {$remote_db_dump} {$tc}:{$db_r}"];
				$steps[] = ['type' => 'ssh', 'label' => 'Copy project backup into container',
					'on_host' => true,
					'cmd' => "docker cp {$remote_project_tar} {$tc}:{$pr_r}"];
			}

			$extract = 'head -1 | cut -d";" -f1 | cut -d"=" -f2 | tr -d " " | sed s/^.// | sed s/.$//';
			$creds = "DB_NAME=\$(grep dbname {$target_config} | {$extract}) && "
			       . "DB_USER=\$(grep dbusername {$target_config} | {$extract}) && "
			       . "export PGPASSWORD=\$(grep dbpassword {$target_config} | {$extract})";

			$sudo = self::sudo_prefix($node);
			$step_base = $restore_on_host ? ['on_host' => true] : [];

			$steps[] = array_merge($step_base, ['type' => 'ssh', 'label' => 'Auto-backup fresh DB before restore',
				'cmd' => "{$sudo}mkdir -p /backups && {$creds} && pg_dump -U \"\$DB_USER\" \"\$DB_NAME\" | gzip > /backups/auto_pre_install_restore_\$(date +%Y%m%d_%H%M%S).sql.gz",
				'timeout' => 3600]);

			$steps[] = array_merge($step_base, ['type' => 'ssh', 'label' => 'Restore source database',
				'cmd' => "{$creds} && psql -U \"\$DB_USER\" \"\$DB_NAME\" -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;' && gunzip -c {$remote_db_dump} | psql -U \"\$DB_USER\" \"\$DB_NAME\"",
				'timeout' => 3600]);

			$steps[] = array_merge($step_base, ['type' => 'ssh', 'label' => 'Extract project files',
				'cmd' => "tar xzf {$remote_project_tar} -C /var/www/html/{$sitename} --strip-components=1 --exclude='config/Globalvars_site.php'",
				'timeout' => 3600, 'continue_on_error' => true]);

			$steps[] = array_merge($step_base, ['type' => 'ssh', 'label' => 'Fix permissions',
				'cmd' => "bash /var/www/html/{$sitename}/maintenance_scripts/install_tools/fix_permissions.sh /var/www/html/{$sitename}",
				'continue_on_error' => true]);

			$steps[] = array_merge($step_base, ['type' => 'ssh', 'label' => 'Clean up restore artifacts on target',
				'cmd' => "rm -f {$remote_db_dump} {$remote_project_tar}",
				'continue_on_error' => true]);

			// For Docker: also clean up the staged files on the host
			if ($is_docker_install) {
				$steps[] = ['type' => 'ssh', 'label' => 'Clean up restore artifacts on host',
					'on_host' => true,
					'cmd' => "rm -f {$remote_db_dump} {$remote_project_tar}",
					'continue_on_error' => true];
			}

			$steps[] = ['type' => 'local', 'label' => 'Clean up backup files on control plane',
				'cmd' => "rm -f {$local_db_backup} {$local_project_backup}",
				'continue_on_error' => true];
		}

		// Cleanup installer on target (release tarball was piped through tar; no local file)
		$steps[] = ['type' => 'ssh', 'label' => 'Clean up installer on target',
			'on_host' => true,
			'cmd' => "sudo rm -rf {$remote_install_dir}",
			'continue_on_error' => true];

		// Post-install verification. Globalvars_site.php is chmod 640 root:www-data so
		// user1 needs sudo to test-read it.
		// Docker mode: config lives inside the container — exec test through docker.
		if ($docker === 'docker') {
			$verify_cmd = "echo INSTALL_SUCCESS && hostname && "
			            . "sudo docker exec {$sitename} test -f /var/www/html/{$sitename}/config/Globalvars_site.php && echo CONFIG_OK";
		} else {
			$verify_cmd = "echo INSTALL_SUCCESS && hostname && "
			            . "sudo test -f /var/www/html/{$sitename}/config/Globalvars_site.php && echo CONFIG_OK";
		}
		$steps[] = ['type' => 'ssh', 'label' => 'Verify install',
			'on_host' => true,
			'cmd' => $verify_cmd];

		return $steps;
	}

	/**
	 * The bash script that runs on the remote host to discover Joinery instances.
	 * Outputs structured lines: JOINERY_INSTANCE|type|name|web_root|domain|db_name|version
	 */
	private static function get_discover_script() {
		return <<<'BASH'
#!/bin/bash
found=0

# Check Docker containers
containers=$(docker ps --format "{{.Names}}" 2>/dev/null)
if [ -n "$containers" ]; then
  for c in $containers; do
    config=$(docker exec "$c" find /var/www/html -maxdepth 3 -name "Globalvars_site.php" -path "*/config/*" 2>/dev/null | head -1)
    if [ -n "$config" ]; then
      web_root=$(echo "$config" | sed 's|/config/Globalvars_site.php||')/public_html
      web_dir=$(docker exec "$c" grep "webDir" "$config" 2>/dev/null | head -1 | grep -oP "'[^']+'" | tail -1 | tr -d "'")
      db_name=$(docker exec "$c" grep "dbname" "$config" 2>/dev/null | head -1 | grep -oP "'[^']+'" | tail -1 | tr -d "'")
      db_user=$(docker exec "$c" grep "dbusername" "$config" 2>/dev/null | head -1 | grep -oP "'[^']+'" | tail -1 | tr -d "'")
      db_pass=$(docker exec "$c" grep "dbpassword" "$config" 2>/dev/null | head -1 | grep -oP "'[^']+'" | tail -1 | tr -d "'")
      version=""
      if [ -n "$db_name" ]; then
        version=$(docker exec "$c" bash -c "PGPASSWORD='$db_pass' psql -U '${db_user:-postgres}' -d '$db_name' -tAc \"SELECT stg_value FROM stg_settings WHERE stg_name = 'system_version'\"" 2>/dev/null)
      fi
      echo "JOINERY_INSTANCE|docker|$c|$web_root|$web_dir|$db_name|$version"
      found=$((found+1))
    fi
  done
fi

# Check bare metal if no containers found
if [ "$found" = "0" ]; then
  for config in $(find /var/www/html -maxdepth 3 -name "Globalvars_site.php" -path "*/config/*" 2>/dev/null); do
    site_dir=$(dirname $(dirname "$config"))
    web_root="$site_dir/public_html"
    web_dir=$(grep "webDir" "$config" 2>/dev/null | head -1 | grep -oP "'[^']+'" | tail -1 | tr -d "'")
    db_name=$(grep "dbname" "$config" 2>/dev/null | head -1 | grep -oP "'[^']+'" | tail -1 | tr -d "'")
    db_user=$(grep "dbusername" "$config" 2>/dev/null | head -1 | grep -oP "'[^']+'" | tail -1 | tr -d "'")
    db_pass=$(grep "dbpassword" "$config" 2>/dev/null | head -1 | grep -oP "'[^']+'" | tail -1 | tr -d "'")
    dir_name=$(basename "$site_dir")
    version=""
    if [ -n "$db_name" ]; then
      version=$(PGPASSWORD="$db_pass" psql -U "${db_user:-postgres}" -d "$db_name" -tAc "SELECT stg_value FROM stg_settings WHERE stg_name = 'system_version'" 2>/dev/null)
    fi
    echo "JOINERY_INSTANCE|bare|$dir_name|$web_root|$web_dir|$db_name|$version"
    found=$((found+1))
  done
fi

if [ "$found" = "0" ]; then
  echo "NO_JOINERY_FOUND"
fi
echo "SCAN_COMPLETE|$found"
BASH;
	}
}
?>
