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
 * Two things drive it. The mailbox view calls drainForUser() directly, which is
 * lower-latency than waiting for a tick — the rules have always run by the time
 * any mailbox view renders. And the plugin registers it with VaultDeferredWork
 * (specs/in_window_deferred_work.md), so the backlog also drains while the
 * owner is anywhere else on the site with their vault open.
 *
 * @version 1.2
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));

class DeferredIngest {

	/** Safety cap per drain pass so a huge logged-out backlog never blocks a page render. */
	const DEFAULT_MAX = 200;

	/** Is there anything to parse for this owner? Cheap, indexed, no decrypt —
	 *  it runs on every vault heartbeat via VaultDeferredWork. */
	public static function hasWork(int $user_id): bool {
		if ($user_id <= 0) {
			return false;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT 1 FROM iem_inbound_email_messages
			  WHERE iem_pending_parse = true
			    AND iem_sealed_owner_user_id = ?
			    AND iem_delete_time IS NULL
			  LIMIT 1"
		);
		$stmt->execute(array($user_id));
		return (bool)$stmt->fetchColumn();
	}

	/**
	 * Parse pending-parse messages owned by $user_id, using their in-window
	 * vault secret. Returns the number of messages parsed. Per-message failures are
	 * logged and the row is left pending (retried at the next drain) — one bad blob
	 * never stalls the rest of the backlog.
	 *
	 * $deadline is a microtime(true) value: parsing stops before starting a new
	 * message once it passes. It bounds how many messages START, so a slow one
	 * may overrun it — the same contract every VaultDeferredWork consumer has.
	 * Null means no deadline (the mailbox-view call sites, bounded by $max).
	 */
	public static function drainForUser(int $user_id, string $secret_key, int $max = self::DEFAULT_MAX,
			?float $deadline = null): int {
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
			if ($deadline !== null && microtime(true) >= $deadline) {
				break;
			}
			try {
				// One message is one unit of work for the hot-turn rule, by the
				// same argument RecipeRunner::run() makes: a forward action can
				// open this message's stored raw, and without the boundary that
				// one open would leave every LATER message in the pass hot —
				// refusing attachment rows and log lines whose content came from
				// their own plaintext, not from anything sealed. Nothing one
				// message decrypts is in play when the next one starts.
				$msg = new InboundEmailMessage(intval($id), TRUE);
				$done = SealedEgressGuard::isolate(function () use ($router, $msg, $secret_key) {
					return $router->parsePendingMessage($msg, $secret_key);
				});
				if ($done) {
					$parsed++;
				}
			} catch (\Throwable $e) {
				error_log('DeferredIngest: failed to parse pending message ' . $id . ': ' . $e->getMessage());
			}
		}
		return $parsed;
	}

	/**
	 * The pending-parse message ids for an owner, NEWEST first.
	 *
	 * Two reasons for that order. Your most recent mail should be the first to
	 * become readable after an unlock, not the last. And the AI email jobs skip
	 * unparsed messages while themselves taking the newest candidate first
	 * (specs/in_window_deferred_work.md § New mail goes first) — parsing
	 * oldest-first would leave them stalled on exactly the mail they want while
	 * this worked forward through the backlog. The two orders have to agree.
	 */
	private static function pendingIds(int $user_id, int $max): array {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT iem_inbound_email_message_id
			   FROM iem_inbound_email_messages
			  WHERE iem_pending_parse = true
			    AND iem_sealed_owner_user_id = ?
			    AND iem_delete_time IS NULL
			  ORDER BY iem_received_time DESC
			  LIMIT ?"
		);
		$stmt->bindValue(1, $user_id, PDO::PARAM_INT);
		$stmt->bindValue(2, max(1, $max), PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: array();
	}
}
