<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');


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

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['rule'])) {
            $filters['cdr_rule_pk'] = [$this->options['rule'], PDO::PARAM_STR];
        }
        
        if (isset($this->options['profile_id'])) {
            $filters['cdr_cdp_ctldprofile_id'] = [$this->options['profile_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['active'])) {
            $filters['cdr_is_active'] = $this->options['active'] ? "= 1" : "= 0";
        }
        
        if (isset($this->options['rule_action'])) {
            $filters['cdr_rule_action'] = [$this->options['rule_action'], PDO::PARAM_INT];
        }
        
        return $this->_get_resultsv2('cdr_ctldrules', $filters, $this->order_by, $only_count, $debug);
    }
    
    function load($debug = false) {
        parent::load();
        $q = $this->getMultiResults(false, $debug);
        foreach($q->fetchAll() as $row) {
            $child = new CtldRule($row->cdr_ctldrule_id);
            $child->load_from_data($row, array_keys(CtldRule::$fields));
            $this->add($child);
        }
    }
    
    function count_all($debug = false) {
        $q = $this->getMultiResults(TRUE, $debug);
        return $q;
    }

}


?>
