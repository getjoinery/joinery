<?php
/**
 * ForumTopicRead — how far one member has read one topic.
 *
 * One row per member per topic they have opened. A topic is unread for them
 * when its last post is newer than ftr_last_read_post_id; a topic with no row
 * is new to them. The marker only moves forward, and it moves when the page
 * that showed the posts tells the server (the forum_mark_read action), never as
 * a side effect of loading the page.
 *
 * @version 1.0.0
 */

class ForumTopicRead extends SystemBase {

	public static $prefix = 'ftr';
	public static $tablename = 'ftr_topic_reads';
	public static $pkey_column = 'ftr_topic_read_id';

	public static $field_specifications = array(
		'ftr_topic_read_id'      => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'ftr_usr_user_id'        => array('type' => 'int4', 'is_nullable' => false, 'required' => true,
			'unique_with' => array('ftr_ftp_forum_topic_id')),
		'ftr_ftp_forum_topic_id' => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true,
			'foreign_key' => array('table' => 'ftp_forum_topics', 'column' => 'ftp_forum_topic_id', 'on_delete' => 'CASCADE')),
		'ftr_last_read_post_id'  => array('type' => 'int8', 'is_nullable' => false, 'default' => 0),
		'ftr_update_time'        => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	protected static $foreign_key_actions = array(
		'ftr_ftp_forum_topic_id' => array('action' => 'cascade'),
		'ftr_usr_user_id'        => array('action' => 'cascade'),
	);

	// A member's reading position is theirs alone.
	function authenticate_read($data) {
		if ((int)$this->get('ftr_usr_user_id') !== (int)($data['current_user_id'] ?? 0)) {
			throw new SystemAuthenticationError('That is someone else\'s reading position.');
		}
	}

	function authenticate_write($data) {
		$this->authenticate_read($data);
	}

	/**
	 * Move a member's marker forward to $post_id (never back), in one statement
	 * so two tabs cannot race it backwards.
	 */
	public static function advance(int $user_id, int $topic_id, int $post_id): void {
		$q = DbConnector::GetPreparedStatement(
			'INSERT INTO ftr_topic_reads (ftr_usr_user_id, ftr_ftp_forum_topic_id, ftr_last_read_post_id, ftr_update_time)
			 VALUES (?, ?, ?, now())
			 ON CONFLICT (ftr_usr_user_id, ftr_ftp_forum_topic_id) DO UPDATE
			 SET ftr_last_read_post_id = GREATEST(ftr_topic_reads.ftr_last_read_post_id, EXCLUDED.ftr_last_read_post_id),
			     ftr_update_time = now()');
		$q->execute(array($user_id, $topic_id, $post_id));
	}

	/**
	 * The markers one member has for a set of topics.
	 *
	 * @param int[] $topic_ids
	 * @return array<int,int> topic id => last read post id
	 */
	public static function markers(int $user_id, array $topic_ids): array {
		$topic_ids = array_values(array_filter(array_map('intval', $topic_ids)));
		if ($user_id <= 0 || !$topic_ids) {
			return array();
		}
		$q = DbConnector::GetPreparedStatement('SELECT ftr_ftp_forum_topic_id, ftr_last_read_post_id FROM ftr_topic_reads
			WHERE ftr_usr_user_id = ? AND ftr_ftp_forum_topic_id IN (' . implode(',', $topic_ids) . ')');
		$q->execute(array($user_id));
		$out = array();
		foreach ($q->fetchAll(PDO::FETCH_NUM) as $row) {
			$out[(int)$row[0]] = (int)$row[1];
		}
		return $out;
	}
}

class MultiForumTopicRead extends SystemMultiBase {
	protected static $model_class = 'ForumTopicRead';

	protected function getMultiResults($only_count = false, $debug = false) {
		return $this->_get_resultsv2('ftr_topic_reads', array(), $this->order_by, $only_count, $debug);
	}
}
