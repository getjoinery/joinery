<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/FieldConstraints.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

require_once(PathHelper::getIncludePath('data/users_class.php'));

class MailingListRegistrantException extends SystemBaseException {}

class MailingListRegistrant extends SystemBase {	public static $prefix = 'mlr';
	public static $tablename = 'mlr_mailing_list_registrants';
	public static $pkey_column = 'mlr_mailing_list_registrant_id';
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
	    'mlr_mailing_list_registrant_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'mlr_mlt_mailing_list_id' => array('type'=>'int4', 'required'=>true),
	    'mlr_usr_user_id' => array('type'=>'int4', 'required'=>true),
	    'mlr_change_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'mlr_delete_time' => array('type'=>'timestamp(6)'),
	);	

	public static $field_constraints = array();	

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
	protected static $model_class = 'MailingListRegistrant';

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

}

?>
