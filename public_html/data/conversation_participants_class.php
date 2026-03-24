<?php
/**
 * ConversationParticipant and MultiConversationParticipant classes
 *
 * Tracks who is in each conversation, their read status, and mute preferences.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class ConversationParticipantException extends SystemBaseException {}

class ConversationParticipant extends SystemBase {
	public static $prefix = 'cnp';
	public static $tablename = 'cnp_conversation_participants';
	public static $pkey_column = 'cnp_conversation_participant_id';

	protected static $foreign_key_actions = [
		'cnp_usr_user_id' => ['action' => 'permanent_delete'],
		'cnp_cnv_conversation_id' => ['action' => 'permanent_delete']
	];

	public static $field_specifications = array(
		'cnp_conversation_participant_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'cnp_cnv_conversation_id'         => array('type' => 'int8', 'required' => true),
		'cnp_usr_user_id'                 => array('type' => 'int4', 'required' => true),
		'cnp_last_read_time'              => array('type' => 'timestamp(6)'),
		'cnp_is_muted'                    => array('type' => 'bool', 'default' => false),
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

		if (isset($this->options['deleted'])) {
			$filters['cnp_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('cnp_conversation_participants', $filters, $this->order_by, $only_count, $debug);
	}
}
