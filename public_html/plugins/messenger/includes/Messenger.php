<?php
/**
 * Messenger — the shared middle of the messages app.
 *
 * Every messenger action asks the same three questions before it does anything
 * (is the feature on, who is asking, are they in this conversation), and the
 * poll, the send and the first page render all describe a conversation and a
 * message to the browser in exactly the same shape. Both live here so the
 * answers cannot drift between endpoints.
 *
 * @version 1.1.0
 */

/** A refusal with a message the member can read. */
class MessengerRefusal extends Exception {}

class Messenger {

	/** How many messages one thread page carries. */
	const PAGE_SIZE = 50;

	/** How many conversations the inbox loads at once. */
	const INBOX_SIZE = 60;

	/** @var array<int,array> per-request cache of the people we have described */
	protected static $people = array();

	// ------------------------------------------------------------------
	// Gates
	// ------------------------------------------------------------------

	/**
	 * The signed-in member's id, or a refusal.
	 *
	 * @throws MessengerRefusal when nobody is signed in or the app is switched off
	 */
	public static function requireMember(): int {
		$session = SessionControl::get_instance();
		$user_id = (int)$session->get_user_id();
		if (!$user_id) {
			throw new MessengerRefusal('Sign in required.');
		}
		$settings = Globalvars::get_instance();
		// Two switches, both honoured: messaging_active is the platform's
		// member-messaging switch (the legacy views and the iOS actions read it
		// too), messenger_active is this app's own.
		if (!$settings->get_setting('messaging_active', true, true)
			|| !$settings->get_setting('messenger_active', true, true)) {
			throw new MessengerRefusal('Messages are turned off on this site.');
		}
		return $user_id;
	}

	/**
	 * Load a conversation the caller is actually in.
	 *
	 * The same refusal for "no such conversation" and "not yours" on purpose:
	 * telling a stranger which conversation ids exist is itself a disclosure.
	 *
	 * @throws MessengerRefusal
	 */
	public static function conversationFor($conversation_id, int $user_id): Conversation {
		$conversation_id = (int)$conversation_id;
		if ($conversation_id <= 0 || !Conversation::check_if_exists($conversation_id)) {
			throw new MessengerRefusal('Conversation not found.');
		}
		$conversation = new Conversation($conversation_id, TRUE);
		if ($conversation->get('cnv_delete_time') || !$conversation->has_participant($user_id)) {
			throw new MessengerRefusal('Conversation not found.');
		}
		return $conversation;
	}

	// ------------------------------------------------------------------
	// Settings
	// ------------------------------------------------------------------

	/**
	 * One integer knob, with its factory default when unset or blank.
	 *
	 * get_setting has no default parameter — its second argument toggles
	 * calculated values — so the fallback lives here, and it must match what
	 * the server-side consumer of the same knob falls back to.
	 */
	protected static function settingInt(string $name, int $default): int {
		$value = (int)Globalvars::get_instance()->get_setting($name, true, true);
		return $value > 0 ? $value : $default;
	}

	/** The knobs the browser needs, resolved once. */
	public static function clientSettings(): array {
		return array(
			'poll_thread_ms' => self::settingInt('messenger_poll_thread_seconds', 3) * 1000,
			'poll_list_ms'   => self::settingInt('messenger_poll_list_seconds', 12) * 1000,
			'max_group_size' => max(2, self::settingInt('messenger_max_group_size', 32)),
			'max_attachment_mb' => self::settingInt('messenger_max_attachment_mb', 25),
			'max_message_length' => Conversation::MAX_MESSAGE_LENGTH,
			'default_level'  => ProtectionLevel::normalize(
				Globalvars::get_instance()->get_setting('messenger_default_protection_level', true, true)),
		);
	}

	// ------------------------------------------------------------------
	// Describing people
	// ------------------------------------------------------------------

	/** Name and picture for one member, cached for the request. */
	public static function person($user_id): array {
		$user_id = (int)$user_id;
		if (isset(self::$people[$user_id])) {
			return self::$people[$user_id];
		}
		$out = array('id' => $user_id, 'name' => 'Unknown', 'avatar' => '/assets/images/blank-avatar.png');
		try {
			$user = new User($user_id, TRUE);
			if ($user->key) {
				$out['name']   = $user->display_name();
				$out['avatar'] = $user->get_picture_link('avatar');
			}
		} catch (Exception $e) {
			// A deleted or unreadable member still needs a bubble to sit under.
		}
		return self::$people[$user_id] = $out;
	}

