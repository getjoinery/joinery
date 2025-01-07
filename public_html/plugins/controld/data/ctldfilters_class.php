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


class CtldFilterException extends SystemClassException {}

class CtldFilter extends SystemBase {

	public static $prefix = 'cdf';
	public static $tablename = 'cdf_ctldfilters';
	public static $pkey_column = 'cdf_ctldfilter_id';
	public static $permanent_delete_actions = array(
		'cdf_ctldfilter_id' => 'delete', 
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'cdf_ctldfilter_id' => 'ID of the ctldfilter',
		'cdf_cdp_ctldprofile_id' => 'Foreign key to profile',
		'cdf_filter_pk' => 'Primary key at controld',
		'cdf_is_active' => 'Is it active?',
	);

	public static $field_specifications = array(
		'cdf_ctldfilter_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'cdf_cdp_ctldprofile_id' => array('type'=>'varchar(64)'),
		'cdf_filter_pk' => array('type'=>'varchar(32)'),
		'cdf_is_active' => array('type'=>'int2'),
	);
			
	public static $required_fields = array();

	public static $field_constraints = array(
		/*'cdf_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),*/
	);	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	);	

	
}

class MultiCtldFilter extends SystemMultiBase {


	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $ctldfilter) {
			$items['('.$ctldfilter->key.') '.$ctldfilter->get('cdf_filter_pk')] = $ctldfilter->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('filter', $this->options)) {
		 	$where_clauses[] = 'cdf_filter_pk = ?';
		 	$bind_params[] = array($this->options['filter'], PDO::PARAM_STR);
		} 
		
		if (array_key_exists('profile_id', $this->options)) {
		 	$where_clauses[] = 'cdf_cdp_ctldprofile_id = ?';
		 	$bind_params[] = array($this->options['profile_id'], PDO::PARAM_INT);
		} 

		if (array_key_exists('active', $this->options)) {
		 	$where_clauses[] = 'cdf_is_active = ' . ($this->options['active'] ? 'TRUE' : 'FALSE');
		}

				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM cdf_ctldfilters ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM cdf_ctldfilters
				' . $where_clause . '
				ORDER BY ';

			if (empty($this->order_by)) {
				$sql .= " cdf_ctldfilter_id ASC ";
			}
			else {
				if (array_key_exists('ctldfilter_id', $this->order_by)) {
					$sql .= ' cdf_ctldfilter_id ' . $this->order_by['ctldfilter_id'];
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
			$child = new CtldFilter($row->cdf_ctldfilter_id);
			$child->load_from_data($row, array_keys(CtldFilter::$fields));
			$this->add($child);
		}
	}

}


?>
