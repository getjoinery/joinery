<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));

class MessageException extends SystemBaseException {}
class MessageNotSentException extends MessageException {};

class Message extends SystemBase {	public static $prefix = 'msg';
	public static $tablename = 'msg_messages';
	public static $pkey_column = 'msg_message_id';

	// REST CRUD exposure (Layer 1). User-owned (Bucket B): readable + writable
	// under the deny-by-default owner-or-staff row scope.
	public static $api_readable = true;
	public static $api_writable = true;

	// AI auto-discovery (read)
	// NOTE: msg_body is user-generated text and a prompt-injection vector. The
	// executor blocklist + auto-block regex prevent extracting credentials, but
	// recipe authors should treat retrieved bodies as data, not instructions.
	public static $ai_readable        = true;
	public static $ai_owner_field     = ['msg_usr_user_id_sender', 'msg_usr_user_id_recipient']; // a member sees messages they sent or received
	public static $ai_description     = 'Direct messages between users (or to/from event hosts). msg_body is the message text.';
	public static $ai_excluded_fields = [];
	public static $ai_untrusted_fields = ['msg_body'];

	protected static $foreign_key_actions = [
		'msg_usr_user_id_sender' => ['action' => 'set_value', 'value' => User::USER_DELETED],
		'msg_usr_user_id_recipient' => ['action' => 'set_value', 'value' => User::USER_DELETED],
		// 'cnv' prefix collides with ContentVersion - name the source explicitly
		'msg_cnv_conversation_id' => ['action' => 'cascade', 'source_class' => 'Conversation']
	];

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
	    'msg_context_type' => array('type'=>'varchar(32)', 'is_nullable'=>true),
	    'msg_context_id' => array('type'=>'int4', 'is_nullable'=>true),
	    'msg_cnv_conversation_id' => array('type'=>'int8'),
	    'msg_body' => array('type'=>'text', 'required'=>true),
	    'msg_sent_time' => array('type'=>'timestamp(6)', 'required'=>true),
	    'msg_delete_time' => array('type'=>'timestamp(6)'),
	);

function display_title(){
		if($this->get('msg_body')){
			return substr(strip_tags($this->get('msg_body')), 0, 100);
		}
		else{
			return '';
		}
	}

	// REST API per-record read scope: a private message is readable only by its
	// sender or recipient (or staff, permission >= 5). A message has no bare
	// msg_usr_user_id column — the parties are msg_usr_user_id_sender and
	// msg_usr_user_id_recipient — so both are checked.
	function authenticate_read($data) {
		$uid = $data['current_user_id'];
		if ($this->get('msg_usr_user_id_sender') != $uid
			&& $this->get('msg_usr_user_id_recipient') != $uid) {
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to view this entry in '. static::$tablename);
			}
		}
	}

	// REST API per-record write scope: a message is owned by its sender, so only
	// the sender (or staff, permission >= 5) may create/update/delete it. There
	// is no bare msg_usr_user_id column — the owner is msg_usr_user_id_sender.
	function authenticate_write($data) {
		if ($this->get('msg_usr_user_id_sender') != $data['current_user_id']) {
			// Not the sender — require staff access, otherwise denied.
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

		if (isset($this->options['context_type'])) {
			$filters['msg_context_type'] = [$this->options['context_type'], PDO::PARAM_STR];
		}

		if (isset($this->options['context_id'])) {
			$filters['msg_context_id'] = [$this->options['context_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['context_id_only'])) {
			$filters['msg_context_id'] = '= '.intval($this->options['context_id_only']).' AND msg_usr_user_id_recipient IS NULL';
		}

		if (isset($this->options['conversation_id'])) {
			$filters['msg_cnv_conversation_id'] = [$this->options['conversation_id'], PDO::PARAM_INT];
		}


		return $this->_get_resultsv2('msg_messages', $filters, $this->order_by, $only_count, $debug);
	}

}

?>
