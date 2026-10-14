<?php
/**
 * ForumCategory — one forum category: its name, its place in the list, and who
 * may read it and post in it.
 *
 * A category is a SpaceAccess space: the read and post rules, the tier level
 * and the group live in its own columns, and SpaceAccess decides. Every surface
 * that shows forum content goes through that one decision.
 *
 * Deleting a category is soft and hides every topic in it with the same
 * timestamp, so undelete() brings back exactly the topics the delete took.
 *
 * @version 1.0.0
 */

class ForumCategory extends SystemBase {

	public static $prefix = 'fcg';
	public static $tablename = 'fcg_forum_categories';
	public static $pkey_column = 'fcg_forum_category_id';

	/** The posts a category holds; their $write_permission is the moderator level. */
	public static $post_model = 'ForumPost';

	public static $field_specifications = array(
		'fcg_forum_category_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'fcg_name'              => array('type' => 'varchar(100)', 'is_nullable' => false, 'required' => true),
		'fcg_slug'              => array('type' => 'varchar(100)', 'is_nullable' => false),
		'fcg_description'       => array('type' => 'text', 'is_nullable' => true),
		'fcg_read_rule'         => array('type' => 'varchar(20)', 'is_nullable' => false, 'default' => 'members',
			'allowed_values' => array('public', 'members', 'tier', 'group', 'staff')),
		'fcg_post_rule'         => array('type' => 'varchar(20)', 'is_nullable' => false, 'default' => 'members',
			'allowed_values' => array('members', 'tier', 'group', 'staff')),
		'fcg_tier_min_level'    => array('type' => 'int4', 'is_nullable' => true),
		'fcg_grp_group_id'      => array('type' => 'int4', 'is_nullable' => true),
		'fcg_sort_order'        => array('type' => 'int4', 'is_nullable' => false, 'default' => 0),
		'fcg_create_time'       => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
		'fcg_update_time'       => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'fcg_delete_time'       => array('type' => 'timestamp(6)', 'is_nullable' => true),
	);

	// A slug names one live category; a deleted one gives its slug back.
	public static $index_specifications = array(
		array('columns' => array('fcg_slug'), 'unique' => true, 'where' => 'fcg_delete_time IS NULL'),
	);

	protected static $foreign_key_actions = array(
		// A removed group leaves the category closed to the group rule (nobody
		// matches a NULL group), never open.
		'fcg_grp_group_id' => array('action' => 'null', 'source_table' => 'grp_groups'),
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
		if (trim((string)$this->get('fcg_name')) === '') {
			$problems[] = 'A category needs a name.';
		}
		$slug = (string)$this->get('fcg_slug');
		if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) > 100) {
			$problems[] = 'A category address may use lowercase letters, numbers and single hyphens.';
		} elseif (in_array($slug, Forum::RESERVED_SLUGS, true)) {
			$problems[] = 'That address is used by the forum itself; choose another.';
		}
		foreach (array('read' => 'fcg_read_rule', 'post' => 'fcg_post_rule') as $what => $col) {
			$rule = (string)$this->get($col);
			if ($rule === 'tier' && (int)$this->get('fcg_tier_min_level') <= 0) {
				$problems[] = 'Choose the subscription tier the ' . $what . ' rule asks for.';
			}
			if ($rule === 'group' && (int)$this->get('fcg_grp_group_id') <= 0) {
				$problems[] = 'Choose the group the ' . $what . ' rule asks for.';
			}
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
		if ($this->key) {
			$this->set('fcg_update_time', 'now()');
		}
		return parent::save($debug);
	}

	public function isPublic(): bool {
		return $this->get('fcg_read_rule') === SpaceAccess::RULE_PUBLIC;
	}

	public function url(): string {
		return '/forum/' . rawurlencode((string)$this->get('fcg_slug'));
	}

	/**
	 * The search-engine inventory: live public categories, at /forum/{slug}.
	 *
	 * @return array<int,array{id:int,link:string,modify_time:?string}>
	 */
	public static function seo_public_records(): array {
		$q = DbConnector::GetPreparedStatement("SELECT fcg_forum_category_id, fcg_slug, COALESCE(fcg_update_time, fcg_create_time) AS t
			FROM fcg_forum_categories WHERE fcg_delete_time IS NULL AND fcg_read_rule = 'public' ORDER BY fcg_sort_order, fcg_forum_category_id");
		$q->execute();
		$out = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$out[] = array('id' => (int)$row['fcg_forum_category_id'], 'link' => (string)$row['fcg_slug'], 'modify_time' => $row['t']);
		}
		return $out;
	}

	// Readers are the space rule's to decide; the REST and AI surfaces go
	// through it like every page does.
	function authenticate_read($data) {
		if (!SpaceAccess::mayRead($this, (int)($data['current_user_id'] ?? 0))) {
			throw new SystemAuthenticationError('You cannot see this forum category.');
		}
	}

	// Categories are an admin's to change.
	function authenticate_write($data) {
		if ((int)($data['current_user_permission'] ?? 0) < ForumPost::$write_permission) {
			throw new SystemAuthenticationError('Only a forum moderator can change a category.');
		}
	}

	/** Hide the category and every topic in it, with one timestamp. */
	function soft_delete() {
		return Forum::transaction(function () {
			parent::soft_delete();
			$q = DbConnector::GetPreparedStatement('UPDATE ftp_forum_topics SET ftp_delete_time = now()
				WHERE ftp_fcg_forum_category_id = ? AND ftp_delete_time IS NULL');
			$q->execute(array($this->key));
			return true;
		});
	}

	/** Remove the category and its topics for good, with every watch on it. */
	public function permanent_delete($debug = false) {
		$category_id = (int)$this->key;
		$result = parent::permanent_delete($debug);
		if ($result && !$debug && $category_id > 0) {
			ForumWatch::removeTarget(ForumWatch::TARGET_CATEGORY, $category_id);
		}
		return $result;
	}

	/** Bring the category back, with exactly the topics its delete took. */
	function undelete() {
		Forum::transaction(function () {
			$q = DbConnector::GetPreparedStatement('UPDATE ftp_forum_topics SET ftp_delete_time = NULL
				WHERE ftp_fcg_forum_category_id = ?
				AND ftp_delete_time = (SELECT fcg_delete_time FROM fcg_forum_categories WHERE fcg_forum_category_id = ?)');
			$q->execute(array($this->key, $this->key));
			$q = DbConnector::GetPreparedStatement('UPDATE fcg_forum_categories SET fcg_delete_time = NULL, fcg_update_time = now()
				WHERE fcg_forum_category_id = ?');
			$q->execute(array($this->key));
		});
		$this->load();
		return true;
	}
}

class MultiForumCategory extends SystemMultiBase {
	protected static $model_class = 'ForumCategory';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		// readable_by => user id (0 = signed out): only the categories that
		// person may read, decided in SQL by the same rule SpaceAccess applies.
		if (isset($this->options['readable_by'])) {
			$filters['fcg_forum_category_id'] = 'IN (SELECT fcg.fcg_forum_category_id FROM fcg_forum_categories fcg WHERE '
				. SpaceAccess::readableSql('ForumCategory', 'fcg', (int)$this->options['readable_by']) . ')';
		}

		return $this->_get_resultsv2('fcg_forum_categories', $filters, $this->order_by, $only_count, $debug);
	}
}
