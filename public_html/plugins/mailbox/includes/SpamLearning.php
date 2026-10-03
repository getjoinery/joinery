<?php
/**
 * SpamLearning — teach the deployment's spam corpus (SpamBayes) and sender
 * records (SpamSenderRecords) what the user's own behaviour says about a
 * message (spam_learning_in_core.md § Teaching).
 *
 * WHAT IS TAUGHT. A row whose iem_train_verdict differs from its
 * iem_learned_verdict. iem_train_verdict is evidence from the user, set by
 * recordEvidence(): Mark as spam (spam), Not spam and Always allow (ham), and a
 * reply the user sent to the message (ham). NULL means no evidence, so a
 * verdict ingest wrote is never taught and the classifier never trains on its
 * own output. Deleting, reading, archiving and forwarding teach nothing.
 *
 * Only inbound mail that arrived here is taught. IMAP-polled rows never are:
 * the remote's junk folder is their verdict and their content is the remote's
 * mail stream. Fortress rows are never openable here; they are marked handled
 * (learned = train) so they stop selecting.
 *
 * ONE TEACHING, ONE TRANSACTION. teach() first compare-and-sets the learned
 * marker from the value it read; a row count other than 1 means another request
 * already taught it, and nothing more is written. Then it subtracts the message
 * from the class it was taught to before (when its tokenizer version still
 * matches; otherwise only the totals and the sender record move), adds it to the
 * new class, and moves the sender record's counters. A half-taught message
 * cannot exist. No per-message token list is ever stored: unteaching
 * retokenizes the message, which needs its plaintext.
 *
 * WHERE IT RUNS. Rows stored in the clear are taught in the request that records
 * the action (recordEvidence). Rows sealed to a member's vault wait for that
 * member's window: the mailbox_spam_learn deferred-work consumer drains them
 * (drainForUser). The LearnSpamFeedback cron pass is the backstop for clear rows
 * a request did not finish.
 *
 * @version 2.0 - the corpus is a Postgres table taught in the request; no
 *   rebuilt email, no HTTP call to a scanner
 * @version 1.0
 */

class SpamLearning {

	/** Bound one pass; teachings are human-paced, so the set is small. */
	const MAX_PER_PASS = 200;

	const TAUGHT   = 'taught';
	const HANDLED  = 'handled';
	const DEFERRED = 'deferred';

	/** A row with something to teach. */
	private static function pendingSql(): string {
		return "iem_train_verdict IS NOT NULL
			AND iem_train_verdict IS DISTINCT FROM iem_learned_verdict
			AND iem_pending_parse = false";
	}

	/** Sealed to a member's vault, openable in their window (not end-to-end). */
	public static function windowSealedSql(): string {
		return "(iem_content_sealed = true OR iem_raw_sealed = true)
			AND (iem_sealed_key IS NULL OR NOT " . InboundEmailMessage::mailKeySql() . ")";
	}

	/** Rows whose evidence teaches: inbound mail that arrived here, not polled from IMAP. */
	private static function eligibleSql(): string {
		return "iem_direction = 'inbound' AND iem_iia_inbound_imap_account_id IS NULL";
	}

