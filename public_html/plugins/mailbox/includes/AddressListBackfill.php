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
 *  - 'remote' rows (an IMAP locator, no platform raw): the header blocks are
 *    fetched from the source over IMAP in batches — one connection per
 *    account for the whole drain, and one STATUS + one FETCH per folder per
 *    FETCH_CHUNK rows (ImapIngestor::fetchHeaderTexts), so a backlog costs a
 *    round trip per fifty messages rather than two per message. No vault
 *    involved in the fetch.
 *  - stored-raw rows: the header block comes from the raw
 *    (InboundEmailMessage::getRawMessage), which on a sealed message opens
 *    only inside the owner's window. That is why this runs as a
 *    VaultDeferredWork consumer rather than a scheduled task
 *    (docs/scheduled_tasks.md: cron can never read sealed content).
 *  - archive rows (§ 5b): a row linked from an import entry
 *    (mie_iem_inbound_email_message_id) whose run still holds its archive
 *    file reads its header block at the entry's locator through the run's
 *    reader (MailArchiveImporter::readerAndPath). This is how a row that no
 *    connected account holds gets its lists: the owner uploads the archive
 *    it came from again and imports it into the same mailbox — every message
 *    dedups and links its entry to the existing row — and the drain reads
 *    from it. One reader per run per drain.
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
 * @version 1.5
 * @changelog 1.5 - the archive arm (§ 5b): a row linked from an import entry whose run
 *   still holds its archive reads its header block at the entry's locator — the
 *   rows no connected account has, once the owner uploads the archive again
 * @changelog 1.4 - Finished = nothing waiting and every sweep done (a daily-retried row does
 *   not hold it open); the no-copy line says what those rows are once the sweep is over
 * @changelog 1.3 - writeLists() and stampRows() are public for AddressListSweep
 *   (§ 5a, the other temporary half); the card carries the sweep's lines
 * @changelog 1.2 - progress() + renderProgressCard(): the card on Inbound Email →
 *   Accounts, so an operator can see the catch-up move and know when it is
 *   finished. Everything about the card lives here; the page makes one call.
 * @changelog 1.1 - 'remote' rows fetch their headers in batches (one STATUS +
 *   one FETCH per folder per FETCH_CHUNK rows); DEFAULT_MAX 25 -> 200, the
 *   turn deadline being the real bound
 */

require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php')); // declares VaultLockedException

class AddressListBackfill {

	/** Rows per turn. A turn is bounded by its deadline, checked before every
	 *  header fetch and every stored-raw row, so this is a ceiling on one
	 *  candidate query, not on the time a turn may take. */
	const DEFAULT_MAX = 200;

