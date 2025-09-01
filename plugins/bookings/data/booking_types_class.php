<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SystemClass.php');
	
class BookingTypeException extends SystemClassException {}

class BookingType extends SystemBase {
	
	public static $prefix = 'bkt';
	public static $tablename = 'bkt_booking_types';
	public static $pkey_column = 'bkt_booking_type_id';
	public static $permanent_delete_actions = array(
		//'pac_itr_item_relation_id' => 'delete',
		//'com_itr_item_relation_id' => 'null'
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	const BOOKING_STATUS_INACTIVE = 0;
	const BOOKING_STATUS_ACTIVE = 1;

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
	    'bkt_booking_type_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'bkt_calendly_event_type_uri' => array('type'=>'varchar(255)'),
	    'bkt_usr_user_id' => array('type'=>'int4'),
	    'bkt_pro_product_id' => array('type'=>'int4'),
	    'bkt_name' => array('type'=>'varchar(255)'),
	    'bkt_slug' => array('type'=>'varchar(255)'),
	    'bkt_description_html' => array('type'=>'varchar(255)'),
	    'bkt_description_plain' => array('type'=>'varchar(255)'),
	    'bkt_status' => array('type'=>'int4', 'zero_on_create'=>true),
	    'bkt_schedule_link' => array('type'=>'varchar(255)'),
	    'bkt_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'bkt_delete_time' => array('type'=>'timestamp(6)'),
	    'bkt_update_time' => array('type'=>'timestamp(6)'),
	);

	public static $field_constraints = array();	

	static function GetByCalendlyUri($calendly_uri){
		$results = new MultiBookingType(array('calendly_uri' => $calendly_uri));
		$results->load();

		if(count($results)){	
			return $results->get(0);	
		}
		else{
			return false;
		}
	}

	function authenticate_write($data) {
		if ($this->get(static::$prefix.'_usr_user_id') != $data['current_user_id']) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in '. static::$tablename);
			}
		}
	}

}

class MultiBookingType extends SystemMultiBase {
	protected static $model_class = 'BookingType';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['user_id'])) {
            $filters['bkt_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['calendly_uri'])) {
            $filters['bkt_calendly_event_type_uri'] = [$this->options['calendly_uri'], PDO::PARAM_STR];
        }
        
        if (isset($this->options['deleted'])) {
            $filters['bkt_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }
        
        return $this->_get_resultsv2('bkt_booking_types', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
