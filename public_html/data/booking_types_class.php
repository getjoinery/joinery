<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SystemClass.php');
	
class BookingTypeException extends SystemClassException {}

class BookingType extends SystemBase {
	
	public static $prefix = 'bkt';
	public static $tablename = 'bkt_booking_types';
	public static $pkey_column = 'bkt_booking_type_id';

	const BOOKING_STATUS_INACTIVE = 0;
	const BOOKING_STATUS_ACTIVE = 1;

	public static $fields = array(
		'bkt_booking_type_id' => 'BookingType id',
		'bkt_calendly_event_type_uri' => 'Calendly uuid',
		'bkt_usr_user_id' => 'Owner',
		'bkt_pro_product_id' => 'Product booked',
		'bkt_name' => 'Name of booking_type',
		'bkt_slug' => 'Url safe slug',
		'bkt_description_html' => 'Html description',
		'bkt_description_plain' => 'Plain description',
		'bkt_status' => 'Status of booking_type',
		'bkt_schedule_link' => 'Link to schedule',
		'bkt_create_time' => 'Time created',
		'bkt_delete_time' => 'Time of deletion',
		'bkt_update_time' => 'Time updated',
	);

	public static $field_specifications = array(
		'bkt_booking_type_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'bkt_calendly_event_type_uri' => array('type'=>'varchar(255)'),
		'bkt_usr_user_id' => array('type'=>'int4'),
		'bkt_pro_product_id' => array('type'=>'int4'),
		'bkt_name' => array('type'=>'varchar(255)'),
		'bkt_slug' => array('type'=>'varchar(255)'),
		'bkt_description_html' => array('type'=>'varchar(255)'),
		'bkt_description_plain' => array('type'=>'varchar(255)'),
		'bkt_status' => array('type'=>'int4'),
		'bkt_schedule_link' => array('type'=>'varchar(255)'),
		'bkt_create_time' => array('type'=>'timestamp(6)'),
		'bkt_delete_time' => array('type'=>'timestamp(6)'),
		'bkt_update_time' => array('type'=>'timestamp(6)'),
	);


	public static $required_fields = array();

	public static $field_constraints = array();	
	
	public static $zero_variables = array('bkt_status');	

	public static $initial_default_values = array('bkt_create_time' => 'now()');	

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

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('user_id', $this->options)) {
			$where_clauses[] = 'bkt_usr_user_id = ?';
			$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		}
	

		if (array_key_exists('calendly_uri', $this->options)) {
			$where_clauses[] = 'bkt_calendly_event_type_uri = ?';
			$bind_params[] = array($this->options['calendly_uri'], PDO::PARAM_STR);
		}
		
		if (array_key_exists('deleted', $this->options)) {
			$where_clauses[] = 'bkt_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		}	 		
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}


		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM bkt_booking_types ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM bkt_booking_types
				' . $where_clause . '
				ORDER BY ';
			
			if (!$this->order_by) {
				$sql .= " bkt_booking_type_id ASC ";
			}
			else {
				if (array_key_exists('booking_type_id', $this->order_by)) {
					$sql .= ' bkt_booking_type_id ' . $this->order_by['booking_type_id'];
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
			$child = new BookingType($row->bkt_booking_type_id);
			$child->load_from_data($row, array_keys(BookingType::$fields));
			$this->add($child);
		}
	}

}



?>
