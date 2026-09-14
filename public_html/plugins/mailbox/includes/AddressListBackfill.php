<?php
/**
 * AddressListBackfill - recover the To / Cc lists (iem_to / iem_cc) for
 * messages stored before those columns existed, so the reader shows who else
 * a received message went to and Reply All includes them.
 *
 * TEMPORARY. A one-time catch-up for rows stored before the columns existed;
 * ingest fills the lists for everything after. specs/mailbox_to_cc_lists.md
 * stays in specs/ (never implemented/) until this is retired, and says what
 * "done" means and what to remove.
 *
 * The ingest fills the lists the moment a message arrives; this consumer
 * covers what was already stored without them. A row can answer from one of
 * three places, and the read path already handles the first:
 *
 *  - a retained header block (iem_raw_headers): MailboxService derives the
 *    lists on every view, so those rows are not candidates here.
 *  - 'remote' rows (an IMAP locator, no platform raw): the header block is
 *    fetched from the source over IMAP (ImapIngestor::fetchHeaderText), one
 *    connection per account for the whole drain. No vault involved in the
 *    fetch.
 *  - stored-raw rows: the header block comes from the raw
 *    (InboundEmailMessage::getRawMessage), which on a sealed message opens
 *    only inside the owner's window. That is why this runs as a
 *    VaultDeferredWork consumer rather than a scheduled task
 *    (docs/scheduled_tasks.md: cron can never read sealed content).
 *
 * The lists are written the way ingest writes them: plaintext on an unsealed
 * row, sealed under the row's OWN DEK on a sealed one (the DEK unwraps only
 * in-window — a second reason this is deferred work). A source that carries
 * neither header records '' in both columns, which the read path treats as
 * "captured, nothing there" and never re-derives.
 *
 * Retry posture: every attempted row is stamped (iem_lists_attempt_time) and
 * retried at most daily, so a message whose source copy is gone costs one
 * attempt per day rather than an IMAP round trip per heartbeat drain.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php')); // declares VaultLockedException

class AddressListBackfill {

	/** Small on purpose: a 'remote' row costs an IMAP round trip, and a drain
	 *  slice runs inside a user's page-adjacent request. */
	const DEFAULT_MAX = 25;

	/** SQL interval before a stamped row is retried. */
	const RETRY_INTERVAL = '1 day';

	/**
	 * The candidate predicate, shared verbatim by hasWork() and the drain. Two
	 * user placeholders. A sealed row belongs to the owner it records; an
	 * unsealed row (a Standard mailbox records no owner) belongs to anyone who
	 * holds a grant on its mailbox — the same people the reader shows it to.
	 */
	private static function candidateWhere(): string {
		return "(m.iem_sealed_owner_user_id = ?
		         OR (m.iem_content_sealed IS NOT TRUE
		             AND m.iem_iea_inbound_email_alias_id IN (
		                 SELECT g.ieg_iea_inbound_email_alias_id
		                   FROM ieg_inbound_email_mailbox_grants g WHERE g.ieg_usr_user_id = ?)))
		    AND m.iem_direction = 'inbound'
		    AND m.iem_delete_time IS NULL
		    AND m.iem_pending_parse IS NOT TRUE
		    AND m.iem_to IS NULL AND m.iem_cc IS NULL
		    AND COALESCE(length(m.iem_raw_headers), 0) = 0
		    AND (m.iem_lists_attempt_time IS NULL
		         OR m.iem_lists_attempt_time < now() - interval '" . self::RETRY_INTERVAL . "')
		    AND ((m.iem_raw_storage_driver = 'remote' AND m.iem_iia_inbound_imap_account_id IS NOT NULL)
		         OR COALESCE(length(m.iem_raw_message), 0) > 0
		         OR m.iem_raw_storage_key IS NOT NULL)";
	}

	/** Any message this user can read still without its lists and with a source to ask? Cheap, no decrypt. */
	public static function hasWork(int $user_id): bool {
		if ($user_id <= 0) {
			return false;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT 1 FROM iem_inbound_email_messages m WHERE " . self::candidateWhere() . " LIMIT 1");
		$stmt->execute(array($user_id, $user_id));
		return (bool)$stmt->fetchColumn();
	}

	/**
	 * Recover the lists for up to $max messages owned by $user_id, newest
	 * first (the mail the owner is looking at). Returns how many rows were
	 * filled. Every attempted row is stamped first, so a failure backs off
	 * instead of retrying on the next heartbeat.
	 */
	public static function drainForUser(int $user_id, VaultKey $key, int $max = self::DEFAULT_MAX,
			?float $deadline = null): int {
		if ($user_id <= 0) {
			return 0;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT m.iem_inbound_email_message_id AS msg_id
			   FROM iem_inbound_email_messages m
			  WHERE " . self::candidateWhere() . "
			  ORDER BY m.iem_received_time DESC, m.iem_inbound_email_message_id DESC
			  LIMIT " . intval($max));
		$stmt->execute(array($user_id, $user_id));
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$ingestors = array(); // one open IMAP connection per account for the batch
		$done = 0;
		try {
			foreach ($rows as $row) {
				if ($deadline !== null && microtime(true) >= $deadline) {
					break;
				}
				$msg_id = intval($row['msg_id']);
				$stamp = $db->prepare('UPDATE iem_inbound_email_messages
					SET iem_lists_attempt_time = now() WHERE iem_inbound_email_message_id = ?');
				$stamp->execute(array($msg_id));
				try {
					// One message is one unit for the hot-turn rule: opening a
					// sealed raw opens this owner's scope, and nothing one row
					// decrypts is in play when the next one starts.
					$ok = SealedEgressGuard::isolate(function () use ($msg_id, &$ingestors) {
						return self::fillOne($msg_id, $ingestors);
					});
					if ($ok) {
						$done++;
					}
				} catch (VaultLockedException $e) {
					// The window closed mid-drain — stop, never an error. The row
					// was not asked anything, so it owes no retry wait: unstamp it.
					$db->prepare('UPDATE iem_inbound_email_messages
						SET iem_lists_attempt_time = NULL WHERE iem_inbound_email_message_id = ?')
						->execute(array($msg_id));
					break;
				} catch (\Throwable $e) {
					error_log('AddressListBackfill: could not fill message ' . $msg_id . ': ' . $e->getMessage());
				}
			}
		} finally {
			foreach ($ingestors as $ingestor) {
				try { $ingestor->close(); } catch (\Throwable $e) { /* best effort */ }
			}
		}
		return $done;
	}

	/**
	 * Resolve one message's header block through whichever source it has, and
	 * write its lists. Returns false when the source cannot answer (the row
	 * stays stamped for the daily retry).
	 *
	 * @param array<int,ImapIngestor> $ingestors per-account cache, filled here
	 */
	private static function fillOne(int $msg_id, array &$ingestors): bool {
		$msg = new InboundEmailMessage($msg_id, TRUE);
		if (!$msg->key) {
			return false;
		}

		$block = self::headerBlockFor($msg, $ingestors);
		if ($block === null) {
			return false;
		}
		$lists = MailAddressList::fromHeaderBlock($block);
		return self::writeLists($msg, $lists['to'], $lists['cc']);
	}

	/** The wire header block from the source, or null when it cannot be had. */
	private static function headerBlockFor(InboundEmailMessage $msg, array &$ingestors): ?string {
		$driver = (string)$msg->get('iem_raw_storage_driver') ?: 'inline';
		if ($driver === 'remote') {
			$acc_id = intval($msg->get('iem_iia_inbound_imap_account_id'));
			if ($acc_id <= 0) {
				return null;
			}
			if (!isset($ingestors[$acc_id])) {
				$account = new InboundImapAccount($acc_id, TRUE);
				if (!$account->key || !$account->get('iia_is_enabled')) {
					return null;
				}
				$ingestors[$acc_id] = self::makeIngestor($account);
			}
			$res = $ingestors[$acc_id]->fetchHeaderText(intval($msg->get('iem_imap_uid')),
				$msg->get('iem_imap_uidvalidity') !== null ? intval($msg->get('iem_imap_uidvalidity')) : null,
				(string)$msg->get('iem_imap_folder'), (string)$msg->get('iem_message_id_header'));
			return !empty($res['ok']) ? (string)$res['headers'] : null;
		}

		// Opens the sealed raw in-window; throws VaultLockedException when the
		// window is closed, which the drain treats as "stop".
		$raw = $msg->getRawMessage();
		if ($raw === null || $raw === '') {
			return null;
		}
		return (new InboundEmailRouter())->rawHeaderBlock($raw);
	}

	/**
	 * Write the lists the way ingest does for this row's state: sealed under
	 * the row's own DEK when the row is sealed, plaintext otherwise. An empty
	 * list is stored as '' — "captured, nothing there" — so the row leaves the
	 * candidate set and the read path stops deriving.
	 */
	private static function writeLists(InboundEmailMessage $msg, string $to, string $cc): bool {
		$msg_id = intval($msg->key);
		if (!$msg->get('iem_content_sealed')) {
			InboundEmailMessage::updateColumns($msg_id, array('iem_to' => $to, 'iem_cc' => $cc));
			return true;
		}
		$owner_id = InboundEmailMessage::sealedOwnerFor($msg);
		$sealed_key = (string)$msg->get('iem_sealed_key');
		if ($owner_id === null || $owner_id <= 0 || $sealed_key === '') {
			error_log('AddressListBackfill: message ' . $msg_id . ' is sealed but names no owner or key; '
				. 'its lists stay unrecorded rather than being stored in the clear.');
			return false;
		}
		$dek = InboundEmailMessage::unwrapDekInWindow($owner_id, $sealed_key);
		if ($dek === null) {
			throw new VaultLockedException();
		}
		// Reusing the row DEK: the vault argument is consulted only when a key
		// is minted, and the existing wrapping stays untouched.
		InboundEmailMessage::sealColumns($msg_id, null, array('iem_to' => $to, 'iem_cc' => $cc), $dek);
		return true;
	}

	/** Test seam: how an account becomes an ingestor. */
	public static $ingestor_factory = null;

	private static function makeIngestor(InboundImapAccount $account): ImapIngestor {
		if (self::$ingestor_factory !== null) {
			return call_user_func(self::$ingestor_factory, $account);
		}
		return new ImapIngestor($account);
	}
}
