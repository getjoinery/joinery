<?php
/**
 * SpamSenderRecords — the sender fingerprint and the facts the spam filter
 * keeps per (mailbox, sender) (spam_learning_in_core.md § The sender
 * record, § The sender fingerprint).
 *
 * fingerprint(address) = HMAC-SHA256(mailbox_sender_fingerprint_key,
 * lowercase(trim(address))), 64 hex characters. It is computed wherever the
 * plaintext address is in hand: at ingest from the header From, on a composed
 * send from each recipient, on a contact add or import. That is what lets the
 * relationship signals work on sealed mailboxes, at ingest, with no window open.
 *
 * Every write is one upsert statement on the (mailbox, fingerprint) unique pair,
 * so concurrent writers never lose an increment. Counters only move by deltas
 * and are clamped at 0 on the way down.
 *
 * The reply signal does not need a fingerprint: it asks whether a Message-ID
 * the arriving message refers to is one the user's own send minted
 * (MailboxSender records each one on mst_mailbox_send_attempts).
 *
 * @version 1.0
 */

class SpamSenderRecords {

	/** The fingerprint of an address, or '' when there is no usable address. */
	public static function fingerprint(string $address): string {
		$address = strtolower(trim($address, " \t\r\n<>"));
		if ($address === '' || strpos($address, '@') === false) {
			return '';
		}
		return hash_hmac('sha256', $address, MailboxSpamKeys::get(MailboxSpamKeys::FINGERPRINT));
	}

	/**
	 * The fingerprint of the address in a From display string
	 * ('"Name" <addr>' or a bare address), '' when there is none.
	 */
	public static function fingerprintOfSender(string $sender): string {
		$parsed = MailboxContacts::parseAddress($sender);
		if ($parsed === null) {
			// parseAddress validates strictly; a sender that is not a valid address
			// still gets a fingerprint of what it is, so the same malformed sender
			// still accumulates history.
			$sender = trim($sender);
			if (preg_match_all('/<([^<>]*)>/', $sender, $m) && count($m[1])) {
				$sender = (string)end($m[1]);
			}
			return self::fingerprint($sender);
		}
		return self::fingerprint($parsed[0]);
	}

	/**
	 * The record for one sender on one mailbox, or null when there is none.
	 *
	 * @return array{sent:int, spam:int, ham:int, messages:int, first_seen:?string}|null
	 */
	public static function get(int $alias_id, string $fingerprint): ?array {
		if ($alias_id <= 0 || $fingerprint === '') {
			return null;
		}
		$stmt = self::db()->prepare('SELECT isr_sent_count, isr_spam_taught, isr_ham_taught, isr_message_count,
				isr_first_seen_time
			FROM isr_inbound_sender_records
			WHERE isr_iea_inbound_email_alias_id = ? AND isr_sender_fingerprint = ?');
		$stmt->execute(array($alias_id, $fingerprint));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$row) {
			return null;
		}
		return array(
			'sent'       => intval($row['isr_sent_count']),
			'spam'       => intval($row['isr_spam_taught']),
			'ham'        => intval($row['isr_ham_taught']),
			'messages'   => intval($row['isr_message_count']),
			'first_seen' => $row['isr_first_seen_time'],
		);
	}

