<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');


class ApiKeyException extends SystemClassException {}

class ApiKey extends SystemBase {

	public static $prefix = 'apk';
	public static $tablename = 'apk_api_keys';
	public static $pkey_column = 'apk_api_key_id';
	public static $permanent_delete_actions = array(
		'apk_api_key_id' => 'delete'
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'apk_api_key_id' => 'ID of the api_key',
		'apk_usr_user_id' => 'The user who owns the key',
		'apk_name' => 'Name of this key',
		'apk_public_key' => 'The username, basically',
		'apk_secret_key' => 'The key',
		'apk_permission' => '1=read, 2=write, 3=read/write, 4=read/write/delete',
		'apk_ip_restriction' => 'Limit use to these ip addresses, comma separated',
		'apk_start_time' => 'Start time of key',
		'apk_expires_time' => 'End time of key',
		'apk_is_active' => 'Is it active?',
		'apk_create_time' => 'Time Created',
		'apk_delete_time' => 'Time deleted'
	);

	public static $field_specifications = array(
		'apk_api_key_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'apk_usr_user_id' => array('type'=>'int4'),
		'apk_name' => array('type'=>'varchar(32)'),
		'apk_public_key' => array('type'=>'varchar(32)'),
		'apk_secret_key' => array('type'=>'varchar(34)'),
		'apk_permission' => array('type'=>'int4'),
		'apk_ip_restriction' => array('type'=>'varchar(255)'),
		'apk_start_time' => array('type'=>'timestamp(6)'),
		'apk_expires_time' => array('type'=>'timestamp(6)'),
		'apk_is_active' => array('type'=>'bool'),
		'apk_create_time' => array('type'=>'timestamp(6)'),
		'apk_delete_time' => array('type'=>'timestamp(6)'),
	);
			
	public static $required_fields = array();

	public static $field_constraints = array(
		/*'apk_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),*/
	);	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
		'apk_create_time' => 'now()'
	);	
	
	public static function GenerateKey($key) {
		PathHelper::requireOnce('includes/PasswordHash.php');
		$hasher = new PasswordHash(8, TRUE);
		return $hasher->HashPassword($key);
	}
	
	function check_secret_key($key) {
		PathHelper::requireOnce('includes/PasswordHash.php');
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

	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new ApiKey($row->apk_api_key_id);
			$child->load_from_data($row, array_keys(ApiKey::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}


?>
