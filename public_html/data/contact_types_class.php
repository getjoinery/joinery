<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class ContactTypeException extends SystemBaseException {}

class ContactType extends SystemBase {	public static $prefix = 'ctt';
	public static $tablename = 'ctt_contact_types';
	public static $pkey_column = 'ctt_contact_type_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

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
	    'ctt_contact_type_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'ctt_name' => array('type'=>'varchar(255)', 'required'=>true),
	    'ctt_description' => array('type'=>'varchar(255)'),
	    'ctt_delete_time' => array('type'=>'timestamp(6)'),
	    'ctt_mailchimp_list_id' => array('type'=>'varchar(255)'),
	);

public static function ToReadable($ctt_contact_type_id){
		$contact_type = ContactType::GetByColumn('ctt_contact_type_id', (int)$ctt_contact_type_id);
		return $contact_type->get('ctt_name');
	}

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}
	
}

class MultiContactType extends SystemMultiBase {
	protected static $model_class = 'ContactType';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $contact_type) {
			$items[$contact_type->get('ctt_name')] = $contact_type->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['deleted'])) {
            $filters['ctt_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }

        return $this->_get_resultsv2('ctt_contact_types', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
