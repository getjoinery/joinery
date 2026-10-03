<?php
/**
 * SpamSenderBackfill — fill the spam filter's sender facts for mail and
 * contacts written before they existed (spam_learning_in_core.md
 * § Backfill).
 *
 * Three things, each with its own "not done yet" marker:
 *   - inbound rows (iem_sender_recorded = false): the sender fingerprint, and one
 *     arrival in that sender's record;
 *   - outbound rows (iem_sender_recorded = false): one composed send to each of
 *     its recipients;
 *   - contacts (imc_sender_fingerprint IS NULL): the address's fingerprint.
 *
 * Rows stored in the clear are filled by the keyless cron pass
 * (LearnSpamFeedback), a bounded batch per run, so the work finishes within
 * hours on any mailbox and never holds the runner. Rows sealed to a member's
 * vault are filled in that member's window by the mailbox_spam_backfill
 * deferred-work consumer, which reads them through the model and writes nothing
 * but fingerprints and counters. Fortress rows are never readable here; they
 * are marked recorded with nothing to record.
 *
 * The relationship signals work on new mail at once (ingest and sending record
 * as they go) and grow stronger as this completes.
 *
 * @version 1.0
 */

class SpamSenderBackfill {

	const BATCH = 500;

	/** The subject MailboxSender::normalizeSubject gives a forward. */
	const FORWARD_SUBJECT = '/^\s*(fwd?|fw)\s*:/i';

	/** Rows still owed, by whether they need a window. */
	private static function rowsSql(): string {
		return "iem_sender_recorded = false AND iem_direction IN ('inbound', 'outbound')
			AND iem_pending_parse = false AND iem_iia_inbound_imap_account_id IS NULL";
	}

