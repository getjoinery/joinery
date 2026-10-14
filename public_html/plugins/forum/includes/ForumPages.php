<?php
/**
 * ForumPages — what the forum's pages show, and where a link should land.
 *
 * Pages read; they never write. A topic page tells the server how far the
 * member has read with the forum_mark_read action after it loads, so a page
 * view stays a GET that changes nothing.
 *
 * @version 1.0.0
 */

class ForumPages {

	/** The categories a viewer may read, in order, with their latest activity. */
	public static function categories(int $viewer_id): array {
		$out = array();
		$categories = new MultiForumCategory(array('readable_by' => $viewer_id, 'deleted' => false),
			array('fcg_sort_order' => 'ASC', 'fcg_name' => 'ASC'));
		$moderator = Forum::isModerator($viewer_id);
		foreach ($categories as $category) {
			$q = DbConnector::GetPreparedStatement(
				'SELECT COUNT(*) AS topics, COALESCE(SUM(ftp_reply_count), 0) AS replies, MAX(ftp_last_post_time) AS last_time
				 FROM ftp_forum_topics WHERE ftp_fcg_forum_category_id = ? AND ftp_delete_time IS NULL AND ftp_is_approved = TRUE');
			$q->execute(array((int)$category->key));
			$stats = $q->fetch(PDO::FETCH_ASSOC);
			$latest = self::latestTopic((int)$category->key);
			$out[] = array(
				'category'   => $category,
				'topics'     => (int)$stats['topics'],
				'posts'      => (int)$stats['topics'] + (int)$stats['replies'],
				'last_time'  => $stats['last_time'],
				'latest'     => $latest,
				'can_post'   => SpaceAccess::mayPost($category, $viewer_id),
				'moderator'  => $moderator,
			);
		}
		return $out;
	}

	private static function latestTopic(int $category_id): ?ForumTopic {
		foreach (new MultiForumTopic(array('ftp_fcg_forum_category_id' => $category_id, 'ftp_is_approved' => true, 'deleted' => false),
			array('ftp_last_post_time' => 'DESC'), 1) as $topic) {
			return $topic;
		}
		return null;
	}

	/**
	 * One page of a category's topics: pinned first, then by latest activity.
	 *
	 * @return array{topics:ForumTopic[],page:int,pages:int,unread:array<int,bool>}
	 */
	public static function categoryPage(ForumCategory $category, int $viewer_id, int $page): array {
		$moderator = Forum::mayModerate($category, $viewer_id);
		$options = array('ftp_fcg_forum_category_id' => (int)$category->key, 'deleted' => false,
			'visible_to' => $viewer_id, 'moderator' => $moderator);
		$per = Forum::topicsPageSize();
		$total = (new MultiForumTopic($options))->count_all();
		$pages = max(1, (int)ceil($total / $per));
		$page = min(max(1, $page), $pages);

		$topics = array();
		foreach (new MultiForumTopic($options, array('ftp_is_pinned' => 'DESC', 'ftp_last_post_time' => 'DESC', 'ftp_forum_topic_id' => 'DESC'),
			$per, ($page - 1) * $per) as $topic) {
			$topics[] = $topic;
		}
		return array(
			'topics' => $topics,
			'page'   => $page,
			'pages'  => $pages,
			'unread' => self::unreadFlags($topics, $viewer_id),
		);
	}

	/**
	 * Which of these topics have posts the viewer has not read. Signed-out
	 * visitors have no marker, so nothing is "new" for them.
	 *
	 * @param ForumTopic[] $topics
	 * @return array<int,bool> topic id => unread
	 */
	public static function unreadFlags(array $topics, int $viewer_id): array {
		$out = array();
		if ($viewer_id <= 0 || !$topics) {
			return $out;
		}
		$ids = array_map(function ($t) { return (int)$t->key; }, $topics);
		$markers = ForumTopicRead::markers($viewer_id, $ids);
		foreach ($topics as $topic) {
			$id = (int)$topic->key;
			$last = (int)$topic->get('ftp_last_post_id');
			$out[$id] = $last > 0 && $last > ($markers[$id] ?? 0);
		}
		return $out;
	}

	/**
	 * One page of a topic's posts, oldest first, with what each shows.
	 *
	 * @return array{posts:array[],page:int,pages:int,last_post_id:int}
	 */
	public static function topicPage(ForumTopic $topic, ForumCategory $category, int $viewer_id, int $page): array {
		$moderator = Forum::mayModerate($category, $viewer_id);
		$options = array('fpo_ftp_forum_topic_id' => (int)$topic->key, 'deleted' => false,
			'visible_to' => $viewer_id, 'moderator' => $moderator);
		$per = Forum::pageSize();
		$total = (new MultiForumPost($options))->count_all();
		$pages = max(1, (int)ceil($total / $per));
		$page = min(max(1, $page), $pages);

		$posts = array();
		foreach (new MultiForumPost($options, array('fpo_forum_post_id' => 'ASC'), $per, ($page - 1) * $per) as $post) {
			$posts[] = $post;
		}
		$ids = array_map(function ($p) { return (int)$p->key; }, $posts);
		$attachments = ForumUploads::forPosts($ids);
		$likes = self::likeCounts($ids);
		$mine = self::likedBy($viewer_id, $ids);
		$locked = (bool)$topic->get('ftp_is_locked');
		$can_post = SpaceAccess::mayPost($category, $viewer_id) && (!$locked || $moderator);

		// The posts these answer, in one query: always in this topic, shown
		// when the viewer may see them (live, and approved unless theirs or a
		// moderator is looking).
		$targets = array();
		$reply_ids = array_values(array_filter(array_map(function ($p) { return (int)$p->get('fpo_reply_to_id'); }, $posts)));
		if ($reply_ids) {
			$q = DbConnector::GetPreparedStatement('SELECT fpo_forum_post_id, fpo_usr_user_id FROM fpo_forum_posts
				WHERE fpo_forum_post_id IN (' . implode(',', array_map('intval', $reply_ids)) . ')
				  AND fpo_ftp_forum_topic_id = ? AND fpo_delete_time IS NULL
				  AND (fpo_is_approved = TRUE OR ? OR fpo_usr_user_id = ?)');
			$q->execute(array((int)$topic->key, $moderator ? 'true' : 'false', $viewer_id));
			foreach ($q->fetchAll(PDO::FETCH_NUM) as $row) {
				$targets[(int)$row[0]] = (int)$row[1];
			}
		}

		$rows = array();
		$last = 0;
		foreach ($posts as $post) {
			$id = (int)$post->key;
			$last = max($last, $id);
			$author_id = (int)$post->get('fpo_usr_user_id');
			$is_author = $viewer_id > 0 && $author_id === $viewer_id;
			$reply_to = null;
			$reply_to_id = (int)$post->get('fpo_reply_to_id');
			if ($reply_to_id > 0 && isset($targets[$reply_to_id])) {
				$reply_to = array(
					'id'   => $reply_to_id,
					'name' => Forum::person($targets[$reply_to_id])['name'],
					'url'  => $topic->url() . '?post=' . $reply_to_id,
				);
			}
			$rows[] = array(
				'post'        => $post,
				'id'          => $id,
				'author'      => Forum::person($author_id),
				'html'        => (string)$post->get('fpo_body_html'),
				'is_first'    => (bool)$post->get('fpo_is_first'),
				'held'        => !$post->get('fpo_is_approved'),
				'edited'      => (bool)$post->get('fpo_edited_time'),
				'reply_to'    => $reply_to,
				'attachments' => $attachments[$id] ?? array(),
				'likes'       => $likes[$id] ?? 0,
				'liked'       => isset($mine[$id]),
				'can_edit'    => $viewer_id > 0 && (($is_author && (!$locked || $moderator)) || $moderator),
				'can_delete'  => $viewer_id > 0 && ($moderator || ($is_author && !($post->get('fpo_is_first') && (int)$topic->get('ftp_reply_count') > 0))),
				'can_reply'   => $can_post && $topic->get('ftp_is_approved'),
				'can_like'    => $viewer_id > 0 && !$is_author && $post->get('fpo_is_approved'),
				'can_report'  => $viewer_id > 0 && !$is_author,
			);
		}
		return array('posts' => $rows, 'page' => $page, 'pages' => $pages, 'last_post_id' => $last,
			'can_post' => $can_post, 'moderator' => $moderator);
	}

	/**
	 * The page a member should land on: their first unread post's page; a
	 * topic read through opens at its last page; a first visit at the top.
	 *
	 * @return array{page:int,post_id:?int}
	 */
	public static function landing(ForumTopic $topic, ForumCategory $category, int $viewer_id): array {
		if ($viewer_id <= 0) {
			return array('page' => 1, 'post_id' => null);
		}
		$markers = ForumTopicRead::markers($viewer_id, array((int)$topic->key));
		if (!isset($markers[(int)$topic->key])) {
			return array('page' => 1, 'post_id' => null);
		}
		$marker = $markers[(int)$topic->key];
		$moderator = Forum::mayModerate($category, $viewer_id);
		$q = DbConnector::GetPreparedStatement('SELECT MIN(fpo_forum_post_id) FROM fpo_forum_posts
			WHERE fpo_ftp_forum_topic_id = ? AND fpo_delete_time IS NULL AND fpo_forum_post_id > ?
			  AND (fpo_is_approved = TRUE OR ? OR fpo_usr_user_id = ?)');
		$q->execute(array((int)$topic->key, $marker, $moderator ? 'true' : 'false', $viewer_id));
		$first_unread = (int)$q->fetchColumn();
		if ($first_unread > 0) {
			return array('page' => self::pageOf($topic, $first_unread, $viewer_id, $moderator), 'post_id' => $first_unread);
		}
		$last = (int)$topic->get('ftp_last_post_id');
		return array('page' => $last > 0 ? self::pageOf($topic, $last, $viewer_id, $moderator) : 1, 'post_id' => $last ?: null);
	}

	/**
	 * The address of one post. Which page it is on depends on who looks (a
	 * moderator sees held posts in between), so the link names the post and the
	 * topic page sends each visitor to the page it is on for them.
	 */
	public static function postUrl(ForumTopic $topic, ForumPost $post): string {
		return $topic->url() . '?post=' . (int)$post->key;
	}

	/** The page a post sits on, counting the posts this viewer sees before it. */
	public static function pageOf(ForumTopic $topic, int $post_id, int $viewer_id, bool $moderator): int {
		$q = DbConnector::GetPreparedStatement('SELECT COUNT(*) FROM fpo_forum_posts
			WHERE fpo_ftp_forum_topic_id = ? AND fpo_delete_time IS NULL AND fpo_forum_post_id <= ?
			  AND (fpo_is_approved = TRUE OR ? OR fpo_usr_user_id = ?)');
		$q->execute(array((int)$topic->key, $post_id, $moderator ? 'true' : 'false', $viewer_id));
		$before = max(1, (int)$q->fetchColumn());
		return (int)ceil($before / Forum::pageSize());
	}

	/** @return array<int,int> post id => likes */
	private static function likeCounts(array $ids): array {
		if (!$ids) {
			return array();
		}
		$q = DbConnector::GetPreparedStatement('SELECT rct_entity_id, COUNT(*) FROM rct_reactions
			WHERE rct_entity_type = ? AND rct_delete_time IS NULL AND rct_reaction_type = ?
			  AND rct_entity_id IN (' . implode(',', array_map('intval', $ids)) . ')
			GROUP BY rct_entity_id');
		$q->execute(array(ForumPost::ENTITY_TYPE, 'like'));
		$out = array();
		foreach ($q->fetchAll(PDO::FETCH_NUM) as $row) {
			$out[(int)$row[0]] = (int)$row[1];
		}
		return $out;
	}

	/** @return array<int,true> the posts this viewer liked */
	private static function likedBy(int $viewer_id, array $ids): array {
		if ($viewer_id <= 0 || !$ids) {
			return array();
		}
		$q = DbConnector::GetPreparedStatement('SELECT rct_entity_id FROM rct_reactions
			WHERE rct_usr_user_id = ? AND rct_entity_type = ? AND rct_delete_time IS NULL AND rct_reaction_type = ?
			  AND rct_entity_id IN (' . implode(',', array_map('intval', $ids)) . ')');
		$q->execute(array($viewer_id, ForumPost::ENTITY_TYPE, 'like'));
		$out = array();
		foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
			$out[(int)$id] = true;
		}
		return $out;
	}

	/** A time for a page: the viewer's zone, short. */
	public static function when($utc): string {
		if (!$utc) {
			return '';
		}
		return (string)LibraryFunctions::convert_time($utc, 'UTC', SessionControl::get_instance()->get_timezone(), 'M j, Y g:i A');
	}
}
