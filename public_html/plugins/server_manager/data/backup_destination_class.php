<?php
/**
 * BackupDestination - A configured storage target for backups (B2, S3, Linode, or local).
 *
 * Credentials are stored as JSON in bkd_credentials. Structure varies by provider:
 *   b2:    {"key_id": "...", "app_key": "..."}
 *   s3:    {"access_key": "...", "secret_key": "...", "region": "us-east-1"}
 *   linode: {"access_key": "...", "secret_key": "...", "region": "...", "endpoint": "..."}
 *   local: {}
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class BackupDestinationException extends SystemBaseException {}

class BackupDestination extends SystemBase {
	public static $prefix = 'bkd';
	public static $tablename = 'bkd_backup_destinations';
	public static $pkey_column = 'bkd_id';

	public static $json_vars = array('bkd_credentials');

	public static $field_specifications = array(
		'bkd_id'              => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'bkd_name'            => array('type'=>'varchar(100)', 'required'=>true, 'is_nullable'=>false),
		'bkd_provider'        => array('type'=>'varchar(30)', 'required'=>true, 'is_nullable'=>false),
		'bkd_bucket'          => array('type'=>'varchar(255)'),
		'bkd_path_prefix'     => array('type'=>'varchar(255)', 'default'=>"'joinery-backups'"),
		'bkd_credentials'     => array('type'=>'jsonb'),
		'bkd_delete_local'    => array('type'=>'bool', 'default'=>'false', 'is_nullable'=>false),
		'bkd_enabled'         => array('type'=>'bool', 'default'=>'true', 'is_nullable'=>false),
		'bkd_create_time'     => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'bkd_update_time'     => array('type'=>'timestamp(6)'),
		'bkd_delete_time'     => array('type'=>'timestamp(6)'),
	);

	private static $valid_providers = ['local', 'b2', 's3', 'linode'];

	function prepare() {
		if (empty($this->get('bkd_name'))) {
			throw new BackupDestinationException('Destination name is required.');
		}

		$provider = $this->get('bkd_provider');
		if (!in_array($provider, self::$valid_providers)) {
			throw new BackupDestinationException('Invalid provider. Must be one of: ' . implode(', ', self::$valid_providers));
		}

		if ($provider !== 'local' && empty($this->get('bkd_bucket'))) {
			throw new BackupDestinationException('Bucket name is required for cloud providers.');
		}

		$this->set('bkd_update_time', gmdate('Y-m-d H:i:s'));
	}

	/**
	 * Get credentials as an associative array.
	 */
	function get_credentials() {
		$creds = $this->get('bkd_credentials');
		if (is_string($creds)) {
			return json_decode($creds, true) ?: [];
		}
		return is_array($creds) ? $creds : [];
	}

	/**
	 * Check if this is a cloud destination (not local-only).
	 */
	function is_cloud() {
		return $this->get('bkd_provider') !== 'local';
	}
}

class MultiBackupDestination extends SystemMultiBase {
	protected static $model_class = 'BackupDestination';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['provider'])) {
			$filters['bkd_provider'] = [$this->options['provider'], PDO::PARAM_STR];
		}

		if (isset($this->options['enabled'])) {
			$filters['bkd_enabled'] = $this->options['enabled'] ? "= true" : "= false";
		}

		if (isset($this->options['deleted'])) {
			$filters['bkd_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('bkd_backup_destinations', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
