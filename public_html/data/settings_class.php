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



class SettingException extends SystemClassException {}

class Setting extends SystemBase {
	public static $prefix = 'stg';
	public static $tablename = 'stg_settings';
	public static $pkey_column = 'stg_setting_id';
	public static $permanent_delete_actions = array(
		'stg_setting_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'stg_setting_id' => 'ID of the setting',
		'stg_name' => 'Name',
		'stg_value' => 'Value of the setting',
		'stg_group_name' => 'String to group settings into bundles',
		'stg_usr_user_id' => 'User who created/updated last',
		'stg_create_time' => 'Created',
		'stg_update_time' => 'Updated',
	);

	public static $field_specifications = array(
		'stg_setting_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'stg_name' => array('type'=>'varchar(100)'),
		'stg_value' => array('type'=>'text'),
		'stg_group_name' => array('type'=>'varchar(255)'),
		'stg_usr_user_id' => array('type'=>'int4'),
		'stg_create_time' => array('type'=>'timestamp(6)'),
		'stg_update_time' => array('type'=>'timestamp(6)'),
	);	

	public static $required_fields = array(
		'stg_name', 'stg_value');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array(
		'stg_create_time' => 'now()', 
		'stg_update_time' => 'now()'
		);		
	
	private function _check_for_duplicate_setting() {
		
		$settings = Globalvars::get_instance();
		if($settings->get_setting($this->get('stg_name'))){
			return true;
		}
		
		$count = new MultiSetting(array(
			'setting_name' => $this->get('stg_name'),
		));
		
		if ($count->count_all() > 0) {
						echo 'duplicate';
			exit();
			$count->load();
			return $count->get(0);
		}
		return NULL;
	}		
	

	function prepare() {
		
		//CHECK FOR DUPLICATES
		if(!$this->key){
			if($this->_check_for_duplicate_setting()){
				throw new SettingException(
				'This setting already exists');
			}
		}

	}

	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		if ($session->get_permission() < 10) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this setting.');
		}
	}

}

class MultiSetting extends SystemMultiBase {

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $entry) {
			$option_display = $entry->get('stg_name'); 
			$items[$option_display] = $entry->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();
		
		if (array_key_exists('setting_id', $this->options)) {
			$where_clauses[] = 'stg_setting_id = ?';
			$bind_params[] = array($this->options['setting_id'], PDO::PARAM_INT);
		}		

		if (array_key_exists('setting_name', $this->options)) {
			$where_clauses[] = 'stg_name = ?';
			$bind_params[] = array($this->options['setting_name'], PDO::PARAM_STR);
		}			
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM stg_settings ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM stg_settings
				' . $where_clause . '
				ORDER BY ';
				
			if (!$this->order_by) {
				$sql .= " stg_setting_id ASC ";
			}
			else {
				if (array_key_exists('setting_id', $this->order_by)) {
					$sql .= ' stg_setting_id ' . $this->order_by['setting_id'];
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
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new Setting($row->stg_setting_id);
			$child->load_from_data($row, array_keys(Setting::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->_get_results(TRUE);
		$counter = $q->fetch();
		return $counter->count;
	}
}


?>
