<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/FieldConstraints.php');
require_once($siteDir . '/includes/Globalvars.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SingleRowAccessor.php');
require_once($siteDir . '/includes/SystemClass.php');
require_once($siteDir . '/includes/Validator.php');


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
		$settings = Globalvars::get_instance();
		$siteDir = $settings->get_setting('siteDir');
		require_once($siteDir . '/includes/PasswordHash.php');
		$hasher = new PasswordHash(8, TRUE);
		return $hasher->HashPassword($key);
	}
	
	function check_secret_key($key) {
		$settings = Globalvars::get_instance();
		$siteDir = $settings->get_setting('siteDir');
		require_once($siteDir . '/includes/PasswordHash.php');
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

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('user_id', $this->options)) {
		 	$where_clauses[] = 'apk_usr_user_id = ?';
		 	$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		} 
		
		if (array_key_exists('public_key', $this->options)) {
			$where_clauses[] = 'apk_public_key = ?';
			$bind_params[] = array($this->options['public_key'], PDO::PARAM_STR);
		}			

		if (array_key_exists('published', $this->options)) {
		 	$where_clauses[] = 'apk_is_published = ' . ($this->options['published'] ? 'TRUE' : 'FALSE');
		}
		
		if (array_key_exists('deleted', $this->options)) {
		 	$where_clauses[] = 'apk_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		} 
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM apk_api_keys ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM apk_api_keys
				' . $where_clause . '
				ORDER BY ';

			if (empty($this->order_by)) {
				$sql .= " apk_api_key_id ASC ";
			}
			else {
				if (array_key_exists('api_key_id', $this->order_by)) {
					$sql .= ' apk_api_key_id ' . $this->order_by['api_key_id'];
				}			
			}
			
			$sql .= ' '.$this->generate_limit_and_offset();	
		}

		$q = DbConnector::GetPreparedStatement($sql);

		if($debug){
			echo $sql. "<br>\n";
			print_r($this->options);
		}

		$total_params = count($bind_params);
		for ($i=0; $i<$total_params; $i++) {
			list($param, $type) = $bind_params[$i];
			$q->bindValue($i+1, $param, $type);
		}
		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);

		return $q;
	}

	function load($debug = false) {
		parent::load();
		$q = $this->_get_results(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new ApiKey($row->apk_api_key_id);
			$child->load_from_data($row, array_keys(ApiKey::$fields));
			$this->add($child);
		}
	}

}


?>
