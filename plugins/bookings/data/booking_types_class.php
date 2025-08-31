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
