<?php
/**
 * InlineFlagBackfill - decide, by what the HTML body shows, the inline flag of
 * attachment rows stored before that rule (InlineParts, B5).
 *
 * Unsealed mail was decided once by migration inl_001. A sealed (Private)
 * message's body is ciphertext, so its rows are decided here, inside the
 * owner's unlock window, as a VaultDeferredWork consumer: the body is opened,
 * its cid: references read, and each row with a Content-ID set to inline
 * exactly when the body shows it. Every row looked at is marked
 * (ima_inline_by_body), so it is decided once. A row that becomes an inline
 * image without bytes of its own is then InlineImageBackfill's to adopt, which
 * is registered after this one.
 *
 * A message whose content is not sealed (a draft whose sealed key is held
 * but whose body is plaintext, bootstrap 1.30) has a readable body, so the
 * migration inl_002 decides it, not this.
 *
 * Fortress rows are never touched: their Content-ID is blank on the server
 * (the manifest is sealed to the person's devices), and the browser decides
 * inline from the opened body each time it shows one.
 *
 * @version 1.1 - counts rows decided, not changed; a body that cannot be opened keeps its flags (reviewer2 B1)
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InlineParts.php'));
require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php')); // declares VaultLockedException

class InlineFlagBackfill {

	/** Messages per drain slice: each opens one body. */
	const DEFAULT_MAX = 25;

	/** The candidate rows, shared verbatim by hasWork() and the drain. */
	private static function candidateWhere(): string {
		return "a.ima_inline_by_body IS NULL
		    AND coalesce(a.ima_content_id, '') <> ''
		    AND m.iem_content_sealed = true
		    AND m.iem_sealed_owner_user_id = ?
		    AND m.iem_delete_time IS NULL";
	}

	/** Any sealed row of this owner's whose inline flag the body has not decided? */
	public static function hasWork(int $user_id): bool {
		if ($user_id <= 0) {
			return false;
		}
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT 1 FROM ima_inbound_message_attachments a
			   JOIN iem_inbound_email_messages m
			     ON m.iem_inbound_email_message_id = a.ima_iem_inbound_email_message_id
			  WHERE " . self::candidateWhere() . " LIMIT 1");
		$stmt->execute(array($user_id));
		return (bool)$stmt->fetchColumn();
	}

	/**
	 * Decide up to $max messages' rows. Returns how many rows were decided
	 * (changed or not), so a drain keeps going while rows remain: most
	 * pre-rule flags were already right, and counting only changes would end
	 * each pass after one slice.
	 */
	public static function drainForUser(int $user_id, VaultKey $key, int $max = self::DEFAULT_MAX,
			?float $deadline = null): int {
		if ($user_id <= 0) {
			return 0;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT DISTINCT a.ima_iem_inbound_email_message_id AS msg_id
			   FROM ima_inbound_message_attachments a
			   JOIN iem_inbound_email_messages m
			     ON m.iem_inbound_email_message_id = a.ima_iem_inbound_email_message_id
			  WHERE " . self::candidateWhere() . "
			  ORDER BY 1 DESC LIMIT " . intval($max));
		$stmt->execute(array($user_id));
		$decided = 0;
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $msg_id) {
			if ($deadline !== null && microtime(true) >= $deadline) {
				break;
			}
			try {
				// One message is one unit for the hot-turn rule: its body opens
				// this owner's scope, and nothing it decrypts outlives the unit.
				$decided += SealedEgressGuard::isolate(function () use ($msg_id) {
					return self::decideMessage(intval($msg_id));
				});
			} catch (VaultLockedException $e) {
				break; // the window closed mid-drain: stop, never an error
			} catch (\Throwable $e) {
				// A body that cannot be opened for any other reason (a corrupt
				// row, a key it was not sealed to) keeps its stored flags and is
				// marked decided, so it is tried once and never starves the
				// older messages behind it.
				error_log('InlineFlagBackfill: could not open message ' . intval($msg_id)
					. ', its stored inline flags stand: ' . $e->getMessage());
				$db->prepare("UPDATE ima_inbound_message_attachments SET ima_inline_by_body = true
					WHERE ima_iem_inbound_email_message_id = ? AND ima_inline_by_body IS NULL")->execute(array(intval($msg_id)));
				$decided++;
			}
		}
		return $decided;
	}

	/** Open one message's body and decide each undecided row with a Content-ID; returns how many. */
	private static function decideMessage(int $msg_id): int {
		$msg = new InboundEmailMessage($msg_id, TRUE);
		if (!$msg->key) {
			return 0;
		}
		$shown = InlineParts::referencedIds((string)$msg->get('iem_body_html'));
		$db = DbConnector::get_instance()->get_db_link();
		$rows = $db->prepare("SELECT ima_inbound_message_attachment_id AS id, ima_content_id AS cid, ima_is_inline AS inline
			FROM ima_inbound_message_attachments
			WHERE ima_iem_inbound_email_message_id = ? AND ima_inline_by_body IS NULL AND coalesce(ima_content_id, '') <> ''");
		$rows->execute(array($msg_id));
		$set = $db->prepare('UPDATE ima_inbound_message_attachments
			SET ima_is_inline = ?, ima_inline_by_body = true WHERE ima_inbound_message_attachment_id = ?');
		$decided = 0;
		foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$set->execute(array(InlineParts::isInline((string)$r['cid'], $shown) ? 't' : 'f', intval($r['id'])));
			$decided++;
		}
		return $decided;
	}
}