	/**
	 * The keyless pass: everything stored in the clear, up to $limit rows and
	 * $limit contacts. Returns how many were filled.
	 */
	public static function keylessPass(int $limit = self::BATCH): int {
		$db = DbConnector::get_instance()->get_db_link();

		// Fortress rows: nothing a server can read, so nothing to record. IMAP-polled
		// rows: the remote's mail stream, outside the spam filter (and a remote Sent
		// folder holds forwards nobody can tell apart from composed mail).
		$db->exec("UPDATE iem_inbound_email_messages SET iem_sender_recorded = true
			WHERE iem_sender_recorded = false
			  AND (" . InboundEmailMessage::mailKeySql() . " OR iem_iia_inbound_imap_account_id IS NOT NULL)");

		$ids = $db->query("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
			WHERE " . self::rowsSql() . " AND NOT (" . SpamLearning::windowSealedSql() . ")
			ORDER BY iem_inbound_email_message_id ASC LIMIT " . max(1, intval($limit)))
			->fetchAll(PDO::FETCH_COLUMN, 0) ?: array();
		$done = 0;
		foreach ($ids as $id) {
			try {
				if (self::recordRow(intval($id))) {
					$done++;
				}
			} catch (\Throwable $e) {
				self::passOver(intval($id), $e);
			}
		}

		$contacts = $db->query("SELECT imc_mailbox_contact_id, imc_address FROM imc_mailbox_contacts
			WHERE imc_sender_fingerprint IS NULL AND imc_content_sealed = false
			ORDER BY imc_mailbox_contact_id ASC LIMIT " . max(1, intval($limit)))
			->fetchAll(PDO::FETCH_ASSOC) ?: array();
		foreach ($contacts as $c) {
			self::setContactFingerprint(intval($c['imc_mailbox_contact_id']), (string)$c['imc_address']);
			$done++;
		}
		return $done;
	}

	/** Deferred-work predicate: one indexed LIMIT 1 per kind, no decrypt. */
	public static function hasWindowWork(int $user_id): bool {
		if ($user_id <= 0) {
			return false;
		}
		return count(self::windowRowIds($user_id, 1)) > 0 || count(self::windowContactIds($user_id, 1)) > 0;
	}

	/**
	 * The window's pass for $user_id's sealed rows and contacts, until
	 * $deadline. The caller holds the open window.
	 */
	public static function drainForUser(int $user_id, float $deadline): int {
		$done = 0;
		foreach (self::windowRowIds($user_id, self::BATCH) as $id) {
			if (microtime(true) >= $deadline) {
				return $done;
			}
			try {
				$ok = SealedEgressGuard::isolate(function () use ($id) {
					return self::recordRow($id);
				});
			} catch (\Throwable $e) {
				self::passOver($id, $e);
				continue;
			}
			if (!$ok) {
				return $done;   // the window shut mid-pass
			}
			$done++;
		}
		foreach (self::windowContactIds($user_id, self::BATCH) as $cid) {
			if (microtime(true) >= $deadline) {
				return $done;
			}
			$ok = SealedEgressGuard::isolate(function () use ($cid) {
				try {
					$contact = new MailboxContact($cid, TRUE);
					self::setContactFingerprint($cid, (string)$contact->get('imc_address'));
					return true;
				} catch (VaultLockedException $e) {
					return false;
				} catch (\Throwable $e) {
					// An unreadable contact gets '' so it stops selecting; the
					// contact signal simply never finds it.
					error_log('SpamSenderBackfill: contact ' . $cid . ' could not be read: ' . $e->getMessage());
					self::setContactFingerprintValue($cid, '');
					return true;
				}
			});
			if (!$ok) {
				return $done;
			}
			$done++;
		}
		return $done;
	}

	/** @return int[] */
	private static function windowRowIds(int $user_id, int $limit): array {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
			  WHERE iem_sealed_owner_user_id = ? AND " . self::rowsSql() . "
			    AND " . SpamLearning::windowSealedSql() . "
			  ORDER BY iem_inbound_email_message_id ASC LIMIT " . max(1, intval($limit)));
		$stmt->execute(array($user_id));
		return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: array());
	}

	/** @return int[] */
	private static function windowContactIds(int $user_id, int $limit): array {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT imc_mailbox_contact_id FROM imc_mailbox_contacts
			  WHERE imc_usr_user_id = ? AND imc_sender_fingerprint IS NULL AND imc_content_sealed = true
			  ORDER BY imc_mailbox_contact_id ASC LIMIT " . max(1, intval($limit)));
		$stmt->execute(array($user_id));
		return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: array());
	}

	/**
	 * Record one row's facts and mark it recorded. False only when a sealed row
	 * could not be opened (the window closed), which leaves it for later.
	 */
	public static function recordRow(int $id): bool {
		$msg = new InboundEmailMessage($id, TRUE);
		if (!$msg->key || $msg->get('iem_sender_recorded')) {
			return true;
		}
		$alias_id = intval($msg->get('iem_iea_inbound_email_alias_id'));
		$time = (string)$msg->get('iem_received_time');
		$db = DbConnector::get_instance()->get_db_link();
		try {
			if ((string)$msg->get('iem_direction') === 'outbound') {
				// A forward the user sent from the reader has a Sent copy like any
				// compose; its recipients are not people the user writes to. The
				// composer marks new ones recorded at send; one stored before that
				// is known by the prefix the composer gives a forward's subject.
				if (preg_match(self::FORWARD_SUBJECT, (string)$msg->get('iem_subject'))) {
					self::claim($id);
					return true;
				}
				$addresses = array();
				foreach (array('iem_to', 'iem_cc', 'iem_bcc') as $col) {
					$addresses = array_merge($addresses, MailAddressList::addresses((string)$msg->get($col)));
				}
				if (!count($addresses)) {
					$addresses = MailAddressList::addresses((string)$msg->get('iem_recipient'));
				}
				$db->beginTransaction();
				$claimed = self::claim($id);
				if ($claimed) {
					SpamSenderRecords::recordSent($alias_id, $addresses, $time);
				}
				$db->commit();
				return true;
			}
			$fingerprint = $msg->get('iem_sender_fingerprint');
			if ($fingerprint === null) {
				$fingerprint = SpamSenderRecords::fingerprintOfSender((string)$msg->get('iem_sender'));
			}
			$db->beginTransaction();
			if (self::claim($id, (string)$fingerprint)) {
				SpamSenderRecords::recordArrival($alias_id, (string)$fingerprint, $time);
			}
			$db->commit();
			return true;
		} catch (VaultLockedException $e) {
			return false;
		} catch (\Throwable $e) {
			if ($db->inTransaction()) {
				$db->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * Mark a row recorded (and fill its fingerprint), only if it was not
	 * already: two passes over one row record it once.
	 */
	private static function claim(int $id, ?string $fingerprint = null): bool {
		$db = DbConnector::get_instance()->get_db_link();
		if ($fingerprint === null) {
			$stmt = $db->prepare('UPDATE iem_inbound_email_messages SET iem_sender_recorded = true
				WHERE iem_inbound_email_message_id = ? AND iem_sender_recorded = false');
			$stmt->execute(array($id));
		} else {
			$stmt = $db->prepare('UPDATE iem_inbound_email_messages SET iem_sender_recorded = true,
					iem_sender_fingerprint = COALESCE(iem_sender_fingerprint, ?)
				WHERE iem_inbound_email_message_id = ? AND iem_sender_recorded = false');
			$stmt->execute(array($fingerprint, $id));
		}
		return $stmt->rowCount() === 1;
	}

	/**
	 * A row that cannot be recorded (corrupt, undecryptable) is marked recorded
	 * with nothing counted, so it never again stands first in every pass and
	 * holds back the rows behind it. A database error is left for the next pass
	 * instead: it is usually transient (a deadlock, a dropped connection), and
	 * claiming the row would lose its count for good.
	 */
	private static function passOver(int $id, \Throwable $e): void {
		if ($e instanceof PDOException) {
			error_log('SpamSenderBackfill: message ' . $id . ' hit a database error, retried next pass: ' . $e->getMessage());
			return;
		}
		error_log('SpamSenderBackfill: message ' . $id . ' could not be recorded, passed over: ' . $e->getMessage());
		try {
			self::claim($id);
		} catch (\Throwable $ignored) {
			// The database itself is failing; the next pass tries again.
		}
	}

	private static function setContactFingerprint(int $contact_id, string $address): void {
		$parsed = MailboxContacts::parseAddress($address);
		self::setContactFingerprintValue($contact_id, ($parsed !== null) ? SpamSenderRecords::fingerprint($parsed[0]) : '');
	}

	private static function setContactFingerprintValue(int $contact_id, string $fp): void {
		DbConnector::get_instance()->get_db_link()
			->prepare('UPDATE imc_mailbox_contacts SET imc_sender_fingerprint = ?
				WHERE imc_mailbox_contact_id = ? AND imc_sender_fingerprint IS NULL')
			->execute(array($fp, $contact_id));
	}
}
?>
