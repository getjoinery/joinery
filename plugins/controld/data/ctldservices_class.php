<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

PathHelper::requireOnce('plugins/controld/includes/ControlDHelper.php');

class CtldServiceException extends SystemClassException {}

class CtldService extends SystemBase {

	public static $prefix = 'cds';
	public static $tablename = 'cds_ctldservices';
	public static $pkey_column = 'cds_ctldservice_id';
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
	    'cds_ctldservice_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'cds_cdp_ctldprofile_id' => array('type'=>'varchar(64)'),
	    'cds_service_pk' => array('type'=>'varchar(32)'),
	    'cds_is_active' => array('type'=>'int2'),
	);

	public static $field_constraints = array(
		/*'cds_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),*/
	);	

}

class MultiCtldService extends SystemMultiBase {
	protected static $model_class = 'CtldService';

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

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['service'])) {
            $filters['cds_service_pk'] = [$this->options['service'], PDO::PARAM_STR];
        }
        
        if (isset($this->options['profile_id'])) {
            $filters['cds_cdp_ctldprofile_id'] = [$this->options['profile_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['active'])) {
            $filters['cds_is_active'] = $this->options['active'] ? "= 1" : "= 0";
        }
        
        return $this->_get_resultsv2('cds_ctldservices', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
