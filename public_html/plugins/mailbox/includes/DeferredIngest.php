<?php
/**
 * DeferredIngest - parse the backlog of relay-sealed mail at unlock.
 *
 * On a relay-fronted deployment (specs/inbound_email_hardened_ingest_relay_executor.md),
 * MX-path relay-sealed mail arrives sealed to the owner's vault public key. While the
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
 * It runs as background work: the plugin registers it with VaultDeferredWork
 * (specs/in_window_deferred_work.md), so the backlog drains wherever the owner is
 * on the site with their vault open. A parse is slow — the spam scan alone can
 * wait seconds on the scanner's network lookups — so the mailbox list never
 * parses: it lists a pending row as "opening" and says `parsing`, and the reader
 * starts the drain at once. Opening a thread parses just that thread's pending
 * rows (parseMessages), so the mail someone clicks is readable when it shows.
 *
 * Every parse holds a per-message advisory lock and re-reads the row under it,
 * so two requests — the background drain and a thread open, or two tabs — never
 * parse one message twice (twice would seal it twice, split its attachments
 * twice and run its rules twice).
 *
 * @version 1.4 - per-message lock (parseOne); parseMessages() for a thread open; the
 *               list no longer drains inline
 * @version 1.3 - skips a pending row whose key the owner's browser holds (a relay-sealed
 *               Fortress arrival): the browser parses it, the window cannot
 * @version 1.2.1 - comment wording: Private plus the relay-sealing and sending-lock add-ons
 * @version 1.2
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_messages_class.php'));
require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));

class DeferredIngest {

	/** Safety cap per drain pass so a huge logged-out backlog never blocks a page render. */
	const DEFAULT_MAX = 200;

	/** How long a thread open waits for a drain already parsing one of its messages. */
	const OPEN_WAIT_SECONDS = 12.0;

	/** First key of the per-message parse lock (the message id is the second). */
	const LOCK_CLASS = 0x44494E47;   // 'DING'

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
			    AND (iem_sealed_key IS NULL OR NOT " . InboundEmailMessage::mailKeySql() . ")
			  LIMIT 1"
		);
		$stmt->execute(array($user_id));
		return (bool)$stmt->fetchColumn();
	}

	/**
	 * Parse pending-parse messages owned by $user_id, using their in-window
	 * vault key. Returns the number of messages parsed. Per-message failures are
	 * logged and the row is left pending (retried at the next drain) — one bad blob
	 * never stalls the rest of the backlog.
	 *
	 * $deadline is a microtime(true) value: parsing stops before starting a new
	 * message once it passes. It bounds how many messages START, so a slow one
	 * may overrun it — the same contract every VaultDeferredWork consumer has.
	 * Null means no deadline (bounded by $max).
	 */
	public static function drainForUser(int $user_id, VaultKey $key, int $max = self::DEFAULT_MAX,
			?float $deadline = null): int {
		if ($user_id <= 0) {
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
			// A message another request holds is skipped, not waited for: it is
			// being parsed right now, and the next drain finds it done.
			if (self::parseOne($router, intval($id), $user_id, $key, 0.0)) {
				$parsed++;
			}
		}
		return $parsed;
	}

	/**
	 * Parse the given messages, when pending and owned by $user_id — a thread
	 * being opened. Each waits up to $wait_seconds for a drain already parsing
	 * it, so the thread shows the parsed message rather than a placeholder.
	 * Returns the number this call parsed (a message the drain finished while
	 * this waited counts as none here, and is parsed all the same).
	 *
	 * @param int[] $ids
	 */
	public static function parseMessages(int $user_id, VaultKey $key, array $ids,
			float $wait_seconds = self::OPEN_WAIT_SECONDS): int {
		if ($user_id <= 0 || empty($ids)) {
			return 0;
		}
		$router = new InboundEmailRouter();
		$parsed = 0;
		foreach ($ids as $id) {
			if (self::parseOne($router, intval($id), $user_id, $key, $wait_seconds)) {
				$parsed++;
			}
		}
		return $parsed;
	}

	/**
	 * One message, under its advisory lock. The row is read AFTER the lock is
	 * held: a row read before it could be one another request has since parsed,
	 * and parsing that copy again is exactly what the lock exists to stop.
	 * Returns true when this call parsed it. Never throws: a failure is logged
	 * and the row stays pending for the next drain.
	 */
	private static function parseOne(InboundEmailRouter $router, int $id, int $user_id, VaultKey $key,
			float $wait_seconds): bool {
		if (!self::lockMessage($id, $wait_seconds)) {
			return false;
		}
		try {
			$msg = new InboundEmailMessage($id, TRUE);
			if (!$msg->key || !$msg->get('iem_pending_parse')
					|| intval($msg->get('iem_sealed_owner_user_id')) !== $user_id
					|| $msg->get('iem_delete_time') !== null) {
				return false;
			}
			if (InboundEmailMessage::isBrowserSealed($msg)) {
				return false;   // a Fortress arrival: the owner's browser parses it
			}
			// One message is one unit of work for the hot-turn rule, by the
			// same argument RecipeRunner::run() makes: a forward action can
			// open this message's stored raw, and without the boundary that
			// one open would leave every LATER message in the pass hot —
			// refusing attachment rows and log lines whose content came from
			// their own plaintext, not from anything sealed. Nothing one
			// message decrypts is in play when the next one starts.
			return (bool)SealedEgressGuard::isolate(function () use ($router, $msg, $key) {
				return $router->parsePendingMessage($msg, $key);
			});
		} catch (\Throwable $e) {
			error_log('DeferredIngest: failed to parse pending message ' . $id . ': ' . $e->getMessage());
			return false;
		} finally {
			self::unlockMessage($id);
		}
	}

	/**
	 * Take the message's parse lock: at once, or polling for up to
	 * $wait_seconds while another request holds it. Session-scoped, so a
	 * request that dies releases it with its connection.
	 */
	private static function lockMessage(int $id, float $wait_seconds): bool {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare('SELECT pg_try_advisory_lock(?, ?)');
		$until = microtime(true) + max(0.0, $wait_seconds);
		while (true) {
			try {
				$q->execute(array(self::LOCK_CLASS, $id & 0x7FFFFFFF));
				if ((bool)$q->fetchColumn()) {
					return true;
				}
			} catch (\Throwable $e) {
				error_log('DeferredIngest: parse lock failed for message ' . $id . ': ' . $e->getMessage());
				return false;   // fail closed: no lock, no parse
			}
			if (microtime(true) >= $until) {
				return false;
			}
			usleep(100000);
		}
	}

	private static function unlockMessage(int $id): void {
		try {
			DbConnector::get_instance()->get_db_link()
				->prepare('SELECT pg_advisory_unlock(?, ?)')
				->execute(array(self::LOCK_CLASS, $id & 0x7FFFFFFF));
		} catch (\Throwable $e) {
			error_log('DeferredIngest: parse unlock failed for message ' . $id . ': ' . $e->getMessage());
		}
	}

	/**
	 * The pending-parse ids among $ids that $user_id's window would parse —
	 * the same test pendingIds() applies, narrowed to one thread's rows.
	 *
	 * @param int[] $ids
	 * @return int[]
	 */
	public static function pendingAmong(int $user_id, array $ids): array {
		$ids = array_values(array_filter(array_map('intval', $ids)));
		if ($user_id <= 0 || empty($ids)) {
			return array();
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT iem_inbound_email_message_id
			   FROM iem_inbound_email_messages
			  WHERE iem_inbound_email_message_id = ANY(?::bigint[])
			    AND iem_pending_parse = true
			    AND iem_sealed_owner_user_id = ?
			    AND iem_delete_time IS NULL
			    AND (iem_sealed_key IS NULL OR NOT " . InboundEmailMessage::mailKeySql() . ")
			  ORDER BY iem_received_time DESC"
		);
		$stmt->execute(array('{' . implode(',', $ids) . '}', $user_id));
		return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: array());
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
			    AND (iem_sealed_key IS NULL OR NOT " . InboundEmailMessage::mailKeySql() . ")
			  ORDER BY iem_received_time DESC
			  LIMIT ?"
		);
		$stmt->bindValue(1, $user_id, PDO::PARAM_INT);
		$stmt->bindValue(2, max(1, $max), PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: array();
	}
}
