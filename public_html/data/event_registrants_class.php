<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/FieldConstraints.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SessionControl.php');
require_once($siteDir . '/includes/SingleRowAccessor.php');
require_once($siteDir . '/includes/SystemClass.php');

require_once($siteDir . '/data/events_class.php');
require_once($siteDir . '/data/users_class.php');


class EventRegistrantException extends SystemClassException {}
class DisplayableEventRegistrantException extends EventRegistrantException implements DisplayableErrorMessage {}
class DisplayablePermanentEventRegistrantException extends EventRegistrantException implements DisplayablePermanentErrorMessage {}

/*
class EventRegistrantUnviewableDisplayException extends EventRegistrantException implements CustomErrorPage {
	function __construct($title, $error_message) {
		$this->title = $title;
		$this->error_message = $error_message;
		parent::__construct($error_message);
	}

	function display_error_page() {
		PublicPageTW::OutputGenericPublicPage(
			$this->title,
			$this->title,
			$this->error_message,
			array(
				'noindex' => TRUE
			));
	}
}
*/

class EventRegistrant extends SystemBase {
	public static $prefix = 'evr';
	public static $tablename = 'evr_event_registrants';
	public static $pkey_column = 'evr_event_registrant_id';
	public static $permanent_delete_actions = array(
		'evr_event_registrant_id' => 'delete',	
		'odi_evr_event_registrant_id' => 'prevent'
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

	public static $fields = array(
		'evr_event_registrant_id' => 'event_registrant ID',
		'evr_evt_event_id' => 'The event',
		'evr_usr_user_id' => 'The attendee',
		'evr_recording_consent' => 'Consent to record',
		'evr_first_event' => 'Is this the persons first event',
		'evr_create_time' => 'Timestamp when this request was created',
		'evr_other_events' => '',
		'evr_health_notes' => 'Health notes',
		'evr_extra_info_completed' => 'Whether the person has entered the needed questions for the event',
		'evr_ord_order_id' => 'Order for the registration',
		'evr_expires_time' => 'Time at which this registration expires.', 
		'evr_odi_order_item_id' => 'Order Item ID for this registration',
		'evr_delete_time' => 'Time of deletion',
		'evr_grp_group_id' => 'Event bundle that created this registration, if applicable'
	);

	public static $field_specifications = array(
		'evr_event_registrant_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'evr_evt_event_id' => array('type'=>'int4'),
		'evr_usr_user_id' => array('type'=>'int4'),
		'evr_recording_consent' => array('type'=>'bool'),
		'evr_first_event' => array('type'=>'bool'),
		'evr_create_time' =>  array('type'=>'timestamp(6)'),
		'evr_other_events' => array('type'=>'varchar(255)'),
		'evr_health_notes' => array('type'=>'varchar(255)'),
		'evr_extra_info_completed' => array('type'=>'bool'),
		'evr_ord_order_id' => array('type'=>'int4'),
		'evr_expires_time' => array('type'=>'timestamp(6)'),
		'evr_odi_order_item_id' => array('type'=>'int4'),
		'evr_delete_time' => array('type'=>'timestamp(6)'),
		'evr_grp_group_id' => array('type'=>'int4'),
	);
			 
	public static $required_fields = array(
		'evr_evt_event_id', 'evr_usr_user_id'
	);
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
		'evr_create_time' => 'now()'
	);	

	public static $field_constraints = array(

		);

	
	public static $json_vars = array(
		'evr_event_registrant_id', 'evr_evt_event_id', 'evr_usr_user_id');

	static function check_if_registrant_exists($userid, $eventid){
		$sql = 'SELECT * FROM evr_event_registrants WHERE evr_usr_user_id = ? AND evr_evt_event_id = ?';

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
			
		try {
			$q = $dblink->prepare($sql);
			$q->bindValue(1, $userid, PDO::PARAM_INT);
			$q->bindValue(2, $eventid, PDO::PARAM_INT);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		} catch (PDOException $e) {
			$dbhelper->handle_query_error($e);
		}

		if ($q->rowCount()) {
			$event_registrant = new EventRegistrant($q->fetch()->evr_event_registrant_id, TRUE);
			return $event_registrant;
		}	
		else{
			return FALSE;
		}
	}
	
