<?php
/**
 * ConversationParticipant and MultiConversationParticipant classes
 *
 * Tracks who is in each conversation, their read status, and mute preferences.
 *
 * @version 1.1
 * @changelog 1.1 - cnp_is_admin: group membership and the group name are an
 *   admin's to manage (specs/implemented/joinery_messenger.md).
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class ConversationParticipantException extends SystemBaseException {}

class ConversationParticipant extends SystemBase {
	public static $prefix = 'cnp';

	// REST API per-record scope: only the owner (or staff, permission >= 5) may read or write this row via the API.
	function authenticate_read($data) {
		if ($this->get(static::$prefix.'_usr_user_id') != $data['current_user_id']) {
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError('Current user does not have permission to view this entry in '. static::$tablename);
			}
		}
	}

	function authenticate_write($data) {
		if ($this->get(static::$prefix.'_usr_user_id') != $data['current_user_id']) {
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError('Current user does not have permission to edit this entry in '. static::$tablename);
			}
		}
	}
	public static $tablename = 'cnp_conversation_participants';
	public static $pkey_column = 'cnp_conversation_participant_id';

	// REST CRUD exposure (Layer 1). User-owned (Bucket B): readable + writable
	// under the deny-by-default owner-or-staff row scope.
	public static $api_readable = true;
	public static $api_writable = true;

	// AI auto-discovery (read)
	public static $ai_readable        = true;
	public static $ai_description     = 'The user\'s participation in a conversation (read state, mute setting). Does not include message bodies.';
	public static $ai_excluded_fields = [];

	protected static $foreign_key_actions = [
		'cnp_usr_user_id' => ['action' => 'permanent_delete'],
		// 'cnv' prefix collides with ContentVersion - name the source explicitly
		'cnp_cnv_conversation_id' => ['action' => 'permanent_delete', 'source_class' => 'Conversation']
	];

	public static $field_specifications = array(
		'cnp_conversation_participant_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'cnp_cnv_conversation_id'         => array('type' => 'int8', 'required' => true),
		'cnp_usr_user_id'                 => array('type' => 'int4', 'required' => true),
		'cnp_last_read_time'              => array('type' => 'timestamp(6)'),
		'cnp_is_muted'                    => array('type' => 'bool', 'default' => false),
		// Group admins manage membership and the group name. The creator is one;
		// a 1:1 conversation has no meaningful admin and ignores this.
		'cnp_is_admin'                    => array('type' => 'bool', 'default' => false),
		'cnp_create_time'                 => array('type' => 'timestamp(6)'),
		'cnp_delete_time'                 => array('type' => 'timestamp(6)'),
	);

	function display_title() {
		return 'Participant #' . $this->key;
	}
}

class MultiConversationParticipant extends SystemMultiBase {
	protected static $model_class = 'ConversationParticipant';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['conversation_id'])) {
			$filters['cnp_cnv_conversation_id'] = [$this->options['conversation_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['user_id'])) {
			$filters['cnp_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}


		return $this->_get_resultsv2('cnp_conversation_participants', $filters, $this->order_by, $only_count, $debug);
	}
}
