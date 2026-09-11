<?php
/**
 * Conversation and MultiConversation classes
 *
 * Threaded messaging system — conversations group messages between participants.
 * The core data layer under both the legacy /profile/conversation(s) views and
 * the Joinery Messenger plugin; group membership, system messages, replies and
 * the realtime NOTIFY live here because messaging is core and several consumers
 * (the messenger UI, the iOS member app, the AI participant) share these rows.
 *
 * @version 1.2
 * @changelog 1.2 - review remediation: protection level one-way at the column
 *   (set() refuses lowering), the 1:1 lookup never matches a named group, and
 *   the group-size cap survives a missing setting.
 * @changelog 1.1 - specs/implemented/joinery_messenger.md phase 1: cnv_guid +
 *   cnv_protection_level, group membership (add/remove/rename/leave with
 *   cnp_is_admin), system messages, replies and attachments through
 *   add_message()'s options, and the generic NOTIFY message_events emission a
 *   future realtime service listens on.
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('data/messages_class.php'));

class ConversationException extends SystemBaseException {}

class Conversation extends SystemBase {
	public static $prefix = 'cnv';
	public static $tablename = 'cnv_conversations';
	public static $pkey_column = 'cnv_conversation_id';

	// REST CRUD exposure (Layer 1). User-owned (Bucket B): readable + writable
	// under the deny-by-default owner-or-staff row scope.
	public static $api_readable = true;
	public static $api_writable = true;

	// AI auto-discovery (read)
	public static $ai_readable        = true;
	public static $ai_description     = 'Direct-message conversation thread headers. Pair with Message for the actual content and ConversationParticipant for who is in the thread.';
	public static $ai_excluded_fields = [];
	public static $ai_untrusted_fields = ['cnv_subject'];

	const MAX_MESSAGE_LENGTH = 5000;

	/** msg_message_type values. */
	const TYPE_TEXT   = 'text';
	const TYPE_SYSTEM = 'system';

	/** The Postgres NOTIFY channel a future realtime service LISTENs on. */
	const NOTIFY_CHANNEL = 'message_events';

	public static $field_specifications = array(
		'cnv_conversation_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'cnv_subject'         => array('type' => 'varchar(255)'),
		// Stable identity across instances — the same conversation on the sender's
		// and the recipient's deployment carries the same guid.
		'cnv_guid'            => array('type' => 'varchar(36)', 'is_nullable' => true),
		// The platform protection ladder (ProtectionLevel), per conversation.
		'cnv_protection_level' => array('type' => 'varchar(20)', 'default' => 'standard'),
		'cnv_create_time'     => array('type' => 'timestamp(6)'),
		'cnv_update_time'     => array('type' => 'timestamp(6)'),
		'cnv_delete_time'     => array('type' => 'timestamp(6)'),
	);

	/**
	 * Get or create a 1:1 conversation between two users.
	 * Returns existing conversation if one exists, creates new one otherwise.
	 */
	public static function get_or_create_conversation($user_id_1, $user_id_2) {
		if ($user_id_1 == $user_id_2) {
			throw new ConversationException('Cannot create a conversation with yourself');
		}

		// Check blocks if block system exists
		if (class_exists('UserBlock')) {
			if (UserBlock::is_blocked($user_id_1, $user_id_2)) {
				throw new ConversationException('Cannot message this user');
			}
		}

		// Look for existing conversation between these two users. A NAMED
		// two-person conversation is a group that happens to hold two people —
		// never the 1:1, which is why cnv_subject filters here: reusing the
		// group as the DM would let a later protection raise land on a room
		// other members can be added to.
		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();
		$sql = "SELECT cnp1.cnp_cnv_conversation_id
				FROM cnp_conversation_participants cnp1
				JOIN cnp_conversation_participants cnp2
				  ON cnp1.cnp_cnv_conversation_id = cnp2.cnp_cnv_conversation_id
				JOIN cnv_conversations cnv
				  ON cnv.cnv_conversation_id = cnp1.cnp_cnv_conversation_id
				WHERE cnp1.cnp_usr_user_id = ?
				  AND cnp2.cnp_usr_user_id = ?
				  AND cnv.cnv_delete_time IS NULL
				  AND cnv.cnv_subject IS NULL
				  AND NOT EXISTS (
				      SELECT 1 FROM cnp_conversation_participants cnp3
				      WHERE cnp3.cnp_cnv_conversation_id = cnp1.cnp_cnv_conversation_id
				        AND cnp3.cnp_usr_user_id NOT IN (?, ?)
				  )
				LIMIT 1";
		$q = $dblink->prepare($sql);
		$q->execute([$user_id_1, $user_id_2, $user_id_1, $user_id_2]);
		$row = $q->fetch(PDO::FETCH_ASSOC);

		if ($row) {
			return new Conversation($row['cnp_cnv_conversation_id'], TRUE);
		}

		// No existing conversation — create one
		return self::create_conversation([$user_id_1, $user_id_2]);
	}

	/**
	 * Create a conversation with given participant user IDs.
	 *
	 * @param array       $participant_user_ids at least two distinct user ids
	 * @param string|null $subject              group name; null for a plain 1:1
	 * @param array       $options              admin_user_id (defaults to none —
	 *                                          the creator, when the caller names
	 *                                          one, is that group's first admin),
	 *                                          protection_level, guid
	 * @return Conversation the new, saved conversation
	 */
	public static function create_conversation($participant_user_ids, $subject = null, array $options = array()) {
		$participant_user_ids = array_values(array_unique(array_map('intval', $participant_user_ids)));

		if (count($participant_user_ids) < 2) {
			throw new ConversationException('A conversation requires at least 2 participants');
		}

		$conversation = new Conversation(NULL);
		if ($subject !== null) {
			$conversation->set('cnv_subject', $subject);
		}
		$conversation->set('cnv_guid', isset($options['guid']) && $options['guid']
			? (string)$options['guid'] : self::mint_guid());
		// A conversation is created Standard and raised. Protecting one is a
		// ceremony — mint a key, hand it to every member, seal what is already
		// there — and a create that quietly recorded "private" without doing any
		// of it would leave a conversation wearing a promise it does not keep.
		$level = ProtectionLevel::normalize($options['protection_level'] ?? ProtectionLevel::STANDARD);
		if ($level !== ProtectionLevel::STANDARD) {
			throw new ConversationException(
				'Create the conversation, then raise() it — protection is a ceremony, not a column.');
		}
		$conversation->set('cnv_protection_level', ProtectionLevel::STANDARD);
		$conversation->set('cnv_create_time', gmdate('Y-m-d H:i:s'));
		$conversation->set('cnv_update_time', gmdate('Y-m-d H:i:s'));
		$conversation->save();

		$admin_user_id = isset($options['admin_user_id']) ? (int)$options['admin_user_id'] : 0;

		foreach ($participant_user_ids as $user_id) {
			$participant = new ConversationParticipant(NULL);
			$participant->set('cnp_cnv_conversation_id', $conversation->key);
			$participant->set('cnp_usr_user_id', $user_id);
			if ($admin_user_id && $user_id === $admin_user_id) {
				$participant->set('cnp_is_admin', true);
			}
			$participant->save();
		}

		return $conversation;
	}

	/**
	 * Start a conversation with someone on another Joinery instance.
	 *
	 * The local side has exactly one participant — the member — because the far
	 * party has no account here. They are a remote-peer row instead, which is
	 * what keeps strangers out of the member directory and out of every
	 * permission check on this deployment.
	 *
	 * Both sides mint their own copy; the shared guid is what makes them the
	 * same conversation when a message crosses.
	 */
	public static function create_remote_conversation($local_user_id, string $address,
			?string $display_name = null, ?string $guid = null): Conversation {
		require_once(PathHelper::getIncludePath('data/conversation_remote_peers_class.php'));

		$address = strtolower(trim($address));
		if ($address === '' || strpos($address, '@') === false) {
			throw new ConversationException('That does not look like an address.');
		}

		$conversation = new Conversation(NULL);
		$conversation->set('cnv_guid', $guid ?: self::mint_guid());
		$conversation->set('cnv_protection_level', ProtectionLevel::STANDARD);
		$conversation->set('cnv_create_time', gmdate('Y-m-d H:i:s'));
		$conversation->set('cnv_update_time', gmdate('Y-m-d H:i:s'));
		$conversation->save();

		$participant = new ConversationParticipant(NULL);
		$participant->set('cnp_cnv_conversation_id', $conversation->key);
		$participant->set('cnp_usr_user_id', (int)$local_user_id);
		$participant->set('cnp_create_time', gmdate('Y-m-d H:i:s'));
		$participant->save();

		ConversationRemotePeer::ensure($conversation->key, $address, $display_name);

		return $conversation;
	}

	/**
	 * The existing conversation with one remote address, or null.
	 *
	 * Keyed on (member, address) so a second message to the same person lands
	 * in the thread they are already reading rather than opening a new one.
	 */
	public static function find_remote_conversation($local_user_id, string $address): ?Conversation {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			'SELECT cnv.cnv_conversation_id
			 FROM cnv_conversations cnv
			 JOIN crp_conversation_remote_peers crp
			   ON crp.crp_cnv_conversation_id = cnv.cnv_conversation_id
			  AND crp.crp_address = ? AND crp.crp_delete_time IS NULL
			 JOIN cnp_conversation_participants cnp
			   ON cnp.cnp_cnv_conversation_id = cnv.cnv_conversation_id
			  AND cnp.cnp_usr_user_id = ?
			 WHERE cnv.cnv_delete_time IS NULL
			 LIMIT 1');
		$q->execute(array(strtolower(trim($address)), (int)$local_user_id));
		$row = $q->fetch(PDO::FETCH_ASSOC);
		return $row ? new Conversation((int)$row['cnv_conversation_id'], TRUE) : null;
	}

	/** Everyone in this conversation who is not on this deployment. */
	public function remote_peers(): array {
		if ($this->remote_peers_cache !== null) {
			return $this->remote_peers_cache;
		}
		require_once(PathHelper::getIncludePath('data/conversation_remote_peers_class.php'));
		return $this->remote_peers_cache = ConversationRemotePeer::forConversation($this->key);
	}

	/** Does any of this conversation travel between instances? */
	public function is_federated(): bool {
		return count($this->remote_peers()) > 0;
	}

	/**
	 * A random RFC-4122 v4 identifier. Conversations and messages both carry one
	 * so the same thread and the same bubble are nameable on two instances.
	 */
	public static function mint_guid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		$hex = bin2hex($bytes);
		return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
			. '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
	}

	/**
	 * Where a member is sent to read this conversation. The messenger owns the
	 * one messaging surface when it is active; the legacy view answers otherwise.
	 * Notification links and the header badge both read this, so the two surfaces
	 * never disagree about where a message lives.
	 */
	public static function url_for($conversation_id): string {
		if (PluginHelper::isPluginActive('messenger')
				&& Globalvars::get_instance()->get_setting('messenger_active', true, true)) {
			return '/profile/messenger?c=' . (int)$conversation_id;
		}
		return '/profile/conversation?id=' . (int)$conversation_id;
	}

	/**
	 * Add a message to this conversation.
	 * Creates a Message record linked to this conversation.
	 * Clears cnp_delete_time for all participants (resurfaces deleted conversations).
	 * Creates notifications for other participants (unless muted).
	 * Returns the new Message object.
	 */
	public function add_message($sender_user_id, $body, array $options = array()) {
		$message_type = isset($options['message_type'])
			? (string)$options['message_type'] : self::TYPE_TEXT;
		$attachments  = isset($options['attachments']) && is_array($options['attachments'])
			? $options['attachments'] : array();
		$remote_sender_address = isset($options['remote_sender_address'])
			? (string)$options['remote_sender_address'] : null;

		// Validate
		$body = trim((string)$body);
		if ($body === '' && !$attachments) {
			throw new ConversationException('Message body cannot be empty');
		}
		if (mb_strlen($body) > self::MAX_MESSAGE_LENGTH) {
			throw new ConversationException('Message exceeds maximum length of ' . self::MAX_MESSAGE_LENGTH . ' characters');
		}

		// Verify sender is a participant. A system message has no sender, and a
		// message that arrived from another instance is attributed to a remote
		// address rather than to a local row — neither has a membership to check.
		if ($sender_user_id !== null) {
			if (!$this->has_participant($sender_user_id)) {
				throw new ConversationException('You are not a participant in this conversation');
			}

			// Check blocks if block system exists
			if (class_exists('UserBlock')) {
				$participants = new MultiConversationParticipant(
					['conversation_id' => $this->key, 'deleted' => false]
				);
				foreach ($participants as $p) {
					$p_user_id = $p->get('cnp_usr_user_id');
					if ($p_user_id != $sender_user_id) {
						if (UserBlock::is_blocked($sender_user_id, $p_user_id)) {
							throw new ConversationException('Cannot message this user');
						}
					}
				}
			}
		}

		// Strip HTML tags from user messages (plain text only)
		$clean_body = strip_tags($body);

		// A sealed conversation needs its key before anything is written. The
		// sender just typed this, so their window is open by construction — but
		// a background writer (a deferred ingest) may not have one, and storing
		// the message in the clear because the key was inconvenient is exactly
		// what sealing is for.
		$sealed = $this->is_sealed();
		$dek = isset($options['dek']) ? $options['dek'] : null;
		if ($sealed && $dek === null) {
			$dek = $this->conversation_key();
			if ($dek === null) {
				throw new ConversationException(
					'This conversation is protected — unlock your vault to send in it.');
			}
		}

		// A quoted parent must belong to this same conversation — otherwise a
		// reply id would read a message out of a thread the sender is not in.
		$reply_to_id = null;
		if (!empty($options['reply_to_message_id'])) {
			$candidate = new Message((int)$options['reply_to_message_id'], TRUE);
			if ($candidate->key
				&& (int)$candidate->get('msg_cnv_conversation_id') === (int)$this->key) {
				$reply_to_id = (int)$candidate->key;
			}
		}

		// Create the message
		$message = new Message(NULL);
		$message->set('msg_cnv_conversation_id', $this->key);
		$message->set('msg_usr_user_id_sender', $sender_user_id);
		$message->set('msg_remote_sender_address', $remote_sender_address);
		$message->set('msg_guid', !empty($options['guid'])
			? (string)$options['guid'] : self::mint_guid());
		$message->set('msg_message_type', $message_type);
		$message->set('msg_reply_to_message_id', $reply_to_id);
		// On a sealed conversation the body is written by the sealing path
		// below, never as a plaintext column that a moment later gets
		// overwritten — the row is simply never stored holding the words.
		$message->set('msg_body', $sealed ? null : $clean_body);
		$message->set('msg_sent_time', gmdate('Y-m-d H:i:s'));
		$message->save();

		if ($sealed) {
			Message::sealBody($message->key, $dek, $clean_body);
			$message->load();
		}

		foreach ($attachments as $attachment) {
			if ($sealed) {
				ConversationSealing::sealAttachment($attachment, $dek);
			}
			MessageAttachment::attach($message, $attachment, $sealed);
		}

		// The conversation's own update time is what an inbox delta reads, so it
		// moves with every message including the system ones.
		$this->set('cnv_update_time', gmdate('Y-m-d H:i:s'));
		$this->save();

		// Resurface deleted conversations and create notifications
		$participants = new MultiConversationParticipant(
			['conversation_id' => $this->key]
		);

		require_once(PathHelper::getIncludePath('data/users_class.php'));
		$sender = $sender_user_id !== null ? new User($sender_user_id, TRUE) : null;
		$sender_label = $sender ? $sender->display_name()
			: ($remote_sender_address ?: 'Someone');

		// Guarded conversations keep message content out of every notification —
		// the member is told there is something to read, never what it says.
		$level = ProtectionLevel::normalize($this->get('cnv_protection_level'));
		$content_ok = ($level !== ProtectionLevel::GUARDED);
		$preview = $content_ok ? substr($clean_body, 0, 100) : 'Open Messages to read it.';
		if ($message_type === self::TYPE_SYSTEM) {
			$preview = $content_ok ? substr($clean_body, 0, 100) : '';
		}
		$title = ($message_type === self::TYPE_SYSTEM)
			? 'Update in ' . $this->display_title()
			: ($this->is_group()
				? $sender_label . ' in ' . $this->display_title()
				: 'New message from ' . $sender_label);

		foreach ($participants as $participant) {
			$p_user_id = $participant->get('cnp_usr_user_id');

			// Clear delete_time to resurface conversation
			if ($participant->get('cnp_delete_time')) {
				$participant->set('cnp_delete_time', null);
				$participant->save();
			}

			// Skip sender for notifications
			if ($sender_user_id !== null && $p_user_id == $sender_user_id) {
				continue;
			}

			// Create notification unless muted
			if (!$participant->get('cnp_is_muted')) {
				try {
					require_once(PathHelper::getIncludePath('data/notifications_class.php'));
					Notification::create_notification(
						$p_user_id,
						'message',
						$title,
						$preview,
						self::url_for($this->key),
						$sender_user_id
					);
				} catch (Exception $e) {
					// Notification system may not be installed — continue
				}
			}
		}

		// Invalidate sender's unread count cache
		$_SESSION['message_unread_count'] = null;

		$this->notify_message_event($message);

		return $message;
	}

	/**
	 * Record a membership or rename event as a message of its own.
	 *
	 * System messages ride the ordinary message funnel deliberately: they land in
	 * the same table, in the same order, and reach the same poll and the same
	 * notification fan-out, so no surface needs a second code path to show
	 * "Alice added Bob" in the right place.
	 */
	public function add_system_message($body, $dek = null) {
		return $this->add_message(null, $body,
			array('message_type' => self::TYPE_SYSTEM, 'dek' => $dek));
	}

	/**
	 * The key a membership or protection change will need for its own record of
	 * itself, resolved BEFORE the change happens.
	 *
	 * Two reasons the timing matters. Leaving a protected group revokes the
	 * leaver's key grant, so by the time "Alice left" is written there is no
	 * grant of theirs left to open the conversation with — the key has to be in
	 * hand first. And a change made from a locked session should refuse up
	 * front, with a sentence the member can act on, rather than half-applying
	 * and failing on the announcement.
	 *
	 * Null on a Standard conversation: there is nothing to resolve.
	 */
	public function change_key(): ?string {
		if (!$this->is_sealed()) {
			return null;
		}
		$dek = $this->conversation_key();
		if ($dek === null) {
			throw new ConversationException(
				'This conversation is protected — unlock your vault to change it.');
		}
		return $dek;
	}

	/**
	 * Announce that this conversation changed.
	 *
	 * A NOTIFY with nobody listening costs essentially nothing, and it is the one
	 * seam a future realtime service needs: it LISTENs on this channel and pushes
	 * over its own transport while the messenger's poll endpoint stays as the
	 * fallback. Nothing chat-flavored lives in core beyond this line.
	 */
	protected function notify_message_event(Message $message) {
		try {
			$payload = json_encode(array(
				'conversation_id' => (int)$this->key,
				'message_id'      => (int)$message->key,
				'sent_time'       => $message->get('msg_sent_time'),
			));
			$dblink = DbConnector::get_instance()->get_db_link();
			$q = $dblink->prepare('SELECT pg_notify(?, ?)');
			$q->execute(array(self::NOTIFY_CHANNEL, (string)$payload));
		} catch (Exception $e) {
			// A message that stored is delivered; a missed announcement is not a
			// send failure, and the poll transport does not depend on it.
			error_log('[Conversation] pg_notify failed: ' . $e->getMessage());
		}
	}

	/**
	 * Get unread conversation count for a user — lightweight COUNT query.
	 * A conversation is "unread" if it has messages newer than the participant's last_read_time.
	 */
	public static function get_unread_count($user_id) {
		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();
		$sql = "SELECT COUNT(*)
				FROM cnp_conversation_participants cnp
				JOIN cnv_conversations cnv ON cnv.cnv_conversation_id = cnp.cnp_cnv_conversation_id
				WHERE cnp.cnp_usr_user_id = ?
				  AND cnp.cnp_delete_time IS NULL
				  AND cnv.cnv_delete_time IS NULL
				  AND EXISTS (
				      SELECT 1 FROM msg_messages msg
				      WHERE msg.msg_cnv_conversation_id = cnp.cnp_cnv_conversation_id
				        AND msg.msg_delete_time IS NULL
				        AND msg.msg_message_type <> 'system'
				        AND (msg.msg_usr_user_id_sender IS NULL
				             OR msg.msg_usr_user_id_sender <> cnp.cnp_usr_user_id)
				        AND (cnp.cnp_last_read_time IS NULL OR msg.msg_sent_time > cnp.cnp_last_read_time)
				  )";
		$q = $dblink->prepare($sql);
		$q->execute([$user_id]);
		return (int)$q->fetchColumn();
	}

	/**
	 * Get the other participant in a 1:1 conversation.
	 * Returns User object or null for group conversations.
	 */
	public function get_other_participant($current_user_id) {
		require_once(PathHelper::getIncludePath('data/conversation_participants_class.php'));
		require_once(PathHelper::getIncludePath('data/users_class.php'));

		$participants = new MultiConversationParticipant(
			['conversation_id' => $this->key, 'deleted' => false]
		);
		$participants->load();

		if ($participants->count() != 2) {
			return null; // Group conversation
		}

		foreach ($participants as $p) {
			if ($p->get('cnp_usr_user_id') != $current_user_id) {
				return new User($p->get('cnp_usr_user_id'), TRUE);
			}
		}

		return null;
	}

	/**
	 * Check if a user is a participant in this conversation.
	 */
	public function has_participant($user_id) {
		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();
		$sql = "SELECT COUNT(*) FROM cnp_conversation_participants
				WHERE cnp_cnv_conversation_id = ? AND cnp_usr_user_id = ?";
		$q = $dblink->prepare($sql);
		$q->execute([$this->key, $user_id]);
		return (int)$q->fetchColumn() > 0;
	}

	// ------------------------------------------------------------------
	// Protection level
	//
	// Standard is today's behaviour: plaintext rows the server manages. Private
	// seals message bodies and attachment bytes at rest under one key per
	// conversation, wrapped to each participant. Guarded is Private with the
	// doors guarded — no message content in notifications, the AI pinned to
	// local models, and no unsealed federation.
	// ------------------------------------------------------------------

	/** The rungs a conversation may sit on. Fortress is deliberately not one. */
	const LEVELS = array(ProtectionLevel::STANDARD, ProtectionLevel::PRIVATE_, ProtectionLevel::GUARDED);

	/** This conversation's level, always a real rung. */
	public function protection_level(): string {
		return ProtectionLevel::normalize($this->get('cnv_protection_level'));
	}

	/**
	 * Protection only tightens — enforced at the column itself, so no surface
	 * (the generic REST PUT included) can lower a conversation below a rung it
	 * has reached. raise() is the ceremony that moves it up; this is the lock
	 * on the door, and it is what makes the one-way rule an invariant rather
	 * than a convention raise() alone follows.
	 */
	function set($key, $value, $check_existance = TRUE) {
		if ($key === 'cnv_protection_level' && $this->key !== NULL) {
			$current = $this->protection_level();
			if (!ProtectionLevel::isAtLeast(ProtectionLevel::normalize($value), $current)) {
				throw new ConversationException(
					'Protection can be raised but not lowered. This conversation is already '
					. ProtectionLevel::label($current) . '.');
			}
		}
		return parent::set($key, $value, $check_existance);
	}

	/** Is the content of this conversation ciphertext at rest? */
	public function is_sealed(): bool {
		return ProtectionLevel::isAtLeast($this->protection_level(), ProtectionLevel::PRIVATE_);
	}

	/** Does this conversation keep message content out of notifications? */
	public function is_guarded(): bool {
		return $this->protection_level() === ProtectionLevel::GUARDED;
	}

	/**
	 * The key this conversation's content is sealed under, if anyone present can
	 * supply it. Null means locked — every caller treats that as "ask them to
	 * unlock", never as an error and never as a reason to store plaintext.
	 */
	public function conversation_key(): ?string {
		return ConversationKeyGrant::openConversationKey($this->key);
	}

	/**
	 * Local participants who cannot be given a key because they have no vault.
	 *
	 * The raise names them: "Bob hasn't set up protection yet" is actionable,
	 * where a bare refusal is not.
	 *
	 * @return array<int,string> user id => display name
	 */
	public function members_without_vault(): array {
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
		require_once(PathHelper::getIncludePath('data/users_class.php'));

		$out = array();
		foreach ($this->participant_user_ids() as $user_id) {
			if (UserEncryptionVault::loadForUser($user_id) === null) {
				$user = new User($user_id, TRUE);
				$out[$user_id] = $user->key ? $user->display_name() : ('Member #' . $user_id);
			}
		}
		return $out;
	}

	/**
	 * Raise this conversation to a stronger protection level.
	 *
	 * Levels only tighten. One-way is the platform's rule for derived tiers, and
	 * for a shared room it also settles a consent problem: lowering would let
	 * one member expose everyone else's history.
	 *
	 * Raising to Private or Guarded re-seals the whole history in one pass, so a
	 * conversation is never half-protected — the promise is about what a stolen
	 * disk yields, and a plaintext backlog would break it silently.
	 *
	 * @param string $level  the rung to move to
	 * @param int    $actor_user_id  a participant; any of them may raise
	 * @throws ConversationException with something the member can act on
	 */
	public function raise(string $level, $actor_user_id): void {
		$level = ProtectionLevel::normalize($level);
		if (!in_array($level, self::LEVELS, true)) {
			throw new ConversationException('That is not a protection level a conversation can have.');
		}
		if (!$this->has_participant($actor_user_id)) {
			throw new ConversationException('You are not in this conversation.');
		}

		$current = $this->protection_level();
		if ($level === $current) {
			return;
		}
		if (!ProtectionLevel::isAtLeast($level, $current)) {
			throw new ConversationException(
				'Protection can be raised but not lowered. This conversation is already '
				. ProtectionLevel::label($current) . '.');
		}

		// Guarded is Private plus door rules, so a Standard conversation going
		// straight to Guarded still has to seal its history on the way.
		$needs_sealing = ProtectionLevel::isAtLeast($level, ProtectionLevel::PRIVATE_)
			&& !$this->is_sealed();

		if ($needs_sealing) {
			$missing = $this->members_without_vault();
			if ($missing) {
				throw new ConversationException(
					'Everyone in this conversation needs protection set up first. Waiting on: '
					. implode(', ', $missing) . '.');
			}
			// The freshly minted key seals this change's own record of itself,
			// so a member can protect a conversation without unlocking first —
			// sealing needs public keys only.
			$dek = $this->seal_history();
		} else {
			$dek = $this->change_key();
		}

		$this->set('cnv_protection_level', $level);
		$this->save();

		require_once(PathHelper::getIncludePath('data/users_class.php'));
		$actor = new User($actor_user_id, TRUE);
		$this->add_system_message($actor->display_name() . ' set this conversation to '
			. ProtectionLevel::label($level), $dek);
	}

	/**
	 * Mint this conversation's key, hand it to every participant, and seal
	 * everything already said under it.
	 *
	 * Ordering is deliberate: grants first, so a crash mid-sweep leaves a key
	 * every member can still open, and the sweep is re-runnable. Each message is
	 * sealed on its own — a body already sealed is skipped — so re-running
	 * finishes the job rather than double-sealing.
	 */
	protected function seal_history(): string {
		require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
		require_once(PathHelper::getIncludePath('data/conversation_key_grants_class.php'));

		$crypto = new VaultCrypto();
		$dek = $crypto->newItemDek();

		foreach ($this->participant_user_ids() as $user_id) {
			if (ConversationKeyGrant::grant($this->key, $user_id, $dek) === null) {
				throw new ConversationException(
					'A member of this conversation has no protection set up.');
			}
		}

		$messages = new MultiMessage(array('conversation_id' => $this->key));
		foreach ($messages as $message) {
			if ($message->rowIsSealed()) {
				continue;
			}
			$body = $message->get('msg_body');
			Message::sealBody($message->key, $dek, $body);

			$attachments = new MultiMessageAttachment(
				array('message_id' => (int)$message->key, 'deleted' => false));
			foreach ($attachments as $attachment) {
				if ($attachment->get('msa_is_sealed')) {
					continue;
				}
				$file = new File((int)$attachment->get('msa_fil_file_id'), TRUE);
				if (!$file->key) {
					continue;
				}
				ConversationSealing::sealAttachment($file, $dek);
				$attachment->set('msa_is_sealed', true);
				$attachment->save();
			}
		}

		return $dek;
	}

	// ------------------------------------------------------------------
	// Group membership
	//
	// A conversation is a group when it holds more than two people or carries a
	// name of its own. Membership rows are removed outright on leave/remove —
	// cnp_delete_time means "I cleared this out of my inbox" and a new message
	// undoes it, which is not what leaving a group means.
	// ------------------------------------------------------------------

	/** @var array|null memoized participant rows — see participants() */
	protected $participants_cache = null;
	/** @var array|null memoized remote peers — see remote_peers() */
	protected $remote_peers_cache = null;

	/**
	 * Every participant row, in join order.
	 *
	 * Memoized for the life of this instance. Describing one conversation asks
	 * this three times over (who is in it, is it a group, what is it called),
	 * and the inbox describes up to sixty of them in a single render — the
	 * difference between one query per conversation and three is the difference
	 * between a list that opens and one that crawls.
	 *
	 * Every membership change clears it, so nothing reads a stale room.
	 */
	public function participants() {
		if ($this->participants_cache !== null) {
			return $this->participants_cache;
		}
		$rows = new MultiConversationParticipant(
			array('conversation_id' => $this->key),
			array('cnp_conversation_participant_id' => 'ASC')
		);
		$out = array();
		foreach ($rows as $row) {
			$out[] = $row;
		}
		return $this->participants_cache = $out;
	}

	/** Forget the memoized membership — anything that changes it calls this. */
	protected function forget_membership(): void {
		$this->participants_cache = null;
		$this->remote_peers_cache = null;
	}

	/** Just the user ids, for membership maths. */
	public function participant_user_ids(): array {
		$ids = array();
		foreach ($this->participants() as $p) {
			$ids[] = (int)$p->get('cnp_usr_user_id');
		}
		return $ids;
	}

	/** The caller's own participant row, or null when they are not a member. */
	public function participant_row($user_id) {
		foreach ($this->participants() as $row) {
			if ((int)$row->get('cnp_usr_user_id') === (int)$user_id) {
				return $row;
			}
		}
		return null;
	}

	/** More than two people, or a name — either makes this a group. */
	public function is_group(): bool {
		if ($this->get('cnv_subject')) {
			return true;
		}
		return count($this->participants()) > 2;
	}

	/** May this member manage the group's name and membership? */
	public function is_admin($user_id): bool {
		$row = $this->participant_row($user_id);
		return $row ? (bool)$row->get('cnp_is_admin') : false;
	}

	/**
	 * Refuse anything but an admin. A 1:1 conversation has no membership to
	 * manage, so it refuses outright rather than pretending it has admins.
	 */
	protected function assert_group_admin($actor_user_id) {
		if (!$this->is_group()) {
			throw new ConversationException('This is not a group conversation.');
		}
		if (!$this->is_admin($actor_user_id)) {
			throw new ConversationException('Only a group admin can do that.');
		}
	}

	/**
	 * Add a member and announce it. Returns FALSE when they were already in.
	 */
	public function add_participant($user_id, $actor_user_id) {
		$this->assert_group_admin($actor_user_id);

		$user_id = (int)$user_id;
		if ($this->has_participant($user_id)) {
			return false;
		}

		// get_setting has no default parameter — the fallback lives here, so a
		// missing or blanked setting means the factory cap, never no cap.
		$max = (int)Globalvars::get_instance()->get_setting('messenger_max_group_size', true, true);
		if ($max <= 0) {
			$max = 32;
		}
		if (count($this->participant_user_ids()) >= $max) {
			throw new ConversationException('This group is full (' . $max . ' members).');
		}

		require_once(PathHelper::getIncludePath('data/users_class.php'));
		if (!User::check_if_exists($user_id)) {
			throw new ConversationException('That member does not exist.');
		}

		// On a sealed conversation the new member needs the key before they are
		// in the room, and getting it needs a present member to open it. Doing
		// this first means a member who cannot be given the key is not added at
		// all, rather than added to a conversation they cannot read.
		$dek = $this->change_key();
		if ($dek !== null) {
			$added_user = new User($user_id, TRUE);
			if (ConversationKeyGrant::grant($this->key, $user_id, $dek) === null) {
				throw new ConversationException(
					($added_user->key ? $added_user->display_name() : 'That member')
					. ' has not set up protection yet, so they cannot be added to a protected conversation.');
			}
		}

		$participant = new ConversationParticipant(NULL);
		$participant->set('cnp_cnv_conversation_id', $this->key);
		$participant->set('cnp_usr_user_id', $user_id);
		$participant->set('cnp_create_time', gmdate('Y-m-d H:i:s'));
		$participant->save();
		$this->forget_membership();

		$actor = new User($actor_user_id, TRUE);
		$added = new User($user_id, TRUE);
		$this->add_system_message($actor->display_name() . ' added ' . $added->display_name(), $dek);

		return true;
	}

	/**
	 * Remove a member and announce it. An admin removes anyone; leave() is the
	 * self-service door that needs no admin rights.
	 */
	public function remove_participant($user_id, $actor_user_id) {
		$user_id = (int)$user_id;
		$is_self = ($user_id === (int)$actor_user_id);

		if (!$is_self) {
			$this->assert_group_admin($actor_user_id);
		} elseif (!$this->is_group()) {
			throw new ConversationException('This is not a group conversation.');
		}

		$row = $this->participant_row($user_id);
		if (!$row) {
			return false;
		}

		require_once(PathHelper::getIncludePath('data/users_class.php'));
		$subject = new User($user_id, TRUE);
		$actor   = new User($actor_user_id, TRUE);

		// Before the grant goes: someone leaving a protected conversation still
		// has to be able to write "Alice left" into it, and a moment later they
		// no longer hold a key that opens it.
		$dek = $this->change_key();

		$row->permanent_delete();
		$this->forget_membership();

		// Their key grant goes with their membership: from here the server will
		// not open this conversation for them. What they have already read, they
		// have already read — revocation is about what happens next.
		ConversationKeyGrant::revoke($this->key, $user_id);

		// A group that has lost every admin promotes its longest-standing member,
		// so membership never becomes unmanageable.
		$this->ensure_an_admin();

		$this->add_system_message($is_self
			? $subject->display_name() . ' left'
			: $actor->display_name() . ' removed ' . $subject->display_name(), $dek);

		return true;
	}

	/** A member showing themselves out. */
	public function leave($user_id) {
		return $this->remove_participant($user_id, $user_id);
	}

	/** Rename the group and announce it; an empty name clears it. */
	public function rename($subject, $actor_user_id) {
		$this->assert_group_admin($actor_user_id);

		$subject = trim(strip_tags((string)$subject));
		if (mb_strlen($subject) > 255) {
			$subject = mb_substr($subject, 0, 255);
		}

		$dek = $this->change_key();

		$this->set('cnv_subject', $subject !== '' ? $subject : null);
		$this->save();

		require_once(PathHelper::getIncludePath('data/users_class.php'));
		$actor = new User($actor_user_id, TRUE);
		$this->add_system_message($subject !== ''
			? $actor->display_name() . ' named the group "' . $subject . '"'
			: $actor->display_name() . ' removed the group name', $dek);

		return true;
	}

	/** Grant or revoke a member's admin rights. */
	public function set_admin($user_id, $is_admin, $actor_user_id) {
		$this->assert_group_admin($actor_user_id);

		$row = $this->participant_row($user_id);
		if (!$row) {
			throw new ConversationException('That member is not in this group.');
		}
		$row->set('cnp_is_admin', (bool)$is_admin);
		$row->save();
		$this->forget_membership();

		$this->ensure_an_admin();
		return true;
	}

	/** Promote the longest-standing member when a group is left with no admin. */
	protected function ensure_an_admin() {
		$rows = $this->participants();
		$first = null;
		foreach ($rows as $row) {
			if ($row->get('cnp_is_admin')) {
				return;
			}
			if ($first === null) {
				$first = $row;
			}
		}
		if ($first) {
			$first->set('cnp_is_admin', true);
			$first->save();
			$this->forget_membership();
		}
	}

	/**
	 * What this conversation is called on one member's screen: the group name,
	 * or — for an unnamed thread — the other people in it.
	 */
	public function title_for($current_user_id) {
		if ($this->get('cnv_subject')) {
			return $this->get('cnv_subject');
		}

		require_once(PathHelper::getIncludePath('data/users_class.php'));
		$names = array();
		foreach ($this->participants() as $p) {
			$p_user_id = (int)$p->get('cnp_usr_user_id');
			if ($p_user_id === (int)$current_user_id) {
				continue;
			}
			$user = new User($p_user_id, TRUE);
			$names[] = $user->display_name();
		}

		// Someone on another instance is in the room too, and a thread named
		// "Just you" because they have no account here would be wrong.
		foreach ($this->remote_peers() as $address => $peer_name) {
			$names[] = $peer_name;
		}

		if (!$names) {
			return 'Just you';
		}
		if (count($names) <= 3) {
			return implode(', ', $names);
		}
		return implode(', ', array_slice($names, 0, 3)) . ' and ' . (count($names) - 3) . ' others';
	}

	function display_title() {
		return $this->get('cnv_subject') ?: 'Conversation #' . $this->key;
	}
}

