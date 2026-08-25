<?php
/**
 * InlineImageBackfill - turn existing reference-backed inline image parts into
 * file-backed rows, so the reader's cid: rewrite can serve them
 * (specs/bugfix_sealed_inline_images.md).
 *
 * The ingest adopts inline images the moment a message arrives; this consumer
 * covers everything already stored without them. Rows come in two shapes and
 * both are handled with the bytes-getters the download path already uses:
 *
 *  - 'remote' rows (an IMAP locator, no platform raw): the part is fetched
 *    from the source over IMAP (ImapIngestor::fetchPart). No vault involved.
 *  - stored-raw rows: the part is extracted from the raw
 *    (InboundEmailMessage::getRawMimePart) — which, on a sealed message, opens
 *    only inside the owner's window. That is why this runs as a
 *    VaultDeferredWork consumer rather than a scheduled task
 *    (docs/scheduled_tasks.md: cron can never read sealed content).
 *
 * Adoption seals iff the message is sealed, to the owner the message records —
 * AttachmentByteCustody::adoptBytes, the same primitive the ingest uses.
 *
 * Retry posture: every attempted row is stamped (ima_adopt_attempt_time) and
 * retried at most daily, so a part whose source copy no longer exists costs
 * one attempt per day rather than an IMAP round trip per heartbeat drain.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_message_attachment_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/AttachmentByteCustody.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));
require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php')); // declares VaultLockedException

class InlineImageBackfill {

	/** Small on purpose: a 'remote' row costs an IMAP round trip per part, and a
	 *  drain slice runs inside a user's page-adjacent request. */
	const DEFAULT_MAX = 10;

	/** SQL interval before a stamped row is retried. */
	const RETRY_INTERVAL = '1 day';

	/** The candidate predicate, shared verbatim by hasWork() and the drain. */
	private static function candidateWhere(): string {
		return "a.ima_is_inline = true
		    AND a.ima_fil_file_id IS NULL
		    AND a.ima_content_type LIKE 'image/%'
		    AND (a.ima_adopt_attempt_time IS NULL
		         OR a.ima_adopt_attempt_time < now() - interval '" . self::RETRY_INTERVAL . "')
		    AND m.iem_sealed_owner_user_id = ?
		    AND m.iem_delete_time IS NULL
		    AND (m.iem_iia_inbound_imap_account_id IS NOT NULL
		         OR m.iem_raw_storage_driver <> 'remote')";
	}

	/** Any inline image still reference-backed for this owner? Cheap, indexed, no decrypt. */
	public static function hasWork(int $user_id): bool {
		if ($user_id <= 0) {
			return false;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT 1 FROM ima_inbound_message_attachments a
			   JOIN iem_inbound_email_messages m
			     ON m.iem_inbound_email_message_id = a.ima_iem_inbound_email_message_id
			  WHERE " . self::candidateWhere() . "
			  LIMIT 1");
		$stmt->execute(array($user_id));
		return (bool)$stmt->fetchColumn();
	}

	/**
	 * Adopt up to $max inline image parts owned by $user_id. Returns how many
	 * rows became file-backed. Every attempted row is stamped first, so a
	 * failure backs off instead of retrying on the next heartbeat.
	 */
	public static function drainForUser(int $user_id, string $secret_key, int $max = self::DEFAULT_MAX,
			?float $deadline = null): int {
		if ($user_id <= 0) {
			return 0;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT a.ima_inbound_message_attachment_id AS att_id,
			        a.ima_iem_inbound_email_message_id AS msg_id, a.ima_mime_part
			   FROM ima_inbound_message_attachments a
			   JOIN iem_inbound_email_messages m
			     ON m.iem_inbound_email_message_id = a.ima_iem_inbound_email_message_id
			  WHERE " . self::candidateWhere() . "
			  ORDER BY a.ima_iem_inbound_email_message_id DESC, a.ima_inbound_message_attachment_id ASC
			  LIMIT " . intval($max));
		$stmt->execute(array($user_id));
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$done = 0;
		foreach ($rows as $row) {
			if ($deadline !== null && microtime(true) >= $deadline) {
				break;
			}
			$att_id = intval($row['att_id']);
			$stamp = $db->prepare('UPDATE ima_inbound_message_attachments
				SET ima_adopt_attempt_time = now() WHERE ima_inbound_message_attachment_id = ?');
			$stamp->execute(array($att_id));
			try {
				// One part is one unit for the hot-turn rule: extracting from a
				// sealed raw opens this owner's scope, and nothing one part
				// decrypts is in play when the next one starts.
				$ok = SealedEgressGuard::isolate(function () use ($row) {
					return self::adoptOne(intval($row['msg_id']), intval($row['att_id']),
						(string)$row['ima_mime_part']);
				});
				if ($ok) {
					$done++;
				}
			} catch (VaultLockedException $e) {
				break; // the window closed mid-drain — stop, never an error
			} catch (\Throwable $e) {
				error_log('InlineImageBackfill: could not adopt attachment ' . $att_id . ': ' . $e->getMessage());
			}
		}
		return $done;
	}

	/** Resolve one part's bytes through whichever getter its message supports, and adopt them. */
	private static function adoptOne(int $msg_id, int $att_id, string $mime_part): bool {
		$msg = new InboundEmailMessage($msg_id, TRUE);
		$att = new InboundMessageAttachment($att_id, TRUE);
		if (!$msg->key || !$att->key || $att->get('ima_fil_file_id') || $mime_part === '') {
			return false;
		}

		$bytes = null;
		$driver = (string)$msg->get('iem_raw_storage_driver') ?: 'inline';
		if ($driver === 'remote') {
			$acc_id = intval($msg->get('iem_iia_inbound_imap_account_id'));
			if ($acc_id <= 0) {
				return false;
			}
			$account = new InboundImapAccount($acc_id, TRUE);
			if (!$account->key || !$account->get('iia_is_enabled')) {
				return false;
			}
			$ingestor = new ImapIngestor($account);
			$res = $ingestor->fetchPart($mime_part, intval($msg->get('iem_imap_uid')),
				$msg->get('iem_imap_uidvalidity') !== null ? intval($msg->get('iem_imap_uidvalidity')) : null,
				(string)$msg->get('iem_imap_folder'), (string)$msg->get('iem_message_id_header'));
			if (!empty($res['ok'])) {
				$bytes = (string)$res['content'];
			}
		} else {
			// Opens the sealed raw in-window; throws VaultLockedException when
			// the window is closed, which the drain treats as "stop".
			$part = $msg->getRawMimePart($mime_part);
			if (is_array($part)) {
				$bytes = (string)($part['content'] ?? '');
			}
		}
		if ($bytes === null || $bytes === '' || strlen($bytes) > ImapIngestor::INLINE_ADOPT_MAX_BYTES) {
			return false;
		}

		$sealed = (bool)$msg->get('iem_content_sealed');
		$owner_id = null;
		if ($sealed) {
			$owner_id = InboundEmailMessage::sealedOwnerFor($msg);
			if ($owner_id === null || $owner_id <= 0) {
				error_log('InlineImageBackfill: message ' . $msg_id . ' is sealed but names no owner; '
					. 'its inline image stays reference-backed rather than being stored in the clear.');
				return false;
			}
		} else {
			// The predicate scopes to iem_sealed_owner_user_id, so an unsealed
			// row here still names its owner — a lowered mailbox's rows keep it.
			$owner_id = intval($msg->get('iem_sealed_owner_user_id'));
			if ($owner_id <= 0) {
				return false;
			}
		}
		return AttachmentByteCustody::adoptBytes($att, $bytes, $sealed, $owner_id);
	}
}
