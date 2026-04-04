<?php
// PathHelper is already loaded by the time this file is included

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class SdRuleException extends SystemBaseException {}

class SdRule extends SystemBase {

	public static $prefix = 'sdr';
	public static $tablename = 'sdr_rules';
	public static $pkey_column = 'sdr_rule_id';

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
	    'sdr_rule_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'sdr_sdp_profile_id' => array('type'=>'varchar(64)'),
	    'sdr_hostname' => array('type'=>'varchar(128)'),
	    'sdr_is_active' => array('type'=>'int2'),
	    'sdr_action' => array('type'=>'int2'),
	    'sdr_via' => array('type'=>'varchar(32)'),
	);

	public static $field_constraints = array(
		/*'sdr_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),*/
	);

}

class MultiSdRule extends SystemMultiBase {
	protected static $model_class = 'SdRule';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $sdrule) {
			$items[$sdrule->key] = '('.$sdrule->key.') '.$sdrule->get('sdr_hostname');
		}
		if ($include_new) {
			$items['Enter New Below'] = 'new';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['profile_id'])) {
            $filters['sdr_sdp_profile_id'] = [$this->options['profile_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['active'])) {
            $filters['sdr_is_active'] = $this->options['active'] ? "= 1" : "= 0";
        }

        if (isset($this->options['rule_action'])) {
            $filters['sdr_action'] = [$this->options['rule_action'], PDO::PARAM_INT];
        }

        return $this->_get_resultsv2('sdr_rules', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