	/**
	 * Record what the user's action says about these messages and teach the ones
	 * stored in the clear now. The caller has already scoped $ids to what the
	 * user may change. Rows that are not eligible (outbound, IMAP-polled) are
	 * left alone.
	 *
	 * @param int[] $ids
	 * @return int rows whose evidence was recorded
	 */
	public static function recordEvidence(array $ids, string $verdict): int {
		if (!in_array($verdict, array(InboundEmailMessage::SPAM_VERDICT_SPAM, InboundEmailMessage::SPAM_VERDICT_HAM), true)) {
			return 0;
		}
		$ids = array_values(array_filter(array_map('intval', $ids), function ($i) { return $i > 0; }));
		if (!count($ids)) {
			return 0;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$in = implode(',', $ids);
		$stmt = $db->prepare("UPDATE iem_inbound_email_messages SET iem_train_verdict = ?
			WHERE iem_inbound_email_message_id IN ($in) AND " . self::eligibleSql());
		$stmt->execute(array($verdict));
		$recorded = $stmt->rowCount();

		if ($recorded > 0 && MailboxSpamPolicy::learningEnabled()) {
			$now = $db->query("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
				WHERE iem_inbound_email_message_id IN ($in) AND " . self::pendingSql() . "
				  AND NOT (" . self::windowSealedSql() . ")
				ORDER BY iem_inbound_email_message_id ASC LIMIT " . self::MAX_PER_PASS)
				->fetchAll(PDO::FETCH_COLUMN, 0) ?: array();
			foreach ($now as $id) {
				try {
					self::teach(intval($id));
				} catch (\Throwable $e) {
					// The row stays diverged; the cron pass retries it.
					error_log('SpamLearning: teaching message ' . $id . ' failed: ' . $e->getMessage());
				}
			}
		}
		return $recorded;
	}

	/**
	 * Rows the keyless pass takes: everything that needs no window.
	 *
	 * @return int[]
	 */
	public static function keylessIds(int $limit = self::MAX_PER_PASS): array {
		$sql = "SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
				 WHERE " . self::pendingSql() . "
				   AND NOT (" . self::windowSealedSql() . ")
				 ORDER BY iem_inbound_email_message_id ASC
				 LIMIT " . max(1, intval($limit));
		$stmt = DbConnector::get_instance()->get_db_link()->query($sql);
		return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: array());
	}

	/**
	 * Rows sealed to $user_id's vault, for their window's pass.
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
			    AND " . self::pendingSql() . "
			    AND " . self::windowSealedSql() . "
			  ORDER BY iem_inbound_email_message_id ASC
			  LIMIT " . max(1, intval($limit)));
		$stmt->execute(array($user_id));
		return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: array());
	}

	/** Deferred-work predicate: cheap (settings, one indexed LIMIT 1), no decrypt. */
	public static function hasWindowWork(int $user_id): bool {
		return MailboxSpamPolicy::learningEnabled() && count(self::windowIds($user_id, 1)) > 0;
	}

	/**
	 * The window's pass: teach $user_id's sealed rows until $deadline. The caller
	 * holds the open window (VaultDeferredWork). Returns the number taught or
	 * handled.
	 */
	public static function drainForUser(int $user_id, float $deadline): int {
		if (!MailboxSpamPolicy::learningEnabled()) {
			return 0;
		}
		$done = 0;
		foreach (self::windowIds($user_id) as $id) {
			if (microtime(true) >= $deadline) {
				break;
			}
			// One message, one unit of the hot-turn rule: nothing this row opens
			// is in play when the next one starts. A row that fails for any other
			// reason than a shut window is logged and passed by, so it never holds
			// back every sealed row behind it; it stays diverged and is tried again.
			try {
				$outcome = SealedEgressGuard::isolate(function () use ($id) {
					return self::teach($id);
				});
			} catch (\Throwable $e) {
				error_log('SpamLearning: teaching message ' . $id . ' failed: ' . $e->getMessage());
				continue;
			}
			if ($outcome === self::DEFERRED) {
				break;   // the window shut: try again later
			}
			$done++;
		}
		return $done;
	}

	/**
	 * Teach one row. Re-reads it, and the compare-and-set makes a row another
	 * request taught since it was read a no-op.
	 */
	public static function teach(int $id): string {
		$msg = new InboundEmailMessage($id, TRUE);
		if (!$msg->key) {
			return self::HANDLED;
		}
		$new = (string)$msg->get('iem_train_verdict');
		$old = (string)$msg->get('iem_learned_verdict');
		if ($new === '' || $new === $old) {
			return self::HANDLED;
		}

		if (InboundEmailMessage::isBrowserSealed($msg)
				|| (string)$msg->get('iem_direction') !== 'inbound'
				|| intval($msg->get('iem_iia_inbound_imap_account_id')) > 0) {
			// Nothing here can or may be taught: mark it so it stops selecting.
			self::compareAndSet($id, $old, $new, null);
			return self::HANDLED;
		}

		try {
			$content = self::content($msg);
		} catch (VaultLockedException $e) {
			return self::DEFERRED;
		}
		$hashes = SpamBayes::hashes(SpamBayes::tokens($content));
		$alias_id = intval($msg->get('iem_iea_inbound_email_alias_id'));
		$fingerprint = (string)$msg->get('iem_sender_fingerprint');
		$fill_fingerprint = ($msg->get('iem_sender_fingerprint') === null);
		if ($fill_fingerprint) {
			$fingerprint = SpamSenderRecords::fingerprintOfSender((string)$content['sender']);
		}
		$old_tokenizer = intval($msg->get('iem_learned_tokenizer'));

		$db = DbConnector::get_instance()->get_db_link();
		$owns_tx = !$db->inTransaction();
		if ($owns_tx) {
			$db->beginTransaction();
		}
		try {
			if (!self::compareAndSet($id, $old, $new, SpamBayes::TOKENIZER_VERSION)) {
				if ($owns_tx) {
					$db->rollBack();
				}
				return self::HANDLED;   // another request taught it first
			}
			if ($old !== '') {
				SpamBayes::adjust($old, ($old_tokenizer === SpamBayes::TOKENIZER_VERSION) ? $hashes : null, -1);
				SpamSenderRecords::adjustTaught($alias_id, $fingerprint, $old, -1);
			}
			SpamBayes::adjust($new, $hashes, 1);
			SpamSenderRecords::adjustTaught($alias_id, $fingerprint, $new, 1);
			if ($fill_fingerprint) {
				$db->prepare('UPDATE iem_inbound_email_messages SET iem_sender_fingerprint = ?
					WHERE iem_inbound_email_message_id = ? AND iem_sender_fingerprint IS NULL')
					->execute(array($fingerprint, $id));
			}
			if ($owns_tx) {
				$db->commit();
			}
		} catch (\Throwable $e) {
			if ($owns_tx && $db->inTransaction()) {
				$db->rollBack();
			}
			throw $e;
		}
		return self::TAUGHT;
	}

	/**
	 * The parts of a message the tokenizer reads, opened through the model (a
	 * sealed row needs its owner's window and throws VaultLockedException
	 * without it). Nothing is written anywhere.
	 *
	 * @return array{subject:string, body_plain:string, body_html:string, sender:string, meta:string[]}
	 */
	public static function content(InboundEmailMessage $msg): array {
		return array(
			'subject'    => (string)$msg->get('iem_subject'),
			'body_plain' => (string)$msg->get('iem_body_plain'),
			'body_html'  => (string)$msg->get('iem_body_html'),
			'sender'     => (string)$msg->get('iem_sender'),
			'meta'       => self::metaTokens((string)$msg->get('iem_spam_meta')),
		);
	}

	/** The meta tokens stored on a row (iem_spam_meta). */
	public static function metaTokens(string $stored): array {
		return SpamMeta::decode($stored)['tokens'];
	}

	/**
	 * Move the learned marker from $old to $new, only if it still reads $old.
	 * True when this call made the move.
	 */
	private static function compareAndSet(int $id, string $old, string $new, ?int $tokenizer): bool {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'UPDATE iem_inbound_email_messages SET iem_learned_verdict = ?, iem_learned_tokenizer = ?
			  WHERE iem_inbound_email_message_id = ? AND iem_learned_verdict IS NOT DISTINCT FROM ?');
		$stmt->execute(array($new, $tokenizer, $id, ($old === '') ? null : $old));
		return $stmt->rowCount() === 1;
	}
}
?>
