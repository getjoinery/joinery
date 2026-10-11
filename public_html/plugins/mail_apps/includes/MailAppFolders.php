<?php
/**
 * MailAppFolders - the folders a mail program sees for one mailbox, and the
 * reconcile that numbers each folder's messages and finds what changed.
 *
 * FOLDERS. Joinery uses labels, not folders: a message can carry several. A
 * mail program sees them the way Gmail presents labels over IMAP — one folder
 * per view of the mailbox, and one per custom label:
 *
 *   INBOX     inbox      (no special use)
 *   Sent      sent       \Sent
 *   Drafts    drafts:{u} \Drafts   — the signed-in person's own drafts
 *   Archive   archive    \Archive
 *   Spam      spam       \Junk
 *   Trash     trash      \Trash
 *   All Mail  all        \All
 *   {label}   label:{id}            — "Labels/{name}" when a label's name is
 *                                     one of the names above
 *
 * What each holds is MailboxService::viewScopeSql() — mailbox defines what
 * "Inbox" means, once, for its reader and for this.
 *
 * RECONCILE (specs/mail_apps_plugin.md § Message numbers, § How Thunderbird
 * learns what changed). Under the folder's row lock:
 *   1. rows whose message left the folder's view are expunged (their numbers
 *      retired, never reused);
 *   2. messages now in the view with no live row are numbered in arrival
 *      order — a sealed one only when it can be served (the window is open),
 *      which is what makes "locked means nothing new appears" hold;
 *   3. every live row's flags as last told are compared with the truth now
 *      (read and starred from mailbox, answered and forwarded from this
 *      plugin's own row); rows that differ get the folder's next change number.
 *   4. Inbox only: while the mailbox's sealed mail is waiting on the lock, one
 *      generated lock notice is numbered; it is expunged when the lock lifts.
 * The comparison is against the current truth, not a log of events, so a
 * change made in the web reader while no mail program was connected is found
 * on the next reconcile, and mailbox needs to know none of this.
 *
 * @version 1.0.0
 */

class MailAppFolders {

	// The flags a mail program is told, as mau_flags_told bits.
	const FLAG_SEEN      = 1;
	const FLAG_FLAGGED   = 2;
	const FLAG_ANSWERED  = 4;
	const FLAG_FORWARDED = 8;
	const FLAG_DRAFT     = 16;
	/** \Deleted: set by a program before EXPUNGE. Ours alone, kept across reconciles. */
	const FLAG_DELETED   = 32;

	/** The IMAP flag names, by bit. $Forwarded is the keyword every client uses. */
	const FLAG_NAMES = array(
		self::FLAG_SEEN      => '\\Seen',
		self::FLAG_FLAGGED   => '\\Flagged',
		self::FLAG_ANSWERED  => '\\Answered',
		self::FLAG_FORWARDED => '$Forwarded',
		self::FLAG_DRAFT     => '\\Draft',
		self::FLAG_DELETED   => '\\Deleted',
	);

	/** A row of a message that is sealed (or not yet parsed) in its mailbox. */
	const SEALED_SQL = '(iem_content_sealed OR iem_pending_parse OR iem_raw_sealed)';

	/** The fixed folders: key prefix => [name, special use, mailbox view]. */
	const FIXED = array(
		'inbox'   => array('INBOX',    null,        'inbox'),
		'sent'    => array('Sent',     '\\Sent',    'sent'),
		'drafts'  => array('Drafts',   '\\Drafts',  'drafts'),
		'archive' => array('Archive',  '\\Archive', 'archive'),
		'spam'    => array('Spam',     '\\Junk',    'spam'),
		'trash'   => array('Trash',    '\\Trash',   'trash'),
		'all'     => array('All Mail', '\\All',     'all'),
	);

	/** Where a label whose name collides with a fixed folder is listed. */
	const LABEL_PARENT = 'Labels';

