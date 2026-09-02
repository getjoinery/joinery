<?php

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
	public static $ai_owner_field     = 'msg_usr_user_id_sender'; // a member sees the messages they sent
	public static $ai_description     = 'Direct messages between users (or to/from event hosts). msg_body is the message text.';
	public static $ai_excluded_fields = [];
	public static $ai_untrusted_fields = ['msg_body'];

	protected static $foreign_key_actions = [
		'msg_usr_user_id_sender' => ['action' => 'set_value', 'value' => User::USER_DELETED],
		'msg_usr_user_id_recipient' => ['action' => 'set_value', 'value' => User::USER_DELETED],
		// 'cnv' prefix collides with ContentVersion - name the source explicitly.
		// permanent_delete, not cascade: a cascade is one level, so deleting a
		// conversation with a flat cascade would take its messages and leave
		// their reactions and attachments pointing at nothing. Deleting each
		// message through the model runs that second level.
		'msg_cnv_conversation_id' => ['action' => 'permanent_delete', 'source_class' => 'Conversation'],
		// A reply outlives the message it quoted: the pointer clears and the
		// bubble renders without a quote, rather than the reply vanishing.
		'msg_reply_to_message_id' => ['action' => 'null', 'source_class' => 'Message'],
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
	    // Nullable: a message that arrived from another instance over Joinery
	    // Direct has no local user row behind it — msg_remote_sender_address
	    // carries the attributed sender instead.
	    'msg_usr_user_id_sender' => array('type'=>'int4', 'is_nullable'=>true),
	    'msg_remote_sender_address' => array('type'=>'varchar(255)', 'is_nullable'=>true),
	    'msg_context_type' => array('type'=>'varchar(32)', 'is_nullable'=>true),
	    'msg_context_id' => array('type'=>'int4', 'is_nullable'=>true),
	    'msg_cnv_conversation_id' => array('type'=>'int8'),
	    // Stable cross-instance identity, minted for every message. The receiving
	    // instance dedups replays on it.
	    'msg_guid' => array('type'=>'varchar(36)', 'is_nullable'=>true),
	    // 'text' (a member wrote it) or 'system' ("Alice added Bob"). System
	    // messages come from the same add_message() funnel so they poll, notify
	    // and order like everything else.
	    'msg_message_type' => array('type'=>'varchar(20)', 'default'=>'text'),
	    'msg_reply_to_message_id' => array('type'=>'int8', 'is_nullable'=>true),
	    // Not 'required': an attachment-only message carries no text. The body
	    // or attachment rule is enforced in Conversation::add_message().
	    'msg_body' => array('type'=>'text'),
	    'msg_sent_time' => array('type'=>'timestamp(6)', 'required'=>true),
	    'msg_delete_time' => array('type'=>'timestamp(6)'),

	    // How far a message has got on its way to another instance. A message
	    // that never leaves this deployment is 'local' and has no ticks to
	    // show: a stored message IS a delivered one when both parties are here.
	    // Crossing instances is the only case where "sent" and "arrived" are
	    // different moments worth telling a member apart.
	    'msg_delivery_state' => array('type'=>'varchar(16)', 'default'=>'local'),
	    'msg_delivery_attempts' => array('type'=>'int4', 'default'=>0),
	    'msg_delivery_next_try' => array('type'=>'timestamp(6)', 'is_nullable'=>true),

	    // --- Sealed Vault (docs/sealed_vault.md) ---------------------------
	    // A message in a Private or Guarded conversation stores its body as
	    // ciphertext. Unlike every other sealed model, the key is NOT wrapped
	    // onto this row: a conversation has many readers, so the one key is
	    // wrapped once per participant in ckg_conversation_key_grants and
	    // msg_sealed_key stays empty. The two columns that would hold a
	    // single-owner wrapping are declared anyway, because the generic
	    // machinery reads them by name, and left null.
	    'msg_content_sealed' => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
	    'msg_sealed_key' => array('type'=>'text', 'is_nullable'=>true),
	    'msg_sealed_owner_user_id' => array('type'=>'int8', 'is_nullable'=>true),
	    // Which conversation key sealed this body. One key per conversation
	    // today, so always 0; the column is what a future re-key writes into.
	    'msg_key_generation' => array('type'=>'int4', 'is_nullable'=>false, 'default'=>0),
	);

	/**
	 * The body is sealed content when the conversation says so.
	 *
	 * Sealing happens in Conversation::add_message(), which is the one place
	 * that holds both the conversation key and the plaintext, so save() is kept
	 * out of it — a sealed message row's body column is owned by the sealing
	 * path and never written back through an ordinary save.
	 */
	public static $sealed_fields = array('msg_body');
	public static $seal_on_save = false;

	/** msg_delivery_state values (see the column comment). */
	const DELIVERY_LOCAL     = 'local';
	const DELIVERY_QUEUED    = 'queued';
	const DELIVERY_DELIVERED = 'delivered';
	const DELIVERY_FAILED    = 'failed';

	/**
	 * A message row is sealed when its flag says so — no wrapped key of its own
	 * is expected, because the key lives in the conversation's grants.
	 */
	protected static function rowArrayIsSealed(array $row): bool {
		return self::sealFlagIsSet($row['msg_content_sealed'] ?? null);
	}

	/** Same question, for a loaded instance. */
	public function rowIsSealed() {
		return self::sealFlagIsSet($this->data->msg_content_sealed ?? null);
	}

	/**
	 * Is the STORED row sealed? Asked before every save, because that is what
	 * decides whether save() must leave the body column alone.
	 *
	 * The generic version also insists on a wrapped key on the row; a message
	 * never has one, so left alone it would answer "not sealed" and let an
	 * ordinary save write plaintext over a sealed body. Hence the flag alone.
	 */
	protected function rowIsSealedInDb(): bool {
		if ($this->key === NULL) {
			return $this->rowIsSealed();
		}
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT msg_content_sealed FROM msg_messages WHERE msg_message_id = ?');
		$stmt->execute(array($this->key));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return is_array($row) && self::sealFlagIsSet($row['msg_content_sealed'] ?? null);
	}

	/**
	 * The messenger supplies its own key plumbing, so the generic check for a
	 * per-row wrapped-key column does not apply. The flag column still must
	 * exist — without it nothing could tell a sealed row from a plaintext one.
	 */
	protected static function assertSealingDeclared(string $field): void {
		if (!array_key_exists('msg_content_sealed', static::$field_specifications)) {
			throw new RuntimeException('Message declares $sealed_fields but has no msg_content_sealed column.');
		}
	}

	/**
	 * Open a sealed body using the conversation's key.
	 *
	 * The one thing this does differently from every other sealed model: the
	 * key comes from whichever participant is present, through their key grant,
	 * rather than from a wrapping on the row. Locked is locked — a closed window
	 * raises VaultLockedException so the edges answer 423 or a one-tap unlock
	 * placeholder, never ciphertext dressed up as data.
	 */
	public static function decryptSealedFieldStatic($field, $ciphertext, array $row) {
		static::assertSealingDeclared($field);
		if (!static::rowArrayIsSealed($row)) {
			return $ciphertext;
		}
		if ($ciphertext === null || $ciphertext === '') {
			return $ciphertext;
		}
		if (!is_string($ciphertext) || strpos($ciphertext, 'v1.aead.') !== 0) {
			throw new RuntimeException('Message.' . $field . ' holds plaintext on a sealed row — '
				. 'something wrote it without the sealing path.');
		}


		$conversation_id = intval($row['msg_cnv_conversation_id'] ?? 0);
		$dek = $conversation_id > 0 ? ConversationKeyGrant::openConversationKey($conversation_id) : null;
		if ($dek === null) {
			throw new VaultLockedException();
		}

		$crypto = new VaultCrypto();
		return $crypto->openField($ciphertext, $dek,
			static::sealAd(intval($row[static::$pkey_column] ?? 0), $field));
	}

	/**
	 * Seal one message's body under a conversation key.
	 *
	 * Rides sealColumns()' reuse-an-existing-key path: the key is the
	 * conversation's, already wrapped to every participant, so no wrapping is
	 * written onto the row and no vault is consulted here.
	 */
	public static function sealBody($message_id, string $conversation_dek, $plaintext): void {
		static::sealColumns($message_id, null, array('msg_body' => $plaintext), $conversation_dek);
	}

