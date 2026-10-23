<?php
/**
 * MailExport - a mailbox, or one folder of it, as a zip with one mbox file per
 * folder (export_mail_contacts_calendar_drive.md § WP1).
 *
 * FOLDERS. Joinery files mail by view and label, not by folder. An export
 * writes each message once, into the view it sits in — Inbox, Archive, Sent,
 * Spam, Trash, in that order of the file names but decided Trash first (a
 * discarded message), then Spam, then Sent (anything the member sent), then
 * Archive or Inbox. Drafts are not exported: they are unsent work, and our own
 * import would file them as mail. A single label can be exported on its own
 * ('label:{id}'): its messages, wherever they sit.
 *
 * LABELS AND STATE travel as an X-Gmail-Labels header on each message, the way
 * Google Takeout writes them: the view first (Inbox, Archived, Sent, Spam,
 * Trash), then the message's labels, then Unread and Starred. Our importer
 * reads that header (MailArchiveReader::gmailLabels), so an export imports
 * back with its folders, labels and read state.
 *
 * BYTES. Each message is mailbox_message_bytes(): the stored original, else
 * the original fetched live from a connected source, else a full rebuild
 * (MessageRebuild, marked X-Joinery-Rebuilt: yes). A message that cannot be
 * produced (sealed to someone else, end-to-end in a mailbox no longer at
 * Fortress, sealed with no window to open it) is left out and named in
 * 'Messages not included.txt'.
 *
 * THE BUILD runs in bounded slices (build()): Standard ones from the
 * RunMailExports task, Private ones as the owner's deferred work. Each slice
 * writes whole pieces — {run dir}/{folder}-{piece}.mbox, or .jsfc sealed to
 * the run's key — and records how far it got only after a piece is on disk,
 * so a slice killed halfway costs that piece and nothing else. The mbox is
 * mboxrd: a "From " separator line before each message, and any line of the
 * message that starts with zero or more ">" then "From " gets one more ">".
 *
 * THE ZIP is put together as it downloads (streamZip()), from the pieces in
 * order, so the disk holds the mail once. A Private run's pieces are opened
 * in the owner's window as they stream.
 *
 * A FORTRESS mailbox is exported by the owner's browser
 * (assets/mailbox_export.js): fortressPage() hands it the folder's rows as
 * the reader's thread payload carries them, ciphertext untouched.
 *
 * A member who deletes a run while a slice is building it cancels it: the
 * slice stops at its next piece (MailExportCancelled), leaves no folder
 * behind, and nothing records a failure.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/message_export.php'));

/** The run was deleted (the member cancelled it) while a slice was building it. */
class MailExportCancelled extends RuntimeException {}

class MailExport {

	/** The views an export writes, in file order: key => [file name, X-Gmail-Labels word]. */
	const FOLDERS = array(
		'inbox'   => array('Inbox',   'Inbox'),
		'archive' => array('Archive', 'Archived'),
		'sent'    => array('Sent',    'Sent'),
		'spam'    => array('Spam',    'Spam'),
		'trash'   => array('Trash',   'Trash'),
	);

	/** Messages read per query. */
	const BATCH = 100;

	/** About how much plaintext one piece holds before the next begins (a test sets it small). */
	public static $piece_bytes = 64 * 1024 * 1024;

	/** The note naming what was left out, at the top of the zip. */
	const NOTE_NAME = 'Messages not included.txt';

	/** Lines the note keeps; a run that leaves out more says how many more. */
	const MAX_NOTE_LINES = 2000;

	/** Fortress rows handed to the browser per page. */
	const FORTRESS_PAGE = 25;

	/** How long the second factor given to start a Fortress export covers its paging. */
	const FORTRESS_HOURS = 12;

	/** The mbox separator's sender when a message's own cannot be used. */
	const ENVELOPE_DEFAULT = 'MAILER-DAEMON';

	// ── folders ─────────────────────────────────────────────────────────────

	/** Every folder key a member can pick for this mailbox: the views, then its labels. */
	public static function folderChoices(int $alias_id): array {
		$out = array();
		foreach (self::FOLDERS as $key => $f) {
			$out[$key] = $f[0];
		}
		foreach (self::labels($alias_id) as $id => $name) {
			$out['label:' . $id] = $name;
		}
		return $out;
	}