	/** 'remote' rows per IMAP FETCH: one round trip brings back this many
	 *  header blocks (a few KB each), so the whole batch stays a modest reply. */
	const FETCH_CHUNK = 50;

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
		         OR m.iem_raw_storage_key IS NOT NULL
		         OR " . self::archiveSourceSql() . ")";
	}

	/**
	 * The archive source arm (§ 5b): the row is linked from an entry of an
	 * import run that still holds its archive file. Correlated on m.
	 */
	private static function archiveSourceSql(): string {
		return "EXISTS (SELECT 1 FROM mie_mail_import_entries e
		                  JOIN mir_mail_import_runs r ON r.mir_mail_import_run_id = e.mie_mir_mail_import_run_id
		                 WHERE e.mie_iem_inbound_email_message_id = m.iem_inbound_email_message_id
		                   AND e.mie_state IN ('stored', 'dedup')
		                   AND r.mir_fil_file_id IS NOT NULL
		                   AND r.mir_delete_time IS NULL)";
	}

	/**
	 * Where the catch-up stands, for the mailbox admin landing page. One
	 * aggregate over every inbound row still without a retained header block
	 * (rows WITH one answer at read time and were never the backfill's
	 * concern). No decrypt, no user predicate — the operator's view is the
	 * whole node:
	 *
	 *  - filled:        rows the backfill wrote (stamped, lists present)
	 *  - waiting:       rows with a source to ask, not yet tried — by the
	 *                   owner whose open vault window they wait on ('' key =
	 *                   unsealed rows, filled by any grant holder)
	 *  - retrying:      tried, the source did not answer; asked again daily
	 *  - unrecoverable: no source at all (lean push rows, archive imports)
	 *
	 * "Finished" is waiting == 0 and retrying == 0.
	 *
	 * @return array{filled:int,waiting:array<string,int>,retrying:int,unrecoverable:int}
	 */
	public static function progress(): array {
		$db = DbConnector::get_instance()->get_db_link();
		$rows = $db->query(
			"SELECT CASE
			          WHEN m.iem_to IS NOT NULL OR m.iem_cc IS NOT NULL THEN 'filled'
			          WHEN NOT ((m.iem_raw_storage_driver = 'remote' AND m.iem_iia_inbound_imap_account_id IS NOT NULL)
			                    OR COALESCE(length(m.iem_raw_message), 0) > 0
			                    OR m.iem_raw_storage_key IS NOT NULL
			                    OR " . self::archiveSourceSql() . ") THEN 'unrecoverable'
			          WHEN m.iem_lists_attempt_time IS NOT NULL THEN 'retrying'
			          ELSE 'waiting'
			        END AS state,
			        COALESCE(m.iem_sealed_owner_user_id::text, '') AS owner,
			        count(*) AS n
			   FROM iem_inbound_email_messages m
			  WHERE m.iem_direction = 'inbound'
			    AND m.iem_delete_time IS NULL
			    AND m.iem_pending_parse IS NOT TRUE
			    AND COALESCE(length(m.iem_raw_headers), 0) = 0
			    AND (m.iem_lists_attempt_time IS NOT NULL OR (m.iem_to IS NULL AND m.iem_cc IS NULL))
			  GROUP BY 1, 2")->fetchAll(PDO::FETCH_ASSOC);
		$out = array('filled' => 0, 'waiting' => array(), 'retrying' => 0, 'unrecoverable' => 0);
		foreach ($rows as $r) {
			$n = intval($r['n']);
			if ($r['state'] === 'waiting') {
				$out['waiting'][(string)$r['owner']] = ($out['waiting'][(string)$r['owner']] ?? 0) + $n;
			} else {
				$out[$r['state']] += $n;
			}
		}
		return $out;
	}

	/**
	 * The operator's view of progress(), as a card on Inbound Email → Accounts.
	 * The catch-up runs in the owner's browser session and leaves no trace an
	 * operator can see; this is that trace. Renders nothing when no old row is
	 * still without its lists, and goes with this class at retirement (the
	 * page's single call is the only thing outside this file).
	 */
	public static function renderProgressCard(): void {
		$p = self::progress();
		$waiting = array_sum($p['waiting']);
		if ($waiting === 0 && $p['retrying'] === 0 && $p['unrecoverable'] === 0 && $p['filled'] === 0) {
			return;
		}
		// Finished = nothing left that could still be answered: no row waiting
		// on a source, and every account's sweep walked to the end. Rows whose
		// source is gone are retried daily by design and do not hold this open.
		$swept = AddressListSweep::allFinished();
		$finished = ($waiting === 0 && $swept);
		echo '<div class="card mb-3' . ($finished ? ' border-success' : '') . '"><div class="card-body">';
		echo '<h5 class="card-title mb-2">Recovering To/Cc on older messages '
			. ($finished ? '<span class="badge bg-success">Finished</span>'
			             : '<span class="badge bg-info text-dark">In progress</span>')
			. '</h5>';
		echo '<p class="mb-1">Messages stored before the To and Cc lists were kept get them back from their '
			. 'source copy while the owner is signed in with their vault open. Nothing runs in the background.</p>';
		echo '<ul class="mb-0">';
		echo '<li><strong>' . number_format($p['filled']) . '</strong> recovered so far.</li>';
		if ($waiting > 0) {
			$by_owner = array();
			foreach ($p['waiting'] as $owner_id => $n) {
				if ($owner_id === '') {
					$by_owner[] = number_format($n) . ' on shared mailboxes (any member who opens the reader)';
					continue;
				}
				$owner = new User(intval($owner_id), TRUE);
				$who = $owner->key ? $owner->get('usr_email') : 'user ' . intval($owner_id);
				$by_owner[] = number_format($n) . ' waiting on ' . htmlspecialchars((string)$who);
			}
			echo '<li><strong>' . number_format($waiting) . '</strong> still to do: ' . implode('; ', $by_owner) . '.</li>';
		}
		if ($p['retrying'] > 0) {
			echo '<li><strong>' . number_format($p['retrying']) . '</strong> asked and not answered by the source copy '
				. '(the message is gone from where it was pulled); asked again daily.</li>';
		}
		if ($p['unrecoverable'] > 0) {
			echo '<li><strong>' . number_format($p['unrecoverable']) . '</strong> have no copy on this server'
				. ($swept
					? ' and are not in the connected mail account either. Their To/Cc can only come from the '
					  . 'archive they were imported from: upload it again and import it into the same mailbox '
					  . '(nothing is created or deleted; every message matches its existing row), keep the '
					  . 'archive, and open the reader with your vault unlocked — they move to <em>still to do</em> '
					  . 'and fill from it.'
					: '. They are being looked for in the connected mail account (below); any still without '
					  . 'To/Cc when that finishes are not in the account either.')
				. '</li>';
		}
		foreach (AddressListSweep::describe() as $line) {
			echo '<li>Reading headers back from ' . htmlspecialchars($line) . '</li>';
		}
		echo '</ul></div></div>';
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
			"SELECT m.iem_inbound_email_message_id AS msg_id,
			        m.iem_raw_storage_driver AS driver,
			        m.iem_iia_inbound_imap_account_id AS acc_id,
			        m.iem_imap_folder AS folder,
			        m.iem_imap_uid AS uid,
			        m.iem_imap_uidvalidity AS uidvalidity,
			        m.iem_message_id_header AS message_id,
			        (COALESCE(length(m.iem_raw_message), 0) > 0 OR m.iem_raw_storage_key IS NOT NULL) AS has_raw,
			        (SELECT e.mie_mir_mail_import_run_id || '|' || e.mie_locator
			           FROM mie_mail_import_entries e
			           JOIN mir_mail_import_runs r ON r.mir_mail_import_run_id = e.mie_mir_mail_import_run_id
			          WHERE e.mie_iem_inbound_email_message_id = m.iem_inbound_email_message_id
			            AND e.mie_state IN ('stored', 'dedup')
			            AND r.mir_fil_file_id IS NOT NULL AND r.mir_delete_time IS NULL
			          ORDER BY e.mie_mail_import_entry_id DESC LIMIT 1) AS archive_ref
			   FROM iem_inbound_email_messages m
			  WHERE " . self::candidateWhere() . "
			  ORDER BY m.iem_received_time DESC, m.iem_inbound_email_message_id DESC
			  LIMIT " . intval($max));
		$stmt->execute(array($user_id, $user_id));
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		// 'remote' rows group by (account, folder) so each group's headers come
		// back in a few round trips; stored-raw rows are one at a time. Newest
		// first within each — the whole batch is the newest $max regardless.
		$remote = array();
		$stored = array();
		$archive = array(); // run id => rows, read through that run's archive
		foreach ($rows as $row) {
			$has_raw = in_array($row['has_raw'], array(true, 't', '1', 1), true);
			if ((string)$row['driver'] === 'remote' && intval($row['acc_id']) > 0) {
				$remote[intval($row['acc_id']) . '|' . (string)$row['folder']][] = $row;
			} elseif ($has_raw || empty($row['archive_ref'])) {
				$stored[] = $row;
			} else {
				list($run_id, $locator) = explode('|', (string)$row['archive_ref'], 2);
				$row['locator'] = $locator;
				$archive[intval($run_id)][] = $row;
			}
		}

		$ingestors = array(); // one open IMAP connection per account for the batch
		$done = 0;
		try {
			foreach ($archive as $run_id => $group) {
				if ($deadline !== null && microtime(true) >= $deadline) {
					return $done;
				}
				$done += self::fillArchiveGroup(intval($run_id), $group, $db, $deadline);
			}
			foreach ($remote as $group) {
				foreach (array_chunk($group, self::FETCH_CHUNK) as $chunk) {
					if ($deadline !== null && microtime(true) >= $deadline) {
						return $done;
					}
					$done += self::fillRemoteChunk($chunk, $ingestors, $db);
				}
			}
			foreach ($stored as $row) {
				if ($deadline !== null && microtime(true) >= $deadline) {
					break;
				}
				$msg_id = intval($row['msg_id']);
				self::stamp($db, array($msg_id));
				try {
					// One message is one unit for the hot-turn rule: opening a
					// sealed raw opens this owner's scope, and nothing one row
					// decrypts is in play when the next one starts.
					$ok = SealedEgressGuard::isolate(function () use ($msg_id) {
						return self::fillStored($msg_id);
					});
					if ($ok) {
						$done++;
					}
				} catch (VaultLockedException $e) {
					// The window closed mid-drain — stop, never an error. The row
					// was not asked anything, so it owes no retry wait: unstamp it.
					self::unstamp($db, array($msg_id));
					break;
				} catch (\Throwable $e) {
					error_log('AddressListBackfill: could not fill message ' . $msg_id . ': ' . $e->getMessage());
				}
			}
		} catch (VaultLockedException $e) {
			// fillRemoteChunk() has already unstamped what it did not write.
		} finally {
			foreach ($ingestors as $ingestor) {
				try { $ingestor->close(); } catch (\Throwable $e) { /* best effort */ }
			}
		}
		return $done;
	}

	/**
	 * One (account, folder) chunk of 'remote' rows: stamp them all, fetch their
	 * header blocks in one call, write the lists row by row. A row whose source
	 * cannot answer stays stamped for the daily retry. A window that closes
	 * mid-chunk unstamps every row not yet written — none of those was asked
	 * anything the vault answered — and rethrows so the drain stops.
	 *
	 * @param array<int,ImapIngestor> $ingestors per-account cache, filled here
	 */
	private static function fillRemoteChunk(array $chunk, array &$ingestors, PDO $db): int {
		$ids = array_map(function ($row) { return intval($row['msg_id']); }, $chunk);
		self::stamp($db, $ids);

		$acc_id = intval($chunk[0]['acc_id']);
		if (!isset($ingestors[$acc_id])) {
			$account = new InboundImapAccount($acc_id, TRUE);
			if (!$account->key || !$account->get('iia_is_enabled')) {
				return 0; // stamped: an account switched off is asked again tomorrow, not every drain
			}
			$ingestors[$acc_id] = self::makeIngestor($account);
		}

		$locators = array();
		foreach ($chunk as $i => $row) {
			$locators[$i] = array(
				'uid'         => intval($row['uid']),
				'uidvalidity' => $row['uidvalidity'] !== null ? intval($row['uidvalidity']) : null,
				'message_id'  => (string)$row['message_id'],
			);
		}
		$blocks = $ingestors[$acc_id]->fetchHeaderTexts((string)$chunk[0]['folder'], $locators);

		$done = 0;
		foreach ($chunk as $i => $row) {
			$msg_id = intval($row['msg_id']);
			$res = $blocks[$i] ?? null;
			if (empty($res['ok'])) {
				continue;
			}
			$block = (string)$res['headers'];
			try {
				// Same one-row unit as the stored path: the write may unwrap the
				// row's DEK, and nothing that opens survives into the next row.
				$ok = SealedEgressGuard::isolate(function () use ($msg_id, $block) {
					$msg = new InboundEmailMessage($msg_id, TRUE);
					if (!$msg->key) {
						return false;
					}
					$lists = MailAddressList::fromHeaderBlock($block);
					return self::writeLists($msg, $lists['to'], $lists['cc']);
				});
				if ($ok) {
					$done++;
				}
			} catch (VaultLockedException $e) {
				$rest = array();
				foreach ($chunk as $j => $r) {
					if ($j >= $i) {
						$rest[] = intval($r['msg_id']);
					}
				}
				self::unstamp($db, $rest);
				throw $e;
			} catch (\Throwable $e) {
				error_log('AddressListBackfill: could not fill message ' . $msg_id . ': ' . $e->getMessage());
			}
		}
		return $done;
	}

	/**
	 * Rows read through one import run's archive (§ 5b): open the run's
	 * reader once, then per row stamp, read the message at its locator, take
	 * its header block, write the lists. A locator the archive no longer
	 * answers stays stamped for the daily retry; an archive that will not
	 * open stamps the whole group the same way. A window that closes
	 * mid-group unstamps the rows not yet written and rethrows.
	 */
	private static function fillArchiveGroup(int $run_id, array $group, PDO $db, ?float $deadline): int {
		$ids = array_map(function ($row) { return intval($row['msg_id']); }, $group);
		self::stamp($db, $ids);
		try {
			$run = new MailImportRun($run_id, TRUE);
			if (!$run->key) {
				throw new RuntimeException('import run ' . $run_id . ' is gone');
			}
			list($reader, $path) = (new MailArchiveImporter($run))->readerAndPath();
		} catch (\Throwable $e) {
			error_log('AddressListBackfill: archive of import run ' . $run_id . ' could not be opened: ' . $e->getMessage());
			return 0;
		}
		$done = 0;
		foreach ($group as $i => $row) {
			if ($deadline !== null && microtime(true) >= $deadline) {
				$rest = array();
				foreach ($group as $j => $r) {
					if ($j >= $i) { $rest[] = intval($r['msg_id']); }
				}
				self::unstamp($db, $rest); // not asked anything: no wait owed
				break;
			}
			$msg_id = intval($row['msg_id']);
			try {
				$raw = $reader->read($path, (string)$row['locator']);
				if (trim($raw) === '') {
					continue; // stays stamped: the archive holds nothing at this position
				}
				$block = MailArchiveReader::headerBlock($raw);
				$ok = SealedEgressGuard::isolate(function () use ($msg_id, $block) {
					$msg = new InboundEmailMessage($msg_id, TRUE);
					if (!$msg->key) {
						return false;
					}
					$lists = MailAddressList::fromHeaderBlock($block);
					return self::writeLists($msg, $lists['to'], $lists['cc']);
				});
				if ($ok) {
					$done++;
				}
			} catch (VaultLockedException $e) {
				$rest = array();
				foreach ($group as $j => $r) {
					if ($j >= $i) { $rest[] = intval($r['msg_id']); }
				}
				self::unstamp($db, $rest);
				throw $e;
			} catch (\Throwable $e) {
				error_log('AddressListBackfill: could not fill message ' . $msg_id . ' from the archive of run '
					. $run_id . ': ' . $e->getMessage());
			}
		}
		return $done;
	}

	/** stamp() for the sweep, which has no handle of its own. */
	public static function stampRows(array $ids): void {
		self::stamp(DbConnector::get_instance()->get_db_link(), $ids);
	}

	/** Mark these rows attempted now — the daily-retry clock starts here. */
	private static function stamp(PDO $db, array $ids): void {
		if (empty($ids)) {
			return;
		}
		$db->prepare('UPDATE iem_inbound_email_messages SET iem_lists_attempt_time = now()
			WHERE iem_inbound_email_message_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')
			->execute(array_values($ids));
	}

	/** Take the stamp back: these rows were not asked anything, so they owe no wait. */
	private static function unstamp(PDO $db, array $ids): void {
		if (empty($ids)) {
			return;
		}
		$db->prepare('UPDATE iem_inbound_email_messages SET iem_lists_attempt_time = NULL
			WHERE iem_inbound_email_message_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')
			->execute(array_values($ids));
	}

	/**
	 * A stored-raw row: open the raw (in-window when sealed), read its header
	 * block, write the lists. Returns false when the row has no raw to read
	 * (it stays stamped for the daily retry).
	 */
	private static function fillStored(int $msg_id): bool {
		$msg = new InboundEmailMessage($msg_id, TRUE);
		if (!$msg->key) {
			return false;
		}
		// Throws VaultLockedException when the window is closed, which the
		// drain treats as "stop".
		$raw = $msg->getRawMessage();
		if ($raw === null || $raw === '') {
			return false;
		}
		$block = (new InboundEmailRouter())->rawHeaderBlock($raw);
		$lists = MailAddressList::fromHeaderBlock($block);
		return self::writeLists($msg, $lists['to'], $lists['cc']);
	}

	/**
	 * Write the lists the way ingest does for this row's state: sealed under
	 * the row's own DEK when the row is sealed, plaintext otherwise. An empty
	 * list is stored as '' — "captured, nothing there" — so the row leaves the
	 * candidate set and the read path stops deriving.
	 */
	public static function writeLists(InboundEmailMessage $msg, string $to, string $cc): bool {
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
