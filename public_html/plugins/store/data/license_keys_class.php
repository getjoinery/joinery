<?php

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));

class LicenseKeyException extends SystemBaseException {}

class LicenseKey extends SystemBase {	public static $prefix = 'lck';
	public static $tablename = 'lck_license_keys';
	public static $pkey_column = 'lck_license_key_id';

	// Not exposed over REST or AI — keys are shown only on the buyer's own
	// profile and in the key email.
	public static $ai_readable = false;

	protected static $foreign_key_actions = [
		'lck_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED],
		'lck_ord_order_id' => ['action' => 'null'],
		'lck_odi_order_item_id' => ['action' => 'null']
	];

	public static $field_specifications = array(
	    'lck_license_key_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'lck_key' => array('type'=>'varchar(64)', 'required'=>true),
	    'lck_usr_user_id' => array('type'=>'int4', 'required'=>true),
	    'lck_ord_order_id' => array('type'=>'int4'),
	    'lck_odi_order_item_id' => array('type'=>'int4'),
	    'lck_plugin_name' => array('type'=>'varchar(64)', 'required'=>true),
	    'lck_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'lck_revoked_time' => array('type'=>'timestamp(6)'),
	    'lck_delete_time' => array('type'=>'timestamp(6)'),
	);

	/**
	 * Generate a new key string: JNRY- followed by four groups of four
	 * characters from an unambiguous uppercase alphabet (no 0/O/1/I).
	 */
	public static function generate_key_string() {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$groups = array();
		for ($g = 0; $g < 4; $g++) {
			$group = '';
			for ($c = 0; $c < 4; $c++) {
				$group .= $alphabet[random_int(0, strlen($alphabet) - 1)];
			}
			$groups[] = $group;
		}
		return 'JNRY-' . implode('-', $groups);
	}
}

class MultiLicenseKey extends SystemMultiBase {
	protected static $model_class = 'LicenseKey';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['user_id'])) {
			$filters['lck_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['order_item_id'])) {
			$filters['lck_odi_order_item_id'] = [$this->options['order_item_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['plugin_name'])) {
			$filters['lck_plugin_name'] = [$this->options['plugin_name'], PDO::PARAM_STR];
		}

		if (isset($this->options['key'])) {
			$filters['lck_key'] = [$this->options['key'], PDO::PARAM_STR];
		}

		if (empty($this->options['include_deleted'])) {
			$filters['lck_delete_time'] = "IS NULL";
		}

		return $this->_get_resultsv2('lck_license_keys', $filters, $this->order_by, $only_count, $debug);
	}
}

?>
