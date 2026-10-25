<?php
/**
 * ForumPosting — every write to topics and posts.
 *
 * Each operation runs in one transaction that also keeps the topic's counters
 * (reply count, last post) right, then — after commit — clears the cached
 * public pages it changed and sends its alerts. Nothing here trusts the caller
 * beyond the user id: the category's post rule, the lock, authorship and the
 * moderator level are all checked here.
 *
 * Holding: a member's first `forum_hold_first_posts` posts wait for a
 * moderator's approval. A held post is seen only by its author and the
 * moderators, takes no part in the counters, and sends no alerts until it is
 * approved. A held opening post holds its topic the same way.
 *
 * @version 1.0.1
 * @changelog 1.0.1 - topic and counter writes run under rule_checked_write() behind the checks here
 */

class ForumPosting {

	// ------------------------------------------------------------------
	// Topics and replies
	// ------------------------------------------------------------------

	/**
	 * Start a topic: its row and its opening post.
	 *
	 * @return array{topic:ForumTopic,post:ForumPost,held:bool}
	 */
	public static function createTopic(ForumCategory $category, int $user_id, string $title, string $body, array $attachment_ids = array()): array {
		if (!SpaceAccess::mayPost($category, $user_id)) {
			throw new ForumRefusal('You cannot start a topic in this category.');
		}
		$title = Forum::cleanTitle($title);
		$body = self::prepareBody($category, Forum::cleanBody($body));
		self::checkRate($user_id);
		$held = self::shouldHold($category, $user_id);

		$result = Forum::transaction(function () use ($category, $user_id, $title, $body, $held, $attachment_ids) {
			$topic = new ForumTopic(NULL);
			$topic->set('ftp_fcg_forum_category_id', (int)$category->key);
			$topic->set('ftp_usr_user_id', $user_id);
			$topic->set('ftp_title', $title);
			$topic->set('ftp_slug', Forum::slugify($title));
			$topic->set('ftp_is_approved', !$held);
			// The category's post rule admitted this member (mayPost above); a
			// topic's own write level is a moderator's.
			SystemBase::rule_checked_write(function () use ($topic) { $topic->save(); });

			$post = self::insertPost($topic, $user_id, $body, true, null, $held);
			ForumUploads::claimAll($attachment_ids, $user_id, $post);
			self::recount((int)$topic->key);
			ForumWatch::watchUnlessMuted($user_id, (int)$topic->key);
			$topic->load();
			return array('topic' => $topic, 'post' => $post, 'held' => $held);
		});

		self::logPost($user_id);
		if (!$held) {
			ForumCache::topicChanged($result['topic'], $category);
			ForumNotifier::topicCreated($category, $result['topic'], $result['post'], $user_id);
		} else {
			ForumNotifier::postHeld($category, $result['topic'], $result['post'], $user_id);
		}
		return $result;
	}

	/**
	 * Reply in a topic.
	 *
	 * @return array{post:ForumPost,held:bool}
	 */
	public static function reply(ForumTopic $topic, ForumCategory $category, int $user_id, string $body, ?int $reply_to_id = null, array $attachment_ids = array()): array {
		if (!SpaceAccess::mayPost($category, $user_id)) {
			throw new ForumRefusal('You cannot reply in this category.');
		}
		$moderator = Forum::mayModerate($category, $user_id);
		if ($topic->get('ftp_is_locked') && !$moderator) {
			throw new ForumRefusal('This topic is locked.');
		}
		if (!$topic->get('ftp_is_approved') && !$moderator) {
			throw new ForumRefusal('This topic is waiting for approval.');
		}
		$body = self::prepareBody($category, Forum::cleanBody($body));
		$reply_to = self::replyTarget($topic, $reply_to_id, $user_id);
		self::checkRate($user_id);
		$held = self::shouldHold($category, $user_id);

		$result = Forum::transaction(function () use ($topic, $user_id, $body, $reply_to, $held, $attachment_ids) {
			Forum::lockTopic((int)$topic->key);
			$post = self::insertPost($topic, $user_id, $body, false, $reply_to ? (int)$reply_to->key : null, $held);
			ForumUploads::claimAll($attachment_ids, $user_id, $post);
			self::recount((int)$topic->key);
			ForumWatch::watchUnlessMuted($user_id, (int)$topic->key);
			return array('post' => $post, 'held' => $held);
		});

		self::logPost($user_id);
		if (!$held) {
			$topic->load();
			ForumCache::topicChanged($topic, $category);
			ForumNotifier::replyPosted($category, $topic, $result['post'], $reply_to, $user_id);
		} else {
			ForumNotifier::postHeld($category, $topic, $result['post'], $user_id);
		}
		return $result;
	}

