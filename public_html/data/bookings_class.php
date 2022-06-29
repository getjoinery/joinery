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
		'bkn_calendly_event_uuid' => 'Calendly uuid',
		'bkn_usr_user_id_booked' => 'Person being booked',
		'bkn_usr_user_id_client' => 'Person booking',
		'bkn_pro_product_id' => 'Product booked',
		'bkn_type' => 'Type of booking',
		'bkn_notes' => 'Notes',
		'bkn_start_time' => 'Start time of booking',
		'bkn_end_time' => 'End time of booking',
		'bkn_status' => 'Status of booking',
		'bkn_cancel_link' => 'Link to cancel',
		'bkn_reschedule_link' => 'Link to reschedule',
		'bkn_location' => 'Location of meeting (zoom link or something)',
		'bkn_create_time' => 'Time created',
		'bkn_delete_time' => 'Time of deletion',
	);

	public static $field_specifications = array(
		'bkn_booking_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'bkn_calendly_event_uuid' => array('type'=>'varchar(36)'),
		'bkn_usr_user_id_booked' => array('type'=>'int4'),
		'bkn_usr_user_id_client' => array('type'=>'int4'),
		'bkn_pro_product_id' => array('type'=>'int4'),
		'bkn_type' => array('type'=>'int4'),
		'bkn_notes' => array('type'=>'varchar(255)'),
		'bkn_start_time' => array('type'=>'timestamp(6)'),
		'bkn_end_time' => array('type'=>'timestamp(6)'),
		'bkn_status' => array('type'=>'int4'),
		'bkn_cancel_link' => array('type'=>'varchar(255)'),
		'bkn_reschedule_link' => array('type'=>'varchar(255)'),
		'bkn_location' => array('type'=>'varchar(255)'),
		'bkn_create_time' => array('type'=>'timestamp(6)'),
		'bkn_delete_time' => array('type'=>'timestamp(6)'),
	);


	public static $required_fields = array();

	public static $field_constraints = array();	
	
	public static $zero_variables = array('bkn_status');	

	public static $initial_default_values = array('bkn_create_time' => 'now()');	

	static function get_by_calendly_uuid($calendly_uuid){
		$results = new MultiBooking(array('calendly_uuid' => $calendly_uuid));
		$results->load();

		if(count($results)){	
			return $results->get(0);	
		}
		else{
			return false;
		}
	}

	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		if ($this->get('bkn_usr_user_id') != $current_user) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($session->get_permission() < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this booking.');
			}
		}
	}


}

class MultiBooking extends SystemMultiBase {

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('user_id_client', $this->options)) {
			$where_clauses[] = 'bkn_usr_user_id_client = ?';
			$bind_params[] = array($this->options['user_id_client'], PDO::PARAM_INT);
		}
	
		if (array_key_exists('user_id_booked', $this->options)) {
			$where_clauses[] = 'bkn_usr_user_id_booked = ?';
			$bind_params[] = array($this->options['user_id_booked'], PDO::PARAM_INT);
		}	
		
		if (array_key_exists('product_id', $this->options)) {
			$where_clauses[] = 'bkn_pro_product_id = ?';
			$bind_params[] = array($this->options['product_id'], PDO::PARAM_INT);
		}	

		if (array_key_exists('calendly_uuid', $this->options)) {
			$where_clauses[] = 'bkn_calendly_event_uuid = ?';
			$bind_params[] = array($this->options['calendly_uuid'], PDO::PARAM_STR);
		}
		
		if (array_key_exists('deleted', $this->options)) {
			$where_clauses[] = 'bkn_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		}	 		
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}


		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM bkn_bookings ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM bkn_bookings
				' . $where_clause . '
				ORDER BY ';
			
			if (!$this->order_by) {
				$sql .= " bkn_booking_id ASC ";
			}
			else {
				if (array_key_exists('booking_id', $this->order_by)) {
					$sql .= ' bkn_booking_id ' . $this->order_by['booking_id'];
				}			
			}
				
			$sql .= ' '.$this->generate_limit_and_offset();	

		}			
		

		$q = DbConnector::GetPreparedStatement($sql);

		if($debug){
			echo $sql. "<br>\n";
			print_r($this->options);
		}
			
		$total_params = count($bind_params);
		for ($i=0; $i<$total_params; $i++) {
			list($param, $type) = $bind_params[$i];
			$q->bindValue($i+1, $param, $type);
		}
		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);

		return $q;
	}

	function load($debug = false) {
		parent::load();
		$q = $this->_get_results(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new Booking($row->bkn_booking_id);
			$child->load_from_data($row, array_keys(Booking::$fields));
			$this->add($child);
		}
	}

}



?>
