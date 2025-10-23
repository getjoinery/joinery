<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

require_once(PathHelper::getIncludePath('data/users_class.php'));

class WaitingListException extends SystemBaseException {}

class WaitingList extends SystemBase {	public static $prefix = 'ewl';
	public static $tablename = 'ewl_waiting_lists';
	public static $pkey_column = 'ewl_waiting_list_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

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
	    'ewl_waiting_list_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'ewl_evt_event_id' => array('type'=>'int4', 'required'=>true, 'unique_with'=>array (
  0 => 'ewl_usr_user_id',
)),
	    'ewl_usr_user_id' => array('type'=>'int8', 'required'=>true),
	    'ewl_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);	

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

	// Unique constraints now handled automatically by SystemBase
	
	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}

}

class MultiWaitingList extends SystemMultiBase {
	protected static $model_class = 'WaitingList';
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

}

?>