	/** Change a post's text (its author, or a moderator). */
	public static function editPost(ForumPost $post, ForumTopic $topic, ForumCategory $category, int $user_id, string $body, ?string $title = null): ForumPost {
		$moderator = Forum::mayModerate($category, $user_id);
		$is_author = (int)$post->get('fpo_usr_user_id') === $user_id;
		if (!$is_author && !$moderator) {
			throw new ForumRefusal('You can only edit your own posts.');
		}
		if ($is_author && !$moderator && $topic->get('ftp_is_locked')) {
			throw new ForumRefusal('This topic is locked.');
		}
		$body = self::prepareBody($category, Forum::cleanBody($body));
		$new_title = ($title !== null && $post->get('fpo_is_first')) ? Forum::cleanTitle($title) : null;
		$old_body = (string)$post->get('fpo_body');
		$old_title = (string)$topic->get('ftp_title');
		$session = SessionControl::get_instance();

		Forum::transaction(function () use ($post, $topic, $body, $new_title, $old_body, $old_title, $user_id, $session) {
			$post->set('fpo_body', $body);
			$post->set('fpo_body_html', Forum::renderBody($body));
			$post->set('fpo_render_version', MarkdownRenderer::VERSION);
			$post->set('fpo_edited_time', 'now()');
			$post->assert_can_write($session);
			$post->save();
			// The edit's history rows (ChangeTracking records them as the server's).
			if ($old_body !== $body) {
				ChangeTracking::logChange(ForumPost::ENTITY_TYPE, (int)$post->key, (int)$post->get('fpo_usr_user_id'),
					'fpo_body', $old_body, $body, 'edited', null, null, $user_id);
			}
			if ($new_title !== null && $new_title !== $old_title) {
				self::updateTopicColumns((int)$topic->key, array(
					'ftp_title' => $new_title,
					'ftp_slug'  => Forum::slugify($new_title),
				));
				ChangeTracking::logChange('forum_topic', (int)$topic->key, (int)$topic->get('ftp_usr_user_id'),
					'ftp_title', $old_title, $new_title, 'edited', null, null, $user_id);
			}
		});

		$post->load();
		$topic->load();
		if ($post->get('fpo_is_approved')) {
			ForumCache::topicChanged($topic, $category);
			// Someone the edit mentions for the first time is told, once.
			$added = array_diff(MentionParser::userIds($body), MentionParser::userIds($old_body));
			if ($added) {
				ForumNotifier::mentionsAdded($category, $topic, $post, $added, $user_id);
			}
		}
		return $post;
	}

	/**
	 * Remove a post (soft). Removing the opening post removes the topic.
	 *
	 * @return string 'post' or 'topic' — what went
	 */
	public static function deletePost(ForumPost $post, ForumTopic $topic, ForumCategory $category, int $user_id): string {
		$moderator = Forum::mayModerate($category, $user_id);
		if ((int)$post->get('fpo_usr_user_id') !== $user_id && !$moderator) {
			throw new ForumRefusal('You can only remove your own posts.');
		}
		// A lock freezes the thread for authors: no edits, no removals.
		if (!$moderator && $topic->get('ftp_is_locked')) {
			throw new ForumRefusal('This topic is locked.');
		}
		if ($post->get('fpo_is_first')) {
			self::deleteTopic($topic, $category, $user_id);
			return 'topic';
		}
		Forum::transaction(function () use ($post, $topic) {
			Forum::lockTopic((int)$topic->key);
			self::updatePostColumns((int)$post->key, array('fpo_delete_time' => 'now()'));
			self::recount((int)$topic->key);
		});
		$topic->load();
		ForumCache::topicChanged($topic, $category);
		return 'post';
	}

