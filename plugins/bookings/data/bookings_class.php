<?php
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SystemBase.php');
	
class BookingException extends SystemBaseException {}

class Booking extends SystemBase {
	
	public static $prefix = 'bkn';
	public static $tablename = 'bkn_bookings';
	public static $pkey_column = 'bkn_booking_id';

	const BOOKING_STATUS_CREATED = 0;
	const BOOKING_STATUS_BOOKED = 1;
	const BOOKING_STATUS_COMPLETED = 2;
	const BOOKING_STATUS_CANCELED = 3;

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
	    'bkn_booking_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'bkn_calendly_event_uri' => array('type'=>'varchar(255)'),
	    'bkn_usr_user_id_booked' => array('type'=>'int4'),
	    'bkn_usr_user_id_client' => array('type'=>'int4'),
	    'bkn_pro_product_id' => array('type'=>'int4'),
	    'bkn_bkt_booking_type_id' => array('type'=>'varchar(255)'),
	    'bkn_type' => array('type'=>'varchar(255)'),
	    'bkn_notes' => array('type'=>'varchar(255)'),
	    'bkn_start_time' => array('type'=>'timestamp(6)'),
	    'bkn_end_time' => array('type'=>'timestamp(6)'),
	    'bkn_status' => array('type'=>'int4', 'zero_on_create'=>true),
	    'bkn_cancel_link' => array('type'=>'varchar(255)'),
	    'bkn_reschedule_link' => array('type'=>'varchar(255)'),
	    'bkn_location' => array('type'=>'varchar(255)'),
	    'bkn_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'bkn_delete_time' => array('type'=>'timestamp(6)'),
	    'bkn_update_time' => array('type'=>'timestamp(6)'),
	);

	public static $field_constraints = array();	

	static function GetByCalendlyUri($calendly_uri){
		$results = new MultiBooking(array('calendly_uri' => $calendly_uri));
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

class MultiBooking extends SystemMultiBase {
	protected static $model_class = 'Booking';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['user_id_client'])) {
            $filters['bkn_usr_user_id_client'] = [$this->options['user_id_client'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['user_id_booked'])) {
            $filters['bkn_usr_user_id_booked'] = [$this->options['user_id_booked'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['product_id'])) {
            $filters['bkn_pro_product_id'] = [$this->options['product_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['calendly_uri'])) {
            $filters['bkn_calendly_event_uri'] = [$this->options['calendly_uri'], PDO::PARAM_STR];
        }
        
        if (isset($this->options['deleted'])) {
            $filters['bkn_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }
        
        return $this->_get_resultsv2('bkn_bookings', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
