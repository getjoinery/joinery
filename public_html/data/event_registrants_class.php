<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SessionControl.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemBase.php');

PathHelper::requireOnce('data/events_class.php');
PathHelper::requireOnce('data/users_class.php');

class EventRegistrantException extends SystemBaseException {}
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
		PublicPage::OutputGenericPublicPage(
			$this->title,
			$this->title,
			$this->error_message,
			array(
				'noindex' => TRUE
			));
	}
}
*/

class EventRegistrant extends SystemBase {	public static $prefix = 'evr';
	public static $tablename = 'evr_event_registrants';
	public static $pkey_column = 'evr_event_registrant_id';
	public static $permanent_delete_actions = array(		'odi_evr_event_registrant_id' => 'prevent'
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

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
	    'evr_event_registrant_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'evr_evt_event_id' => array('type'=>'int4', 'required'=>true),
	    'evr_usr_user_id' => array('type'=>'int4', 'required'=>true),
	    'evr_recording_consent' => array('type'=>'bool'),
	    'evr_first_event' => array('type'=>'bool'),
	    'evr_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'evr_other_events' => array('type'=>'varchar(255)'),
	    'evr_health_notes' => array('type'=>'varchar(255)'),
	    'evr_extra_info_completed' => array('type'=>'bool'),
	    'evr_ord_order_id' => array('type'=>'int4'),
	    'evr_expires_time' => array('type'=>'timestamp(6)'),
	    'evr_odi_order_item_id' => array('type'=>'int4'),
	    'evr_delete_time' => array('type'=>'timestamp(6)'),
	    'evr_grp_group_id' => array('type'=>'int4'),
	);

	public static $field_constraints = array(

		);


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

	function export_as_array($session=NULL) { 
		$output_array = parent::export_as_array();
		//$output_array['travel_text'] = $this->get_travel_text();
		return $output_array;
	}

	function authenticate_write($data) {
		if ($this->get(static::$prefix.'_usr_user_id') != $data['current_user_id']) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($data['current_user_permission'] < 8) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in '. static::$tablename);
			}
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
	protected static $model_class = 'EventRegistrant';

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

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['event_registrant_id'])) {
            $filters['evr_event_registrant_id'] = [$this->options['event_registrant_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['event_id'])) {
            $filters['evr_evt_event_id'] = [$this->options['event_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['user_id'])) {
            $filters['evr_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['deleted'])) {
            $filters['evr_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }

        if (isset($this->options['expired'])) {
            if($this->options['expired'] == true){
                $filters['evr_expires_time'] = "< now()";
            }
            else{
                // Need parentheses to ensure proper precedence when combined with AND
                $filters['(evr_expires_time'] = ">= now() OR evr_expires_time IS NULL)";
            }
        }

        return $this->_get_resultsv2('evr_event_registrants', $filters, $this->order_by, $only_count, $debug);
    }

}