	/** Remove a topic (soft), with all its posts. */
	public static function deleteTopic(ForumTopic $topic, ForumCategory $category, int $user_id): void {
		$moderator = Forum::mayModerate($category, $user_id);
		if ((int)$topic->get('ftp_usr_user_id') !== $user_id && !$moderator) {
			throw new ForumRefusal('You can only remove your own topics.');
		}
		// An author may take back a topic nobody has answered; once others have
		// replied, the conversation is theirs too and only a moderator removes it.
		if (!$moderator && self::othersPosted($topic, $user_id)) {
			throw new ForumRefusal('Others have replied, so a moderator has to remove this topic. You can edit your opening post.');
		}
		self::updateTopicColumns((int)$topic->key, array('ftp_delete_time' => 'now()'));
		$topic->load();
		ForumCache::topicChanged($topic, $category);
	}

	/** Bring back a removed topic (moderators). */
	public static function restoreTopic(ForumTopic $topic, int $user_id): void {
		$category = Forum::liveCategory($topic->categoryId());
		if (!$category || !Forum::mayModerate($category, $user_id)) {
			throw new ForumRefusal('Only a moderator can restore a topic.');
		}
		$q = DbConnector::GetPreparedStatement('SELECT COUNT(*) FROM fpo_forum_posts
			WHERE fpo_ftp_forum_topic_id = ? AND fpo_is_first = TRUE AND fpo_delete_time IS NULL');
		$q->execute(array((int)$topic->key));
		if ((int)$q->fetchColumn() === 0) {
			throw new ForumRefusal('This topic\'s opening post was turned down or removed, so the topic cannot come back.');
		}
		self::updateTopicColumns((int)$topic->key, array('ftp_delete_time' => null));
		$topic->load();
		ForumCache::topicChanged($topic, $category);
	}

	// ------------------------------------------------------------------
	// Moderation
	// ------------------------------------------------------------------

	/**
	 * Pin, unpin, lock, unlock or move a topic (moderators).
	 *
	 * @param array $changes any of is_pinned (bool), is_locked (bool), category_id (int)
	 */
	public static function setTopic(ForumTopic $topic, ForumCategory $category, int $user_id, array $changes): ForumTopic {
		if (!Forum::mayModerate($category, $user_id)) {
			throw new ForumRefusal('Only a moderator can do that.');
		}
		$columns = array();
		if (array_key_exists('is_pinned', $changes)) {
			$columns['ftp_is_pinned'] = (bool)$changes['is_pinned'];
		}
		if (array_key_exists('is_locked', $changes)) {
			$columns['ftp_is_locked'] = (bool)$changes['is_locked'];
		}
		$moved_from = null;
		if (isset($changes['category_id']) && (int)$changes['category_id'] !== (int)$category->key) {
			$target = Forum::liveCategory((int)$changes['category_id']);
			if (!$target) {
				throw new ForumRefusal('That category is not available.');
			}
			$columns['ftp_fcg_forum_category_id'] = (int)$target->key;
			$moved_from = $category;
			$category = $target;
		}
		if (!$columns) {
			return $topic;
		}
		self::updateTopicColumns((int)$topic->key, $columns);
		$topic->load();
		if ($moved_from) {
			ForumCache::categoryChanged($moved_from);
		}
		ForumCache::topicChanged($topic, $category);
		return $topic;
	}

	/** Release a held post (moderators): it becomes visible and sends its alerts. */
	public static function approvePost(ForumPost $post, int $user_id): void {
		$topic = $post->topic();
		$category = $topic->category();
		if (!Forum::mayModerate($category, $user_id)) {
			throw new ForumRefusal('Only a moderator can approve posts.');
		}
		if ($post->get('fpo_is_approved')) {
			return;
		}
		Forum::transaction(function () use ($post, $topic) {
			Forum::lockTopic((int)$topic->key);
			self::updatePostColumns((int)$post->key, array('fpo_is_approved' => true));
			if ($post->get('fpo_is_first')) {
				self::updateTopicColumns((int)$topic->key, array('ftp_is_approved' => true));
			}
			self::recount((int)$topic->key);
		});
		$post->load();
		$topic->load();
		ForumCache::topicChanged($topic, $category);
		$author_id = (int)$post->get('fpo_usr_user_id');
		if ($post->get('fpo_is_first')) {
			ForumNotifier::topicCreated($category, $topic, $post, $author_id);
		} else {
			$reply_to_id = (int)$post->get('fpo_reply_to_id');
			$reply_to = ($reply_to_id > 0 && ForumPost::check_if_exists($reply_to_id)) ? new ForumPost($reply_to_id, TRUE) : null;
			ForumNotifier::replyPosted($category, $topic, $post, $reply_to, $author_id);
		}
	}

	/** Turn down a held post (moderators): it is removed; its topic too if it opened one. */
	public static function rejectPost(ForumPost $post, int $user_id): void {
		$topic = $post->topic();
		$category = $topic->category();
		if (!Forum::mayModerate($category, $user_id)) {
			throw new ForumRefusal('Only a moderator can turn down posts.');
		}
		// Turning down is for posts still waiting; a published post is removed.
		if ($post->get('fpo_is_approved')) {
			throw new ForumRefusal('That post is already published. Use Remove instead.');
		}
		Forum::transaction(function () use ($post, $topic) {
			self::updatePostColumns((int)$post->key, array('fpo_delete_time' => 'now()'));
			if ($post->get('fpo_is_first')) {
				self::updateTopicColumns((int)$topic->key, array('ftp_delete_time' => 'now()'));
			}
			self::recount((int)$topic->key);
		});
	}

	// ------------------------------------------------------------------
	// Counters
	// ------------------------------------------------------------------

	/**
	 * Recompute a topic's counters from its approved, live posts. Called inside
	 * every transaction that adds, removes, approves or rejects a post, and by
	 * the nightly check.
	 */
	public static function recount(int $topic_id): void {
		$q = DbConnector::GetPreparedStatement(
			"UPDATE ftp_forum_topics ftp SET
			   ftp_reply_count = (SELECT COUNT(*) FROM fpo_forum_posts fpo
			     WHERE fpo.fpo_ftp_forum_topic_id = ftp.ftp_forum_topic_id AND fpo.fpo_delete_time IS NULL
			       AND fpo.fpo_is_approved = TRUE AND fpo.fpo_is_first = FALSE),
			   ftp_last_post_id = (SELECT MAX(fpo.fpo_forum_post_id) FROM fpo_forum_posts fpo
			     WHERE fpo.fpo_ftp_forum_topic_id = ftp.ftp_forum_topic_id AND fpo.fpo_delete_time IS NULL
			       AND fpo.fpo_is_approved = TRUE),
			   ftp_last_post_time = COALESCE((SELECT fpo.fpo_create_time FROM fpo_forum_posts fpo
			     WHERE fpo.fpo_ftp_forum_topic_id = ftp.ftp_forum_topic_id AND fpo.fpo_delete_time IS NULL
			       AND fpo.fpo_is_approved = TRUE ORDER BY fpo.fpo_forum_post_id DESC LIMIT 1), ftp.ftp_create_time)
			 WHERE ftp.ftp_forum_topic_id = ?");
		$q->execute(array($topic_id));
	}

	// ------------------------------------------------------------------
	// Internals
	// ------------------------------------------------------------------

	private static function insertPost(ForumTopic $topic, int $user_id, string $body, bool $is_first, ?int $reply_to_id, bool $held): ForumPost {
		$post = new ForumPost(NULL);
		$post->set('fpo_ftp_forum_topic_id', (int)$topic->key);
		$post->set('fpo_usr_user_id', $user_id);
		$post->set('fpo_is_first', $is_first);
		$post->set('fpo_body', $body);
		$post->set('fpo_body_html', Forum::renderBody($body));
		$post->set('fpo_render_version', MarkdownRenderer::VERSION);
		$post->set('fpo_reply_to_id', $reply_to_id);
		$post->set('fpo_is_approved', !$held);
		$post->save();
		$post->load();
		return $post;
	}

	/**
	 * A body ready to store: each mention of someone who may read the category
	 * keeps their real name; a mention of anyone else becomes plain text.
	 */
	private static function prepareBody(ForumCategory $category, string $body): string {
		return MentionParser::normalizeFor($body, $category);
	}

	/** The post a reply answers, when it names one in the same topic. */
	private static function replyTarget(ForumTopic $topic, ?int $reply_to_id, int $user_id): ?ForumPost {
		if (!$reply_to_id) {
			return null;
		}
		if (!ForumPost::check_if_exists($reply_to_id)) {
			return null;
		}
		$target = new ForumPost($reply_to_id, TRUE);
		if ($target->topicId() !== (int)$topic->key || !Forum::mayReadPost($target, $user_id)) {
			return null;
		}
		return $target;
	}

	/** Has anyone but this member posted in the topic (held posts count)? */
	private static function othersPosted(ForumTopic $topic, int $user_id): bool {
		$q = DbConnector::GetPreparedStatement('SELECT COUNT(*) FROM fpo_forum_posts
			WHERE fpo_ftp_forum_topic_id = ? AND fpo_delete_time IS NULL
			  AND fpo_usr_user_id IS DISTINCT FROM ?');
		$q->execute(array((int)$topic->key, $user_id));
		return (int)$q->fetchColumn() > 0;
	}

	/** Should this member's next post wait for approval? */
	private static function shouldHold(ForumCategory $category, int $user_id): bool {
		$hold = Forum::settingInt('forum_hold_first_posts', 2, 0);
		if ($hold <= 0 || Forum::mayModerate($category, $user_id)) {
			return false;
		}
		$q = DbConnector::GetPreparedStatement('SELECT COUNT(*) FROM fpo_forum_posts
			WHERE fpo_usr_user_id = ? AND fpo_is_approved = TRUE');
		$q->execute(array($user_id));
		return (int)$q->fetchColumn() < $hold;
	}

	/** The per-member post limit. @throws ForumRefusal */
	private static function checkRate(int $user_id): void {
		$limit = Forum::settingInt('forum_post_rate_limit', 3, 1);
		$window = Forum::settingInt('forum_post_rate_window', 60, 1);
		$state = RequestLogger::rate_limit_state('forum_post', $limit, $window, null, $user_id);
		if (!$state['allowed']) {
			throw new ForumRefusal('You are posting quickly. Try again in ' . $state['retry_after'] . ' seconds; your text is still here.');
		}
	}

	private static function logPost(int $user_id): void {
		RequestLogger::log('forum_post', 'post', true, array('user_id' => $user_id));
	}

	/**
	 * The topic row's own columns, written by the server on a member's behalf
	 * (a reply moving its counters, an author removing their topic) — never
	 * through a member-facing save of the topic. Every caller has checked its
	 * right first (the author, the lock, a moderator), so the write runs past
	 * the topic's moderator-only level.
	 */
	private static function updateTopicColumns(int $topic_id, array $columns): void {
		SystemBase::rule_checked_write(function () use ($topic_id, $columns) {
			ForumTopic::updateColumns($topic_id, $columns);
		});
	}

	/** A post's columns, behind the same checks (author or a category moderator). */
	private static function updatePostColumns(int $post_id, array $columns): void {
		SystemBase::rule_checked_write(function () use ($post_id, $columns) {
			ForumPost::updateColumns($post_id, $columns);
		});
	}
}
