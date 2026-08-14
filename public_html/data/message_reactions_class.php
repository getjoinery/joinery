<?php
/**
 * MessageReaction and MultiMessageReaction classes
 *
 * One row per (message, reactor, emoji). A reactor is normally a local member;
 * a reaction that arrived from another instance over Joinery Direct has no local
 * user row and is attributed to the sending address instead.
 *
 * @version 1.0
 */


class MessageReactionException extends SystemBaseException {}

class MessageReaction extends SystemBase {
	public static $prefix = 'msr';
	public static $tablename = 'msr_message_reactions';
	public static $pkey_column = 'msr_message_reaction_id';

	// Reactions are read and written through the messenger's own actions, which
	// check conversation membership. Generic per-row CRUD would have to re-derive
	// that check from the message, so it stays closed.
	public static $api_readable = false;
	public static $api_writable = false;

	public static $ai_readable = false;

	protected static $foreign_key_actions = array(
		'msr_msg_message_id' => array('action' => 'cascade', 'source_class' => 'Message'),
		'msr_usr_user_id'    => array('action' => 'permanent_delete'),
	);

	/** Longest emoji sequence we will store (ZWJ families run long). */
	const MAX_EMOJI_LENGTH = 32;

	public static $field_specifications = array(
		'msr_message_reaction_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		// No database uniqueness constraint: two of the four columns that would
		// make the key are nullable, and Postgres counts NULLs as distinct, so
		// the index would not actually forbid the duplicate. toggle() is the one
		// write path and it reads before it writes.
		'msr_msg_message_id' => array('type' => 'int8', 'is_nullable' => false),
		'msr_usr_user_id'    => array('type' => 'int4', 'is_nullable' => true),
		'msr_remote_address' => array('type' => 'varchar(255)', 'is_nullable' => true),
		'msr_emoji'          => array('type' => 'varchar(32)', 'is_nullable' => false),
		'msr_create_time'    => array('type' => 'timestamp(6)'),
	);

	/**
	 * Add or remove one member's reaction — the tap-again-to-undo behaviour every
	 * chat app has. Returns TRUE when the reaction is now on, FALSE when it came
	 * back off.
	 */
	public static function toggle($message_id, $user_id, $emoji, $remote_address = null): bool {
		$emoji = self::normalize_emoji($emoji);

		$existing = new MultiMessageReaction(array(
			'message_id' => (int)$message_id,
			'emoji'      => $emoji,
		) + ($user_id !== null
			? array('user_id' => (int)$user_id)
			: array('remote_address' => (string)$remote_address)));

		foreach ($existing as $row) {
			$row->permanent_delete();
			return false;
		}

		$row = new MessageReaction(NULL);
		$row->set('msr_msg_message_id', (int)$message_id);
		$row->set('msr_usr_user_id', $user_id !== null ? (int)$user_id : null);
		$row->set('msr_remote_address', $remote_address);
		$row->set('msr_emoji', $emoji);
		$row->set('msr_create_time', gmdate('Y-m-d H:i:s'));
		$row->save();
		return true;
	}

	/**
	 * Keep what a member tapped and nothing else. A reaction is one grapheme
	 * cluster of emoji; anything longer, or anything carrying letters, digits or
	 * markup, is not a reaction and is refused rather than stored and rendered.
	 */
	public static function normalize_emoji($emoji): string {
		$emoji = trim((string)$emoji);
		if ($emoji === '' || strlen($emoji) > self::MAX_EMOJI_LENGTH) {
			throw new MessageReactionException('That is not an emoji.');
		}

		// A keycap ("1️⃣") is the one emoji built on an ASCII character, and it is
		// only an emoji because of the combining enclosing keycap that follows.
		if (preg_match('/^[0-9#*]\x{FE0F}?\x{20E3}$/u', $emoji)) {
			return $emoji;
		}

		// Everything else: a family emoji is several pictographs stitched together
		// with zero-width joiners, variation selectors and skin-tone modifiers.
		// Set the stitching aside, then require that what is left is all
		// pictographs — which is how "😀" passes and "hello" or "<img" does not.
		$stripped = preg_replace('/[\x{200D}\x{FE0E}\x{FE0F}\x{1F3FB}-\x{1F3FF}]/u', '', $emoji);
		if ($stripped === null || $stripped === ''
			|| !preg_match('/^[\p{So}\p{Sk}]+$/u', $stripped)) {
			throw new MessageReactionException('That is not an emoji.');
		}
		return $emoji;
	}

	function display_title() {
		return (string)$this->get('msr_emoji');
	}
}

class MultiMessageReaction extends SystemMultiBase {
	protected static $model_class = 'MessageReaction';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['message_id'])) {
			$filters['msr_msg_message_id'] = array($this->options['message_id'], PDO::PARAM_INT);
		}

		// Every reaction on a page of messages in one query.
		if (isset($this->options['message_ids'])) {
			$ids = array_map('intval', (array)$this->options['message_ids']);
			$ids = array_values(array_filter($ids));
			$filters['msr_msg_message_id'] = $ids
				? 'IN (' . implode(',', $ids) . ')'
				: 'IS NULL';
		}

		if (isset($this->options['user_id'])) {
			$filters['msr_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['remote_address'])) {
			$filters['msr_remote_address'] = array($this->options['remote_address'], PDO::PARAM_STR);
		}

		if (isset($this->options['emoji'])) {
			$filters['msr_emoji'] = array($this->options['emoji'], PDO::PARAM_STR);
		}

		return $this->_get_resultsv2('msr_message_reactions', $filters, $this->order_by, $only_count, $debug);
	}
}
