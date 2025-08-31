<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

PathHelper::requireOnce('data/users_class.php');

class GroupMemberException extends SystemClassException {}

class GroupMember extends SystemBase {	public static $prefix = 'grm';
	public static $tablename = 'grm_group_members';
	public static $pkey_column = 'grm_group_member_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(		'grm_grp_group_id' => 'group id',
		'grm_foreign_key_id' => 'Foreign key pointing to the member in this group',
	);

/**
	 * Field specifications define database column properties and schema constraints
	 * Available options:
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp(6)' | 'numeric(10,2)' | 'bool' | etc.
	 *   'serial' => true/false - Auto-incrementing field
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'unique' => true - Field must be unique (single field constraint)
	 *   'unique_with' => array('field1', 'field2') - Composite unique constraint with other fields
	 */
	public static $field_specifications = array(
		'grm_group_member_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'grm_grp_group_id' => array('type'=>'int4', 'unique_with' => array('grm_foreign_key_id')),
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

	// Unique constraints now handled automatically by SystemBase
	
	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}
	
}

class MultiGroupMember extends SystemMultiBase {
	protected static $model_class = 'GroupMember';
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

}

?>
