<?php
/**
 * BackupTarget - A configured storage target for backups (B2, S3, Linode).
 *
 * Credentials are stored as JSON in bkt_credentials. Structure varies by provider:
 *   b2:    {"key_id": "...", "app_key": "..."}
 *   s3:    {"access_key": "...", "secret_key": "...", "region": "us-east-1"}
 *   linode: {"access_key": "...", "secret_key": "...", "region": "...", "endpoint": "..."}
 *
 * @version 2.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class BackupTargetException extends SystemBaseException {}

class BackupTarget extends SystemBase {
	public static $prefix = 'bkt';
	public static $tablename = 'bkt_backup_targets';
	public static $pkey_column = 'bkt_id';

	public static $json_vars = array('bkt_credentials');

	public static $field_specifications = array(
		'bkt_id'              => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'bkt_name'            => array('type'=>'varchar(100)', 'required'=>true, 'is_nullable'=>false),
		'bkt_provider'        => array('type'=>'varchar(30)', 'required'=>true, 'is_nullable'=>false),
		'bkt_bucket'          => array('type'=>'varchar(255)'),
		'bkt_path_prefix'     => array('type'=>'varchar(255)', 'default'=>"'joinery-backups'"),
		'bkt_credentials'     => array('type'=>'jsonb'),
		'bkt_delete_local'    => array('type'=>'bool', 'default'=>'false', 'is_nullable'=>false),
		'bkt_enabled'         => array('type'=>'bool', 'default'=>'true', 'is_nullable'=>false),
		'bkt_create_time'     => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'bkt_update_time'     => array('type'=>'timestamp(6)'),
		'bkt_delete_time'     => array('type'=>'timestamp(6)'),
	);

	private static $valid_providers = ['b2', 's3', 'linode'];

	function prepare() {
		if (empty($this->get('bkt_name'))) {
			throw new BackupTargetException('Target name is required.');
		}

		$provider = $this->get('bkt_provider');
		if (!in_array($provider, self::$valid_providers)) {
			throw new BackupTargetException('Invalid provider. Must be one of: ' . implode(', ', self::$valid_providers));
		}

		if (empty($this->get('bkt_bucket'))) {
			throw new BackupTargetException('Bucket name is required.');
		}

		$this->set('bkt_update_time', gmdate('Y-m-d H:i:s'));
	}

	/**
	 * Get credentials as an associative array.
	 */
	function get_credentials() {
		$creds = $this->get('bkt_credentials');
		if (is_string($creds)) {
			return json_decode($creds, true) ?: [];
		}
		return is_array($creds) ? $creds : [];
	}

}

class MultiBackupTarget extends SystemMultiBase {
	protected static $model_class = 'BackupTarget';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['provider'])) {
			$filters['bkt_provider'] = [$this->options['provider'], PDO::PARAM_STR];
		}

		if (isset($this->options['enabled'])) {
			$filters['bkt_enabled'] = $this->options['enabled'] ? "= true" : "= false";
		}

		if (isset($this->options['deleted'])) {
			$filters['bkt_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('bkt_backup_targets', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
