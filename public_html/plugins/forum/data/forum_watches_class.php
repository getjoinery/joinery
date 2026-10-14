<?php
/**
 * ForumWatch — a member's choice to hear about a topic or a category, or to
 * hear nothing from it.
 *
 * Starting a topic or replying in one sets `watching` on it, unless the member
 * already chose `muted` there; that choice is never overwritten. A category
 * watch means "tell me about every new topic here". Watches are only ever acted
 * on for people who can still read the category (ForumNotifier checks).
 *
 * @version 1.0.0
 */

class ForumWatch extends SystemBase {

	public static $prefix = 'fwt';
	public static $tablename = 'fwt_forum_watches';
	public static $pkey_column = 'fwt_forum_watch_id';

	const TARGET_TOPIC = 'topic';
	const TARGET_CATEGORY = 'category';
	const LEVEL_WATCHING = 'watching';
	const LEVEL_MUTED = 'muted';

	public static $field_specifications = array(
		'fwt_forum_watch_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'fwt_usr_user_id'    => array('type' => 'int4', 'is_nullable' => false, 'required' => true,
			'unique_with' => array('fwt_target_type', 'fwt_target_id')),
		'fwt_target_type'    => array('type' => 'varchar(16)', 'is_nullable' => false, 'required' => true,
			'allowed_values' => array(self::TARGET_TOPIC, self::TARGET_CATEGORY)),
		'fwt_target_id'      => array('type' => 'int8', 'is_nullable' => false, 'required' => true),
		'fwt_level'          => array('type' => 'varchar(16)', 'is_nullable' => false, 'default' => 'watching',
			'allowed_values' => array(self::LEVEL_WATCHING, self::LEVEL_MUTED)),
		'fwt_update_time'    => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	public static $index_specifications = array(
		array('columns' => array('fwt_target_type', 'fwt_target_id')),
	);

	protected static $foreign_key_actions = array(
		'fwt_usr_user_id' => array('action' => 'cascade'),
	);

	function authenticate_read($data) {
		if ((int)$this->get('fwt_usr_user_id') !== (int)($data['current_user_id'] ?? 0)) {
			throw new SystemAuthenticationError('That is someone else\'s watch.');
		}
	}

	function authenticate_write($data) {
		$this->authenticate_read($data);
	}

	/** Set a member's level on a target (an explicit choice: overwrites). */
	public static function setLevel(int $user_id, string $type, int $target_id, string $level): void {
		$q = DbConnector::GetPreparedStatement(
			'INSERT INTO fwt_forum_watches (fwt_usr_user_id, fwt_target_type, fwt_target_id, fwt_level, fwt_update_time)
			 VALUES (?, ?, ?, ?, now())
			 ON CONFLICT (fwt_usr_user_id, fwt_target_type, fwt_target_id) DO UPDATE
			 SET fwt_level = EXCLUDED.fwt_level, fwt_update_time = now()');
		$q->execute(array($user_id, $type, $target_id, $level));
	}

	/** Start watching a topic the member posted in, unless they muted it. */
	public static function watchUnlessMuted(int $user_id, int $topic_id): void {
		$q = DbConnector::GetPreparedStatement(
			"INSERT INTO fwt_forum_watches (fwt_usr_user_id, fwt_target_type, fwt_target_id, fwt_level, fwt_update_time)
			 VALUES (?, 'topic', ?, 'watching', now())
			 ON CONFLICT (fwt_usr_user_id, fwt_target_type, fwt_target_id) DO NOTHING");
		$q->execute(array($user_id, $topic_id));
	}

	/** Remove a member's choice on a target (back to the default: not watching). */
	public static function clear(int $user_id, string $type, int $target_id): void {
		$q = DbConnector::GetPreparedStatement('DELETE FROM fwt_forum_watches
			WHERE fwt_usr_user_id = ? AND fwt_target_type = ? AND fwt_target_id = ?');
		$q->execute(array($user_id, $type, $target_id));
	}

	/** One member's level on a target, or null when they made no choice. */
	public static function levelFor(int $user_id, string $type, int $target_id): ?string {
		if ($user_id <= 0) {
			return null;
		}
		$q = DbConnector::GetPreparedStatement('SELECT fwt_level FROM fwt_forum_watches
			WHERE fwt_usr_user_id = ? AND fwt_target_type = ? AND fwt_target_id = ?');
		$q->execute(array($user_id, $type, $target_id));
		$level = $q->fetchColumn();
		return $level === false ? null : (string)$level;
	}

	/**
	 * Everyone at a level on a target.
	 *
	 * @return int[]
	 */
	public static function userIds(string $type, int $target_id, string $level = self::LEVEL_WATCHING): array {
		$q = DbConnector::GetPreparedStatement('SELECT fwt_usr_user_id FROM fwt_forum_watches
			WHERE fwt_target_type = ? AND fwt_target_id = ? AND fwt_level = ?');
		$q->execute(array($type, $target_id, $level));
		return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
	}

	/** Remove every watch on a target (the topic or category is gone for good). */
	public static function removeTarget(string $type, int $target_id): void {
		$q = DbConnector::GetPreparedStatement('DELETE FROM fwt_forum_watches WHERE fwt_target_type = ? AND fwt_target_id = ?');
		$q->execute(array($type, $target_id));
	}
}

class MultiForumWatch extends SystemMultiBase {
	protected static $model_class = 'ForumWatch';

	protected function getMultiResults($only_count = false, $debug = false) {
		return $this->_get_resultsv2('fwt_forum_watches', array(), $this->order_by, $only_count, $debug);
	}
}