	function remove(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
		$q = $dblink->prepare('DELETE FROM evr_event_registrants WHERE evr_event_registrant_id =?');
		$q->bindValue(1, $this->key, PDO::PARAM_INT);
		
		$success = $q->execute();

		$q = $dblink->prepare('UPDATE odi_order_items SET odi_evr_event_registrant_id = NULL WHERE odi_evr_event_registrant_id = ?');
		$q->bindValue(1, $this->key, PDO::PARAM_INT);
		
		$success = $q->execute();
		
		return $success;		
	}	

	function load($debug = false) {
		parent::load();

		$sql = 'SELECT * FROM evr_event_registrants WHERE evr_event_registrant_id = ?';

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
			
		try {
			$q = $dblink->prepare($sql);
			$q->bindValue(1, $this->key, PDO::PARAM_INT);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		} catch (PDOException $e) {
			$dbhelper->handle_query_error($e);
		}

		if (!$q->rowCount()) {
			throw new DisplayablePermanentEventRegistrantException('Sorry, this registration does not exist or has already withdrawn from the event.');
		}

		$this->data = $q->fetch();
	}


	function export_as_array($session=NULL) { 
		$output_array = parent::export_as_array();
		//$output_array['travel_text'] = $this->get_travel_text();
		return $output_array;
	}

	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		
		if ($session->get_permission() < 8 && ($this->get('evr_usr_user_id') != $current_user)) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this item.');
		}
	}	
	
	function save($debug=false) {
		if(!$this->key){
			$dbhelper = DbConnector::get_instance();
			$dblink = $dbhelper->get_db_link();
			//MAKE SURE NO DUPLICATES
			$sql = "SELECT COUNT(*) AS numfound FROM evr_event_registrants WHERE evr_evt_event_id=:event_registrants AND evr_usr_user_id=:user";
			try{
				$q = $dblink->prepare($sql);
				$q->bindParam(':event_registrants', $rowdata['evr_evt_event_id'], PDO::PARAM_INT);
				$q->bindParam(':user', $rowdata['evr_usr_user_id'], PDO::PARAM_INT);
				$success = $q->execute();
				$numfound = $q->fetch()->numfound;
			}
			catch(PDOException $e){
				$dbhelper->handle_query_error($e);
			}		
			
			if($numfound){
				throw new DisplayableEventRegistrantException('You cannot register twice for the same event.');
			}			
		}
		parent::save($debug);
	}	


}

class MultiEventRegistrant extends SystemMultiBase {

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $entry) {
			$user = new User($entry->get('evr_usr_user_id'), TRUE);
			$event = new Event($entry->get('evr_evt_event_id'), TRUE);
			$option_display = $user->display_name() . ' - ' . $event->get('evt_name'); 
			$items[$option_display] = $entry->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('event_registrant_id', $this->options)) {
			$where_clauses[] = 'evr_event_registrant_id = ?';
			$bind_params[] = array($this->options['event_registrant_id'], PDO::PARAM_INT);
		}

		if (array_key_exists('event_id', $this->options)) {
			$where_clauses[] = 'evr_evt_event_id = ?';
			$bind_params[] = array($this->options['event_id'], PDO::PARAM_INT);
		}
		
		if (array_key_exists('user_id', $this->options)) {
			$where_clauses[] = 'evr_usr_user_id = ?';
			$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		}
		
		if (array_key_exists('deleted', $this->options)) {
			$where_clauses[] = 'evr_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		}	
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM evr_event_registrants ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM evr_event_registrants
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " evr_event_registrant_id ASC ";
			}
			else {
				if (array_key_exists('event_registrant_id', $this->order_by)) {
					$sql .= ' evr_event_registrant_id ' . $this->order_by['event_registrant_id'];
				}		

				if (array_key_exists('event_id', $this->order_by)) {
					$sql .= ' evr_evt_event_id ' . $this->order_by['event_id'];
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
		$q = $this->_get_results(FALSE, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new EventRegistrant($row->evr_event_registrant_id);
			$child->load_from_data($row, array_keys(EventRegistrant::$fields));
			$this->add($child);
		}
	}

}