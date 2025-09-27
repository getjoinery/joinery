<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class MessageException extends SystemBaseException {}
class MessageNotSentException extends MessageException {};

class Message extends SystemBase {	public static $prefix = 'msg';
	public static $tablename = 'msg_messages';
	public static $pkey_column = 'msg_message_id';
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
	    'msg_message_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'msg_usr_user_id_recipient' => array('type'=>'int4'),
	    'msg_usr_user_id_sender' => array('type'=>'int4'),
	    'msg_evt_event_id' => array('type'=>'int4'),
	    'msg_body' => array('type'=>'text', 'required'=>true),
	    'msg_sent_time' => array('type'=>'timestamp(6)', 'required'=>true),
	    'msg_delete_time' => array('type'=>'timestamp(6)'),
	);

	public static $field_constraints = array();	

	function display_title(){
		if($this->get('msg_body')){
			return substr(strip_tags($this->get('msg_body')), 0, 100);
		}
		else{
			return '';
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

}

class MultiMessage extends SystemMultiBase {
	protected static $model_class = 'Message';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['user_id_recipient'])) {
			$filters['msg_usr_user_id_recipient'] = [$this->options['user_id_recipient'], PDO::PARAM_INT];
		}

		if (isset($this->options['user_id_sender'])) {
			$filters['msg_usr_user_id_sender'] = [$this->options['user_id_sender'], PDO::PARAM_INT];
		}

		if (isset($this->options['event_id'])) {
			$filters['msg_evt_event_id'] = [$this->options['event_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['event_id_only'])) {
			$filters['msg_evt_event_id'] = '= '.$this->options['event_id_only'].' AND msg_usr_user_id_recipient IS NULL';
		}

		if (isset($this->options['deleted'])) {
			$filters['msg_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('msg_messages', $filters, $this->order_by, $only_count, $debug);
	}

}

?>
