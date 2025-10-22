<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class ApiKeyException extends SystemBaseException {}

class ApiKey extends SystemBase {	public static $prefix = 'apk';
	public static $tablename = 'apk_api_keys';
	public static $pkey_column = 'apk_api_key_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

		/**
	 * Field specifications define database column properties and validation rules
	 * 
	 * Database schema properties (used by update_database):
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp' | 'bool' | etc.
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'serial' => true/false - Auto-incrementing field
	 * 
	 * Validation and behavior properties (used by SystemBase):
	 *   'required' => true/false - Field must have non-empty value on save
	 *   'default' => mixed - Default value for new records (applied on INSERT only)
	 *   'zero_on_create' => true/false - Set to 0 when creating if NULL (INSERT only)
	 * 
	 * Note: Timestamp fields are auto-detected based on type for smart_get() and export_as_array()
	 */
	public static $field_specifications = array(
	    'apk_api_key_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'apk_usr_user_id' => array('type'=>'int4'),
	    'apk_name' => array('type'=>'varchar(32)'),
	    'apk_public_key' => array('type'=>'varchar(32)'),
	    'apk_secret_key' => array('type'=>'varchar(34)'),
	    'apk_permission' => array('type'=>'int4'),
	    'apk_ip_restriction' => array('type'=>'varchar(255)'),
	    'apk_start_time' => array('type'=>'timestamp(6)'),
	    'apk_expires_time' => array('type'=>'timestamp(6)'),
	    'apk_is_active' => array('type'=>'bool'),
	    'apk_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'apk_delete_time' => array('type'=>'timestamp(6)'),
	);

	public static $field_constraints = array(
		/*'apk_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),*/
	);	

	public static function GenerateKey($key) {
		require_once(PathHelper::getIncludePath('includes/PasswordHash.php'));
		$hasher = new PasswordHash(8, TRUE);
		return $hasher->HashPassword($key);
	}
	
	function check_secret_key($key) {
		require_once(PathHelper::getIncludePath('includes/PasswordHash.php'));
		$hasher = new PasswordHash(8, TRUE);
		return $hasher->CheckPassword($key, $this->get('apk_secret_key'));
	}

	function authenticate_write($data) {
		if ($this->get(static::$prefix.'_usr_user_id') != $data['current_user_id']) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in '. static::$tablename);
			}
		}
	}

}

class MultiApiKey extends SystemMultiBase {
	protected static $model_class = 'ApiKey';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $api_key) {
			$items['('.$api_key->key.') '.$api_key->get('apk_api_key')] = $api_key->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['user_id'])) {
            $filters['apk_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['public_key'])) {
            $filters['apk_public_key'] = [$this->options['public_key'], PDO::PARAM_STR];
        }

        if (isset($this->options['published'])) {
            $filters['apk_is_published'] = $this->options['published'] ? "= TRUE" : "= FALSE";
        }
        
        if (isset($this->options['deleted'])) {
            $filters['apk_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }

        return $this->_get_resultsv2('apk_api_keys', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
