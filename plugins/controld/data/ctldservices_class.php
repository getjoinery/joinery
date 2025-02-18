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

require_once($siteDir . '/plugins/controld/includes/ControlDHelper.php');


class CtldServiceException extends SystemClassException {}

class CtldService extends SystemBase {

	public static $prefix = 'cds';
	public static $tablename = 'cds_ctldservices';
	public static $pkey_column = 'cds_ctldservice_id';
	public static $permanent_delete_actions = array(
		'cds_ctldservice_id' => 'delete', 
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'cds_ctldservice_id' => 'ID of the ctldservice',
		'cds_cdp_ctldprofile_id' => 'Foreign key to profile',
		'cds_service_pk' => 'Primary key at controld',
		'cds_is_active' => 'Is it active?',
	);

	public static $field_specifications = array(
		'cds_ctldservice_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'cds_cdp_ctldprofile_id' => array('type'=>'varchar(64)'),
		'cds_service_pk' => array('type'=>'varchar(32)'),
		'cds_is_active' => array('type'=>'int2'),
	);
			
	public static $required_fields = array();

	public static $field_constraints = array(
		/*'cds_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),*/
	);	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	);	

	
}

class MultiCtldService extends SystemMultiBase {


	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $ctldservice) {
			$items['('.$ctldservice->key.') '.$ctldservice->get('cds_service_pk')] = $ctldservice->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('service', $this->options)) {
		 	$where_clauses[] = 'cds_service_pk = ?';
		 	$bind_params[] = array($this->options['service'], PDO::PARAM_STR);
		} 
		
		if (array_key_exists('profile_id', $this->options)) {
		 	$where_clauses[] = 'cds_cdp_ctldprofile_id = ?';
		 	$bind_params[] = array($this->options['profile_id'], PDO::PARAM_INT);
		} 

		if (array_key_exists('active', $this->options)) {
		 	$where_clauses[] = 'cds_is_active = ' . ($this->options['active'] ? 1 : 0);
		}

				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM cds_ctldservices ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM cds_ctldservices
				' . $where_clause . '
				ORDER BY ';

			if (empty($this->order_by)) {
				$sql .= " cds_ctldservice_id ASC ";
			}
			else {
				if (array_key_exists('ctldservice_id', $this->order_by)) {
					$sql .= ' cds_ctldservice_id ' . $this->order_by['ctldservice_id'];
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
			$child = new CtldService($row->cds_ctldservice_id);
			$child->load_from_data($row, array_keys(CtldService::$fields));
			$this->add($child);
		}
	}

}


?>
