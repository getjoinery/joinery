<?php
/**
 * SpamLearning — teach the local rspamd Bayes corpus the spam / not-spam
 * corrections members make in the reader
 * (specs/mailbox_spam_filtering_simplification.md D3).
 *
 * WHAT IS TAUGHT. A correction, and only a correction: a row a member marked
 * (MailboxService::setSpamVerdict stamps iem_spam_corrected_time) whose verdict
 * differs from what was last taught (iem_learned_verdict). The verdict ingest
 * wrote is the scanner's own answer; teaching it back would only confirm the
 * scanner to itself. Flip-backs fall out for free — change the verdict and the
 * row diverges again and is re-taught the new way.
 *
 * WHERE IT IS TAUGHT. Two passes split the rows between them by what opening a
 * row needs, so neither re-selects rows it can never finish:
 *
 *   keyless  — LearnSpamFeedback, every cron pass: rows stored in the clear,
 *              plus end-to-end (Fortress) rows, which no server ever opens and
 *              are marked handled.
 *   window   — the mailbox_spam_learn deferred-work consumer
 *              (includes/bootstrap.php): rows sealed to a member's vault,
 *              taught while that member's window is open.
 *
 * WHAT IS SENT. rspamd learns from an RFC822 message. A row that kept its whole
 * raw sends that. Most rows did not (the lean record keeps the header block and
 * the decoded bodies, and splits attachments into Files), so the message is
 * rebuilt from those: the stored header block with its MIME structure headers
 * replaced by a single text/plain part holding the plain body (or the readable
 * text of the HTML body). Bayes reads headers and text, which is all of it. The
 * original's multipart headers are dropped because they would describe parts
 * the rebuilt body does not have, and rspamd would find no text to learn.
 *
 * Sending sealed content to the scanner is the same loopback hand-off the
 * ingest scan makes with every arriving message; nothing is written anywhere
 * but the learned marker, a closed-vocabulary word.
 *
 * OUTCOMES. taught (marker stamped); handled (nothing any server can teach —
 * Fortress, no stored text, or the scanner refused the message itself — marker
 * stamped so the row stops re-selecting); deferred (scanner down or failing,
 * or the window shut mid-pass — the row stays diverged and is retried, which is
 * what lets the corpus heal through an outage or a wipe).
 *
 * @version 1.0
 */

class SpamLearning {

	/** Bound one pass; corrections are human-paced, so the set is small. */
	const MAX_PER_PASS = 200;

	const TAUGHT   = 'taught';
	const HANDLED  = 'handled';
	const DEFERRED = 'deferred';

	/** Headers that describe the original's MIME structure, replaced on a rebuilt message. */
	const STRUCTURE_HEADERS = array('mime-version', 'content-type', 'content-transfer-encoding', 'content-disposition');

	/** A correction not yet taught. */
	private static function correctionSql(): string {
		return "iem_spam_corrected_time IS NOT NULL
			AND iem_spam_verdict IS NOT NULL
			AND iem_spam_verdict IS DISTINCT FROM iem_learned_verdict
			AND iem_pending_parse = false";
	}

	/** Sealed to a member's vault, openable in their window (not end-to-end). */
	private static function windowSealedSql(): string {
		return "(iem_content_sealed = true OR iem_raw_sealed = true)
			AND (iem_sealed_key IS NULL OR NOT " . InboundEmailMessage::mailKeySql() . ")";
	}

