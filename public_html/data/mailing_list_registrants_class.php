<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

PathHelper::requireOnce('data/users_class.php');

	
class MailingListRegistrantException extends SystemClassException {}

class MailingListRegistrant extends SystemBase {
	public static $prefix = 'mlr';
	public static $tablename = 'mlr_mailing_list_registrants';
	public static $pkey_column = 'mlr_mailing_list_registrant_id';
	public static $permanent_delete_actions = array(
		'mlr_mailing_list_registrant_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'mlr_mailing_list_registrant_id' => 'ID of the group member',
		'mlr_mlt_mailing_list_id' => 'Mailing list id',
		'mlr_usr_user_id' => 'Foreign key pointing to the member in this group',
		'mlr_change_time' => 'Time created',
		'mlr_delete_time' => 'Time created',
	);

	public static $field_specifications = array(
		'mlr_mailing_list_registrant_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'mlr_mlt_mailing_list_id' => array('type'=>'int4'),
		'mlr_usr_user_id' => array('type'=>'int4'),
		'mlr_change_time' => array('type'=>'timestamp(6)'),
		'mlr_delete_time' => array('type'=>'timestamp(6)'),
	);	

	public static $required_fields = array('mlr_mlt_mailing_list_id', 'mlr_usr_user_id');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array(
		'mlr_change_time' => 'now()');		

	public static function CheckIfExists($user_id, $mailing_list_id) {
		
		$count = new MultiMailingListRegistrant(array(
			'user_id' => $user_id,
			'mailing_list_id' => $mailing_list_id,
		));
		 
		if ($count->count_all() > 0) {
			$count->load();
			return $count->get(0);
		}
		return false;
	}	

	function prepare() {	
		
		if(!$this->key){
			if($this->check_for_duplicate(array('mlr_mlt_mailing_list_id', 'mlr_usr_user_id'))){
				throw new MailingListRegistrantException('This is a duplicate mailing list registrant:'. $this->get('mlr_usr_user_id'));
			}
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

	function save($debug=false) {
		if(!$this->key){
			if($this->check_for_duplicate(array('mlr_mlt_mailing_list_id', 'mlr_usr_user_id'))){
				return FALSE;
			}			
		}
		parent::save($debug);
	}
}

class MultiMailingListRegistrant extends SystemMultiBase {

	
	function get_dropdown_array($include_new=FALSE) {
		return false;
	}
	
	
	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['user_id'])) {
			$filters['mlr_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['mailing_list_id'])) {
			$filters['mlr_mlt_mailing_list_id'] = [$this->options['mailing_list_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['deleted'])) {
			$filters['mlr_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('mlr_mailing_list_registrants', $filters, $this->order_by, $only_count, $debug);
	}


	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new MailingListRegistrant($row->mlr_mailing_list_registrant_id);
			$child->load_from_data($row, array_keys(MailingListRegistrant::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}


?>