	/** One inbound message from this sender arrived (at $time, UTC). */
	public static function recordArrival(int $alias_id, string $fingerprint, ?string $time = null): void {
		if ($alias_id <= 0 || $fingerprint === '') {
			return;
		}
		$time = ($time !== null && $time !== '') ? $time : gmdate('Y-m-d H:i:s');
		self::db()->prepare('INSERT INTO isr_inbound_sender_records
				(isr_iea_inbound_email_alias_id, isr_sender_fingerprint, isr_message_count,
				 isr_first_seen_time, isr_last_seen_time)
			VALUES (?, ?, 1, ?, ?)
			ON CONFLICT (isr_iea_inbound_email_alias_id, isr_sender_fingerprint) DO UPDATE SET
				isr_message_count = isr_inbound_sender_records.isr_message_count + 1,
				isr_first_seen_time = LEAST(COALESCE(isr_inbound_sender_records.isr_first_seen_time, EXCLUDED.isr_first_seen_time), EXCLUDED.isr_first_seen_time),
				isr_last_seen_time = GREATEST(COALESCE(isr_inbound_sender_records.isr_last_seen_time, EXCLUDED.isr_last_seen_time), EXCLUDED.isr_last_seen_time)')
			->execute(array($alias_id, $fingerprint, $time, $time));
	}

	/**
	 * The user composed mail to these addresses from this mailbox (a forward is
	 * never recorded — it says nothing about who the user writes to).
	 *
	 * @param string[] $addresses bare addresses
	 */
	public static function recordSent(int $alias_id, array $addresses, ?string $time = null): void {
		if ($alias_id <= 0) {
			return;
		}
		$time = ($time !== null && $time !== '') ? $time : gmdate('Y-m-d H:i:s');
		$seen = array();
		foreach ($addresses as $address) {
			$fp = self::fingerprint((string)$address);
			if ($fp === '' || isset($seen[$fp])) {
				continue;
			}
			$seen[$fp] = true;
			self::db()->prepare('INSERT INTO isr_inbound_sender_records
					(isr_iea_inbound_email_alias_id, isr_sender_fingerprint, isr_sent_count, isr_last_sent_time)
				VALUES (?, ?, 1, ?)
				ON CONFLICT (isr_iea_inbound_email_alias_id, isr_sender_fingerprint) DO UPDATE SET
					isr_sent_count = isr_inbound_sender_records.isr_sent_count + 1,
					isr_last_sent_time = GREATEST(COALESCE(isr_inbound_sender_records.isr_last_sent_time, EXCLUDED.isr_last_sent_time), EXCLUDED.isr_last_sent_time)')
				->execute(array($alias_id, $fp, $time));
		}
	}

	/**
	 * Move the teaching counters: $class is 'spam' or 'ham', $delta +1 to teach,
	 * -1 to unteach (clamped at 0). Runs inside the caller's teaching transaction.
	 */
	public static function adjustTaught(int $alias_id, string $fingerprint, string $class, int $delta): void {
		if ($alias_id <= 0 || $fingerprint === '' || $delta === 0) {
			return;
		}
		$col = ($class === InboundEmailMessage::SPAM_VERDICT_SPAM) ? 'isr_spam_taught' : 'isr_ham_taught';
		if ($delta > 0) {
			self::db()->prepare('INSERT INTO isr_inbound_sender_records
					(isr_iea_inbound_email_alias_id, isr_sender_fingerprint, ' . $col . ')
				VALUES (?, ?, ?)
				ON CONFLICT (isr_iea_inbound_email_alias_id, isr_sender_fingerprint) DO UPDATE SET
					' . $col . ' = isr_inbound_sender_records.' . $col . ' + EXCLUDED.' . $col)
				->execute(array($alias_id, $fingerprint, $delta));
			return;
		}
		self::db()->prepare('UPDATE isr_inbound_sender_records SET ' . $col . ' = GREATEST(' . $col . ' + ?, 0)
			WHERE isr_iea_inbound_email_alias_id = ? AND isr_sender_fingerprint = ?')
			->execute(array($delta, $alias_id, $fingerprint));
	}

	/** Is this sender a contact on this mailbox (any grantee's, sealed or not)? */
	public static function isContact(int $alias_id, string $fingerprint): bool {
		if ($alias_id <= 0 || $fingerprint === '') {
			return false;
		}
		$stmt = self::db()->prepare('SELECT 1 FROM imc_mailbox_contacts
			WHERE imc_iea_inbound_email_alias_id = ? AND imc_sender_fingerprint = ? LIMIT 1');
		$stmt->execute(array($alias_id, $fingerprint));
		return $stmt->fetchColumn() !== false;
	}

	/**
	 * Does the message refer (References / In-Reply-To) to a Message-ID of mail
	 * the user sent from this mailbox: an outbound row's own Message-ID, or a
	 * composed send the carrier took (mst_, which outlives a Sent copy that
	 * failed to store)?
	 *
	 * Never matched against the thread key: a thread's key is its root, which in
	 * a thread someone else started is the stranger's Message-ID, and on a
	 * mailing list is public.
	 *
	 * @param string[] $message_ids angle-bracketed Message-IDs
	 */
	public static function repliesToComposed(int $alias_id, array $message_ids): bool {
		$ids = array();
		foreach ($message_ids as $id) {
			$id = substr(trim((string)$id), 0, 255);
			if ($id !== '') {
				$ids[$id] = true;
			}
		}
		if ($alias_id <= 0 || !count($ids)) {
			return false;
		}
		$ids = array_slice(array_keys($ids), 0, 50);
		$marks = implode(',', array_fill(0, count($ids), '?'));
		$stmt = self::db()->prepare("SELECT 1 FROM iem_inbound_email_messages
			WHERE iem_message_id_header IN ($marks) AND iem_iea_inbound_email_alias_id = ?
			  AND iem_direction = 'outbound'
			UNION ALL
			SELECT 1 FROM mst_mailbox_send_attempts
			WHERE mst_message_id_header IN ($marks) AND mst_iea_inbound_email_alias_id = ?
			  AND mst_kind = 'compose' AND mst_outcome IN ('sent', 'partial')
			LIMIT 1");
		$stmt->execute(array_merge($ids, array($alias_id), $ids, array($alias_id)));
		return $stmt->fetchColumn() !== false;
	}

	/**
	 * The Message-IDs a message refers to, from its References and In-Reply-To
	 * header values.
	 *
	 * @return string[]
	 */
	public static function referencedIds(string $references, string $in_reply_to): array {
		$out = array();
		foreach (array($in_reply_to, $references) as $value) {
			if (preg_match_all('/<[^<>\s]+>/', $value, $m)) {
				foreach ($m[0] as $id) {
					$out[$id] = true;
				}
			}
		}
		return array_keys($out);
	}

	private static function db(): PDO {
		return DbConnector::get_instance()->get_db_link();
	}
}
?>
