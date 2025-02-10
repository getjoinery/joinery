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

	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldaccounts_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldprofiles_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldfilters_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldservices_class.php');


class CtldDeviceBackupException extends SystemClassException {}

class CtldDeviceBackup extends SystemBase {

	public static $prefix = 'cdb';
	public static $tablename = 'cdb_ctlddevice_backups';
	public static $pkey_column = 'cdb_ctlddevice_backup_id';
	public static $permanent_delete_actions = array(
		'cdb_ctlddevice_backup_id' => 'delete', 
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	


	public static $fields = array(
		'cdb_ctlddevice_backup_id' => 'ID of the ctlddevice_backup',
		'cdb_device_backup_name' => 'Name of device_backup',
		'cdb_usr_user_id' => 'User id this profile is assigned to',
		'cdb_create_time' => 'Time Created',
		'cdb_delete_time' => 'Time deleted',
		'cdb_deactivation_pin' => 'Pin to turn off the service',
	);

	public static $field_specifications = array(
		'cdb_ctlddevice_backup_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'cdb_device_backup_name' => array('type'=>'varchar(64)'),
		'cdb_usr_user_id' => array('type'=>'int4'),
		'cdb_create_time' => array('type'=>'timestamp(6)'),
		'cdb_delete_time' => array('type'=>'timestamp(6)'),
		'cdb_deactivation_pin' => array('type'=>'varchar(10)'),
	);
			
	public static $required_fields = array();

	public static $field_constraints = array(
		/*'cdb_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),*/
	);	
	
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

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('user_id', $this->options)) {
		 	$where_clauses[] = 'cdb_usr_user_id = ?';
		 	$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		} 

		
		if (array_key_exists('deleted', $this->options)) {
		 	$where_clauses[] = 'cdb_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		} 
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM cdb_ctlddevice_backups ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM cdb_ctlddevice_backups
				' . $where_clause . '
				ORDER BY ';

			if (empty($this->order_by)) {
				$sql .= " cdb_ctlddevice_backup_id ASC ";
			}
			else {
				if (array_key_exists('ctlddevice_backup_id', $this->order_by)) {
					$sql .= ' cdb_ctlddevice_backup_id ' . $this->order_by['ctlddevice_backup_id'];
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
			$child = new CtldDeviceBackup($row->cdb_ctlddevice_backup_id);
			$child->load_from_data($row, array_keys(CtldDeviceBackup::$fields));
			$this->add($child);
		}
	}

}


?>
