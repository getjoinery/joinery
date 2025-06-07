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

	
class GroupMemberException extends SystemClassException {}

class GroupMember extends SystemBase {
	public static $prefix = 'grm';
	public static $tablename = 'grm_group_members';
	public static $pkey_column = 'grm_group_member_id';
	public static $permanent_delete_actions = array(
		'grm_group_member_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'grm_group_member_id' => 'ID of the group member',
		'grm_grp_group_id' => 'group id',
		'grm_foreign_key_id' => 'Foreign key pointing to the member in this group',
	);

	public static $field_specifications = array(
		'grm_group_member_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'grm_grp_group_id' => array('type'=>'int4'),
		'grm_foreign_key_id' => array('type'=>'int8'),
	);	

	public static $required_fields = array('grm_grp_group_id');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array(
		);		
	
	function remove(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
		$q = $dblink->prepare('DELETE FROM grm_group_members WHERE grm_group_member_id=?');
		$q->bindValue(1, $this->key, PDO::PARAM_INT);
		
		$success = $q->execute();
		
		return $success;		
	}	
	

	function prepare() {	
		if(!$this->key){
			if($this->check_for_duplicate(array('grm_grp_group_id', 'grm_foreign_key_id'))){
				throw new GroupMemberException('This is a duplicate.');
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
			if($this->check_for_duplicate(array('grm_grp_group_id', 'grm_foreign_key_id'))){
				return FALSE;
			}			
		}
		parent::save($debug);
	}
	
}

class MultiGroupMember extends SystemMultiBase {
	function get_user_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $item) {
			$user = new User($item->get('grm_foreign_key_id'), TRUE);
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
			$event = new Event($item->get('grm_foreign_key_id'), TRUE);
			$items[$event->get('evt_name')] = $event->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;
	}
	
	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['group_id'])) {
			$filters['grm_grp_group_id'] = [$this->options['group_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['foreign_key_id'])) {
			$filters['grm_foreign_key_id'] = [$this->options['foreign_key_id'], PDO::PARAM_INT];
		}

		return $this->_get_resultsv2('grm_group_members', $filters, $this->order_by, $only_count, $debug);
	}


	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new GroupMember($row->grm_group_member_id);
			$child->load_from_data($row, array_keys(GroupMember::$fields));
			$this->add($child);
		}
	}


	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}


?>
