<?php
/**
 * LearnSpamFeedback — the keyless backstop of spam learning, every cron pass
 * (spam_learning_in_core.md § Teaching, § Backfill).
 *
 * Two jobs, both on rows that need no window:
 *   - teach the rows whose evidence (iem_train_verdict) the corpus has not
 *     learned yet. A correction is taught in the request that records it, so
 *     this pass only finds what a request did not finish — a failure, a bulk
 *     action past the per-request bound, a re-teach after an upgrade — and
 *     marks Fortress corrections handled;
 *   - fill the sender facts (fingerprints, arrivals, composed sends, contact
 *     fingerprints) for mail and contacts stored before they existed, a bounded
 *     batch per pass (SpamSenderBackfill). The relationship signals do not
 *     depend on learning, so this runs whenever filing is on.
 *
 * Rows sealed to a member's vault are left for the mailbox_spam_learn and
 * mailbox_spam_backfill deferred-work consumers, which run in that member's
 * window; this pass never selects them, so a sealed backlog can never crowd
 * the clear rows out of its batch.
 *
 * @version 2.0 - teaches the in-core corpus; no scanner to reach; fills the sender facts
 * @version 1.2
 */

class LearnSpamFeedback implements ScheduledTaskInterface {

	public function run(array $config) {
		if (!MailboxSpamPolicy::filingEnabled()) {
			return array('status' => 'skipped', 'message' => 'Spam filing is turned off.');
		}

		$filled = SpamSenderBackfill::keylessPass();

		if (!MailboxSpamPolicy::learningEnabled()) {
			return array('status' => 'success', 'message' => sprintf(
				'Learning from corrections is turned off. Sender facts filled for %d row(s).', $filled));
		}

		$tally = array(SpamLearning::TAUGHT => 0, SpamLearning::HANDLED => 0, SpamLearning::DEFERRED => 0);
		foreach (SpamLearning::keylessIds() as $id) {
			try {
				$tally[SpamLearning::teach($id)]++;
			} catch (\Throwable $e) {
				$tally[SpamLearning::DEFERRED]++;
				error_log('LearnSpamFeedback: teaching message ' . $id . ' failed: ' . $e->getMessage());
			}
		}

		return array('status' => 'success', 'message' => sprintf(
			'Taught %d, marked %d handled, %d failed. Sender facts filled for %d row(s).',
			$tally[SpamLearning::TAUGHT], $tally[SpamLearning::HANDLED], $tally[SpamLearning::DEFERRED], $filled));
	}
}
?>
