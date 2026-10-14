<?php
/**
 * ForumPost — one post in a topic: the opening post (fpo_is_first) or a reply.
 *
 * The markdown source (fpo_body) is the truth; fpo_body_html is what
 * MarkdownRenderer's restricted profile made of it at write time, and is made
 * again on every edit. A post never renders from the source on a page view.
 *
 * $write_permission is the moderator level: anyone at it may edit or remove any
 * post, and SpaceAccess::mayModerate() reads it, so the level is written here
 * and nowhere else. A post's author may edit and delete their own.
 *
 * @version 1.0.0
 */

class ForumPost extends SystemBase {

	public static $prefix = 'fpo';
	public static $tablename = 'fpo_forum_posts';
	public static $pkey_column = 'fpo_forum_post_id';

	/** Who moderates the forum: changes or removes any post. */
	public static $write_permission = 5;
	/** Who may remove any post (the moderators). */
	public static $delete_permission = 5;

	/** The entity type posts carry in reactions, attachments and content reports. */
	const ENTITY_TYPE = 'forum_post';

	public static $field_specifications = array(
		'fpo_forum_post_id'      => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'fpo_ftp_forum_topic_id' => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true,
			'foreign_key' => array('table' => 'ftp_forum_topics', 'column' => 'ftp_forum_topic_id', 'on_delete' => 'CASCADE')),
		'fpo_usr_user_id'        => array('type' => 'int4', 'is_nullable' => true, 'index' => true),
		'fpo_is_first'           => array('type' => 'bool', 'is_nullable' => false, 'default' => false),
		'fpo_body'               => array('type' => 'text', 'is_nullable' => false, 'required' => true),
		'fpo_body_html'          => array('type' => 'text', 'is_nullable' => false, 'default' => ''),
		// The MarkdownRenderer::VERSION that made fpo_body_html; ForumMaintenance
		// renders again any post made by an older one.
		'fpo_render_version'     => array('type' => 'varchar(16)', 'is_nullable' => true),
		'fpo_reply_to_id'        => array('type' => 'int8', 'is_nullable' => true),
		'fpo_is_approved'        => array('type' => 'bool', 'is_nullable' => false, 'default' => true),
		'fpo_edited_time'        => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'fpo_create_time'        => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
		'fpo_delete_time'        => array('type' => 'timestamp(6)', 'is_nullable' => true),
	);

	public static $index_specifications = array(
		// The topic page: a topic's posts in order.
		array('columns' => array('fpo_ftp_forum_topic_id', 'fpo_forum_post_id')),
		// Search (FullTextSearch::indexSpec('fpo_body')).
		array('columns' => array("to_tsvector('english', fpo_body)"), 'method' => 'gin'),
	);

	protected static $foreign_key_actions = array(
		// A topic's posts go with it; each takes its attachments along.
		'fpo_ftp_forum_topic_id' => array('action' => 'permanent_delete'),
		'fpo_usr_user_id'        => array('action' => 'set_value', 'value' => User::USER_DELETED),
	);

	/**
	 * What is wrong with this row, as messages a person can act on. save()
	 * refuses a row with any, so every way in — admin form, action, task —
	 * meets the same rules; prepare() reports them early.
	 *
	 * @return string[]
	 */
	public function problems(): array {
		$problems = array();
		if (trim((string)$this->get('fpo_body')) === '') {
			$problems[] = 'A post needs some text.';
		}
		return $problems;
	}

	function prepare() {
		parent::prepare();
		if ($problems = $this->problems()) {
			throw new DisplayableUserException(implode(' ', $problems));
		}
	}

	function save($debug = false) {
		if ($problems = $this->problems()) {
			throw new DisplayableUserException(implode(' ', $problems));
		}
		return parent::save($debug);
	}

	public function topicId(): int {
		return (int)$this->get('fpo_ftp_forum_topic_id');
	}

	public function topic(): ForumTopic {
		return new ForumTopic($this->topicId(), TRUE);
	}

	/**
	 * Readers: whoever may read the topic; a post still held for approval only
	 * its author and the moderators.
	 */
	function authenticate_read($data) {
		if (!Forum::mayReadPost($this, (int)($data['current_user_id'] ?? 0))) {
			throw new SystemAuthenticationError('You cannot see this post.');
		}
	}

	/**
	 * Writers: the post's author, or a moderator. Whether the author may still
	 * post where it is (the category's post rule, a lock) is ForumPosting's to
	 * check before it saves; this is the row-level floor every write meets.
	 */
	function authenticate_write($data) {
		$user_id = (int)($data['current_user_id'] ?? 0);
		$is_author = $user_id > 0 && (int)$this->get('fpo_usr_user_id') === $user_id;
		if (!$is_author && (int)($data['current_user_permission'] ?? 0) < static::$write_permission) {
			throw new SystemAuthenticationError('You can only change your own posts.');
		}
	}

	/**
	 * Remove the post for good, with its attachments (rows and Files) and its
	 * likes. Content reports about it stay in the queue's history; the queue
	 * shows them as gone.
	 */
	public function permanent_delete($debug = false) {
		$post_id = (int)$this->key;
		$result = parent::permanent_delete($debug);
		if ($result && !$debug && $post_id > 0) {
			ForumUploads::removeAllFor($post_id);
			$q = DbConnector::GetPreparedStatement('DELETE FROM rct_reactions WHERE rct_entity_type = ? AND rct_entity_id = ?');
			$q->execute(array(self::ENTITY_TYPE, $post_id));
		}
		return $result;
	}
}

class MultiForumPost extends SystemMultiBase {
	protected static $model_class = 'ForumPost';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		// visible_to => user id: hides held posts from everyone but their
		// author (moderators pass 'moderator' => TRUE).
		if (isset($this->options['visible_to']) && empty($this->options['moderator'])) {
			$uid = (int)$this->options['visible_to'];
			$filters['(fpo_is_approved'] = '= TRUE OR fpo_usr_user_id = ' . $uid . ')';
		}

		// in_live_topic => TRUE: only posts whose topic is not removed.
		if (!empty($this->options['in_live_topic'])) {
			$filters['fpo_ftp_forum_topic_id'] = 'IN (SELECT ftp_forum_topic_id FROM ftp_forum_topics WHERE ftp_delete_time IS NULL)';
		}

		// after_id => post id: posts newer than it (unread counting).
		if (isset($this->options['after_id'])) {
			$filters['fpo_forum_post_id'] = '> ' . (int)$this->options['after_id'];
		}

		return $this->_get_resultsv2('fpo_forum_posts', $filters, $this->order_by, $only_count, $debug);
	}
}
