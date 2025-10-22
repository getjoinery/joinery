<?php
// PathHelper is already loaded by the time this file is included

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class CtldRuleException extends SystemBaseException {}

class CtldRule extends SystemBase {

	public static $prefix = 'cdr';
	public static $tablename = 'cdr_ctldrules';
	public static $pkey_column = 'cdr_ctldrule_id';

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
	    'cdr_ctldrule_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'cdr_cdp_ctldprofile_id' => array('type'=>'varchar(64)'),
	    'cdr_rule_hostname' => array('type'=>'varchar(128)'),
	    'cdr_is_active' => array('type'=>'int2'),
	    'cdr_rule_action' => array('type'=>'int2'),
	    'cdr_rule_via' => array('type'=>'varchar(32)'),
	);

	public static $field_constraints = array(
		/*'cdr_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),*/
	);	

}

class MultiCtldRule extends SystemMultiBase {
	protected static $model_class = 'CtldRule';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $ctldrule) {
			$items['('.$ctldrule->key.') '.$ctldrule->get('cdr_rule_hostname')] = $ctldrule->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
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

}

?>
