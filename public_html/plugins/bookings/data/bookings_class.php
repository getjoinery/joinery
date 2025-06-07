<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SystemClass.php');
	
class BookingException extends SystemClassException {}

class Booking extends SystemBase {
	
	public static $prefix = 'bkn';
	public static $tablename = 'bkn_bookings';
	public static $pkey_column = 'bkn_booking_id';

	const BOOKING_STATUS_CREATED = 0;
	const BOOKING_STATUS_BOOKED = 1;
	const BOOKING_STATUS_COMPLETED = 2;
	const BOOKING_STATUS_CANCELED = 3;

	public static $fields = array(
		'bkn_booking_id' => 'Booking id',
		'bkn_calendly_event_uri' => 'Calendly uuid',
		'bkn_usr_user_id_booked' => 'Person being booked',
		'bkn_usr_user_id_client' => 'Person booking',
		'bkn_pro_product_id' => 'Product booked',
		'bkn_bkt_booking_type_id' => 'Foreign key to the booking type table',
		'bkn_type' => 'Type of booking from calendly',
		'bkn_notes' => 'Notes',
		'bkn_start_time' => 'Start time of booking',
		'bkn_end_time' => 'End time of booking',
		'bkn_status' => 'Status of booking',
		'bkn_cancel_link' => 'Link to cancel',
		'bkn_reschedule_link' => 'Link to reschedule',
		'bkn_location' => 'Location of meeting (zoom link or something)',
		'bkn_create_time' => 'Time created',
		'bkn_delete_time' => 'Time of deletion',
		'bkn_update_time' => 'Time updated',
	);

	public static $field_specifications = array(
		'bkn_booking_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'bkn_calendly_event_uri' => array('type'=>'varchar(255)'),
		'bkn_usr_user_id_booked' => array('type'=>'int4'),
		'bkn_usr_user_id_client' => array('type'=>'int4'),
		'bkn_pro_product_id' => array('type'=>'int4'),
		'bkn_bkt_booking_type_id' => array('type'=>'varchar(255)'),
		'bkn_type' => array('type'=>'varchar(255)'),
		'bkn_notes' => array('type'=>'varchar(255)'),
		'bkn_start_time' => array('type'=>'timestamp(6)'),
		'bkn_end_time' => array('type'=>'timestamp(6)'),
		'bkn_status' => array('type'=>'int4'),
		'bkn_cancel_link' => array('type'=>'varchar(255)'),
		'bkn_reschedule_link' => array('type'=>'varchar(255)'),
		'bkn_location' => array('type'=>'varchar(255)'),
		'bkn_create_time' => array('type'=>'timestamp(6)'),
		'bkn_delete_time' => array('type'=>'timestamp(6)'),
		'bkn_update_time' => array('type'=>'timestamp(6)'),
	);


	public static $required_fields = array();

	public static $field_constraints = array();	
	
	public static $zero_variables = array('bkn_status');	

	public static $initial_default_values = array('bkn_create_time' => 'now()');	

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
    
    function load($debug = false) {
        parent::load();
        $q = $this->getMultiResults(false, $debug);
        foreach($q->fetchAll() as $row) {
            $child = new Booking($row->bkn_booking_id);
            $child->load_from_data($row, array_keys(Booking::$fields));
            $this->add($child);
        }
    }
    
    function count_all($debug = false) {
        $q = $this->getMultiResults(TRUE, $debug);
        return $q;
    }

}



?>
