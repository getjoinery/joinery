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

	public $prefix = 'evr';

	public static $fields = array(
		'evr_event_registrant_id' => 'event_registrant ID',
		'evr_evt_event_id' => 'The event',
		'evr_usr_user_id' => 'The attendee',
		'evr_recording_consent' => 'Timestamp when this event_registrants begins',
		'evr_first_event' => 'Is this the persons first event',
		'evr_create_time' => 'Timestamp when this request was created',
		'evr_other_events' => 'Is this request public?',
		'evr_health_notes' => 'Are we taking signups',
		'evr_extra_info_completed' => 'Whether the person has entered the needed questions for the event',
		'evr_ord_order_id' => 'Order for the registration',
		'evr_expires_time' => 'Time at which this registration expires.', 
		'evr_odi_order_item_id' => 'Order Item ID for this registration',
		'evr_delete_time' => 'Time of deletion',
		'evr_grp_group_id' => 'Event bundle that created this registration, if applicable'
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

	public static $public_actions = array(

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
		
		return $success;		
	}	

	function load() {
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

	function prepare() {
		
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
	
	function save() {
		parent::save();
		// Saving requires some session control for authentication checking and whatnot
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('evr_event_registrant_id' => $this->key);
			// Editing an existing item
		} else {
			$p_keys = NULL;
			// Creating a new item
			
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
			
			unset($rowdata['evr_event_registrant_id']);
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, "evr_event_registrants", $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['evr_event_registrant_id'];
	}	
	
	function soft_delete(){
		$this->set('evr_delete_time', 'now()');
		$this->save();
		return true;
	}
	
	function undelete(){
		$this->set('evr_delete_time', NULL);
		$this->save();	
		return true;
	}

	static public function GetPublicActions() { 
		return self::$public_actions;
	}
	
	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS evr_event_registrants_evr_event_registrant_id_seq
				INCREMENT BY 1
				NO MAXVALUE
				NO MINVALUE
				CACHE 1;';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}			
		
		$sql = '
			CREATE TABLE IF NOT EXISTS "public"."evr_event_registrants" (
			  "evr_event_registrant_id" int4 NOT NULL DEFAULT nextval(\'evr_event_registrants_evr_event_registrant_id_seq\'::regclass),
			  "evr_evt_event_id" int4 NOT NULL,
			  "evr_recording_consent" bool,
			  "evr_first_event" bool,
			  "evr_other_events" varchar(255) COLLATE "pg_catalog"."default",
			  "evr_health_notes" varchar(255) COLLATE "pg_catalog"."default",
			  "evr_usr_user_id" int4 NOT NULL,
			  "evr_create_time" timestamp(6),
			  "evr_extra_info_completed" bool NOT NULL DEFAULT false,
			  "evr_ord_order_id" int4,
			  "evr_odi_order_item_id" int4,
			  "evr_delete_time" timestamp(6),
			  "evr_grp_group_id" int4
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."evr_event_registrants" ADD CONSTRAINT "evr_event_registrants_pkey" PRIMARY KEY ("evr_event_registrant_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}

		//FOR FUTURE
		//ALTER TABLE table_name ADD COLUMN IF NOT EXISTS column_name INTEGER;
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

	private function _get_results($only_count=FALSE) { 
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

		$total_params = count($bind_params);
		for ($i=0; $i<$total_params; $i++) {
			list($param, $type) = $bind_params[$i];
			$q->bindValue($i+1, $param, $type);
		}
		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);

		return $q;
	}

	function load() {
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new EventRegistrant($row->evr_event_registrant_id);
			$child->load_from_data($row, array_keys(EventRegistrant::$fields));
			$this->add($child);
		}
	}

	function count_all() {
		$q = $this->_get_results(TRUE);
		$counter = $q->fetch();
		return $counter->count;
	}
}