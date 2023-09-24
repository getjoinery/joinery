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
	
	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();
		
		if (array_key_exists('event_id', $this->options)) {
			$where_clauses[] = 'ewl_evt_event_id = ?';
			$bind_params[] = array($this->options['event_id'], PDO::PARAM_INT);
		}

		if (array_key_exists('user_id', $this->options)) {
			$where_clauses[] = 'ewl_usr_user_id = ?';
			$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		}	
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM ewl_waiting_lists ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM ewl_waiting_lists
				' . $where_clause . '
				ORDER BY ';
				
			if (!$this->order_by) {
				$sql .= " ewl_waiting_list_id ASC ";
			}
			else {
				if (array_key_exists('waiting_list_id', $this->order_by)) {
					$sql .= ' ewl_waiting_list_id ' . $this->order_by['waiting_list_id'];
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
			$child = new WaitingList($row->ewl_waiting_list_id);
			$child->load_from_data($row, array_keys(WaitingList::$fields));
			$this->add($child);
		}
	}

}


?>
