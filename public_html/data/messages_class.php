<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SystemClass.php');

class MessageException extends SystemClassException {}
class MessageNotSentException extends MessageException {};

class Message extends SystemBase {	public static $prefix = 'msg';
	public static $tablename = 'msg_messages';
	public static $pkey_column = 'msg_message_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

	public static $fields = array(		'msg_message_id' => 'Primary key - Message ID',
		'msg_usr_user_id_recipient' => 'Message recipient',
		'msg_usr_user_id_sender' => 'Where is the message from',
		'msg_evt_event_id' => 'Event id if sent to event recipients',
		'msg_body' => 'The message',
		'msg_sent_time' => 'Time_sent',
		'msg_delete_time' => 'Time of deletion',
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
		'msg_message_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'msg_usr_user_id_recipient' => array('type'=>'int4'),
		'msg_usr_user_id_sender' => array('type'=>'int4'),
		'msg_evt_event_id' => array('type'=>'int4'),
		'msg_body' => array('type'=>'text'),
		'msg_sent_time' => array('type'=>'timestamp(6)'),
		'msg_delete_time' => array('type'=>'timestamp(6)'),
	);

	public static $required_fields = array('msg_body', 'msg_sent_time');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array();	
	
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
