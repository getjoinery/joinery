<?php
/**
 * LearnSpamFeedback — the keyless half of spam learning: teaches rspamd's Bayes
 * classifier the spam / not-spam corrections members make in the Mailbox Reader
 * (specs/mailbox_spam_filtering_simplification.md D3), every cron pass.
 *
 * SpamLearning owns what a correction is, how a row becomes a message rspamd
 * can learn from, and the learn call. This task takes the rows that need no
 * window — stored in the clear, or end-to-end (never openable here, marked
 * handled). Rows sealed to a member's vault are left diverged for the
 * mailbox_spam_learn deferred-work consumer, which teaches them while that
 * member's window is open; this pass never selects them, so a sealed backlog
 * can never crowd the clear rows out of its batch.
 *
 * The controller binds to loopback and trusts loopback via secure_ip, so the learn
 * command is authorized by originating inside the container — no password to store.
 *
 * Where the corpus is taught is a deployment-wide question, not a per-message one:
 * with learning on, EVERY correction teaches it, whatever path the message arrived
 * by. Webhook-sourced and relay-sourced mail included — the local scanner scores
 * those at ingest, so its corpus is exactly where their corrections belong.
 *
 * A scanner that is missing or down skips the pass and leaves every row diverged,
 * which is what makes the loop self-heal through an outage and through a wiped
 * corpus. There is no per-row attempt counter: the dominant failure (controller
 * down) is global, so a cap would strand every pending correction.
 *
 * @version 1.2 - SpamLearning: only corrections are taught, lean records are rebuilt
 *   from their header block and body, sealed rows go to the in-window consumer
 * @version 1.1
 */

class LearnSpamFeedback implements ScheduledTaskInterface {

	public function run(array $config) {
		if (!MailboxSpamPolicy::learningEnabled()) {
			return array('status' => 'skipped',
				'message' => 'Learning from spam corrections is turned off.');
		}

		$controller = MailboxSpamPolicy::controllerUrl();

		// Expected but not yet installed (an owner just turned learning on), or
		// down mid-run. Neither is an error: the rows stay diverged and are taught
		// on a later pass, and the health probe carries the durable signal with the
		// install command.
		if (!MailboxSpamPolicy::controllerReachable()) {
			return array('status' => 'skipped',
				'message' => 'The spam scanner is not answering on ' . $controller
					. ' — corrections are held and will be taught once it is installed and running.');
		}

		$ids = SpamLearning::keylessIds();
		if (!count($ids)) {
			return array('status' => 'success', 'message' => 'No spam/ham corrections to reconcile.');
		}

		$tally = array(SpamLearning::TAUGHT => 0, SpamLearning::HANDLED => 0, SpamLearning::DEFERRED => 0);
		foreach ($ids as $id) {
			$tally[SpamLearning::teach($id, $controller)]++;
		}

		$summary = sprintf('Reconciled corrections: %d taught, %d marked handled (no-op), %d deferred (transient).',
			$tally[SpamLearning::TAUGHT], $tally[SpamLearning::HANDLED], $tally[SpamLearning::DEFERRED]);
		return array('status' => 'success', 'message' => $summary);
	}
}
?>