	/**
	 * Corrections the keyless pass takes: everything that needs no window.
	 *
	 * @return int[]
	 */
	public static function keylessIds(int $limit = self::MAX_PER_PASS): array {
		$sql = "SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
				 WHERE " . self::correctionSql() . "
				   AND NOT (" . self::windowSealedSql() . ")
				 ORDER BY iem_inbound_email_message_id ASC
				 LIMIT " . max(1, intval($limit));
		$stmt = DbConnector::get_instance()->get_db_link()->query($sql);
		return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: array());
	}

	/**
	 * Corrections sealed to $user_id's vault, for their window's pass.
	 *
	 * @return int[]
	 */
	public static function windowIds(int $user_id, int $limit = self::MAX_PER_PASS): array {
		if ($user_id <= 0) {
			return array();
		}
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
			  WHERE iem_sealed_owner_user_id = ?
			    AND " . self::correctionSql() . "
			    AND " . self::windowSealedSql() . "
			  ORDER BY iem_inbound_email_message_id ASC
			  LIMIT " . max(1, intval($limit)));
		$stmt->execute(array($user_id));
		return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: array());
	}

	/**
	 * Deferred-work predicate: cheap (settings, one indexed LIMIT 1, then a
	 * loopback connect only when there is work), no decrypt. A scanner that is
	 * down reports no work, so the heartbeat does not keep asking for drains
	 * that cannot finish; the rows wait, diverged, for it to come back.
	 */
	public static function hasWindowWork(int $user_id): bool {
		return MailboxSpamPolicy::learningEnabled() && count(self::windowIds($user_id, 1)) > 0
			&& MailboxSpamPolicy::scannerAvailable();
	}

	/**
	 * The window's pass: teach $user_id's sealed corrections until $deadline.
	 * The caller holds the open window (VaultDeferredWork). Returns the number
	 * taught or handled.
	 */
	public static function drainForUser(int $user_id, float $deadline): int {
		if (!MailboxSpamPolicy::learningEnabled() || !MailboxSpamPolicy::scannerAvailable()) {
			return 0;
		}
		$controller = MailboxSpamPolicy::controllerUrl();
		$done = 0;
		foreach (self::windowIds($user_id) as $id) {
			if (microtime(true) >= $deadline) {
				break;
			}
			// One message, one unit of the hot-turn rule: nothing this row opens
			// is in play when the next one starts.
			$outcome = SealedEgressGuard::isolate(function () use ($id, $controller) {
				return self::teach($id, $controller);
			});
			if ($outcome === self::DEFERRED) {
				break;   // the scanner is failing, or the window shut: try again later
			}
			$done++;
		}
		return $done;
	}

	/**
	 * Teach one row. Re-reads it, so a row taught since it was selected (another
	 * tab's pass) is left alone.
	 */
	public static function teach(int $id, string $controller): string {
		$msg = new InboundEmailMessage($id, TRUE);
		if (!$msg->key) {
			return self::HANDLED;
		}
		$verdict = (string)$msg->get('iem_spam_verdict');
		if ($verdict === '' || $verdict === (string)$msg->get('iem_learned_verdict')) {
			return self::HANDLED;
		}
		try {
			$text = self::learnText($msg);
		} catch (VaultLockedException $e) {
			return self::DEFERRED;
		}
		if ($text === null) {
			self::markTaught($id, $verdict);
			return self::HANDLED;
		}
		$cmd = ($verdict === InboundEmailMessage::SPAM_VERDICT_SPAM) ? 'learnspam' : 'learnham';
		$result = self::post($controller, $cmd, $text);
		if ($result === self::DEFERRED) {
			return self::DEFERRED;
		}
		self::markTaught($id, $verdict);
		return $result;
	}

	/**
	 * The message rspamd learns from, or null when nothing a server can read
	 * is stored. Throws VaultLockedException for a sealed row with no window.
	 */
	public static function learnText(InboundEmailMessage $msg): ?string {
		if (InboundEmailMessage::isBrowserSealed($msg)) {
			return null;   // end-to-end: only the owner's devices ever open it
		}
		$raw = $msg->getRawMessage();
		if ($raw !== null && $raw !== '') {
			return $raw;
		}
		$headers = (string)$msg->get('iem_raw_headers');
		$body = (string)$msg->get('iem_body_plain');
		if (trim($body) === '') {
			$html = (string)$msg->get('iem_body_html');
			$body = ($html !== '') ? MailboxHtmlSanitizer::toReadableText($html) : '';
		}
		if (trim($headers) === '' && trim($body) === '') {
			return null;
		}
		return self::plainMessage($headers, $body);
	}

	/**
	 * A header block and a text body as one text/plain RFC822 message: the
	 * block's MIME structure headers (and their folded continuations) removed,
	 * a text/plain declaration put in their place.
	 */
	public static function plainMessage(string $headers, string $body): string {
		$kept = array();
		$dropping = false;
		foreach (preg_split('/\r\n|\n|\r/', trim($headers, "\r\n")) as $line) {
			if ($line === '') {
				continue;
			}
			if ($line[0] === ' ' || $line[0] === "\t") {
				if (!$dropping) {
					$kept[] = $line;
				}
				continue;
			}
			$name = strtolower(trim(strstr($line, ':', true) ?: ''));
			$dropping = in_array($name, self::STRUCTURE_HEADERS, true);
			if (!$dropping) {
				$kept[] = $line;
			}
		}
		$kept[] = 'MIME-Version: 1.0';
		$kept[] = 'Content-Type: text/plain; charset=utf-8';
		$kept[] = 'Content-Transfer-Encoding: 8bit';
		$body = preg_replace('/\r\n|\r|\n/', "\r\n", $body);
		return implode("\r\n", $kept) . "\r\n\r\n" . $body;
	}

	/** Stamp the learned marker with the verdict just taught (or found unteachable). */
	private static function markTaught(int $id, string $verdict): void {
		DbConnector::get_instance()->get_db_link()
			->prepare('UPDATE iem_inbound_email_messages SET iem_learned_verdict = ?
			            WHERE iem_inbound_email_message_id = ?')
			->execute(array($verdict, $id));
	}

	/**
	 * POST a message to the controller's learn endpoint over loopback.
	 * taught: learned, or already learned (the corpus reflects it). handled:
	 * rspamd refused the message itself (a 4xx — too few tokens, unparseable),
	 * which no retry changes. deferred: no answer, or a server-side failure.
	 */
	private static function post(string $controller, string $cmd, string $message): string {
		$url = rtrim($controller, '/') . '/' . $cmd;
		$code = 0;
		$body = false;

		if (function_exists('curl_init')) {
			$ch = curl_init($url);
			curl_setopt_array($ch, array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $message,
				CURLOPT_HTTPHEADER     => array('Content-Type: text/plain'),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT        => 15,
			));
			$body = curl_exec($ch);
			$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			if ($body === false) {
				error_log('SpamLearning: controller ' . $cmd . ' POST failed: ' . curl_error($ch));
			}
		} else {
			$ctx = stream_context_create(array('http' => array(
				'method'        => 'POST',
				'header'        => "Content-Type: text/plain\r\n",
				'content'       => $message,
				'timeout'       => 15,
				'ignore_errors' => true,
			)));
			$body = @file_get_contents($url, false, $ctx);
			// PHP 8.5 deprecates the magic $http_response_header local; its
			// replacement is 8.4-and-later, so both are kept while the fleet
			// spans both. A status line need not carry text after the code.
			$status_line = '';
			if (function_exists('http_get_last_response_headers')) {
				$headers = http_get_last_response_headers();
				if (is_array($headers) && isset($headers[0])) {
					$status_line = (string)$headers[0];
				}
			} else if (isset($http_response_header[0])) {
				$status_line = (string)$http_response_header[0];
			}
			if ($status_line !== '' && preg_match('/\s(\d{3})(?:\s|$)/', $status_line, $m)) {
				$code = (int)$m[1];
			}
			if ($body === false) {
				error_log('SpamLearning: controller ' . $cmd . ' POST failed (stream).');
			}
		}
		if ($body === false) {
			return self::DEFERRED;
		}
		$body = (string)$body;
		if (($code >= 200 && $code < 300) || stripos($body, 'already learned') !== false) {
			return self::TAUGHT;
		}
		error_log('SpamLearning: controller ' . $cmd . ' returned HTTP ' . $code . ': ' . substr($body, 0, 200));
		if ($code >= 400 && $code < 500 && $code !== 408 && $code !== 429) {
			return self::HANDLED;
		}
		return self::DEFERRED;
	}
}
?>
