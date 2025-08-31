<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

class CtldFilterException extends SystemClassException {}

class CtldFilter extends SystemBase {

	public static $prefix = 'cdf';
	public static $tablename = 'cdf_ctldfilters';
	public static $pkey_column = 'cdf_ctldfilter_id';
	public static $permanent_delete_actions = array(
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'cdf_ctldfilter_id' => 'Primary key - CtldFilter ID',
		'cdf_cdp_ctldprofile_id' => 'Foreign key to profile',
		'cdf_filter_pk' => 'Primary key at controld',
		'cdf_is_active' => 'Is it active?',
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
	protected static $model_class = 'CtldFilter';

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

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['filter'])) {
            $filters['cdf_filter_pk'] = [$this->options['filter'], PDO::PARAM_STR];
        }
        
        if (isset($this->options['profile_id'])) {
            $filters['cdf_cdp_ctldprofile_id'] = [$this->options['profile_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['active'])) {
            $filters['cdf_is_active'] = $this->options['active'] ? "= 1" : "= 0";
        }
        
        return $this->_get_resultsv2('cdf_ctldfilters', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
