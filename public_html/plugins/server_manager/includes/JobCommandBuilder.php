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
	 */
	public static function build_backup_database($node, $params = []) {
		$scripts = self::get_scripts_path($node);
		$creds = self::get_db_credentials_script($node);

		$flags = '';
		if (!empty($params['encryption'])) {
			$flags .= ' --encrypt';
		}
		if (isset($params['compression']) && $params['compression'] === false) {
			$flags .= ' --no-compress';
		}

		$label = !empty($params['backup_label']) ? $params['backup_label'] : 'manual';

		$steps = [
			['type' => 'ssh', 'label' => 'Run database backup',
			 'cmd' => "{$creds} && bash {$scripts}/sysadmin_tools/backup_database.sh -d \"\$DB_NAME\" -u \"\$DB_USER\"{$flags}",
			 'timeout' => 3600],
			['type' => 'ssh', 'label' => 'List backup files',
			 'cmd' => "ls -lht /backups/*.sql.gz 2>/dev/null | head -5",
			 'continue_on_error' => true],
		];

		return $steps;
	}

	/**
	 * Full project backup (DB + files + Apache config).
	 */
	public static function build_backup_project($node, $params = []) {
		$scripts = self::get_scripts_path($node);
		$web_root = rtrim($node->get('mgn_web_root'), '/');
		$project_root = dirname($web_root);

		$flags = '';
		if (!empty($params['encryption'])) {
			$flags .= ' --encrypt';
		}

		$steps = [
			['type' => 'ssh', 'label' => 'Run full project backup',
			 'cmd' => "bash {$scripts}/sysadmin_tools/backup_project.sh -p {$project_root}{$flags}",
			 'timeout' => 3600],
			['type' => 'ssh', 'label' => 'List backup files',
			 'cmd' => "ls -lht /backups/ 2>/dev/null | head -5",
			 'continue_on_error' => true],
		];

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
			 'cmd' => "cd /var/www/html/joinerytest/public_html && php utils/publish_upgrade.php {$notes}"],
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
