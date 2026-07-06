<?php
/**
 * LearnSpamFeedback — trains rspamd's Bayes classifier from manual spam/ham
 * corrections in the Mailbox Reader (specs/inbound_email_content_spam_filtering.md).
 *
 * There is no job queue. iem_spam_verdict is the source of truth and
 * iem_learned_verdict is a per-row marker of what has actually been taught. A
 * reader correction (MailboxService::setSpamVerdict) flips the verdict, leaving the
 * row *diverged* from its learned marker. This task reconciles the divergence
 * out-of-band on the 15-minute cron: for each diverged row it POSTs the raw RFC822
 * to the rspamd controller's /learnspam | /learnham and, on success, stamps
 * iem_learned_verdict = iem_spam_verdict so the row stops re-selecting. Flip-backs
 * and idempotency fall out for free — change the verdict and the row re-selects and
 * relearns the new direction.
 *
 * The controller binds to loopback and trusts loopback via secure_ip, so the learn
 * command is authorized by originating inside the container — no password to store.
 *
 * Permanent no-ops (mark handled, never retry): a webhook deployment (no local
 * rspamd), a webhook-sourced row, and a row whose raw is gone (pruned, or IMAP
 * reference-backed). Transient failures (controller down / learn error): leave the
 * row diverged so it retries next pass — which is what makes the loop self-heal
 * through an outage. There is no per-row attempt counter: the dominant failure
 * (controller down) is global, so a cap would strand every pending correction.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundProviderRegistry.php'));

class LearnSpamFeedback implements ScheduledTaskInterface {

	/** Webhook provider auth-sources whose rows have no local rspamd to teach. */
	const WEBHOOK_SOURCES = array('mailgun', 'sendgrid', 'ses');

	/** Bound the per-run batch; the unreconciled set is small and human-paced. */
	const MAX_PER_RUN = 200;

	public function run(array $config) {
		$settings = Globalvars::get_instance();
		if (!$settings->get_setting('mailbox_content_spam_filtering_enabled')) {
			return array('status' => 'skipped', 'message' => 'Content spam filtering is disabled.');
		}

		// A webhook deployment has no local rspamd: every correction is a permanent
		// no-op. Mark the whole diverged set handled so it never retries.
		$provider = InboundProviderRegistry::active();
		if ($provider::isWebhook()) {
			$n = $this->markAllHandled();
			return array('status' => 'success',
				'message' => 'Webhook provider active (no local rspamd); marked ' . $n . ' correction(s) handled.');
		}

		$controller = rtrim((string)$settings->get_setting('mailbox_rspamd_controller_url'), '/');
		if ($controller === '') {
			$controller = 'http://127.0.0.1:11334';
		}

		$rows = $this->divergedRows(self::MAX_PER_RUN);
		if (!count($rows)) {
			return array('status' => 'success', 'message' => 'No spam/ham corrections to reconcile.');
		}

		$taught = 0; $handled = 0; $transient = 0;
		foreach ($rows as $r) {
			$id = (int)$r['iem_inbound_email_message_id'];
			$verdict = (string)$r['iem_spam_verdict'];

			// Webhook-sourced row (provider scanned upstream) → permanent no-op.
			if (in_array((string)$r['iem_auth_source'], self::WEBHOOK_SOURCES, true)) {
				$this->markReconciled($id);
				$handled++;
				continue;
			}

			$msg = new InboundEmailMessage($id, true);
			$raw = $msg->getRawMessage();
			if ($raw === null || $raw === '') {
				// Pruned or reference-backed (IMAP) — nothing local to learn from.
				$this->markReconciled($id);
				$handled++;
				continue;
			}

			$cmd = ($verdict === InboundEmailMessage::SPAM_VERDICT_SPAM) ? 'learnspam' : 'learnham';
			if ($this->postLearn($controller, $cmd, $raw)) {
				$this->markReconciled($id);
				$taught++;
			} else {
				$transient++; // leave diverged; retry next pass
			}
		}

		$summary = sprintf('Reconciled corrections: %d taught, %d marked handled (no-op), %d deferred (transient).',
			$taught, $handled, $transient);
		return array('status' => 'success', 'message' => $summary);
	}

	/**
	 * Rows whose verdict diverges from what was last taught. This is the whole
	 * feedback loop's selection — once taught, iem_learned_verdict catches up and the
	 * row drops out.
	 */
	private function divergedRows(int $limit): array {
		$db = DbConnector::get_instance()->get_db_link();
		$sql = "SELECT iem_inbound_email_message_id, iem_spam_verdict, iem_auth_source
				FROM iem_inbound_email_messages
				WHERE iem_spam_verdict IS DISTINCT FROM iem_learned_verdict
				  AND iem_spam_verdict IS NOT NULL
				ORDER BY iem_inbound_email_message_id ASC
				LIMIT " . intval($limit);
		$stmt = $db->prepare($sql);
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
	}

	/** Stamp the learned marker equal to the current verdict (taught or no-op). */
	private function markReconciled(int $id): void {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"UPDATE iem_inbound_email_messages
			 SET iem_learned_verdict = iem_spam_verdict
			 WHERE iem_inbound_email_message_id = ?");
		$stmt->execute(array($id));
	}

	/** Mark every diverged row handled at once (webhook deployment). Returns count. */
	private function markAllHandled(): int {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"UPDATE iem_inbound_email_messages
			 SET iem_learned_verdict = iem_spam_verdict
			 WHERE iem_spam_verdict IS DISTINCT FROM iem_learned_verdict
			   AND iem_spam_verdict IS NOT NULL");
		$stmt->execute();
		return $stmt->rowCount();
	}

	/**
	 * POST a raw message to the rspamd controller's learn endpoint over loopback.
	 * Returns true on success (or an idempotent "already learned" response). A
	 * connection/learn failure returns false → the row stays diverged and retries.
	 */
	private function postLearn(string $controller, string $cmd, string $raw): bool {
		$url = $controller . '/' . $cmd;

		if (function_exists('curl_init')) {
			$ch = curl_init($url);
			curl_setopt_array($ch, array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $raw,
				CURLOPT_HTTPHEADER     => array('Content-Type: text/plain'),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT        => 15,
			));
			$body = curl_exec($ch);
			$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$err  = curl_error($ch);
			curl_close($ch);

			if ($body === false) {
				error_log('LearnSpamFeedback: controller ' . $cmd . ' POST failed: ' . $err);
				return false;
			}
			return $this->learnSucceeded($code, (string)$body, $cmd);
		}

		// Stream fallback when curl is unavailable.
		$ctx = stream_context_create(array('http' => array(
			'method'        => 'POST',
			'header'        => "Content-Type: text/plain\r\n",
			'content'       => $raw,
			'timeout'       => 15,
			'ignore_errors' => true,
		)));
		$body = @file_get_contents($url, false, $ctx);
		if ($body === false) {
			error_log('LearnSpamFeedback: controller ' . $cmd . ' POST failed (stream).');
			return false;
		}
		$code = 0;
		if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
			$code = (int)$m[1];
		}
		return $this->learnSucceeded($code, (string)$body, $cmd);
	}

	/**
	 * Whether an rspamd learn response counts as success. HTTP 200 is a clean learn;
	 * an "already learned" body is idempotent (the corpus already reflects it), so it
	 * also counts — otherwise the row would re-select forever.
	 */
	private function learnSucceeded(int $code, string $body, string $cmd): bool {
		if ($code === 200) {
			return true;
		}
		if (stripos($body, 'already learned') !== false) {
			return true;
		}
		error_log('LearnSpamFeedback: controller ' . $cmd . ' returned HTTP ' . $code . ': ' . substr($body, 0, 200));
		return false;
	}
}
?>
