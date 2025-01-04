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


class CtldDeviceException extends SystemClassException {}

class CtldDevice extends SystemBase {

	public static $prefix = 'cdd';
	public static $tablename = 'cdd_ctlddevices';
	public static $pkey_column = 'cdd_ctlddevice_id';
	public static $permanent_delete_actions = array(
		'cdd_ctlddevice_id' => 'delete', 
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'cdd_ctlddevice_id' => 'ID of the ctlddevice',
		'cdd_profile_id_primary' => 'ID from controld',
		'cdd_profile_id_secondary' => 'ID from controld',
		'cdd_schedule_id' => 'Schedule applied to the device',
		'cdd_usr_user_id' => 'User id this profile is assigned to',
		'cdd_is_active' => 'Is it active?',
		'cdd_create_time' => 'Time Created',
		'cdd_delete_time' => 'Time deleted',
	);

	public static $field_specifications = array(
		'cdd_ctlddevice_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'cdd_profile_id_primary' => array('type'=>'varchar(64)'),
		'cdd_profile_id_secondary' => array('type'=>'varchar(64)'),
		'cdd_schedule_id' => array('type'=>'varchar(64)'),
		'cdd_usr_user_id' => array('type'=>'int4'),
		'cdd_is_active' => array('type'=>'bool'),
		'cdd_create_time' => array('type'=>'timestamp(6)'),
		'cdd_delete_time' => array('type'=>'timestamp(6)'),
	);
			
	public static $required_fields = array();

	public static $field_constraints = array(
		/*'cdd_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),*/
	);	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
		'cdd_create_time' => 'now()'
	);	

	
	function prepare() {
		if(CtldDevice::GetByColumn('cdd_profile_id', $this->get('cdd_profile_id')) && !$this->key){
			throw new CtldDeviceException('That profile id already exists.');
		}		
		
	}	
	
	
	function authenticate_write($data) {
		if ($this->get('cdd_usr_user_id') != $data['current_user_id']) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in '. static::$tablename.'-'.$data['current_user_permission'] );
			}
		}
	}
	
}

class MultiCtldDevice extends SystemMultiBase {


	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $ctlddevice) {
			$items['('.$ctlddevice->key.') '.$ctlddevice->get('cdd_ctlddevice')] = $ctlddevice->key;
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
		 	$where_clauses[] = 'cdd_usr_user_id = ?';
		 	$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		} 
		
		if (array_key_exists('profile_id', $this->options)) {
		 	$where_clauses[] = 'cdd_profile_id = ?';
		 	$bind_params[] = array($this->options['profile_id'], PDO::PARAM_INT);
		} 
			

		if (array_key_exists('active', $this->options)) {
		 	$where_clauses[] = 'cdd_is_active = ' . ($this->options['active'] ? 'TRUE' : 'FALSE');
		}

		
		if (array_key_exists('deleted', $this->options)) {
		 	$where_clauses[] = 'cdd_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		} 
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM cdd_ctlddevices ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM cdd_ctlddevices
				' . $where_clause . '
				ORDER BY ';

			if (empty($this->order_by)) {
				$sql .= " cdd_ctlddevice_id ASC ";
			}
			else {
				if (array_key_exists('ctlddevice_id', $this->order_by)) {
					$sql .= ' cdd_ctlddevice_id ' . $this->order_by['ctlddevice_id'];
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
			$child = new CtldDevice($row->cdd_ctlddevice_id);
			$child->load_from_data($row, array_keys(CtldDevice::$fields));
			$this->add($child);
		}
	}

}


?>
