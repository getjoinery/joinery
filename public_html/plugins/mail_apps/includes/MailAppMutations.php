<?php
/**
 * MailAppMutations - what a mail program's changes do in Joinery.
 *
 * Every change goes through the mailbox method the web reader uses, scoped by
 * the person's grants (specs/mail_apps_plugin.md § Folders):
 *
 *   mark read / unread, flag            markRead(), setStarred()
 *   \Answered, $Forwarded               this plugin's own mam_ row
 *   move Inbox -> Archive               setArchived(true)
 *   move anything -> Trash              softDelete()  (a draft: deleteDraft())
 *   move / copy into a label            setMembership(true); a move out of Inbox
 *                                       also archives, and out of a label also
 *                                       removes that label
 *   move -> Spam                        setSpamVerdict('spam')
 *   move out of Spam                    setSpamVerdict('ham')
 *   (STORE $Junk / $NotJunk is not offered: a program's automatic junk
 *   classifier sets them on mail the person never touched, and they would
 *   become server verdicts. The program keeps them locally. Owner decision J1.)
 *   move out of Trash                   restoreFromTrash()
 *   \Deleted + EXPUNGE in Trash         purgeFromTrash() — gone for good
 *   \Deleted + EXPUNGE in a label       the label comes off (the message stays)
 *   \Deleted + EXPUNGE in Drafts        deleteDraft()
 *   \Deleted + EXPUNGE elsewhere        softDelete()
 *   APPEND to Drafts                    saveDraft(), parsed from the MIME
 *   APPEND to Sent                      nothing when it is the copy of a message
 *                                       this mailbox sent; else storeRawOutbound()
 *   APPEND anywhere else                InboundEmailRouter::storeMessage(), then
 *                                       placed in that folder
 *
 * Nothing here reports the change: the next reconcile (MailAppFolders) finds
 * it by comparing the truth with what programs were told, the same as a
 * change made in the web reader.
 *
 * @version 1.0.1 - APPEND always answers its number (APPENDUID)
 */

class MailAppMutations {

	// ── flags ──────────────────────────────────────────────────────────────

	/**
	 * STORE: change flags on numbered messages. $op is add, remove or set.
	 *
	 * @param int[]    $uids
	 * @param string[] $flags IMAP flag names
	 */
	public static function store(MailAppContext $ctx, array $folder, array $uids, string $op, array $flags): void {
		$rows = MailAppFolders::liveRows($ctx, $folder, $uids);
		$message_ids = array();
		$row_ids = array();
		foreach ($rows as $row) {
			$row_ids[] = $row['id'];
			if ($row['message_id'] !== null) {
				$message_ids[] = $row['message_id'];
			}
		}
		if (!$row_ids) {
			return;
		}
		$bits = MailAppFolders::flagBits($flags);
		$want = function (int $bit) use ($op, $bits): ?bool {
			if ($op === 'set') {
				return (bool)($bits & $bit);
			}
			if (!($bits & $bit)) {
				return null;
			}
			return $op === 'add';
		};

		if ($message_ids) {
			if (($v = $want(MailAppFolders::FLAG_SEEN)) !== null) {
				$ctx->service->markRead($message_ids, $v);
			}
			if (($v = $want(MailAppFolders::FLAG_FLAGGED)) !== null) {
				$ctx->service->setStarred($message_ids, $v);
			}
			$answered = $want(MailAppFolders::FLAG_ANSWERED);
			$forwarded = $want(MailAppFolders::FLAG_FORWARDED);
			if ($answered !== null || $forwarded !== null) {
				self::setReplyMarks($message_ids, $answered, $forwarded);
			}
		}
		if (($v = $want(MailAppFolders::FLAG_DELETED)) !== null) {
			MailAppFolders::markDeleted($ctx, $folder, $row_ids, $v);
		}
	}

