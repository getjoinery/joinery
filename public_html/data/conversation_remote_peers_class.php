<?php
/**
 * ConversationRemotePeer and MultiConversationRemotePeer classes
 *
 * The person on the other instance.
 *
 * When two members whose accounts live on different Joinery deployments talk,
 * each side stores its own copy of the conversation and the far party is a row
 * here — an address, a domain, and a name to show. Deliberately not a user row:
 * minting shadow accounts for people who have never signed in would put
 * strangers in the member directory, in permission checks, and in every
 * "everyone on this site" query, to gain nothing.
 *
 * A message from such a peer carries msg_remote_sender_address and no
 * msg_usr_user_id_sender; display resolves the name through this table.
 *
 * @version 1.0
 */


class ConversationRemotePeerException extends SystemBaseException {}

class ConversationRemotePeer extends SystemBase {
	public static $prefix = 'crp';
	public static $tablename = 'crp_conversation_remote_peers';
	public static $pkey_column = 'crp_conversation_remote_peer_id';

	// Not REST-exposed: a peer is only meaningful inside its conversation, and
	// the conversation's own participant check is what authorizes reading it.
	public static $api_readable = false;
	public static $api_writable = false;
	public static $ai_readable  = false;

	protected static $foreign_key_actions = array(
		'crp_cnv_conversation_id' => array('action' => 'permanent_delete', 'source_class' => 'Conversation'),
	);

	public static $field_specifications = array(
		'crp_conversation_remote_peer_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'crp_cnv_conversation_id' => array('type' => 'int8', 'is_nullable' => false),
		'crp_address'      => array('type' => 'varchar(255)', 'is_nullable' => false),
		'crp_domain'       => array('type' => 'varchar(255)', 'is_nullable' => false),
		'crp_display_name' => array('type' => 'varchar(255)', 'is_nullable' => true),
		'crp_create_time'  => array('type' => 'timestamp(6)'),
		'crp_delete_time'  => array('type' => 'timestamp(6)'),
	);

	/** Record (or find) the far party of a conversation. */
	public static function ensure($conversation_id, string $address, ?string $display_name = null): ConversationRemotePeer {
		$address = strtolower(trim($address));
		$existing = self::find($conversation_id, $address);
		if ($existing) {
			// A peer whose display name we did not know before, and now do.
			if ($display_name !== null && $display_name !== '' && !$existing->get('crp_display_name')) {
				$existing->set('crp_display_name', substr($display_name, 0, 255));
				$existing->save();
			}
			return $existing;
		}

		$at = strrpos($address, '@');
		$row = new ConversationRemotePeer(NULL);
		$row->set('crp_cnv_conversation_id', (int)$conversation_id);
		$row->set('crp_address', $address);
		$row->set('crp_domain', $at === false ? '' : substr($address, $at + 1));
		$row->set('crp_display_name', $display_name !== null ? substr($display_name, 0, 255) : null);
		$row->set('crp_create_time', gmdate('Y-m-d H:i:s'));
		$row->save();
		return $row;
	}

	/** One peer of one conversation, or null. */
	public static function find($conversation_id, string $address): ?ConversationRemotePeer {
		$rows = new MultiConversationRemotePeer(array(
			'conversation_id' => (int)$conversation_id,
			'address'         => strtolower(trim($address)),
			'deleted'         => false,
		));
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}

	/** Every live peer of a conversation, address => display name. */
	public static function forConversation($conversation_id): array {
		$out = array();
		$rows = new MultiConversationRemotePeer(array(
			'conversation_id' => (int)$conversation_id,
			'deleted'         => false,
		));
		foreach ($rows as $row) {
			$out[(string)$row->get('crp_address')] = (string)($row->get('crp_display_name')
				?: $row->get('crp_address'));
		}
		return $out;
	}

	function display_title() {
		return (string)($this->get('crp_display_name') ?: $this->get('crp_address'));
	}
}

class MultiConversationRemotePeer extends SystemMultiBase {
	protected static $model_class = 'ConversationRemotePeer';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['conversation_id'])) {
			$filters['crp_cnv_conversation_id'] = array($this->options['conversation_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['address'])) {
			$filters['crp_address'] = array($this->options['address'], PDO::PARAM_STR);
		}

		if (isset($this->options['domain'])) {
			$filters['crp_domain'] = array($this->options['domain'], PDO::PARAM_STR);
		}

		return $this->_get_resultsv2('crp_conversation_remote_peers', $filters, $this->order_by, $only_count, $debug);
	}
}