class MultiConversation extends SystemMultiBase {
	protected static $model_class = 'Conversation';

	/**
	 * Custom query for inbox — uses JOIN LATERAL to fetch latest message per conversation.
	 * Does not use _get_resultsv2() because we need the lateral join.
	 */
	protected function getMultiResults($only_count = false, $debug = false) {
		$bind_params = [];
		$where_clauses = [];

		// Base: conversations the user participates in
		if (isset($this->options['participant_user_id'])) {
			$participant_user_id = $this->options['participant_user_id'];
		} else {
			// Fall back to standard query if no participant filter
			return $this->getStandardResults($only_count, $debug);
		}

		$where_clauses[] = "cnp.cnp_usr_user_id = ?";
		$bind_params[] = [$participant_user_id, PDO::PARAM_INT];

		// Participant not deleted
		$where_clauses[] = "cnp.cnp_delete_time IS NULL";

		// Conversation not deleted
		if (isset($this->options['deleted'])) {
			if ($this->options['deleted']) {
				$where_clauses[] = "cnv.cnv_delete_time IS NOT NULL";
			} else {
				$where_clauses[] = "cnv.cnv_delete_time IS NULL";
			}
		} else {
			$where_clauses[] = "cnv.cnv_delete_time IS NULL";
		}

		// Inbox delta: only conversations touched since the caller's cursor. The
		// poll asks for this so a quiet inbox costs one indexed comparison.
		if (!empty($this->options['activity_since'])) {
			$where_clauses[] = "COALESCE(cnv.cnv_update_time, cnv.cnv_create_time) > ?";
			$bind_params[] = [(string)$this->options['activity_since'], PDO::PARAM_STR];
		}

		$where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

		if ($only_count) {
			$sql = "SELECT COUNT(*)
					FROM cnv_conversations cnv
					JOIN cnp_conversation_participants cnp
					  ON cnp.cnp_cnv_conversation_id = cnv.cnv_conversation_id
					$where_sql";

			if ($debug) { echo "COUNT SQL: $sql<br>\n"; }

			$q = DbConnector::GetPreparedStatement($sql);
			foreach ($bind_params as $index => $param) {
				$q->bindValue($index + 1, $param[0], $param[1]);
			}
			$q->execute();
			return $q->fetchColumn();
		}

		$limit_offset_sql = $this->generate_limit_and_offset(false);

		// LEFT JOIN LATERAL, not an inner one: a group that has just been created
		// holds no message yet and must still appear in its members' inbox.
		$sql = "SELECT cnv.cnv_conversation_id, cnv.cnv_subject, cnv.cnv_guid,
				       cnv.cnv_protection_level, cnv.cnv_create_time,
				       cnv.cnv_update_time, cnv.cnv_delete_time,
				       latest.msg_sent_time AS latest_message_time,
				       latest.msg_body AS latest_message_body,
				       latest.msg_content_sealed AS latest_message_sealed,
				       latest.msg_message_type AS latest_message_type,
				       latest.msg_usr_user_id_sender AS latest_message_sender_id,
				       latest.msg_message_id AS latest_message_id,
				       cnp.cnp_last_read_time, cnp.cnp_is_muted, cnp.cnp_is_admin,
				       (SELECT COUNT(*) FROM msg_messages unread
				         WHERE unread.msg_cnv_conversation_id = cnv.cnv_conversation_id
				           AND unread.msg_delete_time IS NULL
				           AND unread.msg_message_type <> 'system'
				           AND (unread.msg_usr_user_id_sender IS NULL
				                OR unread.msg_usr_user_id_sender <> cnp.cnp_usr_user_id)
				           AND (cnp.cnp_last_read_time IS NULL
				                OR unread.msg_sent_time > cnp.cnp_last_read_time)
				       ) AS unread_count
				FROM cnv_conversations cnv
				JOIN cnp_conversation_participants cnp
				  ON cnp.cnp_cnv_conversation_id = cnv.cnv_conversation_id
				LEFT JOIN LATERAL (
				    SELECT msg_message_id, msg_sent_time, msg_body, msg_content_sealed,
				           msg_message_type, msg_usr_user_id_sender
				    FROM msg_messages
				    WHERE msg_cnv_conversation_id = cnv.cnv_conversation_id
				      AND msg_delete_time IS NULL
				    ORDER BY msg_sent_time DESC, msg_message_id DESC
				    LIMIT 1
				) latest ON true
				$where_sql
				ORDER BY COALESCE(latest.msg_sent_time, cnv.cnv_create_time) DESC
				$limit_offset_sql";

		if ($debug) { echo "SQL: $sql<br>\n"; }

		$q = DbConnector::GetPreparedStatement($sql);
		foreach ($bind_params as $index => $param) {
			$q->bindValue($index + 1, $param[0], $param[1]);
		}
		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);
		return $q;
	}

	/**
	 * Standard query for admin/non-inbox use cases.
	 */
	protected function getStandardResults($only_count = false, $debug = false) {
		$filters = [];


		return $this->_get_resultsv2('cnv_conversations', $filters, $this->order_by, $only_count, $debug);
	}

	/**
	 * Override load to handle the lateral join extra columns.
	 * Stores extra inbox data on each Conversation object.
	 */
	function load($debug = false) {
		$this->clear();
		if (!$this->loadable) {
			throw new SystemBaseException('This MultiBase was explicitly set unloaded with $options === NULL');
		}
		$this->loaded = TRUE;

		$q = $this->getMultiResults(false, $debug);

		foreach ($q->fetchAll() as $row) {
			$conversation = new Conversation($row->cnv_conversation_id);
			$conversation->load_from_data($row, array_keys(Conversation::$field_specifications));

			// Store extra inbox data as transient properties
			if (property_exists($row, 'cnp_last_read_time')) {
				$conversation->latest_message_time = $row->latest_message_time;
				$conversation->latest_message_body = $this->previewBody($row);
				$conversation->latest_message_sealed = !empty($row->latest_message_sealed);
				$conversation->latest_message_type = $row->latest_message_type ?? null;
				$conversation->latest_message_id = $row->latest_message_id ?? null;
				$conversation->latest_message_sender_id = $row->latest_message_sender_id;
				$conversation->cnp_last_read_time = $row->cnp_last_read_time;
				$conversation->cnp_is_muted = $row->cnp_is_muted;
				$conversation->cnp_is_admin = $row->cnp_is_admin ?? false;
				$conversation->unread_count = isset($row->unread_count) ? (int)$row->unread_count : 0;
			}

			$this->add($conversation);
		}
	}

	/**
	 * What a conversation list may show for the latest message. A sealed body
	 * is resolved through the model RIGHT HERE — the words while a participant's
	 * window is open, a stand-in while locked — because this query is what every
	 * inbox surface reads, and raw sealed bytes must never leave the model layer
	 * dressed up as a preview.
	 */
	protected function previewBody($row): ?string {
		$body = $row->latest_message_body;
		if (empty($row->latest_message_sealed) || $body === null || $body === '') {
			return $body;
		}
		try {
			return Message::decryptSealedFieldStatic('msg_body', $body, array(
				'msg_content_sealed'      => $row->latest_message_sealed,
				'msg_cnv_conversation_id' => $row->cnv_conversation_id,
				'msg_message_id'          => $row->latest_message_id,
			));
		} catch (VaultLockedException $e) {
			return 'Protected message';
		}
	}
}
