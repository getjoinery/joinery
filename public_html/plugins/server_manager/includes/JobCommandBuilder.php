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
	 * Test SSH connectivity to a node.
	 */
	public static function build_test_connection($node) {
		return [
			['type' => 'ssh', 'label' => 'Test SSH connection', 'cmd' => 'echo "Connection successful" && hostname && whoami'],
		];
	}

	/**
	 * Check system health metrics on a node.
	 */
	public static function build_check_status($node) {
		$web_root = $node->get('mgn_web_root');
		$steps = [
			['type' => 'ssh', 'label' => 'Check disk usage', 'cmd' => 'df -h /'],
			['type' => 'ssh', 'label' => 'Check memory', 'cmd' => 'free -m'],
			['type' => 'ssh', 'label' => 'Check uptime', 'cmd' => 'uptime'],
			['type' => 'ssh', 'label' => 'Check PostgreSQL', 'cmd' => 'pg_isready'],
			['type' => 'ssh', 'label' => 'Check Joinery version',
			 'cmd' => self::get_db_credentials_script($node) . " && psql -U \"\$DB_USER\" -d \"\$DB_NAME\" -tAc \"SELECT 'VERSION=' || stg_value FROM stg_settings WHERE stg_name = 'system_version'\""],
			['type' => 'ssh', 'label' => 'Recent errors',
			 'cmd' => "grep -i 'fatal\\|error\\|exception' " . dirname($web_root) . "/logs/error.log | tail -20",
			 'continue_on_error' => true],
		];

		if ($node->get('mgn_container_name')) {
			$container = $node->get('mgn_container_name');
			$steps[] = ['type' => 'ssh', 'label' => 'Container stats',
						'cmd' => "docker stats --no-stream {$container}", 'on_host' => true];
		}

		return $steps;
	}

	/**
	 * Backup a node's database using backup_database.sh.
	 * If the node has a cloud backup destination, appends upload and optional cleanup steps.
	 */
	public static function build_backup_database($node, $params = []) {
		$scripts = self::get_scripts_path($node);
		$creds = self::get_db_credentials_script($node);

		// Force encryption for B2 destinations
		$dest = self::get_destination($node);
		if ($dest && $dest->get('bkd_provider') === 'b2') {
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

		$steps[] = ['type' => 'ssh', 'label' => 'Ensure backup directory',
			'cmd' => 'mkdir -p /backups'];

		$steps[] = ['type' => 'ssh', 'label' => 'Run database backup',
			'cmd' => "{$creds} && cd /backups && bash {$scripts}/sysadmin_tools/backup_database.sh {$flags} \"\$DB_NAME\"",
			'timeout' => 3600];

		// Append upload step if node has a cloud destination
		self::append_upload_steps($steps, $node);

		$steps[] = ['type' => 'ssh', 'label' => 'List backup files',
			'cmd' => "ls -lht /backups/*.sql.gz /backups/*.sql.gz.enc /backups/*.tar.gz 2>/dev/null | head -5",
			'continue_on_error' => true];

		return $steps;
	}

	/**
	 * Full project backup (DB + files + Apache config).
	 * If the node has a cloud backup destination, appends upload and optional cleanup steps.
	 */
	public static function build_backup_project($node, $params = []) {
		$scripts = self::get_scripts_path($node);
		$web_root = rtrim($node->get('mgn_web_root'), '/');
		$project_root = dirname($web_root);
		// Extract project name from path: /var/www/html/empoweredhealthtn/public_html -> empoweredhealthtn
		$project_name = basename($project_root);

		// Force encryption for B2 destinations
		$dest = self::get_destination($node);
		if ($dest && $dest->get('bkd_provider') === 'b2') {
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

		$steps[] = ['type' => 'ssh', 'label' => 'Ensure backup directory',
			'cmd' => 'mkdir -p /backups'];

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
	 * SCP a backup file from the remote node to the control plane.
	 */
	public static function build_fetch_backup($node, $params) {
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

		// Safety: auto-backup target database first
		$steps[] = ['type' => 'ssh', 'label' => 'Auto-backup target database before overwrite',
			'cmd' => "{$target_creds} && pg_dump -U \"\$DB_USER\" \"\$DB_NAME\" | gzip > /backups/auto_pre_overwrite_\$(date +%Y%m%d_%H%M%S).sql.gz",
			'node_id' => $target_node->key];

		// Dump source
		$steps[] = ['type' => 'ssh', 'label' => 'Dump source database',
			'cmd' => "{$source_creds} && pg_dump -U \"\$DB_USER\" \"\$DB_NAME\" | gzip > {$dump_file}",
			'timeout' => 3600];

		// Download from source to control plane
		$steps[] = ['type' => 'scp', 'label' => 'Download dump from source',
			'direction' => 'download', 'remote_path' => $dump_file, 'local_path' => $dump_file];

		// Upload to target
		$steps[] = ['type' => 'scp', 'label' => 'Upload dump to target',
			'direction' => 'upload', 'local_path' => $dump_file, 'remote_path' => $dump_file,
			'node_id' => $target_node->key];

		// Restore on target
		$steps[] = ['type' => 'ssh', 'label' => 'Restore database on target',
			'cmd' => "{$target_creds} && gunzip -c {$dump_file} | psql -U \"\$DB_USER\" \"\$DB_NAME\"",
			'node_id' => $target_node->key,
			'timeout' => 3600];

		// Cleanup steps (continue on error)
		$steps[] = ['type' => 'ssh', 'label' => 'Clean up source dump',
			'cmd' => "rm -f {$dump_file}", 'continue_on_error' => true];
		$steps[] = ['type' => 'ssh', 'label' => 'Clean up target dump',
			'cmd' => "rm -f {$dump_file}", 'node_id' => $target_node->key, 'continue_on_error' => true];
		$steps[] = ['type' => 'local', 'label' => 'Clean up control plane',
			'cmd' => "rm -f {$dump_file}", 'continue_on_error' => true];

		return $steps;
	}

	/**
	 * Restore a database from a backup file already on the target server.
	 * Auto-prepends a backup before overwrite.
	 */
	public static function build_restore_database($node, $params) {
		$creds = self::get_db_credentials_script($node);
		$backup_path = $params['backup_path'];

		$steps = [];

		// Safety: auto-backup current database first
		$steps[] = ['type' => 'ssh', 'label' => 'Auto-backup database before restore',
			'cmd' => "{$creds} && pg_dump -U \"\$DB_USER\" \"\$DB_NAME\" | gzip > /backups/auto_pre_restore_\$(date +%Y%m%d_%H%M%S).sql.gz",
			'timeout' => 3600];

		// Restore
		$steps[] = ['type' => 'ssh', 'label' => 'Restore database from backup',
			'cmd' => "{$creds} && gunzip -c {$backup_path} | psql -U \"\$DB_USER\" \"\$DB_NAME\"",
			'timeout' => 3600];

		// Verify
		$steps[] = ['type' => 'ssh', 'label' => 'Verify restore',
			'cmd' => "{$creds} && psql -U \"\$DB_USER\" \"\$DB_NAME\" -c \"SELECT count(*) AS table_count FROM information_schema.tables WHERE table_schema = 'public'\""];

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
	 */
	public static function build_publish_upgrade($params) {
		$notes = escapeshellarg($params['release_notes']);
		return [
			['type' => 'local', 'label' => 'Publish upgrade',
			 'cmd' => "cd /var/www/html/joinerytest/public_html && php plugins/server_manager/includes/publish_upgrade.php {$notes}"],
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

	// ── Backup destination helpers ──

	/**
	 * Load the backup destination for a node, if configured.
	 * Returns BackupDestination or null.
	 */
	private static function get_destination($node) {
		$dest_id = $node->get('mgn_bkd_backup_destination_id');
		if (!$dest_id) return null;
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/backup_destination_class.php'));
		try {
			$dest = new BackupDestination($dest_id, TRUE);
			if ($dest->get('bkd_enabled') && $dest->is_cloud()) {
				return $dest;
			}
		} catch (Exception $e) {}
		return null;
	}

	/**
	 * Append upload (and optional local cleanup) steps to a steps array
	 * if the node has a cloud backup destination configured.
	 *
	 * The upload command uses NEWEST_BACKUP shell variable which should be set
	 * by finding the most recently modified backup file.
	 */
	private static function append_upload_steps(&$steps, $node) {
		$dest = self::get_destination($node);
		if (!$dest) return;

		$slug = $node->get('mgn_slug');
		$prefix = $dest->get('bkd_path_prefix') ?: 'joinery-backups';
		$creds = $dest->get_credentials();
		$bucket = $dest->get('bkd_bucket');
		$provider = $dest->get('bkd_provider');

		// Find the newest backup file
		$find_newest = 'NEWEST_BACKUP=$(ls -t /backups/*.sql.gz /backups/*.sql.gz.enc /backups/*.tar.gz 2>/dev/null | head -1)';
		$check = 'test -n "$NEWEST_BACKUP"';

		$upload_cmd = self::build_provider_upload_cmd($provider, $creds, $bucket, $prefix, $slug);

		$steps[] = [
			'type' => 'ssh',
			'label' => 'Upload backup to ' . $dest->get('bkd_name'),
			'cmd' => "{$find_newest} && {$check} && {$upload_cmd}",
			'timeout' => 3600,
			'continue_on_error' => true,
		];

		if ($dest->get('bkd_delete_local')) {
			$steps[] = [
				'type' => 'ssh',
				'label' => 'Clean up local backup',
				'cmd' => "{$find_newest} && {$check} && rm -f \"\$NEWEST_BACKUP\"",
				'continue_on_error' => true,
			];
		}
	}

	/**
	 * Build the provider-specific upload command.
	 * Assumes $NEWEST_BACKUP is set in the shell environment.
	 */
	private static function build_provider_upload_cmd($provider, $creds, $bucket, $prefix, $slug) {
		$remote_path = "{$prefix}/{$slug}/\$(basename \"\$NEWEST_BACKUP\")";

		if ($provider === 'b2') {
			$key_id = escapeshellarg($creds['key_id'] ?? '');
			$app_key = escapeshellarg($creds['app_key'] ?? '');
			return "b2 authorize-account {$key_id} {$app_key} && b2 upload-file " . escapeshellarg($bucket) . " \"\$NEWEST_BACKUP\" \"{$remote_path}\"";
		}

		// S3-compatible (AWS S3, Linode Object Storage, etc.)
		$access = $creds['access_key'] ?? '';
		$secret = $creds['secret_key'] ?? '';
		$region = $creds['region'] ?? 'us-east-1';
		$endpoint = $creds['endpoint'] ?? '';

		$env = "AWS_ACCESS_KEY_ID=" . escapeshellarg($access) . " AWS_SECRET_ACCESS_KEY=" . escapeshellarg($secret);
		$cmd = "{$env} aws s3 cp \"\$NEWEST_BACKUP\" \"s3://{$bucket}/{$remote_path}\" --region " . escapeshellarg($region);
		if ($endpoint) {
			$cmd .= " --endpoint-url " . escapeshellarg($endpoint);
		}
		return $cmd;
	}

	/**
	 * List backup files on a node (local + cloud if destination configured).
	 * Output is parsed by JobResultProcessor::process_list_backups.
	 */
	public static function build_list_backups($node) {
		$steps = [
			['type' => 'ssh', 'label' => 'List local backups',
			 'cmd' => "for f in /backups/*.sql.gz /backups/*.sql.gz.enc /backups/*.tar.gz; do "
			        . "[ -f \"\$f\" ] && stat --format='LOCAL|%s|%Y|%n' \"\$f\"; "
			        . "done 2>/dev/null; echo 'LOCAL_LIST_DONE'",
			 'continue_on_error' => true],
		];

		$dest = self::get_destination($node);
		if ($dest) {
			$slug = $node->get('mgn_slug');
			$prefix = $dest->get('bkd_path_prefix') ?: 'joinery-backups';
			$creds = $dest->get_credentials();
			$bucket = $dest->get('bkd_bucket');
			$provider = $dest->get('bkd_provider');

			$list_cmd = self::build_provider_list_cmd($provider, $creds, $bucket, $prefix, $slug);
			$steps[] = [
				'type' => 'ssh', 'label' => 'List cloud backups',
				'cmd' => $list_cmd,
				'continue_on_error' => true,
			];
		}

		return $steps;
	}

	/**
	 * Build provider-specific list command.
	 */
	private static function build_provider_list_cmd($provider, $creds, $bucket, $prefix, $slug) {
		$cloud_path = "{$prefix}/{$slug}/";

		if ($provider === 'b2') {
			$key_id = escapeshellarg($creds['key_id'] ?? '');
			$app_key = escapeshellarg($creds['app_key'] ?? '');
			return "b2 authorize-account {$key_id} {$app_key} && b2 ls --long " . escapeshellarg($bucket) . " " . escapeshellarg($cloud_path) . "; echo 'CLOUD_LIST_DONE'";
		}

		$access = $creds['access_key'] ?? '';
		$secret = $creds['secret_key'] ?? '';
		$region = $creds['region'] ?? 'us-east-1';
		$endpoint = $creds['endpoint'] ?? '';

		$env = "AWS_ACCESS_KEY_ID=" . escapeshellarg($access) . " AWS_SECRET_ACCESS_KEY=" . escapeshellarg($secret);
		$cmd = "{$env} aws s3 ls \"s3://{$bucket}/{$cloud_path}\" --region " . escapeshellarg($region);
		if ($endpoint) {
			$cmd .= " --endpoint-url " . escapeshellarg($endpoint);
		}
		return $cmd . "; echo 'CLOUD_LIST_DONE'";
	}

	/**
	 * Delete a backup file from local, cloud, or both.
	 * $params: target ('local', 'cloud', 'both'), local_path, cloud_path, filename
	 */
	public static function build_delete_backup($node, $params) {
		$target = $params['target'] ?? 'local';
		$local_path = $params['local_path'] ?? '';
		$cloud_path = $params['cloud_path'] ?? '';
		$steps = [];

		if (($target === 'local' || $target === 'both') && $local_path) {
			$steps[] = [
				'type' => 'ssh', 'label' => 'Delete local backup',
				'cmd' => "rm -f " . escapeshellarg($local_path) . " && echo 'LOCAL_DELETE_OK'",
				'continue_on_error' => true,
			];
		}

		if (($target === 'cloud' || $target === 'both') && $cloud_path) {
			$dest = self::get_destination($node);
			if ($dest) {
				$creds = $dest->get_credentials();
				$bucket = $dest->get('bkd_bucket');
				$provider = $dest->get('bkd_provider');

				$delete_cmd = self::build_provider_delete_cmd($provider, $creds, $bucket, $cloud_path);
				$steps[] = [
					'type' => 'ssh', 'label' => 'Delete cloud backup',
					'cmd' => $delete_cmd,
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
	 * Build provider-specific delete command.
	 */
	private static function build_provider_delete_cmd($provider, $creds, $bucket, $cloud_path) {
		if ($provider === 'b2') {
			$key_id = escapeshellarg($creds['key_id'] ?? '');
			$app_key = escapeshellarg($creds['app_key'] ?? '');
			return "b2 authorize-account {$key_id} {$app_key} && b2 delete-file-version " . escapeshellarg($bucket) . " " . escapeshellarg($cloud_path) . " && echo 'CLOUD_DELETE_OK'";
		}

		$access = $creds['access_key'] ?? '';
		$secret = $creds['secret_key'] ?? '';
		$region = $creds['region'] ?? 'us-east-1';
		$endpoint = $creds['endpoint'] ?? '';

		$env = "AWS_ACCESS_KEY_ID=" . escapeshellarg($access) . " AWS_SECRET_ACCESS_KEY=" . escapeshellarg($secret);
		$cmd = "{$env} aws s3 rm \"s3://{$bucket}/{$cloud_path}\" --region " . escapeshellarg($region);
		if ($endpoint) {
			$cmd .= " --endpoint-url " . escapeshellarg($endpoint);
		}
		return $cmd . " && echo 'CLOUD_DELETE_OK'";
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
