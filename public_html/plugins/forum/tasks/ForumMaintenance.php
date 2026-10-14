<?php
/**
 * ForumMaintenance — the forum's daily upkeep.
 *
 *   - Files uploaded for a post that was never posted are removed after a day.
 *   - Posts rendered by an older MarkdownRenderer are rendered again, so an
 *     improvement to the renderer reaches posts written before it.
 *   - Every topic's counters are recomputed from its posts. They are kept in
 *     the same transaction as every post change, so this normally changes
 *     nothing; when it does, it says how many topics it corrected.
 *
 * @version 1.1.0 - re-render is time-boxed, never overwrites an edit, and clears the cache; the counter check covers the last-post time
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class ForumMaintenance implements ScheduledTaskInterface {

	public function run(array $config) {
		if (!Forum::isActive()) {
			return array('status' => 'skipped', 'message' => 'The forum is turned off.');
		}
		$pruned = ForumUploads::pruneUnclaimed(24);
		$rendered = self::rerender();
		$fixed = self::reconcile();
		return array('status' => 'success',
			'message' => $pruned . ' unposted upload(s) removed, ' . $rendered . ' post(s) rendered again, '
				. $fixed . ' topic counter(s) corrected.');
	}

	/**
	 * Render again the posts an older renderer made, in batches, for at most
	 * $seconds. Only the HTML changes. Each write is conditional on the post's
	 * source and version still being what was read, so an edit made meanwhile
	 * is never overwritten with HTML of the old text. Rendered pages leave the
	 * static cache once at the end.
	 */
	public static function rerender(int $seconds = 20, int $batch = 500): int {
		$deadline = microtime(true) + max(1, $seconds);
		$n = 0;
		$after = 0;
		do {
			$q = DbConnector::GetPreparedStatement('SELECT fpo_forum_post_id, fpo_body, fpo_render_version FROM fpo_forum_posts
				WHERE fpo_render_version IS DISTINCT FROM ? AND fpo_forum_post_id > ?
				ORDER BY fpo_forum_post_id LIMIT ' . max(1, $batch));
			$q->execute(array(MarkdownRenderer::VERSION, $after));
			$rows = $q->fetchAll(PDO::FETCH_ASSOC);
			foreach ($rows as $row) {
				$after = (int)$row['fpo_forum_post_id'];
				$u = DbConnector::GetPreparedStatement('UPDATE fpo_forum_posts
					SET fpo_body_html = ?, fpo_render_version = ?
					WHERE fpo_forum_post_id = ? AND fpo_body = ? AND fpo_render_version IS NOT DISTINCT FROM ?');
				$u->execute(array(Forum::renderBody((string)$row['fpo_body']), MarkdownRenderer::VERSION,
					$after, (string)$row['fpo_body'], $row['fpo_render_version']));
				$n += $u->rowCount();
			}
		} while (count($rows) === $batch && microtime(true) < $deadline);
		if ($n > 0) {
			ForumCache::forumChanged();
		}
		return $n;
	}

	/** Recompute counters on topics whose stored values disagree with their posts. */
	public static function reconcile(): int {
		$q = DbConnector::GetPreparedStatement(
			"SELECT ftp.ftp_forum_topic_id FROM ftp_forum_topics ftp
			 WHERE ftp.ftp_reply_count <> (SELECT COUNT(*) FROM fpo_forum_posts fpo
			         WHERE fpo.fpo_ftp_forum_topic_id = ftp.ftp_forum_topic_id AND fpo.fpo_delete_time IS NULL
			           AND fpo.fpo_is_approved = TRUE AND fpo.fpo_is_first = FALSE)
			    OR ftp.ftp_last_post_id IS DISTINCT FROM (SELECT MAX(fpo.fpo_forum_post_id) FROM fpo_forum_posts fpo
			         WHERE fpo.fpo_ftp_forum_topic_id = ftp.ftp_forum_topic_id AND fpo.fpo_delete_time IS NULL
			           AND fpo.fpo_is_approved = TRUE)
			    OR ftp.ftp_last_post_time IS DISTINCT FROM COALESCE((SELECT fpo.fpo_create_time FROM fpo_forum_posts fpo
			         WHERE fpo.fpo_ftp_forum_topic_id = ftp.ftp_forum_topic_id AND fpo.fpo_delete_time IS NULL
			           AND fpo.fpo_is_approved = TRUE ORDER BY fpo.fpo_forum_post_id DESC LIMIT 1), ftp.ftp_create_time)");
		$q->execute();
		$ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
		foreach ($ids as $topic_id) {
			Forum::transaction(function () use ($topic_id) {
				Forum::lockTopic($topic_id);
				ForumPosting::recount($topic_id);
			});
		}
		return count($ids);
	}
}