	/** Set or clear \Answered / $Forwarded on the plugin's own rows (null leaves one alone). */
	public static function setReplyMarks(array $message_ids, ?bool $answered, ?bool $forwarded): void {
		$db = DbConnector::get_instance()->get_db_link();
		foreach (array_unique(array_map('intval', $message_ids)) as $id) {
			$db->prepare('INSERT INTO mam_mail_app_messages (mam_iem_inbound_email_message_id) VALUES (?)
				ON CONFLICT (mam_iem_inbound_email_message_id) DO NOTHING')->execute(array($id));
			if ($answered !== null) {
				$db->prepare('UPDATE mam_mail_app_messages SET mam_is_answered = ? WHERE mam_iem_inbound_email_message_id = ?')
					->execute(array($answered ? 'true' : 'false', $id));
			}
			if ($forwarded !== null) {
				$db->prepare('UPDATE mam_mail_app_messages SET mam_is_forwarded = ? WHERE mam_iem_inbound_email_message_id = ?')
					->execute(array($forwarded ? 'true' : 'false', $id));
			}
		}
	}

	// ── moves and copies ──────────────────────────────────────────────────

	/**
	 * MOVE or COPY numbered messages to another folder. Answers the numbers
	 * the messages have there afterwards, source => destination (COPYUID).
	 *
	 * @return array{map: array<int,int>, uidvalidity: int}
	 */
	public static function place(MailAppContext $ctx, array $from, array $uids, array $to, bool $move): array {
		$rows = MailAppFolders::liveRows($ctx, $from, $uids);
		$by_uid = array();
		$notices = array();
		foreach ($rows as $uid => $row) {
			if ($row['message_id'] !== null) {
				$by_uid[$uid] = $row['message_id'];
			} elseif ($row['notice']) {
				$notices[] = $row['id'];
			}
		}
		// The lock notice is nobody's mail: moving it anywhere just dismisses it.
		if ($notices && $move) {
			MailAppFolders::markDeleted($ctx, $from, $notices, true);
			MailAppFolders::expungeRows($ctx, $from, $notices);
		}
		$ids = array_values(array_unique($by_uid));
		if ($ids) {
			self::applyPlacement($ctx, $from, $to, $ids, $move);
		}

		$from_state = MailAppFolders::reconcile($ctx, $from);
		unset($from_state);
		$to_state = MailAppFolders::reconcile($ctx, $to);
		$map = array();
		foreach ($by_uid as $src => $message_id) {
			$dst = MailAppFolders::uidOf($ctx, $to['key'], $message_id);
			if ($dst !== null) {
				$map[$src] = $dst;
			}
		}
		return array('map' => $map, 'uidvalidity' => $to_state['uidvalidity']);
	}

	/** What leaving $from for $to means for these messages (see the class comment). */
	private static function applyPlacement(MailAppContext $ctx, array $from, array $to, array $ids, bool $move): void {
		$svc = $ctx->service;
		$from_view = $from['view'];
		$to_view = $to['view'];
		if ($from['key'] === $to['key']) {
			return;
		}
		if ($to_view === 'sent') {
			throw new MailAppRefusal('Sent holds the mail this mailbox sent; nothing can be moved into it.', 'CANNOT');
		}
		if ($to_view === 'drafts') {
			throw new MailAppRefusal('Drafts holds drafts written here; nothing can be moved into it.', 'CANNOT');
		}
		if ($from_view === 'drafts') {
			if ($to_view !== 'trash') {
				throw new MailAppRefusal('A draft can only be deleted from here, not moved.', 'CANNOT');
			}
			if ($move) {
				self::deleteDrafts($ctx, $ids);
			}
			return;
		}

		// Leaving where it was. Trash and Spam are left on a copy too: a message
		// cannot be discarded (or judged spam) and in another folder at once.
		if ($from_view === 'trash') {
			if ($to_view === 'trash') {
				return;
			}
			$svc->restoreFromTrash($ids);
		}
		if ($from_view === 'spam' && $to_view !== 'trash' && $to_view !== 'spam') {
			$svc->setSpamVerdict($ids, 'ham');
		}
		if ($move && $from_view === 'label' && $to_view !== 'trash') {
			$svc->setMembership($ids, intval($from['label_id']), false);
		}
		if ($move && $from_view === 'inbox' && $to_view === 'label') {
			$svc->setArchived($ids, true);
		}

		// Arriving.
		switch ($to_view) {
			case 'trash':
				$svc->softDelete($ids);
				break;
			case 'spam':
				$svc->setSpamVerdict($ids, 'spam');
				break;
			case 'inbox':
				$svc->setArchived($ids, false);
				break;
			case 'archive':
				$svc->setArchived($ids, true);
				break;
			case 'label':
				$svc->setMembership($ids, intval($to['label_id']), true);
				break;
		}
	}

	// ── expunge ───────────────────────────────────────────────────────────

	/**
	 * EXPUNGE: carry out every \Deleted message in the folder (or only those
	 * in $uids, for UID EXPUNGE), and retire their numbers. Answers the
	 * numbers expunged.
	 *
	 * @return int[]
	 */
	public static function expunge(MailAppContext $ctx, array $folder, ?array $uids = null): array {
		$db = DbConnector::get_instance()->get_db_link();
		$params = array($ctx->alias_id, $folder['key']);
		$only = '';
		if ($uids !== null) {
			if (!$uids) {
				return array();
			}
			$only = ' AND mau_uid = ANY(?::bigint[])';
			$params[] = '{' . implode(',', array_map('intval', $uids)) . '}';
		}
		$stmt = $db->prepare('SELECT mau_mail_app_uid_id, mau_uid, mau_iem_inbound_email_message_id, mau_is_notice
			FROM mau_mail_app_uids
			WHERE mau_iea_inbound_email_alias_id = ? AND mau_folder = ? AND mau_expunged_time IS NULL
			  AND (mau_flags_told & ' . MailAppFolders::FLAG_DELETED . ') <> 0' . $only);
		$stmt->execute($params);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		if (!$rows) {
			return array();
		}
		$ids = array();
		foreach ($rows as $r) {
			if ($r['mau_iem_inbound_email_message_id'] !== null) {
				$ids[] = intval($r['mau_iem_inbound_email_message_id']);
			}
		}
		if ($ids) {
			$svc = $ctx->service;
			switch ($folder['view']) {
				case 'trash':
					$svc->purgeFromTrash($ids);
					break;
				case 'drafts':
					self::deleteDrafts($ctx, $ids);
					break;
				case 'label':
					$svc->setMembership($ids, intval($folder['label_id']), false);
					break;
				default:
					$svc->softDelete($ids);
			}
		}
		MailAppFolders::expungeRows($ctx, $folder, array_map('intval', array_column($rows, 'mau_mail_app_uid_id')));
		$expunged = array_map('intval', array_column($rows, 'mau_uid'));
		sort($expunged);
		return $expunged;
	}

	private static function deleteDrafts(MailAppContext $ctx, array $ids): void {
		$drafts = new MailboxDrafts($ctx->viewer);
		foreach ($ids as $id) {
			try {
				$drafts->deleteDraft(intval($id));
			} catch (\Throwable $e) {
				error_log('MailAppMutations: draft ' . $id . ' could not be deleted: ' . $e->getMessage());
			}
		}
	}

	// ── adding messages ───────────────────────────────────────────────────

	/**
	 * APPEND a message a mail program put in a folder. Answers its number
	 * there — null when it cannot be numbered yet (sealed, and the window is
	 * shut), which IMAP allows.
	 *
	 * @param string[] $flags
	 * @return array{uid:?int, uidvalidity:int}
	 */
	public static function append(MailAppContext $ctx, array $folder, string $raw, array $flags, ?string $date): array {
		if (strlen($raw) === 0) {
			throw new MailAppRefusal('That message is empty.', 'CANNOT');
		}
		if (strlen($raw) > MailboxSender::MAX_TOTAL_BYTES + 2097152) {
			throw new MailAppRefusal('That message is larger than this mailbox stores (25 MB).', 'TOOBIG');
		}
		$bits = MailAppFolders::flagBits($flags);
		$message_id_header = self::headerValue($raw, 'message-id');

		switch ($folder['view']) {
			case 'drafts':
				$id = self::appendDraft($ctx, $raw, $message_id_header);
				break;
			case 'sent':
				$id = self::sentCopy($ctx, $message_id_header);
				if ($id === null) {
					try {
						$id = (new MailboxSender($ctx->viewer))->storeRawOutbound($ctx->alias, $raw);
					} catch (MailboxSenderException $e) {
						throw new MailAppRefusal($e->getMessage(), 'CANNOT');
					}
				}
				break;
			default:
				// Into Archive or a label: out of the Inbox, the way a move there is.
				$id = self::storeInbound($ctx, $raw, $bits, $date,
					in_array($folder['view'], array('archive', 'label'), true));
				$placement = $folder['view'];
				if ($placement === 'trash') {
					$ctx->service->softDelete(array($id));
				} elseif ($placement === 'spam') {
					$ctx->service->setSpamVerdict(array($id), 'spam');
				} elseif ($placement === 'label') {
					$ctx->service->setMembership(array($id), intval($folder['label_id']), true);
				} elseif ($placement === 'inbox') {
					$ctx->service->setArchived(array($id), false);
				}
		}

		if ($bits & (MailAppFolders::FLAG_SEEN | MailAppFolders::FLAG_FLAGGED)) {
			if ($bits & MailAppFolders::FLAG_SEEN) {
				$ctx->service->markRead(array($id), true);
			}
			if ($bits & MailAppFolders::FLAG_FLAGGED) {
				$ctx->service->setStarred(array($id), true);
			}
		}
		if ($bits & (MailAppFolders::FLAG_ANSWERED | MailAppFolders::FLAG_FORWARDED)) {
			self::setReplyMarks(array($id),
				($bits & MailAppFolders::FLAG_ANSWERED) ? true : null,
				($bits & MailAppFolders::FLAG_FORWARDED) ? true : null);
		}

		$state = MailAppFolders::reconcile($ctx, $folder);
		$uid = MailAppFolders::uidOf($ctx, $folder['key'], $id);
		// The number is always answered (APPENDUID); only a message deleted in the
		// meantime has none. A server that offers UIDPLUS must, and Thunderbird reads a missing one as the UID of the last message
		// it fetched on that connection, then files its copy and runs its after-send
		// filters under that wrong number. The program keeps the bytes it appended
		// under this number; what the site serves for it may differ in encoding
		// (a copy to Sent matched to the site's own, a draft, a message stored as
		// headers and decoded parts), but it is the same message.
		return array('uid' => $uid, 'uidvalidity' => $state['uidvalidity']);
	}

	/**
	 * The Sent row a program's copy to Sent is the copy of: one this mailbox
	 * sent under that Message-ID, or under the one it was given in its place.
	 */
	private static function sentCopy(MailAppContext $ctx, string $message_id_header): ?int {
		if ($message_id_header === '') {
			return null;
		}
		$stmt = DbConnector::get_instance()->get_db_link()->prepare("SELECT iem_inbound_email_message_id
			FROM iem_inbound_email_messages
			LEFT JOIN mam_mail_app_messages ON mam_iem_inbound_email_message_id = iem_inbound_email_message_id
			WHERE iem_iea_inbound_email_alias_id = ? AND iem_direction = 'outbound' AND iem_delete_time IS NULL
			  AND (iem_message_id_header = ? OR mam_submitted_message_id = ?)
			ORDER BY iem_inbound_email_message_id DESC LIMIT 1");
		$stmt->execute(array($ctx->alias_id, substr($message_id_header, 0, 255), substr($message_id_header, 0, 255)));
		$id = $stmt->fetchColumn();
		return $id !== false ? intval($id) : null;
	}

	/** Store a program's message as an inbound row of the mailbox. Answers its id. */
	private static function storeInbound(MailAppContext $ctx, string $raw, int $bits, ?string $date, bool $archived): int {
		$router = new InboundEmailRouter();
		$parsed = $router->parseEmail($raw);
		$domain = new InboundEmailDomain(intval($ctx->alias->get('iea_ied_inbound_email_domain_id')), TRUE);
		$received = null;
		foreach (array((string)$date, self::headerValue($raw, 'date')) as $candidate) {
			$ts = $candidate !== '' ? strtotime($candidate) : false;
			if ($ts !== false && $ts > 0 && $ts <= time() + 86400) {
				$received = gmdate('Y-m-d H:i:s', $ts);
				break;
			}
		}
		try {
			$stored = $router->storeMessage($raw, $parsed, $ctx->alias, $domain, $ctx->address,
				array('spf' => 'unverified', 'dkim' => 'unverified', 'dmarc' => 'unverified', 'source' => 'none'),
				array('score' => null, 'decision' => array('verdict' => null, 'reason' => null, 'meta' => null, 'fingerprint' => null)),
				array(
					'direction'     => 'inbound',
					'run_filters'   => false,
					'is_read'       => (bool)($bits & MailAppFolders::FLAG_SEEN),
					'is_starred'    => (bool)($bits & MailAppFolders::FLAG_FLAGGED),
					'is_archived'   => $archived,
					'received_time' => $received ?? gmdate('Y-m-d H:i:s'),
				));
		} catch (\Throwable $e) {
			error_log('MailAppMutations: APPEND to alias ' . $ctx->alias_id . ' failed: ' . $e->getMessage());
			throw new MailAppRefusal('That message could not be stored: ' . $e->getMessage(), 'CANNOT');
		}
		if (!empty($stored['message'])) {
			return intval($stored['message']->key);
		}
		// Already here (the same Message-ID to this mailbox): place that one.
		$message_id_header = self::headerValue($raw, 'message-id');
		$existing = $message_id_header !== ''
			? $router->duplicateMessageId(substr($message_id_header, 0, 255), $ctx->address, 'inbound') : null;
		if ($existing === null) {
			throw new MailAppRefusal('That message could not be stored.', 'CANNOT');
		}
		return intval($existing);
	}

	/**
	 * Save a program's draft through mailbox's own drafts, parsed from its MIME:
	 * From must be this mailbox, the recipients and subject as written, the
	 * HTML body (or the plain one), and every other part — inline parts keep
	 * their Content-ID. The program's Message-ID is remembered, so a program
	 * that looks for its draft by Message-ID finds it.
	 */
	private static function appendDraft(MailAppContext $ctx, string $raw, string $message_id_header): int {
		require_once(PathHelper::getIncludePath('includes/MimeParse.php'));
		$decode = function (string $v): string {
			$d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
			return $d === false ? $v : $d;
		};
		try {
			$mime = MimeParse::parseMessage($raw);
		} catch (\Throwable $e) {
			throw new MailAppRefusal('That draft could not be read.', 'CANNOT');
		}
		$plain = null;
		$html = null;
		$files = array();
		$inside = null; // an attached message's MIME id: its parts stay inside it
		foreach ($mime->partIterator() as $part) {
			$mime_id = (string)$part->getMimeId();
			if ($inside !== null && strpos($mime_id, $inside . '.') === 0) {
				continue;
			}
			if ($part->getPrimaryType() === 'multipart') {
				continue;
			}
			if ($part->getPrimaryType() === 'message') {
				$inside = $mime_id;
			}
			$type = strtolower((string)$part->getType());
			$name = (string)($part->getName() ?? '');
			$disposition = strtolower((string)$part->getDisposition());
			$cid = trim((string)$part->getContentId(), '<> ');
			if (($type === 'text/plain' || $type === 'text/html') && $disposition !== 'attachment' && $name === '' && $cid === '') {
				$text = (string)$part->getContents();
				$charset = strtolower((string)$part->getCharset());
				if ($charset !== '' && $charset !== 'utf-8' && $charset !== 'us-ascii') {
					$converted = @mb_convert_encoding($text, 'UTF-8', $charset);
					$text = $converted === false ? $text : $converted;
				}
				if ($type === 'text/plain' && $plain === null) { $plain = $text; continue; }
				if ($type === 'text/html' && $html === null) { $html = $text; continue; }
			}
			$files[] = array(
				'name'       => $name !== '' ? $name : 'attachment',
				'type'       => $type,
				'bytes'      => (string)$part->getContents(),
				'content_id' => ($cid !== '' && $disposition !== 'attachment') ? $cid : '',
			);
		}

		$source_id = 0;
		$parent = self::headerValue($raw, 'in-reply-to');
		if (preg_match('/<[^<>]+>/', $parent, $m)) {
			$stmt = DbConnector::get_instance()->get_db_link()->prepare("SELECT iem_inbound_email_message_id
				FROM iem_inbound_email_messages WHERE iem_message_id_header = ? AND iem_iea_inbound_email_alias_id = ?
				  AND iem_delete_time IS NULL AND iem_direction IS DISTINCT FROM 'draft'
				ORDER BY iem_inbound_email_message_id DESC LIMIT 1");
			$stmt->execute(array(substr($m[0], 0, 255), $ctx->alias_id));
			$source_id = intval($stmt->fetchColumn());
		}

		$params = array(
			'alias_id'  => $ctx->alias_id,
			'mode'      => $source_id > 0 ? 'reply' : 'new',
			'source_id' => $source_id,
			'to'        => $decode(self::headerValue($raw, 'to')),
			'cc'        => $decode(self::headerValue($raw, 'cc')),
			'bcc'       => $decode(self::headerValue($raw, 'bcc')),
			'subject'   => $decode(self::headerValue($raw, 'subject')),
		);
		if ($html !== null && trim($html) !== '') {
			$params['body_html'] = $html;
		} else {
			$params['body'] = (string)$plain;
		}
		try {
			$saved = (new MailboxDrafts($ctx->viewer))->saveDraft($params, $files);
		} catch (MailboxDraftsException $e) {
			throw new MailAppRefusal($e->getMessage(), 'CANNOT');
		}
		if (!empty($saved['locked']) || empty($saved['draft_id'])) {
			throw new MailAppRefusal('Your mailbox is locked - unlock it at ' . $ctx->unlockUrl() . ' to save this draft.', 'UNAVAILABLE');
		}
		$id = intval($saved['draft_id']);
		if ($message_id_header !== '') {
			DbConnector::get_instance()->get_db_link()->prepare('INSERT INTO mam_mail_app_messages
					(mam_iem_inbound_email_message_id, mam_submitted_message_id) VALUES (?, ?)
				ON CONFLICT (mam_iem_inbound_email_message_id) DO UPDATE SET mam_submitted_message_id = EXCLUDED.mam_submitted_message_id')
				->execute(array($id, substr($message_id_header, 0, 255)));
		}
		return $id;
	}

	/** One header's value (unfolded, first occurrence), or ''. */
	public static function headerValue(string $raw, string $name): string {
		$pos = strpos($raw, "\r\n\r\n");
		if ($pos === false) {
			$pos = strpos($raw, "\n\n");
		}
		$block = $pos === false ? $raw : substr($raw, 0, $pos);
		$block = preg_replace('/\r?\n[ \t]+/', ' ', $block);
		foreach (preg_split('/\r?\n/', $block) as $line) {
			$colon = strpos($line, ':');
			if ($colon !== false && strcasecmp(trim(substr($line, 0, $colon)), $name) === 0) {
				return trim(substr($line, $colon + 1));
			}
		}
		return '';
	}
}
?>