	/**
	 * Every folder of the mailbox, in listing order.
	 *
	 * @return array<int, array{key:string, name:string, special_use:?string, view:string, label_id:?int}>
	 */
	public static function listFor(MailAppContext $ctx): array {
		$out = array();
		$taken = array();
		foreach (self::FIXED as $prefix => $fixed) {
			$key = $prefix === 'drafts' ? 'drafts:' . $ctx->user_id : $prefix;
			$out[] = array('key' => $key, 'name' => $fixed[0], 'special_use' => $fixed[1],
				'view' => $fixed[2], 'label_id' => null);
			$taken[strtolower($fixed[0])] = true;
		}
		foreach (self::labelsFor($ctx) as $label) {
			$name = trim((string)$label['name']);
			if ($name === '') {
				continue;
			}
			// The same rule byKey() applies: only a fixed folder's own name moves.
			foreach (self::FIXED as $fixed) {
				if (strcasecmp($name, $fixed[0]) === 0) {
					$name = self::LABEL_PARENT . '/' . $name;
					break;
				}
			}
			if (isset($taken[strtolower($name)])) {
				continue; // two labels the same name apart from case: the first one shows
			}
			$taken[strtolower($name)] = true;
			$out[] = array('key' => 'label:' . intval($label['id']), 'name' => $name, 'special_use' => null,
				'view' => 'label', 'label_id' => intval($label['id']));
		}
		return $out;
	}

	/** The mailbox's custom labels, as the reader's rail lists them. */
	private static function labelsFor(MailAppContext $ctx): array {
		$listed = $ctx->service->listMailboxes();
		foreach ((array)($listed['mailboxes'] ?? array()) as $mailbox) {
			if (isset($mailbox['alias_id']) && intval($mailbox['alias_id']) === $ctx->alias_id) {
				return is_array($mailbox['folders'] ?? null) ? $mailbox['folders'] : array();
			}
		}
		return array();
	}

	/**
	 * The folder a mail program names, or null. INBOX is case-insensitive
	 * (RFC 3501); every other name matches as listed.
	 */
	public static function byName(MailAppContext $ctx, string $name): ?array {
		foreach (self::listFor($ctx) as $folder) {
			if ($folder['name'] === $name || ($folder['key'] === 'inbox' && strcasecmp($name, 'INBOX') === 0)) {
				return $folder;
			}
		}
		return null;
	}

	/**
	 * The folder with this key, or null. Cheap — no listing: the gateway names
	 * folders by the key the listing gave it, and a mail program polling every
	 * thirty seconds must not pay for the reader's whole mailbox rail each time.
	 */
	public static function byKey(MailAppContext $ctx, string $key): ?array {
		foreach (self::FIXED as $prefix => $fixed) {
			$own = $prefix === 'drafts' ? 'drafts:' . $ctx->user_id : $prefix;
			if ($key === $own) {
				return array('key' => $own, 'name' => $fixed[0], 'special_use' => $fixed[1],
					'view' => $fixed[2], 'label_id' => null);
			}
		}
		if (!preg_match('/^label:(\d+)$/', $key, $m)) {
			return null;
		}
		$stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT ilb_name FROM ilb_inbound_email_labels
			WHERE ilb_inbound_email_label_id = ? AND ilb_delete_time IS NULL');
		$stmt->execute(array(intval($m[1])));
		$name = $stmt->fetchColumn();
		if ($name === false) {
			return null;
		}
		$name = (string)$name;
		foreach (self::FIXED as $fixed) {
			if (strcasecmp($name, $fixed[0]) === 0) {
				$name = self::LABEL_PARENT . '/' . $name;
				break;
			}
		}
		return array('key' => $key, 'name' => $name, 'special_use' => null, 'view' => 'label',
			'label_id' => intval($m[1]));
	}

	/** One WHERE fragment over iem_ for what the folder holds. */
	public static function scopeSql(MailAppContext $ctx, array $folder): string {
		return $ctx->service->viewScopeSql($ctx->alias_id, $folder['view'], $folder['label_id']);
	}

