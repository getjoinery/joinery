<?php
/**
 * ForumTopic — a titled discussion in a category. The opening post is the
 * topic's first ForumPost (fpo_is_first); the topic row holds the title, the
 * pin and lock flags and the counters a category list is ordered by.
 *
 * The counters (reply count, last post id and time) belong to the posts: the
 * post save path in ForumPosting keeps them, inside the same transaction as the
 * post, counting only approved, live posts. A topic is never saved by a member
 * to change them. ForumReconcile recomputes them nightly as a check.
 *
 * @version 1.0.0
 */

class ForumTopic extends SystemBase {

	public static $prefix = 'ftp';
	public static $tablename = 'ftp_forum_topics';
	public static $access_class = 'content';
	public static $pkey_column = 'ftp_forum_topic_id';

	public static $field_specifications = array(
		'ftp_forum_topic_id'        => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'ftp_fcg_forum_category_id' => array('type' => 'int8', 'is_nullable' => false, 'required' => true,
			'foreign_key' => array('table' => 'fcg_forum_categories', 'column' => 'fcg_forum_category_id', 'on_delete' => 'CASCADE')),
		'ftp_usr_user_id'           => array('type' => 'int4', 'is_nullable' => true, 'index' => true),
		'ftp_title'                 => array('type' => 'varchar(200)', 'is_nullable' => false, 'required' => true),
		'ftp_slug'                  => array('type' => 'varchar(200)', 'is_nullable' => false),
		'ftp_is_pinned'             => array('type' => 'bool', 'is_nullable' => false, 'default' => false),
		'ftp_is_locked'             => array('type' => 'bool', 'is_nullable' => false, 'default' => false),
		// False while the opening post is held for approval: the topic is then
		// visible only to its author and moderators.
		'ftp_is_approved'           => array('type' => 'bool', 'is_nullable' => false, 'default' => true),
		'ftp_reply_count'           => array('type' => 'int4', 'is_nullable' => false, 'default' => 0),
		'ftp_last_post_id'          => array('type' => 'int8', 'is_nullable' => true),
		'ftp_last_post_time'        => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
		'ftp_create_time'           => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
		'ftp_delete_time'           => array('type' => 'timestamp(6)', 'is_nullable' => true),
	);

	public static $index_specifications = array(
		// The category list: pinned first, then by latest activity.
		array('columns' => array('ftp_fcg_forum_category_id', 'ftp_is_pinned DESC', 'ftp_last_post_time DESC'),
			'where' => 'ftp_delete_time IS NULL'),
		// Title search (FullTextSearch::indexSpec('ftp_title')).
		array('columns' => array("to_tsvector('english', ftp_title)"), 'method' => 'gin'),
	);

	protected static $foreign_key_actions = array(
		// A category's topics go with it; each takes its own posts along.
		'ftp_fcg_forum_category_id' => array('action' => 'permanent_delete'),
		'ftp_usr_user_id'           => array('action' => 'set_value', 'value' => User::USER_DELETED),
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
		if (trim((string)$this->get('ftp_title')) === '') {
			$problems[] = 'A topic needs a title.';
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

	public function categoryId(): int {
		return (int)$this->get('ftp_fcg_forum_category_id');
	}

	public function category(): ForumCategory {
		return new ForumCategory($this->categoryId(), TRUE);
	}

	/** The topic's address; $page and $post_id add the page and the anchor. */
	public function url(?int $page = null, ?int $post_id = null): string {
		$url = '/forum/t/' . (int)$this->key . '/' . rawurlencode((string)$this->get('ftp_slug'));
		// A link to one post always names its page, page 1 included: without
		// ?page the topic page would send a returning member to their unread post.
		if ($page !== null && ($page > 1 || $post_id !== null)) {
			$url .= '?page=' . max(1, $page);
		}
		if ($post_id !== null) {
			$url .= '#post-' . $post_id;
		}
		return $url;
	}

	/**
	 * Readers: whoever may read the category, and — while the topic waits for
	 * approval — only its author and the moderators.
	 */
	function authenticate_read($data) {
		if (!Forum::mayReadTopic($this, (int)($data['current_user_id'] ?? 0))) {
			throw new SystemAuthenticationError('You cannot see this topic.');
		}
	}

	// A topic changes only through ForumPosting, which checks the rules itself;
	// a direct write (REST, AI) is a moderator's.
	function authenticate_write($data) {
		if (!Access::allows(ForumPost::access_level('write'), (int)($data['current_user_permission'] ?? 0))) {
			throw new SystemAuthenticationError('Only a forum moderator can change a topic directly.');
		}
	}

	/** Remove the topic and its posts for good, with every watch on it. */
	public function permanent_delete($debug = false) {
		$topic_id = (int)$this->key;
		$result = parent::permanent_delete($debug);
		if ($result && !$debug && $topic_id > 0) {
			ForumWatch::removeTarget(ForumWatch::TARGET_TOPIC, $topic_id);
		}
		return $result;
	}

	/**
	 * The search-engine inventory: topics in public categories, live and
	 * approved — never anything a signed-out visitor could not open. Each record's
	 * link is "{id}/{slug}" under the "forum/t" namespace, the topic's URL.
	 *
	 * @return array<int,array{id:int,link:string,modify_time:?string}>
	 */
	public static function seo_public_records(): array {
		$q = DbConnector::GetPreparedStatement(
			"SELECT ftp.ftp_forum_topic_id, ftp.ftp_slug, ftp.ftp_last_post_time
			 FROM ftp_forum_topics ftp
			 JOIN fcg_forum_categories fcg ON fcg.fcg_forum_category_id = ftp.ftp_fcg_forum_category_id
			 WHERE ftp.ftp_delete_time IS NULL AND ftp.ftp_is_approved = TRUE
			   AND fcg.fcg_delete_time IS NULL AND fcg.fcg_read_rule = 'public'
			 ORDER BY ftp.ftp_forum_topic_id");
		$q->execute();
		$out = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$out[] = array(
				'id'          => (int)$row['ftp_forum_topic_id'],
				'link'        => (int)$row['ftp_forum_topic_id'] . '/' . rawurlencode((string)$row['ftp_slug']),
				'modify_time' => $row['ftp_last_post_time'],
			);
		}
		return $out;
	}
}

class MultiForumTopic extends SystemMultiBase {
	protected static $model_class = 'ForumTopic';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		// visible_to => user id: hides topics still waiting for approval from
		// everyone but their author (moderators pass 'moderator' => TRUE).
		if (isset($this->options['visible_to']) && empty($this->options['moderator'])) {
			$uid = (int)$this->options['visible_to'];
			$filters['(ftp_is_approved'] = '= TRUE OR ftp_usr_user_id = ' . $uid . ')';
		}

		return $this->_get_resultsv2('ftp_forum_topics', $filters, $this->order_by, $only_count, $debug);
	}
}
