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

class Setting extends SystemBase {	public static $prefix = 'stg';
	public static $tablename = 'stg_settings';
	public static $pkey_column = 'stg_setting_id';
	public static $permanent_delete_actions = array(
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

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
	    'stg_setting_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'stg_name' => array('type'=>'varchar(100)', 'required'=>true),
	    'stg_value' => array('type'=>'text'),
	    'stg_group_name' => array('type'=>'varchar(255)'),
	    'stg_usr_user_id' => array('type'=>'int4'),
	    'stg_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'stg_update_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);	

	public static $field_constraints = array();	

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
	protected static $model_class = 'Setting';

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

}

?>
