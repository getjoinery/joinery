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


class CtldRuleException extends SystemClassException {}

class CtldRule extends SystemBase {

	public static $prefix = 'cdr';
	public static $tablename = 'cdr_ctldrules';
	public static $pkey_column = 'cdr_ctldrule_id';
	public static $permanent_delete_actions = array(
		'cdr_ctldrule_id' => 'delete', 
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'cdr_ctldrule_id' => 'ID of the ctldfilter',
		'cdr_cdp_ctldprofile_id' => 'Foreign key to profile',
		'cdr_rule_hostname' => 'Hostname of the rule',
		'cdr_is_active' => 'Is it active?',
		'cdr_rule_action' => '0 = BLOCK. 1 = BYPASS, 2 = SPOOF, 3 = REDIRECT',
		'cdr_rule_via' => 'Spoof/Redirect target. If SPOOF, this can be an IPv4 or hostname. If REDIRECT, this must be a valid proxy identifier.',
	);

	public static $field_specifications = array(
		'cdr_ctldrule_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'cdr_cdp_ctldprofile_id' => array('type'=>'varchar(64)'),
		'cdr_rule_hostname' =>  array('type'=>'varchar(128)'),
		'cdr_is_active' => array('type'=>'int2'),
		'cdr_rule_action' => array('type'=>'int2'),
		'cdr_rule_via' => array('type'=>'varchar(32)'),
	);
			
	public static $required_fields = array();

	public static $field_constraints = array(
		/*'cdr_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),*/
	);	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	);	

	
}

class MultiCtldRule extends SystemMultiBase {


	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $ctldrule) {
			$items['('.$ctldrule->key.') '.$ctldrule->get('cdr_rule_pk')] = $ctldrule->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('rule', $this->options)) {
		 	$where_clauses[] = 'cdr_rule_pk = ?';
		 	$bind_params[] = array($this->options['rule'], PDO::PARAM_STR);
		} 
		
		if (array_key_exists('profile_id', $this->options)) {
		 	$where_clauses[] = 'cdr_cdp_ctldprofile_id = ?';
		 	$bind_params[] = array($this->options['profile_id'], PDO::PARAM_INT);
		} 

		if (array_key_exists('active', $this->options)) {
		 	$where_clauses[] = 'cdr_is_active = ' . ($this->options['active'] ? 1 : 0);
		}
		
		if (array_key_exists('rule_action', $this->options)) {
		 	$where_clauses[] = 'cdr_rule_action = ?';
		 	$bind_params[] = array($this->options['rule_action'], PDO::PARAM_INT);
		} 
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM cdr_ctldrules ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM cdr_ctldrules
				' . $where_clause . '
				ORDER BY ';

			if (empty($this->order_by)) {
				$sql .= " cdr_ctldrule_id ASC ";
			}
			else {
				if (array_key_exists('ctldrule_id', $this->order_by)) {
					$sql .= ' cdr_ctldrule_id ' . $this->order_by['ctldrule_id'];
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
			$child = new CtldRule($row->cdr_ctldrule_id);
			$child->load_from_data($row, array_keys(CtldRule::$fields));
			$this->add($child);
		}
	}

}


?>
