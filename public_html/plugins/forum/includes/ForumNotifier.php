<?php
/**
 * ForumNotifier — who hears about what, and the signals that tell them.
 *
 *   forum.topic_created  everyone watching the category
 *   forum.reply_posted   everyone watching the topic (its author and repliers
 *                        watch it unless they muted it), and the author of the
 *                        post the reply answers
 *   forum.mention        each person the post @mentions
 *
 * Nobody hears about their own post, nobody hears twice about one post (a
 * mention wins over a reply alert), muted people hear nothing from that topic,
 * and only people who can still read the category are told — a watch outlives
 * a group membership, a notification must not.
 *
 * Every signal is dispatched with `recipients`, so each person's Notify
 * preferences apply. Held posts dispatch nothing until approved (ForumPosting).
 *
 * @version 1.0.0
 */

class ForumNotifier {

	const SUMMARY_LIMIT = 280;

	public static function topicCreated(ForumCategory $category, ForumTopic $topic, ForumPost $post, int $author_id): void {
		$mentioned = self::mentionRecipients($category, $post, $author_id);
		$watchers = ForumWatch::userIds(ForumWatch::TARGET_CATEGORY, (int)$category->key);
		$watchers = array_diff(self::readers($category, $watchers), $mentioned, array($author_id));

		$who = Forum::person($author_id)['name'];
		self::send('forum.topic_created', $category, $topic, $topic->url(null, (int)$post->key),
			$who . ' started a topic: ' . Forum::plainExcerpt((string)$post->get('fpo_body'), 160), $watchers, $author_id);
		self::sendMentions($category, $topic, $post, $mentioned, $author_id);
	}

	public static function replyPosted(ForumCategory $category, ForumTopic $topic, ForumPost $post, ?ForumPost $reply_to, int $author_id): void {
		$mentioned = self::mentionRecipients($category, $post, $author_id);
		$topic_id = (int)$topic->key;

		$ids = ForumWatch::userIds(ForumWatch::TARGET_TOPIC, $topic_id);
		if ($reply_to && (int)$reply_to->get('fpo_usr_user_id') > 0) {
			$ids[] = (int)$reply_to->get('fpo_usr_user_id');
		}
		$muted = ForumWatch::userIds(ForumWatch::TARGET_TOPIC, $topic_id, ForumWatch::LEVEL_MUTED);
		$ids = array_diff(array_unique($ids), $muted, $mentioned, array($author_id));
		$ids = self::readers($category, $ids);

		$who = Forum::person($author_id)['name'];
		$url = ForumPages::postUrl($topic, $post);
		self::send('forum.reply_posted', $category, $topic, $url,
			$who . ' replied: ' . Forum::plainExcerpt((string)$post->get('fpo_body'), 160), $ids, $author_id);
		self::sendMentions($category, $topic, $post, $mentioned, $author_id);
	}

	/** A post is waiting for a moderator: tell the moderators (the admins audience). */
	public static function postHeld(ForumCategory $category, ForumTopic $topic, ForumPost $post, int $author_id): void {
		SignalBus::dispatch('forum.post_held', array(
			'category_name'  => mb_substr(self::oneLine((string)$category->get('fcg_name')), 0, 80),
			'topic_title'    => mb_substr(self::oneLine((string)$topic->get('ftp_title')), 0, 140),
			'author_name'    => Forum::person($author_id)['name'],
			'summary'        => Forum::plainExcerpt((string)$post->get('fpo_body'), 160),
			'source_user_id' => $author_id ?: null,
		));
	}

	/** An edit mentioned these people for the first time. */
	public static function mentionsAdded(ForumCategory $category, ForumTopic $topic, ForumPost $post, array $ids, int $author_id): void {
		$muted = ForumWatch::userIds(ForumWatch::TARGET_TOPIC, (int)$topic->key, ForumWatch::LEVEL_MUTED);
		$ids = self::readers($category, array_diff(array_map('intval', $ids), $muted, array($author_id)));
		self::sendMentions($category, $topic, $post, $ids, $author_id);
	}

	/**
	 * The people a post's mentions reach: mentioned, able to read the category,
	 * not the author, and not muted on the topic.
	 *
	 * @return int[]
	 */
	private static function mentionRecipients(ForumCategory $category, ForumPost $post, int $author_id): array {
		$ids = MentionParser::userIds((string)$post->get('fpo_body'));
		$muted = ForumWatch::userIds(ForumWatch::TARGET_TOPIC, $post->topicId(), ForumWatch::LEVEL_MUTED);
		return self::readers($category, array_diff($ids, $muted, array($author_id)));
	}

	private static function sendMentions(ForumCategory $category, ForumTopic $topic, ForumPost $post, array $ids, int $author_id): void {
		$who = Forum::person($author_id)['name'];
		self::send('forum.mention', $category, $topic, ForumPages::postUrl($topic, $post),
			$who . ' mentioned you: ' . Forum::plainExcerpt((string)$post->get('fpo_body'), 160), $ids, $author_id);
	}

	/**
	 * Only the people who can read the category.
	 *
	 * @param int[] $ids
	 * @return int[]
	 */
	public static function readers(ForumCategory $category, array $ids): array {
		return SpaceAccess::readableUserIds($category, $ids);
	}

	/** @param int[] $recipients */
	private static function send(string $signal, ForumCategory $category, ForumTopic $topic, string $url, string $summary, array $recipients, int $actor_id): void {
		$recipients = array_values(array_unique(array_map('intval', $recipients)));
		if (!$recipients) {
			return;
		}
		SignalBus::dispatch($signal, array(
			'category_name'  => mb_substr(self::oneLine((string)$category->get('fcg_name')), 0, 80),
			'topic_title'    => mb_substr(self::oneLine((string)$topic->get('ftp_title')), 0, 140),
			'topic_url'      => $url,
			'summary'        => mb_substr(self::oneLine($summary), 0, self::SUMMARY_LIMIT),
			'recipients'     => $recipients,
			'source_user_id' => $actor_id ?: null,
		));
	}

	private static function oneLine(string $text): string {
		return trim(preg_replace('/\s+/u', ' ', $text));
	}
}