	/** The mailbox's live labels, id => name. */
	public static function labels(int $alias_id): array {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT ilb_inbound_email_label_id, ilb_label_name
			FROM ilb_inbound_email_labels
			WHERE ilb_iea_inbound_email_alias_id = ? AND ilb_delete_time IS NULL
			ORDER BY lower(ilb_label_name), ilb_inbound_email_label_id');
		$stmt->execute(array($alias_id));
		$out = array();
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$out[intval($r['ilb_inbound_email_label_id'])] = (string)$r['ilb_label_name'];
		}
		return $out;
	}

	/** Is $key a folder of this mailbox? */
	public static function validFolder(int $alias_id, string $key): bool {
		return array_key_exists($key, self::folderChoices($alias_id));
	}

	/** The folders this run writes, in order. */
	public static function plan(MailExportRun $run): array {
		$folder = (string)$run->get('mxr_folder');
		return $folder !== '' ? array($folder) : array_keys(self::FOLDERS);
	}

	/** A folder's file name, without .mbox. */
	public static function folderName(string $key, int $alias_id): string {
		if (isset(self::FOLDERS[$key])) {
			return self::FOLDERS[$key][0];
		}
		$labels = self::labels($alias_id);
		$id = intval(substr($key, strlen('label:')));
		return $labels[$id] ?? 'Label';
	}

	/**
	 * The rows of one folder, as a WHERE fragment over iem_inbound_email_messages.
	 * The five views do not overlap; a label folder is its messages wherever
	 * they sit, Trash and Spam aside.
	 */
	public static function folderSql(string $key, int $alias_id): string {
		$sql = 'iem_iea_inbound_email_alias_id = ' . intval($alias_id)
			. " AND iem_direction IS DISTINCT FROM 'draft'";
		$live = ' AND iem_delete_time IS NULL';
		$not_spam = " AND iem_spam_verdict IS DISTINCT FROM 'spam'";
		$received = " AND iem_direction IS DISTINCT FROM 'outbound'";
		switch ($key) {
			case 'trash':
				return $sql . ' AND iem_delete_time IS NOT NULL';
			case 'spam':
				return $sql . $live . " AND iem_spam_verdict = 'spam'";
			case 'sent':
				return $sql . $live . $not_spam . " AND iem_direction = 'outbound'";
			case 'archive':
				return $sql . $live . $not_spam . $received . ' AND iem_is_archived = true';
			case 'inbox':
				return $sql . $live . $not_spam . $received . ' AND iem_is_archived = false';
		}
		if (strpos($key, 'label:') === 0) {
			return $sql . $live . $not_spam . ' AND iem_inbound_email_message_id IN (SELECT ilm_iem_inbound_email_message_id
				FROM ilm_inbound_label_members WHERE ilm_ilb_inbound_email_label_id = '
				. intval(substr($key, strlen('label:'))) . ' AND ilm_present_local = true)';
		}
		return '1=0';
	}

	/** How many messages these folders hold. */
	public static function count(int $alias_id, array $keys): int {
		$db = DbConnector::get_instance()->get_db_link();
		$n = 0;
		foreach ($keys as $key) {
			$n += intval($db->query('SELECT COUNT(*) FROM iem_inbound_email_messages WHERE '
				. self::folderSql($key, $alias_id))->fetchColumn());
		}
		return $n;
	}

	/** Their stored size, for the disk check before a build is accepted. */
	public static function storedBytes(int $alias_id, array $keys): int {
		$db = DbConnector::get_instance()->get_db_link();
		$n = 0;
		foreach ($keys as $key) {
			$n += intval($db->query('SELECT COALESCE(SUM(iem_size_bytes), 0) FROM iem_inbound_email_messages WHERE '
				. self::folderSql($key, $alias_id))->fetchColumn());
		}
		return $n;
	}

	/** The next ids of a folder after $after, in id order. */
	private static function idsAfter(string $key, int $alias_id, int $after, int $limit): array {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT iem_inbound_email_message_id
			FROM iem_inbound_email_messages WHERE ' . self::folderSql($key, $alias_id) . '
			  AND iem_inbound_email_message_id > ?
			ORDER BY iem_inbound_email_message_id ASC LIMIT ' . intval($limit));
		$stmt->execute(array($after));
		return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
	}

	/** Each message's label names (the mailbox's own, present here), id => names. */
	public static function labelNamesFor(array $ids): array {
		$ids = array_values(array_filter(array_map('intval', $ids)));
		if (!$ids) {
			return array();
		}
		$rows = DbConnector::get_instance()->get_db_link()->query('SELECT m.ilm_iem_inbound_email_message_id AS id, l.ilb_label_name AS name
			FROM ilm_inbound_label_members m
			JOIN ilb_inbound_email_labels l ON l.ilb_inbound_email_label_id = m.ilm_ilb_inbound_email_label_id
			JOIN iem_inbound_email_messages e ON e.iem_inbound_email_message_id = m.ilm_iem_inbound_email_message_id
			WHERE m.ilm_iem_inbound_email_message_id IN (' . implode(',', $ids) . ')
			  AND m.ilm_present_local = true AND l.ilb_delete_time IS NULL
			  AND l.ilb_iea_inbound_email_alias_id = e.iem_iea_inbound_email_alias_id
			ORDER BY lower(l.ilb_label_name)')->fetchAll(PDO::FETCH_ASSOC);
		$out = array();
		foreach ($rows as $r) {
			$out[intval($r['id'])][] = (string)$r['name'];
		}
		return $out;
	}

	// ── one message ─────────────────────────────────────────────────────────

	/**
	 * The X-Gmail-Labels value for a message: the view word, its labels, then
	 * Unread and Starred. A name holding a comma or a quote is quoted, its
	 * quotes doubled.
	 */
	public static function gmailLabels(string $folder_key, array $labels, bool $is_read, bool $is_starred, ?string $view_key = null): string {
		$view = $view_key ?? $folder_key;
		$words = array(isset(self::FOLDERS[$view]) ? self::FOLDERS[$view][1] : 'Archived');
		foreach ($labels as $name) {
			$name = trim(str_replace(array("\r", "\n"), ' ', (string)$name));
			if ($name === '') {
				continue;
			}
			$words[] = (strpbrk($name, ',"') !== false) ? '"' . str_replace('"', '""', $name) . '"' : $name;
		}
		if (!$is_read) {
			$words[] = 'Unread';
		}
		if ($is_starred) {
			$words[] = 'Starred';
		}
		return implode(',', $words);
	}

	/**
	 * Which of the five views a message sits in (the one a label folder's
	 * messages are filed under on the way back in).
	 */
	public static function viewOf(InboundEmailMessage $msg): string {
		if ($msg->get('iem_delete_time')) {
			return 'trash';
		}
		if ((string)$msg->get('iem_spam_verdict') === 'spam') {
			return 'spam';
		}
		if ((string)$msg->get('iem_direction') === 'outbound') {
			return 'sent';
		}
		return self::truthy($msg->get('iem_is_archived')) ? 'archive' : 'inbox';
	}

	/**
	 * One message as an mbox entry: the separator, the X-Gmail-Labels header,
	 * the message with its From lines escaped, a blank line.
	 */
	public static function mboxEntry(string $raw, string $labels_header, string $envelope, int $time): string {
		$crlf = strpos($raw, "\r\n");
		$lf = strpos($raw, "\n");
		$eol = ($crlf !== false && $crlf <= $lf) ? "\r\n" : "\n";
		$raw = 'X-Gmail-Labels: ' . $labels_header . $eol . $raw;
		$raw = preg_replace('/^(>*From )/m', '>$1', $raw);
		if (substr($raw, -1) !== "\n") {
			$raw .= $eol;
		}
		return 'From ' . $envelope . ' ' . self::asctime($time) . "\n" . $raw . $eol;
	}

	/** The separator's sender: the message's From address when it is a plain one. */
	public static function envelopeOf(string $sender): string {
		$list = MailAddressList::parse($sender);
		$email = $list ? (string)$list[0]['email'] : '';
		return preg_match('/^[^\s<>"@]+@[^\s<>"@]+$/', $email) ? $email : self::ENVELOPE_DEFAULT;
	}

	/** asctime(3), UTC: "Thu Jan  1 00:00:00 2026". */
	public static function asctime(int $time): string {
		return gmdate('D M ', $time) . str_pad(gmdate('j', $time), 2, ' ', STR_PAD_LEFT) . gmdate(' H:i:s Y', $time);
	}

	/**
	 * One message's entry for a server build, or why it is left out.
	 *
	 * @return array{ok:bool, entry?:string, reason?:string, locked?:bool}
	 */
	private static function entryFor(InboundEmailMessage $msg, string $folder_key, array $labels, int $owner_id, array &$ingestors): array {
		if (InboundEmailMessage::isBrowserSealed($msg)) {
			return array('ok' => false, 'reason' => 'end-to-end encrypted, so only its owner\'s browser can read it');
		}
		if ($msg->rowIsSealed() || self::truthy($msg->get('iem_raw_sealed'))) {
			$sealed_to = InboundEmailMessage::sealedOwnerFor($msg);
			if ($sealed_to !== null && $sealed_to !== $owner_id) {
				return array('ok' => false, 'reason' => 'sealed to another person\'s vault');
			}
		}
		$ingestor = null;
		$account_id = intval($msg->get('iem_iia_inbound_imap_account_id'));
		if (MessageRebuild::kindOf($msg) === 'remote' && $account_id > 0) {
			if (!array_key_exists($account_id, $ingestors)) {
				$ingestors[$account_id] = null;
				require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_accounts_class.php'));
				require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));
				$account = new InboundImapAccount($account_id, TRUE);
				if ($account->key) {
					$ingestors[$account_id] = new ImapIngestor($account);
				}
			}
			$ingestor = $ingestors[$account_id];
		}
		$bytes = mailbox_message_bytes($msg, $ingestor);
		if ($bytes['locked']) {
			return array('ok' => false, 'locked' => true,
				'reason' => 'sealed to its owner\'s vault, which this export could not open');
		}
		if (!$bytes['ok']) {
			return array('ok' => false, 'reason' => (string)$bytes['reason']);
		}
		$header = self::gmailLabels($folder_key, $labels, self::truthy($msg->get('iem_is_read')),
			self::truthy($msg->get('iem_is_starred')), self::viewOf($msg));
		$time = strtotime((string)$msg->get('iem_received_time') . ' UTC') ?: time();
		return array('ok' => true, 'entry' => self::mboxEntry((string)$bytes['raw'], $header,
			self::envelopeOf((string)$msg->get('iem_sender')), $time));
	}

	/**
	 * The note's line for a message left out. A Private run's note is stored
	 * on its row in the clear, so it names the message by folder, date and
	 * number only: a sealed subject never lands outside its seal.
	 */
	private static function noteLine(string $folder, InboundEmailMessage $msg, string $reason, bool $with_subject = true): string {
		$when = (string)$msg->get('iem_received_time');
		$subject = '';
		if ($with_subject && !InboundEmailMessage::isBrowserSealed($msg) && !$msg->rowIsSealed()) {
			try {
				$subject = trim((string)$msg->get('iem_subject'));
			} catch (Throwable $e) {
				// the date and folder still say which
			}
		}
		$subject = $subject !== '' ? '"' . str_replace(array("\r", "\n"), ' ', $subject) . '"' : 'message ' . intval($msg->key);
		return $folder . ': ' . $subject . ' (' . substr($when, 0, 16) . ' UTC): ' . $reason . '.';
	}

	// ── the build ───────────────────────────────────────────────────────────

	/**
	 * Build until $deadline: whole pieces, each recorded once it is on disk.
	 *
	 * A Private run opens its key in the owner's window; with the window shut
	 * it stops where it is and answers locked, and the next slice in an open
	 * window goes on from there.
	 *
	 * @return array{written:int, left_out:int, finished:bool, locked:bool}
	 * @throws MailExportCancelled the member deleted the run while this slice built it
	 */
	public static function build(MailExportRun $run, float $deadline): array {
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
		$result = array('written' => 0, 'left_out' => 0, 'finished' => false, 'locked' => false);
		$alias_id = intval($run->get('mxr_iea_inbound_email_alias_id'));
		$owner_id = intval($run->get('mxr_usr_user_id'));
		$keys = self::plan($run);
		$sealed = (string)$run->get('mxr_level') === MailExportRun::LEVEL_PRIVATE;

		$fk = null;
		if ($sealed) {
			try {
				$fk = $run->exportKey();
			} catch (VaultLockedException $e) {
				$result['locked'] = true;
				return $result;
			}
		}
		if ((string)$run->get('mxr_state') === MailExportRun::STATE_QUEUED) {
			$run->writeColumns(array('mxr_state' => MailExportRun::STATE_BUILDING,
				'mxr_total' => self::count($alias_id, $keys)));
		}
		$dir = $run->dir();
		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
			throw new RuntimeException('The export could not make its folder on this server.');
		}

		$ingestors = array();
		try {
			while (microtime(true) < $deadline) {
				$cursor = $run->cursor();
				$f = intval($cursor['folder'] ?? 0);
				if ($f >= count($keys)) {
					break;
				}
				$after = intval($cursor['after'] ?? 0);
				$piece = intval($cursor['piece'] ?? 0);
				$key = $keys[$f];
				$name = self::folderName($key, $alias_id);

				// The piece's messages, made as they are written: $state carries
				// what the generator did out to here.
				$state = array('after' => $after, 'written' => 0, 'left' => 0, 'notes' => array(),
					'bytes' => 0, 'folder_done' => false, 'locked' => false, 'sealed_run' => $sealed);
				$messages = self::pieceMessages($key, $name, $alias_id, $owner_id, $deadline, $state, $ingestors);
				$path = sprintf('%s/%02d-%05d.%s', $dir, $f, $piece, $sealed ? 'jsfc' : 'mbox');
				$tmp = $path . '.part';
				self::assertAlive($run);
				try {
					if ($sealed) {
						SealedFileContainer::sealIterable($messages, $tmp, $fk);
					} else {
						$out = @fopen($tmp, 'wb');
						if ($out === false) {
							throw new RuntimeException('The export could not write to its folder on this server.');
						}
						try {
							foreach ($messages as $bytes) {
								if (fwrite($out, $bytes) !== strlen($bytes)) {
									throw new RuntimeException('The export ran out of room on this server.');
								}
							}
						} finally {
							fclose($out);
						}
					}
				} catch (Throwable $e) {
					// A folder deleted under the write is a cancel, not a failure.
					self::assertAlive($run);
					throw $e;
				}

				// The piece is on disk; now the run may say so.
				if ($state['written'] > 0) {
					rename($tmp, $path);
					$piece++;
				} else {
					@unlink($tmp);
				}
				$next = $state['folder_done']
					? array('folder' => $f + 1, 'after' => 0, 'piece' => 0)
					: array('folder' => $f, 'after' => $state['after'], 'piece' => $piece);
				self::commit($run, $next, $state);
				$result['written'] += $state['written'];
				$result['left_out'] += $state['left'];
				if ($state['locked']) {
					$result['locked'] = true;
					break;
				}
			}
		} finally {
			foreach ($ingestors as $ingestor) {
				if ($ingestor !== null) {
					try { $ingestor->close(); } catch (Throwable $e) {}
				}
			}
		}

		$cursor = $run->cursor();
		if (intval($cursor['folder'] ?? 0) >= count($keys) && !$result['locked']) {
			// The email saying so goes from the RunMailExports task (announceReady):
			// a Private build runs in the request that opened sealed mail, which
			// writes nothing long outside a seal, a queued email included.
			$run->moveTo(MailExportRun::STATE_READY);
			$result['finished'] = true;
		}
		return $result;
	}

	/**
	 * The entries of one piece, yielded as they are made, until the piece is
	 * full, the folder ends, the deadline passes or the window shuts. What it
	 * did is left in $state.
	 */
	private static function pieceMessages(string $key, string $name, int $alias_id, int $owner_id, float $deadline,
			array &$state, array &$ingestors): Generator {
		while (true) {
			$ids = self::idsAfter($key, $alias_id, $state['after'], self::BATCH);
			if (!$ids) {
				$state['folder_done'] = true;
				return;
			}
			$labels = self::labelNamesFor($ids);
			foreach ($ids as $id) {
				if (microtime(true) >= $deadline || $state['bytes'] >= self::$piece_bytes) {
					return;
				}
				$msg = new InboundEmailMessage($id, TRUE);
				if (!$msg->key) {
					$state['after'] = $id;
					continue;
				}
				$got = self::entryFor($msg, $key, $labels[$id] ?? array(), $owner_id, $ingestors);
				if (!$got['ok'] && !empty($got['locked']) && $state['sealed_run']) {
					$state['locked'] = true;
					return;
				}
				$state['after'] = $id;
				if ($got['ok']) {
					$state['written']++;
					$state['bytes'] += strlen($got['entry']);
					yield $got['entry'];
				} else {
					$state['left']++;
					$state['notes'][] = self::noteLine($name, $msg, (string)$got['reason'], !$state['sealed_run']);
				}
			}
		}
	}

	/** @throws MailExportCancelled when the run's row is gone */
	private static function assertAlive(MailExportRun $run): void {
		$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT 1 FROM mxr_mail_export_runs WHERE mxr_mail_export_run_id = ?');
		$q->execute(array(intval($run->key)));
		if (!$q->fetchColumn()) {
			MailExportRun::removeDir($run->dir());
			throw new MailExportCancelled('Export run ' . intval($run->key) . ' was deleted while it was being built.');
		}
	}

	/**
	 * Record a piece: where the build got to, and its counts, in one write.
	 *
	 * @throws MailExportCancelled the run is gone: its member deleted it mid-slice
	 */
	private static function commit(MailExportRun $run, array $cursor, array $state): void {
		$db = DbConnector::get_instance()->get_db_link();
		$notes = '';
		$kept = intval($run->get('mxr_left_out'));
		foreach ($state['notes'] as $line) {
			if ($kept < self::MAX_NOTE_LINES) {
				$notes .= $line . "\n";
			}
			$kept++;
		}
		$stmt = $db->prepare('UPDATE mxr_mail_export_runs SET mxr_cursor = ?, mxr_done = mxr_done + ?,
				mxr_left_out = mxr_left_out + ?, mxr_bytes = mxr_bytes + ?,
				mxr_left_out_notes = COALESCE(mxr_left_out_notes, \'\') || ?, mxr_update_time = ?
			WHERE mxr_mail_export_run_id = ?');
		$stmt->execute(array(json_encode($cursor), $state['written'], $state['left'], $state['bytes'], $notes,
			gmdate('Y-m-d H:i:s'), intval($run->key)));
		if ($stmt->rowCount() === 0) {
			self::assertAlive($run); // gone: removes the piece just written and throws
		}
		$run->load();
	}

	/**
	 * Tell each person whose download has become ready, the way they take
	 * notices, once. Run by the RunMailExports task, from cron: never from a
	 * request that has opened sealed content. Returns how many were told.
	 */
	public static function announceReady(int $limit = 50): int {
		require_once(PathHelper::getIncludePath('includes/SignalBus.php'));
		$db = DbConnector::get_instance()->get_db_link();
		$ids = $db->query("SELECT mxr_mail_export_run_id FROM mxr_mail_export_runs
			WHERE mxr_state = 'ready' AND mxr_announce_time IS NULL
			ORDER BY mxr_mail_export_run_id ASC LIMIT " . intval($limit))->fetchAll(PDO::FETCH_COLUMN);
		$told = 0;
		foreach ($ids as $id) {
			// Claimed first: a second pass running alongside tells nobody twice.
			$claim = $db->prepare('UPDATE mxr_mail_export_runs SET mxr_announce_time = ?
				WHERE mxr_mail_export_run_id = ? AND mxr_announce_time IS NULL');
			$claim->execute(array(gmdate('Y-m-d H:i:s'), intval($id)));
			if ($claim->rowCount() !== 1) {
				continue;
			}
			$run = new MailExportRun(intval($id), TRUE);
			try {
				$alias = new InboundEmailAlias(intval($run->get('mxr_iea_inbound_email_alias_id')), TRUE);
				SignalBus::dispatch('mail_export.ready', array(
					'run_id'     => intval($run->key),
					'mailbox'    => $alias->key ? (string)$alias->get_full_address() : '',
					'messages'   => intval($run->get('mxr_done')),
					'recipients' => array(intval($run->get('mxr_usr_user_id'))),
				));
				$told++;
			} catch (Throwable $e) {
				error_log('MailExport: could not announce run ' . $run->key . ' — ' . $e->getMessage());
			}
		}
		return $told;
	}

	/** A claim older than this is a pass that died. */
	const STALE_CLAIM_SECONDS = 1800;

	/**
	 * Atomically take one active run at $level (and, for the deferred work,
	 * of one person), oldest claim first; null when there is none.
	 */
	public static function claim(string $level, ?int $user_id): ?MailExportRun {
		$db = DbConnector::get_instance()->get_db_link();
		$states = "'" . implode("','", MailExportRun::ACTIVE_STATES) . "'";
		$params = array($level);
		$user_sql = '';
		if ($user_id !== null) {
			$user_sql = ' AND mxr_usr_user_id = ?';
			$params[] = $user_id;
		}
		$stmt = $db->prepare("UPDATE mxr_mail_export_runs SET mxr_claim_time = now()
			WHERE mxr_mail_export_run_id = (
				SELECT mxr_mail_export_run_id FROM mxr_mail_export_runs
				WHERE mxr_state IN ($states) AND mxr_level = ?" . $user_sql . "
				  AND (mxr_claim_time IS NULL
				       OR mxr_claim_time < now() - INTERVAL '" . self::STALE_CLAIM_SECONDS . " seconds')
				ORDER BY mxr_claim_time ASC NULLS FIRST, mxr_mail_export_run_id ASC
				LIMIT 1
				FOR UPDATE SKIP LOCKED)
			RETURNING mxr_mail_export_run_id");
		$stmt->execute($params);
		$id = $stmt->fetchColumn();
		if ($id === false) {
			return null;
		}
		$run = new MailExportRun(intval($id), TRUE);
		return $run->key ? $run : null;
	}

	/** Hand the run back for the next pass. Never throws. */
	public static function release(int $run_id): void {
		try {
			DbConnector::get_instance()->get_db_link()
				->prepare('UPDATE mxr_mail_export_runs SET mxr_claim_time = NULL WHERE mxr_mail_export_run_id = ?')
				->execute(array($run_id));
		} catch (Throwable $e) {
			error_log('MailExport: could not release the claim on run ' . $run_id . ' — ' . $e->getMessage());
		}
	}

	// ── the download ────────────────────────────────────────────────────────

	/** The download's file name: mail-{address}[-{folder}]-{date}.zip. */
	public static function filename(MailExportRun $run): string {
		$alias = new InboundEmailAlias(intval($run->get('mxr_iea_inbound_email_alias_id')), TRUE);
		$base = 'mail-' . trim(preg_replace('/[^A-Za-z0-9@._-]+/', '-', (string)$alias->get_full_address()), '-');
		$folder = (string)$run->get('mxr_folder');
		if ($folder !== '') {
			$base .= '-' . trim(preg_replace('/[^A-Za-z0-9._-]+/', '-',
				self::folderName($folder, intval($run->get('mxr_iea_inbound_email_alias_id')))), '-');
		}
		$when = substr((string)($run->get('mxr_finish_time') ?: $run->get('mxr_create_time')), 0, 10);
		return $base . '-' . $when . '.zip';
	}

	/**
	 * Send a ready run's zip as the response and exit(). The caller has
	 * authorized the download and, for a Private run, opened its key ($fk).
	 * The session is let go first: the member's other pages, and the vault
	 * heartbeat that keeps their window open, must not wait on years of mail.
	 */
	public static function send(MailExportRun $run, ?string $fk): void {
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename="' . self::filename($run) . '"');
		header('X-Content-Type-Options: nosniff');
		header('Cache-Control: private, no-store');
		@ini_set('zlib.output_compression', '0');
		if (function_exists('apache_setenv')) {
			@apache_setenv('no-gzip', '1');
		}
		while (ob_get_level() > 0) { ob_end_clean(); }
		if (session_status() === PHP_SESSION_ACTIVE) {
			// Years of mail take a while; the member's other pages, and the vault
			// heartbeat that keeps their window open, must not wait on it.
			session_write_close();
		}
		@set_time_limit(0);

		$buffer = '';
		$sink = function ($bytes) use (&$buffer) {
			$buffer .= $bytes;
			if (strlen($buffer) >= 262144) {
				echo $buffer;
				$buffer = '';
				flush();
			}
		};
		try {
			self::streamZip($run, $sink, $fk);
			echo $buffer;
			flush();
		} catch (Throwable $e) {
			// The headers are gone; a short body is how the browser learns it failed.
			error_log('MailExport: sending export ' . intval($run->key) . ' stopped: ' . $e->getMessage());
		}
		exit();
	}

	/** Each folder's pieces on disk, in order: folder index => paths. */
	public static function pieces(MailExportRun $run): array {
		$out = array();
		foreach (glob($run->dir() . '/*') ?: array() as $path) {
			if (preg_match('~/(\d{2})-(\d{5})\.(mbox|jsfc)$~', $path, $m)) {
				$out[intval($m[1])][intval($m[2])] = $path;
			}
		}
		ksort($out);
		foreach ($out as $f => $list) {
			ksort($list);
			$out[$f] = array_values($list);
		}
		return $out;
	}

	/**
	 * Write the zip to $sink: one mbox per folder from its pieces, and the
	 * note when anything was left out. A Private run's pieces open under $fk.
	 */
	public static function streamZip(MailExportRun $run, callable $sink, ?string $fk = null): int {
		$alias_id = intval($run->get('mxr_iea_inbound_email_alias_id'));
		$keys = self::plan($run);
		$mtime = strtotime((string)($run->get('mxr_finish_time') ?: $run->get('mxr_create_time')) . ' UTC') ?: time();
		$zip = new ZipStreamWriter($sink);
		$taken = array();
		$entries = 0;
		if (intval($run->get('mxr_left_out')) > 0) {
			$taken[strtolower(self::NOTE_NAME)] = true;
		}
		foreach (self::pieces($run) as $f => $paths) {
			if (!isset($keys[$f])) {
				continue;
			}
			$size = 0;
			foreach ($paths as $path) {
				$size += substr($path, -5) === '.jsfc' ? SealedFileContainer::plainSize($path) : filesize($path);
			}
			$name = self::unique(ZipStreamWriter::cleanName(self::folderName($keys[$f], $alias_id)) . '.mbox', $taken);
			$zip->beginFile($name, $mtime, true, $size);
			foreach ($paths as $path) {
				if (substr($path, -5) === '.jsfc') {
					if ($fk === null) {
						throw new VaultLockedException();
					}
					SealedFileContainer::openRange($path, $fk, function ($bytes) use ($zip) { $zip->write($bytes); });
					continue;
				}
				$in = fopen($path, 'rb');
				try {
					while (!feof($in)) {
						$chunk = fread($in, 1048576);
						if ($chunk === false) {
							throw new RuntimeException('A piece of the export could not be read.');
						}
						if ($chunk !== '') {
							$zip->write($chunk);
						}
					}
				} finally {
					fclose($in);
				}
			}
			$zip->endFile();
			$entries++;
		}
		if (intval($run->get('mxr_left_out')) > 0) {
			$zip->addFile(self::NOTE_NAME, self::note($run), $mtime);
		} elseif ($entries === 0) {
			$zip->addFile('README.txt', "This export holds no messages: there were none to export.\n", $mtime);
		}
		return $zip->finish();
	}

	/** The note's text. */
	public static function note(MailExportRun $run): string {
		$n = intval($run->get('mxr_left_out'));
		$text = $n . ($n === 1 ? ' message was' : ' messages were') . " left out of this export:\n\n"
			. (string)$run->get('mxr_left_out_notes');
		if ($n > self::MAX_NOTE_LINES) {
			$text .= '… and ' . ($n - self::MAX_NOTE_LINES) . " more.\n";
		}
		return $text;
	}

	/** A name not yet used in the zip, compared without case: "Name (2).mbox" and on. */
	private static function unique(string $name, array &$taken): string {
		$dot = strrpos($name, '.');
		$stem = $dot === false ? $name : substr($name, 0, $dot);
		$ext = $dot === false ? '' : substr($name, $dot);
		$candidate = $name;
		for ($i = 2; isset($taken[strtolower($candidate)]); $i++) {
			$candidate = $stem . ' (' . $i . ')' . $ext;
		}
		$taken[strtolower($candidate)] = true;
		return $candidate;
	}

	// ── Fortress ────────────────────────────────────────────────────────────

	/**
	 * One page of a Fortress run's rows for the owner's browser: folder $f's
	 * messages after $after. A browser-sealed row comes as the reader's thread
	 * payload has it (ciphertext untouched); a row the server can read (mail
	 * stored before the mailbox moved to Fortress, not yet moved) comes as its
	 * bytes; anything else is a row left out, with its reason.
	 *
	 * @return array{folders:array, folder:int, messages:array, after:int, folder_done:bool, done:bool, bytes?:int}
	 */
	public static function fortressPage(MailExportRun $run, MailboxViewer $viewer, int $f, int $after): array {
		$alias_id = intval($run->get('mxr_iea_inbound_email_alias_id'));
		$keys = self::plan($run);
		$folders = array();
		foreach ($keys as $key) {
			$folders[] = array('key' => $key, 'name' => self::folderName($key, $alias_id));
		}
		$out = array('folders' => $folders, 'folder' => $f, 'messages' => array(), 'after' => $after,
			'folder_done' => true, 'done' => $f >= count($keys));
		if ($f === 0 && $after === 0) {
			// The first page says about how big the whole is, so the browser can
			// name its parts before it saves the first.
			$out['bytes'] = self::storedBytes($alias_id, $keys);
		}
		if ($out['done']) {
			return $out;
		}
		$key = $keys[$f];
		$ids = self::idsAfter($key, $alias_id, $after, self::FORTRESS_PAGE);
		if (!$ids) {
			return $out;
		}
		$out['folder_done'] = false;
		$out['after'] = end($ids);
		$labels = self::labelNamesFor($ids);
		$sealed = array();
		foreach ((new MailboxService($viewer))->fortressExportMessages($ids) as $m) {
			$sealed[intval($m['id'])] = $m;
		}
		$owner_id = intval($run->get('mxr_usr_user_id'));
		$none = array();
		foreach ($ids as $id) {
			$msg = new InboundEmailMessage($id, TRUE);
			if (!$msg->key) {
				continue;
			}
			$item = array(
				'id'     => $id,
				'labels' => self::gmailLabels($key, $labels[$id] ?? array(), self::truthy($msg->get('iem_is_read')),
					self::truthy($msg->get('iem_is_starred')), self::viewOf($msg)),
				'time'   => strtotime((string)$msg->get('iem_received_time') . ' UTC') ?: time(),
			);
			if (isset($sealed[$id])) {
				$item['message'] = $sealed[$id];
			} else {
				$got = self::entryFor($msg, $key, $labels[$id] ?? array(), $owner_id, $none);
				if ($got['ok']) {
					$item['entry'] = base64_encode($got['entry']);
				} else {
					$item['left_out'] = self::noteLine(self::folderName($key, $alias_id), $msg, (string)$got['reason']);
				}
			}
			$out['messages'][] = $item;
		}
		return $out;
	}

	private static function truthy($value): bool {
		return $value === true || $value === 't' || $value === 1 || $value === '1' || $value === 'true';
	}
}
?>
