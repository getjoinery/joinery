<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');



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
		'stg_name');

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

	
	function authenticate_write($data) {
		if ($data['current_user_permission'] < 10) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
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

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['setting_id'])) {
            $filters['stg_setting_id'] = [$this->options['setting_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['setting_name'])) {
            $filters['stg_name'] = [$this->options['setting_name'], PDO::PARAM_STR];
        }
        
        return $this->_get_resultsv2('stg_settings', $filters, $this->order_by, $only_count, $debug);
    }
    
    function load($debug = false) {
        parent::load();
        $q = $this->getMultiResults(false, $debug);
        foreach($q->fetchAll() as $row) {
            $child = new Setting($row->stg_setting_id);
            $child->load_from_data($row, array_keys(Setting::$fields));
            $this->add($child);
        }
    }
    
    function count_all($debug = false) {
        $q = $this->getMultiResults(TRUE, $debug);
        return $q;
    }

}


?>
