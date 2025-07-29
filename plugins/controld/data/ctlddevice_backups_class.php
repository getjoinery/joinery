<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

PathHelper::requireOnce('plugins/controld/data/ctldaccounts_class.php');
PathHelper::requireOnce('plugins/controld/data/ctldprofiles_class.php');
PathHelper::requireOnce('plugins/controld/data/ctldfilters_class.php');
PathHelper::requireOnce('plugins/controld/data/ctldservices_class.php');


class CtldDeviceBackupException extends SystemClassException {}

class CtldDeviceBackup extends SystemBase {

	public static $prefix = 'cdb';
	public static $tablename = 'cdb_ctlddevice_backups';
	public static $pkey_column = 'cdb_ctlddevice_backup_id';
	public static $permanent_delete_actions = array(
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	


	public static $fields = array(
		'cdb_ctld_device_backup_id' => 'Primary key - CtldDeviceBackup ID',
		'cdb_device_backup_name' => 'Name of device_backup',
		'cdb_usr_user_id' => 'User id this profile is assigned to',
		'cdb_create_time' => 'Time Created',
		'cdb_delete_time' => 'Time deleted',
		'cdb_deactivation_pin' => 'Pin to turn off the service',
	);
	
/**
	 * Field specifications define database column properties and schema constraints
	 * Available options:
	 *   'type' => 'varchar(255)'  < /dev/null |  |  'int4' | 'int8' | 'text' | 'timestamp(6)' | 'numeric(10,2)' | 'bool' | etc.
	 *   'serial' => true/false - Auto-incrementing field
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'unique' => true - Field must be unique (single field constraint)
	 *   'unique_with' => array('field1', 'field2') - Composite unique constraint with other fields
	 */
	public static $field_specifications = array(
		'cdb_ctlddevice_backup_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'cdb_device_backup_name' => array('type'=>'varchar(64)'),
		'cdb_usr_user_id' => array('type'=>'int4'),
		'cdb_create_time' => array('type'=>'timestamp(6)'),
		'cdb_delete_time' => array('type'=>'timestamp(6)'),
		'cdb_deactivation_pin' => array('type'=>'varchar(10)'),
	);
			
	

public static $required_fields = array();

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
		'cdb_create_time' => 'now()'
	);	


	function get_readable_name(){
		return preg_replace('/^user\d+-/', '', $this->get('cdb_device_backup_name'));
		
	}
	
	
	
}

class MultiCtldDeviceBackup extends SystemMultiBase {


	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $ctlddevice_backup) {
			$items['('.$ctlddevice_backup->key.') '.$ctlddevice_backup->get('cdb_ctlddevice_backup')] = $ctlddevice_backup->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['user_id'])) {
            $filters['cdb_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['deleted'])) {
            $filters['cdb_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }
        
        return $this->_get_resultsv2('cdb_ctlddevice_backups', $filters, $this->order_by, $only_count, $debug);
    }
    
    function load($debug = false) {
        parent::load();
        $q = $this->getMultiResults(false, $debug);
        foreach($q->fetchAll() as $row) {
            $child = new CtldDeviceBackup($row->cdb_ctlddevice_backup_id);
            $child->load_from_data($row, array_keys(CtldDeviceBackup::$fields));
            $this->add($child);
        }
    }
    
    function count_all($debug = false) {
        $q = $this->getMultiResults(TRUE, $debug);
        return $q;
    }

}


?>