	/** SQL for a message's flags as they are now (iem_ joined to mam_), as FLAG_* bits. */
	public static function flagsSql(array $folder): string {
		$draft = strpos($folder['key'], 'drafts:') === 0 ? self::FLAG_DRAFT : 0;
		return '((CASE WHEN iem_is_read THEN ' . self::FLAG_SEEN . ' ELSE 0 END)'
			. ' + (CASE WHEN iem_is_starred THEN ' . self::FLAG_FLAGGED . ' ELSE 0 END)'
			. ' + (CASE WHEN mam_is_answered THEN ' . self::FLAG_ANSWERED . ' ELSE 0 END)'
			. ' + (CASE WHEN mam_is_forwarded THEN ' . self::FLAG_FORWARDED . ' ELSE 0 END)'
			. ' + ' . $draft . ')';
	}

	/** Flag bits as IMAP flag names. @return string[] */
	public static function flagNames(int $bits): array {
		$out = array();
		foreach (self::FLAG_NAMES as $bit => $name) {
			if ($bits & $bit) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/** IMAP flag names as bits (unknown names are ignored — the program keeps them locally). */
	public static function flagBits(array $names): int {
		$bits = 0;
		foreach ($names as $name) {
			foreach (self::FLAG_NAMES as $bit => $known) {
				if (strcasecmp((string)$name, $known) === 0) {
					$bits |= $bit;
				}
			}
		}
		return $bits;
	}

	// ── reconcile ───────────────────────────────────────────────────────────

	/**
	 * Reconcile one folder (see the class comment). Returns the folder's state
	 * afterwards.
	 *
	 * @return array{uidvalidity:int, uidnext:int, highest_modseq:int, exists:int, unseen:int,
	 *               numbered:int, expunged:int[], locked:bool, waiting:bool}
	 */
	public static function reconcile(MailAppContext $ctx, array $folder): array {
		$db = DbConnector::get_instance()->get_db_link();
		$open = $ctx->windowOpen();

		// Sealed mail parsed in the window first, so it can be numbered now.
		if ($open && $ctx->seals) {
			self::parsePending($ctx, $folder);
		}

		$owns_tx = !$db->inTransaction();
		if ($owns_tx) {
			$db->beginTransaction();
		}
		try {
			$state = self::lockFolder($ctx, $folder['key']);
			$scope = self::scopeSql($ctx, $folder);
			$servable = self::servableSql($ctx, $open);
			$alias = $ctx->alias_id;
			$key = $folder['key'];
			$modseq = null; // the change number this reconcile hands out, minted on first need
			$next_modseq = function () use (&$modseq, $state) {
				if ($modseq === null) {
					$modseq = intval($state['maf_highest_modseq']) + 1;
				}
				return $modseq;
			};

			// 1. Expunge what left the view (and rows whose message is gone).
			$stmt = $db->prepare("SELECT mau_mail_app_uid_id, mau_uid FROM mau_mail_app_uids
				WHERE mau_iea_inbound_email_alias_id = ? AND mau_folder = ? AND mau_expunged_time IS NULL
				  AND NOT mau_is_notice
				  AND (mau_iem_inbound_email_message_id IS NULL OR mau_iem_inbound_email_message_id NOT IN
				       (SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages WHERE $scope))");
			$stmt->execute(array($alias, $key));
			$gone = $stmt->fetchAll(PDO::FETCH_ASSOC);
			$expunged = array();
			if ($gone) {
				$m = $next_modseq();
				$ids = implode(',', array_map('intval', array_column($gone, 'mau_mail_app_uid_id')));
				$db->exec("UPDATE mau_mail_app_uids SET mau_expunged_time = now(), mau_modseq = " . intval($m)
					. " WHERE mau_mail_app_uid_id IN ($ids)");
				$expunged = array_map('intval', array_column($gone, 'mau_uid'));
			}

			// 2. Number what arrived, in arrival order.
			$flags = self::flagsSql($folder);
			$uidnext = intval($state['maf_uidnext']);
			// An anti-join (NOT EXISTS), which Postgres can hash or spill at any
			// size. A NOT IN subquery is hashed only while it fits work_mem —
			// about 260,000 numbers — and above that is re-run per message. What
			// made the anti-join slow once was stale statistics after a bulk
			// numbering (a nested loop, 100,000 x 100,000); the ANALYZE after a
			// large reconcile, below, keeps the planner's picture current.
			$numbered_sql = "NOT EXISTS (SELECT 1 FROM mau_mail_app_uids
				WHERE mau_iea_inbound_email_alias_id = ? AND mau_folder = ? AND mau_expunged_time IS NULL
				  AND mau_iem_inbound_email_message_id = iem_inbound_email_message_id)";
			$stmt = $db->prepare("SELECT COUNT(*) FROM iem_inbound_email_messages
				WHERE $scope AND $servable AND $numbered_sql");
			$stmt->execute(array($alias, $key));
			$arriving = intval($stmt->fetchColumn());
			if ($arriving > 0) {
				$m = $next_modseq();
				$stmt = $db->prepare("INSERT INTO mau_mail_app_uids
					(mau_iea_inbound_email_alias_id, mau_folder, mau_uid, mau_iem_inbound_email_message_id,
					 mau_flags_told, mau_modseq)
					SELECT ?, ?, ? - 1 + row_number() OVER (ORDER BY iem_received_time ASC, iem_inbound_email_message_id ASC),
					       iem_inbound_email_message_id, $flags, ?
					FROM iem_inbound_email_messages
					LEFT JOIN mam_mail_app_messages ON mam_iem_inbound_email_message_id = iem_inbound_email_message_id
					WHERE $scope AND $servable AND $numbered_sql");
				$stmt->execute(array($alias, $key, $uidnext, $m, $alias, $key));
				$numbered = $stmt->rowCount();
				$uidnext += $numbered;
			} else {
				$numbered = 0;
			}

			// 3. Flags told vs. flags now. \Deleted is ours and survives.
			$stmt = $db->prepare("SELECT mau_mail_app_uid_id FROM mau_mail_app_uids
				JOIN iem_inbound_email_messages ON iem_inbound_email_message_id = mau_iem_inbound_email_message_id
				LEFT JOIN mam_mail_app_messages ON mam_iem_inbound_email_message_id = iem_inbound_email_message_id
				WHERE mau_iea_inbound_email_alias_id = ? AND mau_folder = ? AND mau_expunged_time IS NULL
				  AND (mau_flags_told & ~" . self::FLAG_DELETED . ") <> $flags");
			$stmt->execute(array($alias, $key));
			$changed = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
			if ($changed) {
				$m = $next_modseq();
				$ids = implode(',', $changed);
				$db->exec("UPDATE mau_mail_app_uids SET
						mau_flags_told = COALESCE((SELECT $flags FROM iem_inbound_email_messages
							LEFT JOIN mam_mail_app_messages ON mam_iem_inbound_email_message_id = iem_inbound_email_message_id
							WHERE iem_inbound_email_message_id = mau_iem_inbound_email_message_id), 0)
							| (mau_flags_told & " . self::FLAG_DELETED . "),
						mau_modseq = " . intval($m) . "
					WHERE mau_mail_app_uid_id IN ($ids)");
			}

			// 4. The lock notice (Inbox only, a mailbox that seals).
			$waiting = false;
			if ($folder['key'] === 'inbox' && $ctx->seals) {
				$waiting = !$open && self::mailWaiting($ctx, $scope);
				$stmt = $db->prepare("SELECT mau_mail_app_uid_id FROM mau_mail_app_uids
					WHERE mau_iea_inbound_email_alias_id = ? AND mau_folder = 'inbox' AND mau_is_notice
					  AND mau_expunged_time IS NULL");
				$stmt->execute(array($alias));
				$notice = $stmt->fetchColumn();
				// A notice the person deleted stays deleted until the lock lifts:
				// its row keeps \Deleted, and the lifting clears it.
				$stmt = $db->prepare("SELECT mau_mail_app_uid_id, mau_flags_told, mau_expunged_time FROM mau_mail_app_uids
					WHERE mau_iea_inbound_email_alias_id = ? AND mau_folder = 'inbox' AND mau_is_notice
					ORDER BY mau_uid DESC LIMIT 1");
				$stmt->execute(array($alias));
				$latest = $stmt->fetch(PDO::FETCH_ASSOC);
				$dismissed = $latest && $latest['mau_expunged_time'] !== null
					&& (intval($latest['mau_flags_told']) & self::FLAG_DELETED);
				if (!$waiting && $dismissed) {
					$db->prepare('UPDATE mau_mail_app_uids SET mau_flags_told = mau_flags_told & ~' . self::FLAG_DELETED
						. ' WHERE mau_mail_app_uid_id = ?')->execute(array(intval($latest['mau_mail_app_uid_id'])));
				}
				if ($waiting && !$notice && !$dismissed) {
					$m = $next_modseq();
					$db->prepare("INSERT INTO mau_mail_app_uids (mau_iea_inbound_email_alias_id, mau_folder, mau_uid,
						mau_is_notice, mau_flags_told, mau_modseq) VALUES (?, 'inbox', ?, true, 0, ?)")
						->execute(array($alias, $uidnext, $m));
					$uidnext++;
					$numbered++;
				} elseif (!$waiting && $notice) {
					$m = $next_modseq();
					$stmt = $db->prepare("UPDATE mau_mail_app_uids SET mau_expunged_time = now(), mau_modseq = ?
						WHERE mau_mail_app_uid_id = ? RETURNING mau_uid");
					$stmt->execute(array($m, intval($notice)));
					$expunged[] = intval($stmt->fetchColumn());
				}
			}

			if ($modseq !== null || $uidnext !== intval($state['maf_uidnext'])) {
				$db->prepare('UPDATE maf_mail_app_folders SET maf_uidnext = ?, maf_highest_modseq = ?
					WHERE maf_mail_app_folder_id = ?')
					->execute(array($uidnext, $modseq ?? intval($state['maf_highest_modseq']),
						intval($state['maf_mail_app_folder_id'])));
			}

			$stmt = $db->prepare("SELECT COUNT(*) AS n,
					COUNT(*) FILTER (WHERE (mau_flags_told & " . self::FLAG_SEEN . ") = 0) AS unseen
				FROM mau_mail_app_uids
				WHERE mau_iea_inbound_email_alias_id = ? AND mau_folder = ? AND mau_expunged_time IS NULL");
			$stmt->execute(array($alias, $key));
			$counts = $stmt->fetch(PDO::FETCH_ASSOC);

			if ($owns_tx) {
				$db->commit();
			}
		} catch (\Throwable $e) {
			if ($owns_tx && $db->inTransaction()) {
				$db->rollBack();
			}
			throw $e;
		}

		// After a large change (a first sync, a format reset) the planner's
		// picture of the numbers table is badly out of date until autovacuum
		// catches up; refresh it now so the next poll plans on the truth.
		if ($numbered + count($expunged) >= 1000) {
			try {
				$db->exec('ANALYZE mau_mail_app_uids');
			} catch (\Throwable $e) {
				error_log('MailAppFolders: ANALYZE failed: ' . $e->getMessage());
			}
		}

		sort($expunged);
		return array(
			'uidvalidity'    => intval($state['maf_uidvalidity']),
			'uidnext'        => $uidnext,
			'highest_modseq' => $modseq ?? intval($state['maf_highest_modseq']),
			'exists'         => intval($counts['n']),
			'unseen'         => intval($counts['unseen']),
			'numbered'       => $numbered,
			'expunged'       => $expunged,
			'locked'         => $ctx->seals && !$open,
			'waiting'        => $waiting,
		);
	}

	/**
	 * The folder's numbering row, locked for this transaction; created on first
	 * use, and started over (a new UIDVALIDITY, every number retired) when the
	 * message format it was numbered under is not the current one.
	 */
	private static function lockFolder(MailAppContext $ctx, string $key): array {
		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare('INSERT INTO maf_mail_app_folders (maf_iea_inbound_email_alias_id, maf_folder, maf_uidvalidity,
				maf_uidnext, maf_highest_modseq, maf_format)
			VALUES (?, ?, ?, 1, 1, ?) ON CONFLICT DO NOTHING')
			->execute(array($ctx->alias_id, $key, self::freshUidValidity(0), MailAppRebuild::FORMAT));
		$stmt = $db->prepare('SELECT * FROM maf_mail_app_folders
			WHERE maf_iea_inbound_email_alias_id = ? AND maf_folder = ? FOR UPDATE');
		$stmt->execute(array($ctx->alias_id, $key));
		$state = $stmt->fetch(PDO::FETCH_ASSOC);

		if (intval($state['maf_format']) !== MailAppRebuild::FORMAT) {
			// Every message served so far is pinned to the old format's bytes;
			// a mail program must be told to start this folder over.
			$validity = self::freshUidValidity(intval($state['maf_uidvalidity']));
			$db->prepare('UPDATE mau_mail_app_uids SET mau_expunged_time = now()
				WHERE mau_iea_inbound_email_alias_id = ? AND mau_folder = ? AND mau_expunged_time IS NULL')
				->execute(array($ctx->alias_id, $key));
			$db->prepare('UPDATE maf_mail_app_folders SET maf_uidvalidity = ?, maf_format = ?, maf_highest_modseq = maf_highest_modseq + 1
				WHERE maf_mail_app_folder_id = ?')
				->execute(array($validity, MailAppRebuild::FORMAT, intval($state['maf_mail_app_folder_id'])));
			$db->prepare('UPDATE mam_mail_app_messages SET mam_client_size = NULL, mam_client_format = NULL
				WHERE mam_client_format IS DISTINCT FROM ? AND mam_iem_inbound_email_message_id IN
				(SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = ?)')
				->execute(array(MailAppRebuild::FORMAT, $ctx->alias_id));
			$state['maf_uidvalidity'] = $validity;
			$state['maf_format'] = MailAppRebuild::FORMAT;
			$state['maf_highest_modseq'] = intval($state['maf_highest_modseq']) + 1;
		}
		return $state;
	}

	/** A UIDVALIDITY above $previous: a 32-bit non-zero value, rising with time. */
	private static function freshUidValidity(int $previous): int {
		return max(time() & 0x7fffffff, $previous + 1);
	}

	/**
	 * Which messages can be served now: everything when the window is open
	 * (but not a relay-sealed row still waiting to be parsed), only unsealed
	 * ones when it is shut — and a row sealed to someone else is never this
	 * person's to open.
	 */
	private static function servableSql(MailAppContext $ctx, bool $open): string {
		if (!$ctx->seals) {
			return 'iem_pending_parse IS NOT TRUE';
		}
		if ($open) {
			return '(iem_pending_parse IS NOT TRUE AND (NOT ' . self::SEALED_SQL
				. ' OR iem_sealed_owner_user_id = ' . intval($ctx->user_id) . '))';
		}
		return 'NOT ' . self::SEALED_SQL;
	}

	/** True when the folder holds sealed mail that is not numbered yet. */
	private static function mailWaiting(MailAppContext $ctx, string $scope): bool {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare("SELECT 1 FROM iem_inbound_email_messages
			WHERE $scope AND " . self::SEALED_SQL . " AND NOT EXISTS (SELECT 1 FROM mau_mail_app_uids
			  WHERE mau_iea_inbound_email_alias_id = ? AND mau_folder = 'inbox' AND mau_expunged_time IS NULL
			    AND mau_iem_inbound_email_message_id = iem_inbound_email_message_id) LIMIT 1");
		$stmt->execute(array($ctx->alias_id));
		return (bool)$stmt->fetchColumn();
	}

	/** Parse relay-sealed mail in this folder that the open window has not parsed yet (bounded). */
	private static function parsePending(MailAppContext $ctx, array $folder): void {
		try {
			if (!DeferredIngest::hasWork($ctx->user_id)) {
				return;
			}
			$key = VaultUnlock::secretKey($ctx->user_id);
			if ($key === null) {
				return;
			}
			$stmt = DbConnector::get_instance()->get_db_link()->query("SELECT iem_inbound_email_message_id
				FROM iem_inbound_email_messages WHERE " . self::scopeSql($ctx, $folder)
				. " AND iem_pending_parse LIMIT 50");
			$ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
			$pending = $ids ? DeferredIngest::pendingAmong($ctx->user_id, $ids) : array();
			if ($pending) {
				DeferredIngest::parseMessages($ctx->user_id, $key, $pending);
			}
		} catch (\Throwable $e) {
			error_log('MailAppFolders: parsing waiting mail failed for alias ' . $ctx->alias_id . ': ' . $e->getMessage());
		}
	}

	// ── reading numbered rows ───────────────────────────────────────────────

	/**
	 * The folder's live rows, or those changed since $since_modseq (with the
	 * numbers expunged since then), for a mail program's sync.
	 *
	 * @return array{messages: array, vanished: int[]}
	 */
	public static function rows(MailAppContext $ctx, array $folder, ?int $since_modseq = null): array {
		$db = DbConnector::get_instance()->get_db_link();
		$params = array($ctx->alias_id, $folder['key']);
		$since = '';
		if ($since_modseq !== null) {
			$since = ' AND mau_modseq > ?';
			$params[] = $since_modseq;
		}
		$stmt = $db->prepare("SELECT mau_uid, mau_flags_told, mau_modseq, mau_is_notice, mau_create_time,
				iem_received_time, mam_client_size, mam_client_format
			FROM mau_mail_app_uids
			LEFT JOIN iem_inbound_email_messages ON iem_inbound_email_message_id = mau_iem_inbound_email_message_id
			LEFT JOIN mam_mail_app_messages ON mam_iem_inbound_email_message_id = mau_iem_inbound_email_message_id
			WHERE mau_iea_inbound_email_alias_id = ? AND mau_folder = ? AND mau_expunged_time IS NULL $since
			ORDER BY mau_uid ASC");
		$stmt->execute($params);
		$messages = array();
		while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$size = ($r['mam_client_size'] !== null && intval($r['mam_client_format']) === MailAppRebuild::FORMAT)
				? intval($r['mam_client_size']) : null;
			if ($r['mau_is_notice']) {
				$size = strlen(MailAppRebuild::lockNotice($ctx, intval($r['mau_uid']), (string)$r['mau_create_time']));
			}
			$messages[] = array(
				'uid'    => intval($r['mau_uid']),
				'flags'  => self::flagNames(intval($r['mau_flags_told'])),
				'modseq' => intval($r['mau_modseq']),
				'date'   => self::imapTime($r['mau_is_notice'] ? $r['mau_create_time'] : $r['iem_received_time']),
				'size'   => $size,
			);
		}
		$vanished = array();
		if ($since_modseq !== null) {
			$stmt = $db->prepare('SELECT mau_uid FROM mau_mail_app_uids
				WHERE mau_iea_inbound_email_alias_id = ? AND mau_folder = ? AND mau_expunged_time IS NOT NULL
				  AND mau_modseq > ? ORDER BY mau_uid');
			$stmt->execute(array($ctx->alias_id, $folder['key'], $since_modseq));
			$vanished = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
		}
		return array('messages' => $messages, 'vanished' => $vanished);
	}

	/**
	 * Live rows for the given numbers: number => {iem id or null (a notice),
	 * row id, flags told, create time}. Numbers not live in the folder are
	 * absent.
	 *
	 * @param int[] $uids
	 * @return array<int, array{id:int, message_id:?int, notice:bool, flags:int, created:string}>
	 */
	public static function liveRows(MailAppContext $ctx, array $folder, array $uids): array {
		$uids = array_values(array_unique(array_filter(array_map('intval', $uids), function ($u) { return $u > 0; })));
		if (!$uids) {
			return array();
		}
		$stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT mau_mail_app_uid_id, mau_uid,
				mau_iem_inbound_email_message_id, mau_is_notice, mau_flags_told, mau_create_time
			FROM mau_mail_app_uids
			WHERE mau_iea_inbound_email_alias_id = ? AND mau_folder = ? AND mau_expunged_time IS NULL
			  AND mau_uid = ANY(?::bigint[])');
		$stmt->execute(array($ctx->alias_id, $folder['key'], '{' . implode(',', $uids) . '}'));
		$out = array();
		while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$out[intval($r['mau_uid'])] = array(
				'id'         => intval($r['mau_mail_app_uid_id']),
				'message_id' => $r['mau_iem_inbound_email_message_id'] !== null ? intval($r['mau_iem_inbound_email_message_id']) : null,
				'notice'     => (bool)$r['mau_is_notice'],
				'flags'      => intval($r['mau_flags_told']),
				'created'    => (string)$r['mau_create_time'],
			);
		}
		return $out;
	}

	/** The live number of a message in a folder, or null. */
	public static function uidOf(MailAppContext $ctx, string $folder_key, int $message_id): ?int {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT mau_uid FROM mau_mail_app_uids
			WHERE mau_iea_inbound_email_alias_id = ? AND mau_folder = ? AND mau_expunged_time IS NULL
			  AND mau_iem_inbound_email_message_id = ? ORDER BY mau_uid DESC LIMIT 1');
		$stmt->execute(array($ctx->alias_id, $folder_key, $message_id));
		$uid = $stmt->fetchColumn();
		return $uid !== false ? intval($uid) : null;
	}

	/** Set or clear the \Deleted mark on live rows. Returns the rows' numbers. */
	public static function markDeleted(MailAppContext $ctx, array $folder, array $row_ids, bool $deleted): void {
		if (!$row_ids) {
			return;
		}
		$ids = implode(',', array_map('intval', $row_ids));
		$db = DbConnector::get_instance()->get_db_link();
		$db->beginTransaction();
		try {
			$state = self::lockFolder($ctx, $folder['key']);
			$m = intval($state['maf_highest_modseq']) + 1;
			$expr = $deleted ? '(mau_flags_told | ' . self::FLAG_DELETED . ')' : '(mau_flags_told & ~' . self::FLAG_DELETED . ')';
			$db->exec("UPDATE mau_mail_app_uids SET mau_flags_told = $expr, mau_modseq = $m
				WHERE mau_mail_app_uid_id IN ($ids) AND mau_flags_told <> $expr");
			$db->prepare('UPDATE maf_mail_app_folders SET maf_highest_modseq = ? WHERE maf_mail_app_folder_id = ?')
				->execute(array($m, intval($state['maf_mail_app_folder_id'])));
			$db->commit();
		} catch (\Throwable $e) {
			$db->rollBack();
			throw $e;
		}
	}

	/** Retire the given live rows' numbers (an EXPUNGE this plugin carried out). */
	public static function expungeRows(MailAppContext $ctx, array $folder, array $row_ids): void {
		if (!$row_ids) {
			return;
		}
		$ids = implode(',', array_map('intval', $row_ids));
		$db = DbConnector::get_instance()->get_db_link();
		$db->beginTransaction();
		try {
			$state = self::lockFolder($ctx, $folder['key']);
			$m = intval($state['maf_highest_modseq']) + 1;
			$db->exec("UPDATE mau_mail_app_uids SET mau_expunged_time = now(), mau_modseq = $m
				WHERE mau_mail_app_uid_id IN ($ids) AND mau_expunged_time IS NULL");
			$db->prepare('UPDATE maf_mail_app_folders SET maf_highest_modseq = ? WHERE maf_mail_app_folder_id = ?')
				->execute(array($m, intval($state['maf_mail_app_folder_id'])));
			$db->commit();
		} catch (\Throwable $e) {
			$db->rollBack();
			throw $e;
		}
	}

	/** A stored UTC time as RFC 3339, the way the gateway hands it to IMAP's INTERNALDATE. */
	public static function imapTime($utc): string {
		$ts = strtotime((string)$utc . ' UTC');
		return gmdate('Y-m-d\TH:i:s\Z', $ts !== false ? $ts : time());
	}
}
?>