	// ------------------------------------------------------------------
	// Describing conversations
	// ------------------------------------------------------------------

	/**
	 * One row of the conversation list.
	 *
	 * $conversation may be a plain Conversation or one loaded through
	 * MultiConversation (which carries the viewer's read state and the latest
	 * message as transient properties). Both are handled so the poll and the
	 * page render describe a conversation identically.
	 */
	public static function conversationPayload(Conversation $conversation, int $user_id): array {
		$id = (int)$conversation->key;

		$participants = array();
		$is_admin = false;
		foreach ($conversation->participants() as $p) {
			$p_user_id = (int)$p->get('cnp_usr_user_id');
			$participants[] = array(
				'user_id'  => $p_user_id,
				'name'     => self::person($p_user_id)['name'],
				'avatar'   => self::person($p_user_id)['avatar'],
				'is_admin' => (bool)$p->get('cnp_is_admin'),
				'is_me'    => $p_user_id === $user_id,
				'last_read_time' => $p->get('cnp_last_read_time') ?: null,
			);
			if ($p_user_id === $user_id) {
				$is_admin = (bool)$p->get('cnp_is_admin');
			}
		}

		// People in the room who are not on this deployment. They have no user
		// id and never will — a remote peer is an address, not an account here.
		$remote = array();
		foreach ($conversation->remote_peers() as $address => $peer_name) {
			$remote[] = array('address' => $address, 'name' => $peer_name);
			$participants[] = array(
				'user_id'  => null,
				'address'  => $address,
				'name'     => $peer_name,
				'avatar'   => null,
				'is_admin' => false,
				'is_me'    => false,
				'is_remote' => true,
				'last_read_time' => null,
			);
		}

		// MultiConversation resolves a sealed latest body through the model —
		// the words while a window is open, the locked stand-in otherwise — so
		// what arrives here is always showable, never raw sealed bytes.
		$latest_body   = property_exists($conversation, 'latest_message_body') ? $conversation->latest_message_body : null;
		$latest_time   = property_exists($conversation, 'latest_message_time') ? $conversation->latest_message_time : null;
		$latest_type   = property_exists($conversation, 'latest_message_type') ? $conversation->latest_message_type : null;
		$latest_sender = property_exists($conversation, 'latest_message_sender_id') ? $conversation->latest_message_sender_id : null;
		$unread        = property_exists($conversation, 'unread_count') ? (int)$conversation->unread_count : 0;
		$muted         = property_exists($conversation, 'cnp_is_muted')
			? self::isTrue($conversation->cnp_is_muted)
			: false;

		if (!property_exists($conversation, 'cnp_last_read_time')) {
			// Loaded directly rather than through the inbox query, so the
			// viewer's own state and the latest message are not attached. Fill
			// them in here: a caller must never have to know which way a
			// conversation arrived, and a row that answered "no messages yet"
			// because of it would be wrong in the list.
			$row = $conversation->participant_row($user_id);
			$muted = $row ? self::isTrue($row->get('cnp_is_muted')) : false;

			$latest = self::latestMessage($conversation);
			if ($latest) {
				$latest_body   = self::bodyOrLocked($latest);
				$latest_time   = $latest->get('msg_sent_time');
				$latest_type   = $latest->get('msg_message_type');
				$latest_sender = $latest->get('msg_usr_user_id_sender');
				$latest_sender = ($latest_sender === null || $latest_sender === '')
					? null : (int)$latest_sender;
			}
			$unread = self::unreadCount($conversation, $user_id);
		}

		return array(
			'id'      => $id,
			'guid'    => $conversation->get('cnv_guid'),
			'title'   => $conversation->title_for($user_id),
			'subject' => $conversation->get('cnv_subject'),
			'is_group' => $conversation->is_group(),
			'is_federated' => count($remote) > 0,
			'remote_peers' => $remote,
			'protection_level' => ProtectionLevel::normalize($conversation->get('cnv_protection_level')),
			'protection_label' => ProtectionLevel::label($conversation->get('cnv_protection_level')),
			'avatar'  => self::conversationAvatar($conversation, $user_id),
			'unread'  => $unread,
			'is_muted' => $muted,
			'is_admin' => $is_admin,
			'participants' => $participants,
			'last_message' => $latest_time === null ? null : array(
				'excerpt' => self::excerpt((string)$latest_body),
				'time'    => $latest_time,
				'type'    => $latest_type ?: Conversation::TYPE_TEXT,
				'sender_id' => $latest_sender !== null ? (int)$latest_sender : null,
				'sender_name' => $latest_sender !== null ? self::person($latest_sender)['name'] : null,
				'is_mine' => $latest_sender !== null && (int)$latest_sender === $user_id,
			),
		);
	}

