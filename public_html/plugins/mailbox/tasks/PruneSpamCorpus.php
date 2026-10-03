<?php
/**
 * PruneSpamCorpus — keep the spam corpus and the sender records bounded
 * (spam_learning_in_core.md § Pruning). Weekly.
 *
 *   - tokens with spam + ham ≤ 1 not seen for 60 days go (one-off words that
 *     never recurred carry no evidence);
 *   - then the oldest low-count tokens past 2M rows;
 *   - sender records with no sends, no teachings and no message in 180 days.
 *
 * The totals row is never touched. Deleting a token a later message carries
 * again only costs that token's history.
 *
 * @version 1.0
 */

class PruneSpamCorpus implements ScheduledTaskInterface {

	const SENDER_IDLE_DAYS = 180;

	public function run(array $config) {
		$tokens = SpamBayes::prune();
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"DELETE FROM isr_inbound_sender_records
			  WHERE isr_sent_count = 0 AND isr_spam_taught = 0 AND isr_ham_taught = 0
			    AND COALESCE(isr_last_seen_time, isr_create_time) < (now() AT TIME ZONE 'UTC') - make_interval(days => ?)");
		$stmt->execute(array(self::SENDER_IDLE_DAYS));
		return array('status' => 'success', 'message' => sprintf(
			'Removed %d stale token(s), %d past the row cap, and %d idle sender record(s).',
			$tokens['stale'], $tokens['overflow'], $stmt->rowCount()));
	}
}
?>
