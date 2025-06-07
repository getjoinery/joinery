<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/FieldConstraints.php');
require_once($siteDir . '/includes/Globalvars.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SingleRowAccessor.php');
require_once($siteDir . '/includes/SystemClass.php');
require_once($siteDir . '/includes/Validator.php');

require_once($siteDir.'/data/users_class.php');

	
class WaitingListException extends SystemClassException {}

class WaitingList extends SystemBase {
	public static $prefix = 'ewl';
	public static $tablename = 'ewl_waiting_lists';
	public static $pkey_column = 'ewl_waiting_list_id';
	public static $permanent_delete_actions = array(
		'ewl_waiting_list_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'ewl_waiting_list_id' => 'ID of the group member',
		'ewl_evt_event_id' => 'group id',
		'ewl_usr_user_id' => 'User on the waiting list',
		'ewl_create_time' => 'Time added to waiting list',
	);

	public static $field_specifications = array(
		'ewl_waiting_list_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'ewl_evt_event_id' => array('type'=>'int4'),
		'ewl_usr_user_id' => array('type'=>'int8'),
		'ewl_create_time' => array('type'=>'timestamp(6)'),
	);	

	public static $required_fields = array('ewl_evt_event_id', 'ewl_usr_user_id');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array('ewl_create_time' => 'now()');		
		
	
	public static function CheckIfExists($user_id, $event_id) {
		
		$count = new MultiWaitingList(array(
			'user_id' => $user_id,
			'event_id' => $event_id,
		));
		 
		if ($count->count_all() > 0) {
			$count->load();
			return $count->get(0);
		}
		return NULL;
	}	
	
	function remove(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
		$q = $dblink->prepare('DELETE FROM ewl_waiting_lists WHERE ewl_waiting_list_id=?');
		$q->bindValue(1, $this->key, PDO::PARAM_INT);
		
		$success = $q->execute();
		
		return $success;		
	}	
	

	function prepare() {	
		
		if(!$this->key){
			if(WaitingList::CheckIfExists($this->get('ewl_usr_user_id'), $this->get('ewl_evt_event_id'))){
				return false;
			}
		}
	}
	
	
	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}

	function save($debug=false) {
		if(!$this->key){
			if(WaitingList::CheckIfExists($this->get('ewl_usr_user_id'), $this->get('ewl_evt_event_id'))){
				return FALSE;
			}			
		}
		parent::save($debug);
	}
	

	
}

class MultiWaitingList extends SystemMultiBase {
	function get_user_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $item) {
			$user = new User($item->get('ewl_usr_user_id'), TRUE);
			$items[$user->display_name()] = $user->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;
	}

	function get_event_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $item) {
			$event = new Event($item->get('ewl_evt_event_id'), TRUE);
			$items[$event->get('evt_name')] = $event->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;
	}
	
	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['event_id'])) {
            $filters['ewl_evt_event_id'] = [$this->options['event_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['user_id'])) {
            $filters['ewl_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }

        return $this->_get_resultsv2('ewl_waiting_lists', $filters, $this->order_by, $only_count, $debug);
    }

	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new WaitingList($row->ewl_waiting_list_id);
			$child->load_from_data($row, array_keys(WaitingList::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}


?>