	/**
	 * The image variant a picture in a thread is shown at.
	 *
	 * Sizes are declared by the active theme, so the messenger asks rather than
	 * assumes: a deployment whose theme does not declare the reading-width size
	 * shows the original instead of linking at a variant that will never exist.
	 */
	public static function thumbnailSize(): string {
		require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));
		return ImageSizeRegistry::has_size('content') ? 'content' : 'original';
	}

	/** The newest message still standing in a conversation, or null. */
	public static function latestMessage(Conversation $conversation) {
		$rows = new MultiMessage(
			array('conversation_id' => (int)$conversation->key, 'deleted' => false),
			array('msg_message_id' => 'DESC'), 1
		);
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}

	/** How many messages in this conversation one member has not read. */
	public static function unreadCount(Conversation $conversation, int $user_id): int {
		$sql = "SELECT COUNT(*)
				FROM msg_messages msg
				JOIN cnp_conversation_participants cnp
				  ON cnp.cnp_cnv_conversation_id = msg.msg_cnv_conversation_id
				WHERE msg.msg_cnv_conversation_id = ?
				  AND cnp.cnp_usr_user_id = ?
				  AND cnp.cnp_delete_time IS NULL
				  AND msg.msg_delete_time IS NULL
				  AND msg.msg_message_type <> 'system'
				  AND (msg.msg_usr_user_id_sender IS NULL
				       OR msg.msg_usr_user_id_sender <> cnp.cnp_usr_user_id)
				  AND (cnp.cnp_last_read_time IS NULL
				       OR msg.msg_sent_time > cnp.cnp_last_read_time)";
		$q = DbConnector::get_instance()->get_db_link()->prepare($sql);
		$q->execute(array((int)$conversation->key, $user_id));
		return (int)$q->fetchColumn();
	}

	/**
	 * The picture beside a conversation: the group's own photo, the other
	 * person's picture in a 1:1, and nothing for an unnamed group (the UI draws
	 * initials instead).
	 */
	protected static function conversationAvatar(Conversation $conversation, int $user_id): ?string {
		require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
		$photos = new MultiEntityPhoto(
			array('entity_type' => 'conversation', 'entity_id' => (int)$conversation->key, 'deleted' => false),
			array('eph_sort_order' => 'ASC'), 1
		);
		foreach ($photos as $photo) {
			try {
				$file = new File((int)$photo->get('eph_fil_file_id'), TRUE);
				if ($file->key) {
					return $file->get_url('avatar');
				}
			} catch (Exception $e) {
				// Fall through to the participant picture.
			}
		}

		if (!$conversation->is_group()) {
			$other = $conversation->get_other_participant($user_id);
			if ($other) {
				return $other->get_picture_link('avatar');
			}
		}
		return null;
	}

	// ------------------------------------------------------------------
	// Describing messages
	// ------------------------------------------------------------------

	/**
	 * Turn a page of message rows into what the thread draws.
	 *
	 * Reactions and attachments are fetched for the whole page in one query
	 * each — a bubble never runs a query of its own.
	 *
	 * @param Message[] $messages
	 */
	public static function messagesPayload(array $messages, int $user_id): array {
		if (!$messages) {
			return array();
		}

		$ids = array();
		foreach ($messages as $message) {
			$ids[] = (int)$message->key;
		}

		$reactions   = self::reactionsFor($ids, $user_id);
		$attachments = self::attachmentsFor($ids);
		$parents     = self::parentsFor($messages);

		$out = array();
		foreach ($messages as $message) {
			$id        = (int)$message->key;
			$sender_id = $message->get('msg_usr_user_id_sender');
			$sender_id = $sender_id === null || $sender_id === '' ? null : (int)$sender_id;
			$is_deleted = (bool)$message->get('msg_delete_time');
			$type      = $message->get('msg_message_type') ?: Conversation::TYPE_TEXT;

			// A sealed body with nobody present to open it reads as locked — the
			// UI shows a one-tap unlock in place of the words, and the
			// ciphertext never leaves the server.
			$is_locked = false;
			$body = '';
			if (!$is_deleted) {
				try {
					$body = (string)$message->get('msg_body');
				} catch (VaultLockedException $e) {
					$is_locked = true;
				}
			}

			$row = array(
				'id'        => $id,
				'guid'      => $message->get('msg_guid'),
				'type'      => $type,
				'body'      => $body,
				'is_locked' => $is_locked,
				'time'      => $message->get('msg_sent_time'),
				'sender_id' => $sender_id,
				'sender_name' => $sender_id !== null
					? self::person($sender_id)['name']
					: ($message->get('msg_remote_sender_address') ?: null),
				'sender_avatar' => $sender_id !== null ? self::person($sender_id)['avatar'] : null,
				'remote_address' => $message->get('msg_remote_sender_address'),
				// Only meaningful on a message that has to leave this instance:
				// locally, stored IS delivered and there is nothing to show.
				'delivery_state' => $message->get('msg_delivery_state') ?: 'local',
				'is_mine'   => $sender_id !== null && $sender_id === $user_id,
				'is_deleted' => $is_deleted,
				'reply_to'  => null,
				'reactions' => $is_deleted ? array() : ($reactions[$id] ?? array()),
				'attachments' => $is_deleted ? array() : ($attachments[$id] ?? array()),
			);

			$parent_id = $message->get('msg_reply_to_message_id');
			if ($parent_id && isset($parents[(int)$parent_id])) {
				$parent = $parents[(int)$parent_id];
				$parent_sender = $parent->get('msg_usr_user_id_sender');
				$row['reply_to'] = array(
					'id'      => (int)$parent->key,
					'excerpt' => $parent->get('msg_delete_time')
						? 'Deleted message'
						: self::excerpt(self::bodyOrLocked($parent)),
					'sender_name' => $parent_sender !== null && $parent_sender !== ''
						? self::person($parent_sender)['name']
						: ($parent->get('msg_remote_sender_address') ?: null),
				);
			}

			$out[] = $row;
		}
		return $out;
	}

	/** message_id => [ ['emoji','count','mine'], ... ] */
	public static function reactionsFor(array $message_ids, int $user_id): array {
		$out = array();
		if (!$message_ids) {
			return $out;
		}
		$rows = new MultiMessageReaction(array('message_ids' => $message_ids));
		foreach ($rows as $row) {
			$mid   = (int)$row->get('msr_msg_message_id');
			$emoji = (string)$row->get('msr_emoji');
			if (!isset($out[$mid][$emoji])) {
				$out[$mid][$emoji] = array('emoji' => $emoji, 'count' => 0, 'mine' => false);
			}
			$out[$mid][$emoji]['count']++;
			if ((int)$row->get('msr_usr_user_id') === $user_id) {
				$out[$mid][$emoji]['mine'] = true;
			}
		}
		foreach ($out as $mid => $by_emoji) {
			$out[$mid] = array_values($by_emoji);
		}
		return $out;
	}

	/** message_id => [ attachment payloads ] */
	public static function attachmentsFor(array $message_ids): array {
		$out = array();
		if (!$message_ids) {
			return $out;
		}
		$rows = new MultiMessageAttachment(array('message_ids' => $message_ids, 'deleted' => false));
		foreach ($rows as $row) {
			$mid = (int)$row->get('msa_msg_message_id');
			$url = null;
			$thumb = null;
			try {
				$file = new File((int)$row->get('msa_fil_file_id'), TRUE);
				if ($file->key) {
					$url = $file->get_url('original');
					if ($row->is_image()) {
						$thumb = $file->get_url(self::thumbnailSize());
					}
				}
			} catch (Exception $e) {
				// A file that has gone missing renders as an unavailable chip.
			}
			$out[$mid][] = array(
				'id'       => (int)$row->key,
				'name'     => $row->get('msa_filename'),
				'mime'     => $row->get('msa_mime_type'),
				'size'     => (int)$row->get('msa_byte_size'),
				'is_image' => $row->is_image(),
				'url'      => $url,
				'thumb_url' => $thumb,
			);
		}
		return $out;
	}

	/** The quoted parents referenced by a page of messages, in one query. */
	protected static function parentsFor(array $messages): array {
		$parent_ids = array();
		foreach ($messages as $message) {
			$parent_id = $message->get('msg_reply_to_message_id');
			if ($parent_id) {
				$parent_ids[(int)$parent_id] = true;
			}
		}
		if (!$parent_ids) {
			return array();
		}
		$rows = new MultiMessage(array('message_ids' => array_keys($parent_ids)));
		$out = array();
		foreach ($rows as $row) {
			$out[(int)$row->key] = $row;
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Reading a thread
	// ------------------------------------------------------------------

	/**
	 * A page of a conversation's messages, oldest first.
	 *
	 * Soft-deleted messages come back deliberately: "this message was deleted"
	 * is the honest rendering of delete-for-everyone, and a bubble that simply
	 * vanished would leave the people who already read it confused.
	 *
	 * @param array $window before_message_id | after_message_id | limit
	 * @return Message[]
	 */
	public static function threadPage(Conversation $conversation, array $window = array()): array {
		$limit = isset($window['limit']) ? max(1, min(200, (int)$window['limit'])) : self::PAGE_SIZE;

		$where  = array('msg_cnv_conversation_id = ?');
		$params = array((int)$conversation->key);
		$order  = 'DESC';

		// Present means forward-from-here, and 0 legitimately means "from the
		// beginning" — testing it for emptiness would silently turn the first
		// poll of a conversation into a newest-page fetch instead.
		if (array_key_exists('after_message_id', $window) && $window['after_message_id'] !== null) {
			$where[]  = 'msg_message_id > ?';
			$params[] = max(0, (int)$window['after_message_id']);
			$order    = 'ASC';
		} elseif (!empty($window['before_message_id'])) {
			$where[]  = 'msg_message_id < ?';
			$params[] = (int)$window['before_message_id'];
		}

		$sql = 'SELECT msg_message_id FROM msg_messages
				WHERE ' . implode(' AND ', $where) . '
				ORDER BY msg_message_id ' . $order . '
				LIMIT ' . ($limit + 1);
		$q = DbConnector::get_instance()->get_db_link()->prepare($sql);
		$q->execute($params);
		$ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));

		$has_more = count($ids) > $limit;
		$ids = array_slice($ids, 0, $limit);
		if ($order === 'DESC') {
			$ids = array_reverse($ids);
		}

		// One batch load for the page, not one SELECT per message — this runs on
		// the poll hot path. The map restores the window's order, which the
		// collection does not promise.
		$messages = array();
		if ($ids) {
			$by_id = array();
			foreach (new MultiMessage(array('message_ids' => $ids)) as $row) {
				$by_id[(int)$row->key] = $row;
			}
			foreach ($ids as $id) {
				if (isset($by_id[$id])) {
					$messages[] = $by_id[$id];
				}
			}
		}

		return array('messages' => $messages, 'has_more' => $has_more);
	}

	/** Mark the conversation read up to now for one member. */
	public static function markRead(Conversation $conversation, int $user_id): void {
		$row = $conversation->participant_row($user_id);
		if (!$row) {
			return;
		}
		// The poll calls this on every visible tick. A member already read past
		// the conversation's last activity has nothing new to record, and
		// skipping the save is what keeps an idle open thread from writing a
		// row every few seconds per viewer.
		$last_read = (string)$row->get('cnp_last_read_time');
		$last_activity = (string)($conversation->get('cnv_update_time')
			?: $conversation->get('cnv_create_time'));
		if ($last_read !== '' && $last_activity !== '' && $last_read >= $last_activity) {
			return;
		}
		$row->set('cnp_last_read_time', gmdate('Y-m-d H:i:s'));
		$row->save();
		$_SESSION['message_unread_count'] = null;
	}

	// ------------------------------------------------------------------
	// Small helpers
	// ------------------------------------------------------------------

	/**
	 * A message's words, or the standing-in text when nobody present can open
	 * them. Used where a preview is being built and an exception would take a
	 * whole list down over one unreadable line.
	 */
	public static function bodyOrLocked(Message $message): string {
		try {
			return (string)$message->get('msg_body');
		} catch (VaultLockedException $e) {
			return 'Protected message';
		}
	}

	/** A one-line preview for the conversation list and reply quotes. */
	public static function excerpt(string $body, int $length = 120): string {
		$body = trim(preg_replace('/\s+/u', ' ', strip_tags($body)));
		if (mb_strlen($body) <= $length) {
			return $body;
		}
		return mb_substr($body, 0, $length - 1) . '…';
	}

	/** Postgres booleans arrive as 't'/'f' through the raw inbox query. */
	public static function isTrue($value): bool {
		return $value === true || $value === 't' || $value === 'true' || $value === 1 || $value === '1';
	}
}
