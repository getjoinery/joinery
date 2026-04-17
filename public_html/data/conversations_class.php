<?php
/**
 * Conversation and MultiConversation classes
 *
 * Threaded messaging system — conversations group messages between participants.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('data/messages_class.php'));

class ConversationException extends SystemBaseException {}

class Conversation extends SystemBase {
	public static $prefix = 'cnv';
	public static $tablename = 'cnv_conversations';
	public static $pkey_column = 'cnv_conversation_id';

	const MAX_MESSAGE_LENGTH = 5000;

	public static $field_specifications = array(
		'cnv_conversation_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'cnv_subject'         => array('type' => 'varchar(255)'),
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

		// Look for existing conversation between these two users
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
	 * Returns the new Conversation object.
	 */
	public static function create_conversation($participant_user_ids, $subject = null) {
		require_once(PathHelper::getIncludePath('data/conversation_participants_class.php'));

		if (count($participant_user_ids) < 2) {
			throw new ConversationException('A conversation requires at least 2 participants');
		}

		$conversation = new Conversation(NULL);
		if ($subject !== null) {
			$conversation->set('cnv_subject', $subject);
		}
		$conversation->save();

		foreach ($participant_user_ids as $user_id) {
			$participant = new ConversationParticipant(NULL);
			$participant->set('cnp_cnv_conversation_id', $conversation->key);
			$participant->set('cnp_usr_user_id', $user_id);
			$participant->save();
		}

		return $conversation;
	}

	/**
	 * Add a message to this conversation.
	 * Creates a Message record linked to this conversation.
	 * Clears cnp_delete_time for all participants (resurfaces deleted conversations).
	 * Creates notifications for other participants (unless muted).
	 * Returns the new Message object.
	 */
	public function add_message($sender_user_id, $body) {
		require_once(PathHelper::getIncludePath('data/conversation_participants_class.php'));

		// Validate
		$body = trim($body);
		if (empty($body)) {
			throw new ConversationException('Message body cannot be empty');
		}
		if (mb_strlen($body) > self::MAX_MESSAGE_LENGTH) {
			throw new ConversationException('Message exceeds maximum length of ' . self::MAX_MESSAGE_LENGTH . ' characters');
		}

		// Verify sender is a participant
		if (!$this->has_participant($sender_user_id)) {
			throw new ConversationException('You are not a participant in this conversation');
		}

		// Check blocks if block system exists
		if (class_exists('UserBlock')) {
			$participants = new MultiConversationParticipant(
				['conversation_id' => $this->key, 'deleted' => false]
			);
			$participants->load();
			foreach ($participants as $p) {
				$p_user_id = $p->get('cnp_usr_user_id');
				if ($p_user_id != $sender_user_id) {
					if (UserBlock::is_blocked($sender_user_id, $p_user_id)) {
						throw new ConversationException('Cannot message this user');
					}
				}
			}
		}

		// Strip HTML tags from user messages (plain text only)
		$clean_body = strip_tags($body);

		// Create the message
		$message = new Message(NULL);
		$message->set('msg_cnv_conversation_id', $this->key);
		$message->set('msg_usr_user_id_sender', $sender_user_id);
		$message->set('msg_body', $clean_body);
		$message->set('msg_sent_time', gmdate('Y-m-d H:i:s'));
		$message->save();

		// Resurface deleted conversations and create notifications
		$participants = new MultiConversationParticipant(
			['conversation_id' => $this->key]
		);
		$participants->load();

		require_once(PathHelper::getIncludePath('data/users_class.php'));
		$sender = new User($sender_user_id, TRUE);

		foreach ($participants as $participant) {
			$p_user_id = $participant->get('cnp_usr_user_id');

			// Clear delete_time to resurface conversation
			if ($participant->get('cnp_delete_time')) {
				$participant->set('cnp_delete_time', null);
				$participant->save();
			}

			// Skip sender for notifications
			if ($p_user_id == $sender_user_id) {
				continue;
			}

			// Create notification unless muted
			if (!$participant->get('cnp_is_muted')) {
				try {
					require_once(PathHelper::getIncludePath('data/notifications_class.php'));
					Notification::create_notification(
						$p_user_id,
						'message',
						'New message from ' . $sender->display_name(),
						substr($clean_body, 0, 100),
						'/profile/conversation?id=' . $this->key,
						$sender_user_id
					);
				} catch (Exception $e) {
					// Notification system may not be installed — continue
				}
			}
		}

		// Invalidate sender's unread count cache
		$_SESSION['message_unread_count'] = null;

		return $message;
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

		$sql = "SELECT cnv.cnv_conversation_id, cnv.cnv_subject, cnv.cnv_create_time,
				       cnv.cnv_update_time, cnv.cnv_delete_time,
				       latest.msg_sent_time AS latest_message_time,
				       latest.msg_body AS latest_message_body,
				       latest.msg_usr_user_id_sender AS latest_message_sender_id,
				       cnp.cnp_last_read_time, cnp.cnp_is_muted
				FROM cnv_conversations cnv
				JOIN cnp_conversation_participants cnp
				  ON cnp.cnp_cnv_conversation_id = cnv.cnv_conversation_id
				JOIN LATERAL (
				    SELECT msg_sent_time, msg_body, msg_usr_user_id_sender
				    FROM msg_messages
				    WHERE msg_cnv_conversation_id = cnv.cnv_conversation_id
				      AND msg_delete_time IS NULL
				    ORDER BY msg_sent_time DESC
				    LIMIT 1
				) latest ON true
				$where_sql
				ORDER BY latest.msg_sent_time DESC
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

		if (isset($this->options['deleted'])) {
			$filters['cnv_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

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
			if (isset($row->latest_message_time)) {
				$conversation->latest_message_time = $row->latest_message_time;
				$conversation->latest_message_body = $row->latest_message_body;
				$conversation->latest_message_sender_id = $row->latest_message_sender_id;
				$conversation->cnp_last_read_time = $row->cnp_last_read_time;
				$conversation->cnp_is_muted = $row->cnp_is_muted;
			}

			$this->add($conversation);
		}
	}
}