function display_title(){
		if($this->get('msg_body')){
			return substr(strip_tags($this->get('msg_body')), 0, 100);
		}
		else{
			return '';
		}
	}

	// REST API per-record read scope: a message is readable by staff
	// (permission >= 5) and by anyone in its conversation. Membership, not the
	// sender column, is what admits a reader: a group thread's messages belong
	// to every participant, whoever wrote each one.
	function authenticate_read($data) {
		if ($data['current_user_permission'] >= 5) {
			return;
		}
		$uid = (int)$data['current_user_id'];
		if ($this->get('msg_usr_user_id_sender') == $uid) {
			return;
		}
		$conversation_id = (int)$this->get('msg_cnv_conversation_id');
		if ($conversation_id > 0) {
			$conversation = new Conversation($conversation_id, TRUE);
			if ($conversation->key && $conversation->has_participant($uid)) {
				return;
			}
		}
		throw new SystemAuthenticationError(
			'Current user does not have permission to view this entry in '. static::$tablename);
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

		// A conversation message is writable only by someone in the room. And a
		// protected conversation takes messages only through the messenger:
		// Conversation::add_message() is the one writer that holds the sealing
		// key, so a generic write there — which would store plaintext beside
		// ciphertext — is refused for everyone, staff included.
		$conversation_id = (int)$this->get('msg_cnv_conversation_id');
		if ($conversation_id > 0) {
			$conversation = new Conversation($conversation_id, TRUE);
			if (!$conversation->key) {
				throw new SystemAuthenticationError('No such conversation.');
			}
			if ($conversation->is_sealed() || $this->rowIsSealed()) {
				throw new SystemAuthenticationError(
					'This conversation is protected — its messages are written only through Messages.');
			}
			if ($data['current_user_permission'] < 5
					&& !$conversation->has_participant($data['current_user_id'])) {
				throw new SystemAuthenticationError(
					'Current user is not a participant in this conversation.');
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

		// Poll cursor: everything the caller has not seen yet. Keyed on the
		// message id rather than the send time so two messages stored inside the
		// same microsecond cannot straddle the cursor.
		if (isset($this->options['after_message_id'])) {
			$filters['msg_message_id'] = '> ' . (int)$this->options['after_message_id'];
		}

		// A named set of messages in one query — what a thread render needs when
		// it resolves the parents its replies quote.
		if (isset($this->options['message_ids'])) {
			$ids = array_values(array_filter(array_map('intval', (array)$this->options['message_ids'])));
			$filters['msg_message_id'] = $ids
				? 'IN (' . implode(',', $ids) . ')'
				: 'IS NULL';
		}

		if (isset($this->options['message_type'])) {
			$filters['msg_message_type'] = [$this->options['message_type'], PDO::PARAM_STR];
		}

		if (isset($this->options['guid'])) {
			$filters['msg_guid'] = [$this->options['guid'], PDO::PARAM_STR];
		}

		if (isset($this->options['delivery_state'])) {
			$filters['msg_delivery_state'] = [$this->options['delivery_state'], PDO::PARAM_STR];
		}

		// The outbound retry queue: everything whose next attempt is due. The
		// split-parenthesis form keeps the OR inside its own group, so it
		// cannot swallow the other filters.
		if (!empty($this->options['delivery_due'])) {
			$filters['(msg_delivery_next_try'] = '<= now() OR msg_delivery_next_try IS NULL)';
		}


		return $this->_get_resultsv2('msg_messages', $filters, $this->order_by, $only_count, $debug);
	}

}

?>
