<?php
/**
 * DeferredIngest - parse the backlog of relay-sealed Fortress mail at unlock.
 *
 * On a relay-fronted deployment (specs/inbound_email_hardened_ingest_relay_executor.md),
 * MX-path Fortress mail arrives sealed to the owner's vault public key. While the
 * owner is logged out the pull consumer (the relay reconcile task) can only store operational
 * metadata + the sealed raw blob in a PENDING-PARSE state — threading and unread
 * counts work, but the subject/sender/body/attachments do not exist as fields yet.
 *
 * The moment the owner's vault is unlocked (their in-window secret is available),
 * that backlog can be parsed: unseal each blob, run the full ingest pipeline, seal
 * the fields under a fresh per-message DEK, split attachments, run filters, and
 * clear the pending state. For a single-reader mailbox this is invisible — the
 * rules have always run by the time any mailbox view renders.
 *
 * This mirrors how MailboxIndex::fold() runs: lazily, whenever the mailbox is
 * viewed with an open window, rather than needing a dedicated unlock callback.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));

class DeferredIngest {

	/** Safety cap per drain pass so a huge logged-out backlog never blocks a page render. */
	const DEFAULT_MAX = 200;

	/**
	 * Parse every pending-parse message owned by $user_id, using their in-window
	 * vault secret. Returns the number of messages parsed. Per-message failures are
	 * logged and the row is left pending (retried at the next drain) — one bad blob
	 * never stalls the rest of the backlog.
	 */
	public static function drainForUser(int $user_id, string $secret_key, int $max = self::DEFAULT_MAX): int {
		if ($user_id <= 0 || $secret_key === '') {
			return 0;
		}

		$ids = self::pendingIds($user_id, $max);
		if (empty($ids)) {
			return 0;
		}

		$router = new InboundEmailRouter();
		$parsed = 0;
		foreach ($ids as $id) {
			try {
				$msg = new InboundEmailMessage(intval($id), TRUE);
				if ($router->parsePendingMessage($msg, $secret_key)) {
					$parsed++;
				}
			} catch (\Throwable $e) {
				error_log('DeferredIngest: failed to parse pending message ' . $id . ': ' . $e->getMessage());
			}
		}
		return $parsed;
	}

	/**
	 * The pending-parse message ids for an owner, oldest first (so the backlog
	 * drains in arrival order and the newest mail is the last to appear parsed).
	 */
	private static function pendingIds(int $user_id, int $max): array {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT iem_inbound_email_message_id
			   FROM iem_inbound_email_messages
			  WHERE iem_pending_parse = true
			    AND iem_sealed_owner_user_id = ?
			    AND iem_delete_time IS NULL
			  ORDER BY iem_received_time ASC
			  LIMIT ?"
		);
		$stmt->bindValue(1, $user_id, PDO::PARAM_INT);
		$stmt->bindValue(2, max(1, $max), PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: array();
	}
}
