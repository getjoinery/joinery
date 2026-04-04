<?php
// PathHelper is already loaded by the time this file is included

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class SdFilterException extends SystemBaseException {}

class SdFilter extends SystemBase {

	public static $prefix = 'sdf';
	public static $tablename = 'sdf_filters';
	public static $pkey_column = 'sdf_filter_id';

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
	    'sdf_filter_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'sdf_sdp_profile_id' => array('type'=>'varchar(64)'),
	    'sdf_filter_key' => array('type'=>'varchar(32)'),
	    'sdf_is_active' => array('type'=>'int2'),
	);

	public static $field_constraints = array(
		/*'sdf_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),*/
	);

}

class MultiSdFilter extends SystemMultiBase {
	protected static $model_class = 'SdFilter';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $sdfilter) {
			$items[$sdfilter->key] = '('.$sdfilter->key.') '.$sdfilter->get('sdf_filter_key');
		}
		if ($include_new) {
			$items['Enter New Below'] = 'new';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['filter'])) {
            $filters['sdf_filter_key'] = [$this->options['filter'], PDO::PARAM_STR];
        }

        if (isset($this->options['profile_id'])) {
            $filters['sdf_sdp_profile_id'] = [$this->options['profile_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['active'])) {
            $filters['sdf_is_active'] = $this->options['active'] ? "= 1" : "= 0";
        }

        return $this->_get_resultsv2('sdf_filters', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
