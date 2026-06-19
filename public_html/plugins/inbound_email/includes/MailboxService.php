<?php
/**
 * MailboxService - scope-checked reads and state mutations for the Mailbox
 * Reader.
 *
 * Constructed with a MailboxViewer; EVERY method funnels its visibility through
 * the viewer's scope so a viewer can never read or mutate a message outside its
 * grants. List/thread reads are grouped aggregations that don't fit a model, so
 * they use DbConnector directly with safe (intval'd id lists, bound text
 * params) SQL. Mutations are a single scoped bulk UPDATE — out-of-scope ids are
 * filtered in SQL and silently affect nothing.
 *
 * Threading: rows group by iem_thread_key. A row with no thread key is a
 * singleton, keyed by 'm:<message_id>' so the client always has a stable thread
 * handle. getThread()/messageIdsInThread() understand both forms.
 *
 * Unmatched mail (NULL alias) belongs to no mailbox; it is invisible to
 * grant-scoped viewers and surfaces only in a superadmin's unconstrained
 * "All mail" view.
 *
 * getThread() also returns the per-message SPF/DKIM/DMARC verdicts and their
 * source (iem_auth_source) so the reader can show sourced verdicts or an honest
 * "unverified" — it never recomputes authentication.
 *
 * Spam (specs/inbound_email_spam_filtering.md): the default list/switcher hide
 * judged-spam rows (iem_spam_verdict='spam'); the Spam view shows only them.
 * setSpamVerdict() is the manual "Mark as spam"/"Not spam" correction.
 *
 * @version 1.2
 */

require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/MailboxViewer.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_imap_folder_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_message_folder_class.php'));

class MailboxService {

	/** Prefix marking a synthetic singleton thread key (no real thread header). */
	const SINGLETON_PREFIX = 'm:';

	/**
	 * Sentinel alias-scope meaning "unmatched (NULL-alias) mail only" — the
	 * superadmin-only pseudo-mailbox for mail that routed to no alias. A real
	 * alias id is always a positive serial, so -1 can never collide.
	 */
	const UNMATCHED = -1;

	/**
	 * Parse an alias_id request parameter into a scope value for the read/mutation
	 * methods: '' / absent → null (all accessible); 'unmatched' → UNMATCHED; else
	 * the integer alias id. One parser shared by every reader endpoint.
	 *
	 * @param mixed $raw
	 * @return int|null
	 */
	public static function parseAliasParam($raw): ?int {
		if ($raw === null || $raw === '') {
			return null;
		}
		if ($raw === 'unmatched') {
			return self::UNMATCHED;
		}
		return intval($raw);
	}

	/** SQL expression for a row's grouping key (real thread key, or m:<id>). */
	const GROUP_KEY_SQL = "COALESCE(NULLIF(iem_thread_key,''), 'm:' || iem_inbound_email_message_id)";

	/** @var MailboxViewer */
	private $viewer;

	public function __construct(MailboxViewer $viewer) {
		$this->viewer = $viewer;
	}

	// ---------------------------------------------------------------- scope

	private function db() {
		return DbConnector::get_instance()->get_db_link();
	}

	/**
	 * WHERE fragment scoping a READ to what $aliasId resolves to for this viewer.
	 * Always pins iem_delete_time IS NULL. The superadmin "All mail" (null +
	 * all-access) case is unconstrained so NULL-alias unmatched mail surfaces.
	 */
	private function readScopeSql(?int $aliasId): string {
		// "Unmatched" — NULL-alias mail that belongs to no mailbox. Superadmin-only;
		// everyone else gets a no-match scope even if they craft the parameter.
		if ($aliasId === self::UNMATCHED) {
			return $this->viewer->isAllAccess()
				? 'iem_delete_time IS NULL AND iem_iea_inbound_email_alias_id IS NULL'
				: '1=0';
		}
		if ($aliasId === null && $this->viewer->isAllAccess()) {
			return 'iem_delete_time IS NULL';
		}
		$ids = array_map('intval', $this->viewer->scopeAliasIds($aliasId));
		$in = count($ids) ? implode(',', $ids) : 'NULL';
		return 'iem_delete_time IS NULL AND iem_iea_inbound_email_alias_id IN (' . $in . ')';
	}

	/**
	 * WHERE fragment scoping a MUTATION to the rows this viewer may change.
	 * All-access may touch any non-deleted row (including NULL-alias); everyone
	 * else only rows in mailboxes they hold a grant for.
	 */
	private function mutationScopeSql(): string {
		$base = 'iem_delete_time IS NULL';
		if ($this->viewer->isAllAccess()) {
			return $base;
		}
		$ids = array_map('intval', $this->viewer->accessibleAliasIds());
		$in = count($ids) ? implode(',', $ids) : 'NULL';
		return $base . ' AND iem_iea_inbound_email_alias_id IN (' . $in . ')';
	}

	// ------------------------------------------------------------ switcher

	/**
	 * Switcher data: one entry per accessible mailbox (address, domain, unread,
	 * total, any-starred). For an all-access superadmin, prepends an "All mail"
	 * pseudo-mailbox and reports an "Unmatched" (NULL-alias) count.
	 *
	 * @return array
	 */
	public function listMailboxes(): array {
		$alias_ids = $this->viewer->accessibleAliasIds();
		$db = $this->db();

		// Per-alias aggregates for the accessible set.
		$agg = array();
		if (count($alias_ids)) {
			$in = implode(',', array_map('intval', $alias_ids));
			// Badges reflect the inbox view, so judged-spam rows are excluded (they
			// live in the Spam view) — specs/inbound_email_spam_filtering.md.
			$sql = "SELECT iem_iea_inbound_email_alias_id AS alias_id,
						COUNT(*) AS total,
						COUNT(*) FILTER (WHERE iem_is_read = false) AS unread,
						BOOL_OR(iem_is_starred) AS any_starred
					FROM iem_inbound_email_messages
					WHERE iem_delete_time IS NULL
					AND iem_spam_verdict IS DISTINCT FROM 'spam'
					AND iem_iea_inbound_email_alias_id IN ($in)
					GROUP BY iem_iea_inbound_email_alias_id";
			$stmt = $db->query($sql);
			foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
				$agg[intval($r['alias_id'])] = $r;
			}
		}

		// Resolve addresses (alias local-part + domain) for the accessible set.
		$mailboxes = array();
		if (count($alias_ids)) {
			$domains = new MultiInboundEmailDomain(array());
			$domains->load();
			$domain_map = array();
			foreach ($domains as $d) {
				$domain_map[intval($d->key)] = $d->get('ied_domain');
			}

			$aliases = new MultiInboundEmailAlias(array('deleted' => false),
				array('iea_alias' => 'ASC'));
			$aliases->load();
			foreach ($aliases as $a) {
				$aid = intval($a->key);
				if (!in_array($aid, array_map('intval', $alias_ids), true)) {
					continue;
				}
				$domain = $domain_map[intval($a->get('iea_ied_inbound_email_domain_id'))] ?? '?';
				$row = $agg[$aid] ?? null;
				$mailboxes[] = array(
					'alias_id'    => $aid,
					'address'     => $a->get('iea_alias') . '@' . $domain,
					'domain'      => $domain,
					'unread'      => $row ? intval($row['unread']) : 0,
					'total'       => $row ? intval($row['total']) : 0,
					'any_starred' => $row ? (bool)$this->pgBool($row['any_starred']) : false,
				);
				// Tracked membership folders for the reader's folder rail + the move/
				// labels control; the \All coverage view is excluded (the mailbox root
				// is the folder-unfiltered "All Mail" view). `folders_exclusive` drives
				// whether the reader offers single-folder "Move" or multi-label toggles.
				$info = $this->mailboxFolderInfo($aid);
				$mailboxes[count($mailboxes) - 1]['folders'] = $info['folders'];
				$mailboxes[count($mailboxes) - 1]['folders_exclusive'] = $info['exclusive'];
			}
		}

		$result = array(
			'all_access' => $this->viewer->isAllAccess(),
			'mailboxes'  => $mailboxes,
		);

		if ($this->viewer->isAllAccess()) {
			// "All mail" — every non-deleted, non-spam row, including NULL-alias.
			$row = $db->query("SELECT COUNT(*) AS total,
					COUNT(*) FILTER (WHERE iem_is_read = false) AS unread
				FROM iem_inbound_email_messages
				WHERE iem_delete_time IS NULL AND iem_spam_verdict IS DISTINCT FROM 'spam'")
				->fetch(PDO::FETCH_ASSOC);
			$result['all_mail'] = array(
				'unread' => intval($row['unread']),
				'total'  => intval($row['total']),
			);

			// "Unmatched" — rows that belong to no mailbox.
			$row = $db->query("SELECT COUNT(*) AS total,
					COUNT(*) FILTER (WHERE iem_is_read = false) AS unread
				FROM iem_inbound_email_messages
				WHERE iem_delete_time IS NULL AND iem_iea_inbound_email_alias_id IS NULL")
				->fetch(PDO::FETCH_ASSOC);
			$result['unmatched'] = array(
				'unread' => intval($row['unread']),
				'total'  => intval($row['total']),
			);
		}

		return $result;
	}

	/**
	 * The tracked membership folders of the alias's bound feed (for the folder rail
	 * + the move/labels control) and the feed's cardinality. Shown whenever the feed
	 * has discovered, tracked folders — independent of sync mode — so the structure
	 * is visible before sync is switched on (folders fill as sync populates
	 * membership). The \All coverage view is excluded.
	 *
	 * @return array{folders: array[], exclusive: bool}
	 */
	private function mailboxFolderInfo(int $aliasId): array {
		$empty = array('folders' => array(), 'exclusive' => true);
		$accounts = new MultiInboundImapAccount(array(
			'alias_id' => $aliasId, 'enabled' => true, 'deleted' => false,
		));
		$accounts->load();
		if (!count($accounts)) {
			return $empty;
		}
		$account = new InboundImapAccount($accounts->get(0)->key, TRUE);
		if (!$account->key) {
			return $empty;
		}
		$folders = new MultiInboundImapFolder(array(
			'account_id' => intval($account->key), 'tracked' => true,
		), array('iif_name' => 'ASC'));
		$folders->load();
		$out = array();
		foreach ($folders as $f) {
			if ($f->get('iif_role') === InboundImapFolder::ROLE_ALL) {
				continue; // coverage source, not navigable
			}
			$out[] = array(
				'id'   => intval($f->key),
				'name' => $f->get('iif_name'),
				'role' => $f->get('iif_role'),
			);
		}
		return array('folders' => $out, 'exclusive' => $account->foldersExclusive());
	}

	/**
	 * The (non-coverage) folder ids a thread currently belongs to — the union of
	 * its in-scope messages' present_local memberships. Pre-checks the reader's
	 * move/labels control.
	 *
	 * @return int[]
	 */
	public function threadFolderIds(?int $aliasId, string $thread_key): array {
		$ids = $this->messageIdsInThread($aliasId, $thread_key);
		if (!count($ids)) {
			return array();
		}
		$in = implode(',', array_map('intval', $ids));
		$rows = $this->db()->query(
			"SELECT DISTINCT imf.imf_iif_inbound_imap_folder_id AS fid
			 FROM imf_inbound_message_folders imf
			 JOIN iif_inbound_imap_folders f ON f.iif_inbound_imap_folder_id = imf.imf_iif_inbound_imap_folder_id
			 WHERE imf.imf_iem_inbound_email_message_id IN ($in)
			   AND imf.imf_present_local = true
			   AND f.iif_role IS DISTINCT FROM '" . InboundImapFolder::ROLE_ALL . "'")->fetchAll(PDO::FETCH_COLUMN);
		$out = array();
		foreach ($rows as $fid) {
			$out[] = intval($fid);
		}
		return $out;
	}

	/**
	 * Add or remove a folder membership for a set of messages (the reader's move /
	 * labels control). Sets `imf_present_local` (keeping the shadow base) so two-way
	 * push reconciles it to the source as a COPY (label add) / MOVE (exclusive) /
	 * EXPUNGE (label remove) — exactly the path proven for a programmatic change.
	 *
	 * Scoped three ways: the viewer must be able to mutate the message, the message
	 * must belong to the folder's own feed/alias, and it must be reference-backed.
	 * The \All coverage view cannot be a membership target. Returns rows affected.
	 */
	public function setMembership(array $message_ids, int $folderId, bool $present): int {
		$ids = $this->intList($message_ids);
		if (!count($ids) || $folderId <= 0) {
			return 0;
		}
		$folder = new InboundImapFolder($folderId, TRUE);
		if (!$folder->key || $folder->isCoverage()) {
			return 0;
		}
		$account = new InboundImapAccount(intval($folder->get('iif_iia_inbound_imap_account_id')), TRUE);
		if (!$account->key) {
			return 0;
		}
		$feedAliasId = intval($account->get('iia_iea_inbound_email_alias_id'));
		$accountId = intval($account->key);

		$in = implode(',', $ids);
		$sql = "SELECT iem_inbound_email_message_id AS id
				FROM iem_inbound_email_messages
				WHERE iem_inbound_email_message_id IN ($in)
				  AND iem_iea_inbound_email_alias_id = " . $feedAliasId . "
				  AND iem_iia_inbound_imap_account_id = " . $accountId . "
				  AND " . $this->mutationScopeSql();
		$rows = $this->db()->query($sql)->fetchAll(PDO::FETCH_COLUMN);

		$count = 0;
		foreach ($rows as $mid) {
			$mid = intval($mid);
			$existing = InboundMessageFolder::find($mid, $folderId);
			$base = $existing ? (bool)$existing->get('imf_present_base') : false;
			$uid = $existing ? ($existing->get('imf_imap_uid') !== null ? intval($existing->get('imf_imap_uid')) : null) : null;
			$uidv = $existing ? ($existing->get('imf_imap_uidvalidity') !== null ? intval($existing->get('imf_imap_uidvalidity')) : null) : null;
			// Set local to the requested presence, keep the shadow base — that makes
			// the element dirty (or a no-op if already at the target) for push.
			InboundMessageFolder::setPresence($mid, $folderId, $present, $base, $uid, $uidv);
			$count++;
		}
		return $count;
	}

	/**
	 * Create a label/folder for a mailbox from the reader. Makes a tracked, pending
	 * `iif_` row that does not yet exist on the source — the sync push step issues
	 * the IMAP CREATE and clears the pending flag (§14). Idempotent: a same-named
	 * folder is reused (and re-tracked). Returns {id, name, role} or null when the
	 * viewer can't mutate the mailbox, the mailbox has no IMAP feed, or the name is
	 * empty.
	 */
	public function createFolder(int $aliasId, string $name): ?array {
		$name = trim(str_replace(array("\r", "\n", '"'), '', $name));
		if ($name === '' || $aliasId <= 0) {
			return null;
		}
		$name = substr($name, 0, 255);
		if (!$this->viewer->isAllAccess() && !$this->viewer->canAccess($aliasId)) {
			return null;
		}
		$accounts = new MultiInboundImapAccount(array(
			'alias_id' => $aliasId, 'enabled' => true, 'deleted' => false,
		));
		$accounts->load();
		if (!count($accounts)) {
			return null; // no IMAP feed to create the folder on
		}
		$accountId = intval($accounts->get(0)->key);

		// Reuse a same-named folder (re-track it); otherwise create a pending one.
		$existing = new MultiInboundImapFolder(array('account_id' => $accountId, 'name' => $name));
		$existing->load();
		if (count($existing)) {
			$folder = new InboundImapFolder($existing->get(0)->key, TRUE);
			if (!$folder->get('iif_is_tracked')) {
				$folder->set('iif_is_tracked', true);
				$folder->prepare();
				$folder->save();
			}
		} else {
			$folder = new InboundImapFolder(NULL);
			$folder->set('iif_iia_inbound_imap_account_id', $accountId);
			$folder->set('iif_name', $name);
			$folder->set('iif_role', InboundImapFolder::ROLE_CUSTOM);
			$folder->set('iif_is_tracked', true);
			$folder->set('iif_pending_remote_create', true);
			$folder->prepare();
			$folder->save();
			$folder->load();
		}
		return array('id' => intval($folder->key), 'name' => $folder->get('iif_name'), 'role' => $folder->get('iif_role'));
	}

	// -------------------------------------------------------------- threads

	/**
	 * Conversation list within scope, grouped by thread, latest-first.
	 *
	 * @param int|null $aliasId  null = all accessible (unconstrained for superadmin)
	 * @param array    $filters  q, unread_only, starred_only, spam
	 * @param int      $page     1-based
	 * @param int      $perpage
	 * @return array  ['threads'=>[...], 'has_more'=>bool, 'page'=>int]
	 */
	public function listThreads(?int $aliasId, array $filters = array(), int $page = 1, int $perpage = 50, ?int $folderId = null): array {
		$db = $this->db();
		$page = max(1, $page);
		$perpage = max(1, min(200, $perpage));
		$offset = ($page - 1) * $perpage;

		$where = array($this->readScopeSql($aliasId));
		$params = array();

		// Spam disposition (specs/inbound_email_spam_filtering.md): the Spam view shows
		// only judged-spam rows; every other view hides them. A verdict is independent
		// of folder membership, so this works for local and IMAP mailboxes alike.
		if (!empty($filters['spam'])) {
			$where[] = "iem_spam_verdict = 'spam'";
		} else {
			$where[] = "iem_spam_verdict IS DISTINCT FROM 'spam'";
		}

		// Folder dimension (specs/two_way_imap_sync.md §8): restrict to messages
		// present in the chosen folder via the imf_ membership. Null = the
		// folder-unfiltered "All Mail" view, so coverage-only messages (zero imf_
		// rows) are reachable at the mailbox root. Each message row is unique, so
		// the thread aggregation is not double-counted.
		if ($folderId !== null && $folderId > 0) {
			$where[] = 'iem_inbound_email_message_id IN (SELECT imf_iem_inbound_email_message_id
						FROM imf_inbound_message_folders
						WHERE imf_iif_inbound_imap_folder_id = ? AND imf_present_local = true)';
			$params[] = $folderId;
		}

		// Row-level full-text filter: a thread shows if any message matches.
		// The expression MUST stay byte-identical to iem_007's GIN index
		// expression (plugins/inbound_email/migrations/migrations.php), or the
		// planner will not use the index. websearch_to_tsquery tolerates
		// arbitrary user input (stray quotes/operators won't raise).
		if (!empty($filters['q'])) {
			$where[] = "to_tsvector('english',
					coalesce(iem_sender, '')      || ' ' ||
					coalesce(iem_subject, '')     || ' ' ||
					coalesce(iem_body_plain, '')  || ' ' ||
					coalesce(iem_body_html, ''))
				@@ websearch_to_tsquery('english', ?)";
			$params[] = $filters['q'];
		}

		// Thread-level filters.
		$having = array();
		if (!empty($filters['unread_only'])) {
			$having[] = 'COUNT(*) FILTER (WHERE iem_is_read = false) > 0';
		}
		if (!empty($filters['starred_only'])) {
			$having[] = 'BOOL_OR(iem_is_starred)';
		}

		$gk = self::GROUP_KEY_SQL;
		$sql = "SELECT
					$gk AS thread_key,
					MAX(iem_received_time) AS latest_time,
					COUNT(*) AS msg_count,
					COUNT(*) FILTER (WHERE iem_is_read = false) AS unread_count,
					BOOL_OR(iem_is_starred) AS any_starred,
					STRING_AGG(DISTINCT iem_sender, ', ') AS senders,
					(ARRAY_AGG(iem_subject ORDER BY iem_received_time DESC, iem_inbound_email_message_id DESC))[1] AS latest_subject,
					(ARRAY_AGG(iem_inbound_email_message_id ORDER BY iem_received_time DESC, iem_inbound_email_message_id DESC))[1] AS latest_id
				FROM iem_inbound_email_messages
				WHERE " . implode(' AND ', $where) . "
				GROUP BY $gk";
		if (count($having)) {
			$sql .= ' HAVING ' . implode(' AND ', $having);
		}
		$sql .= " ORDER BY latest_time DESC
				LIMIT ? OFFSET ?";

		// Fetch one extra to detect a further page.
		$params[] = $perpage + 1;
		$params[] = $offset;

		$stmt = $db->prepare($sql);
		$stmt->execute($params);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$has_more = count($rows) > $perpage;
		if ($has_more) {
			$rows = array_slice($rows, 0, $perpage);
		}

		$threads = array();
		foreach ($rows as $r) {
			$threads[] = array(
				'thread_key'   => $r['thread_key'],
				'subject'      => $r['latest_subject'],
				'senders'      => $r['senders'],
				'msg_count'    => intval($r['msg_count']),
				'unread_count' => intval($r['unread_count']),
				'any_starred'  => (bool)$this->pgBool($r['any_starred']),
				'latest_time'  => $r['latest_time'],
				'latest_id'    => intval($r['latest_id']),
			);
		}

		return array(
			'threads'  => $threads,
			'has_more' => $has_more,
			'page'     => $page,
		);
	}

	/**
	 * All in-scope messages in a thread, chronological, each with read/star
	 * flags AND its plain/HTML body (rendered client-side in a sandboxed iframe).
	 * Empty if the thread is outside scope.
	 *
	 * @return array[]  message rows
	 */
	public function getThread(?int $aliasId, string $thread_key): array {
		$ids = $this->messageIdsInThread($aliasId, $thread_key);
		if (!count($ids)) {
			return array();
		}
		$in = implode(',', array_map('intval', $ids));
		$db = $this->db();
		$sql = "SELECT iem_inbound_email_message_id, iem_iea_inbound_email_alias_id,
					iem_sender, iem_recipient, iem_subject, iem_received_time,
					iem_is_read, iem_is_starred, iem_read_time, iem_dkim_result,
					iem_spf_result, iem_dmarc_result, iem_auth_source,
					iem_size_bytes, iem_message_id_header, iem_direction,
					iem_body_plain, iem_body_html
				FROM iem_inbound_email_messages
				WHERE iem_inbound_email_message_id IN ($in)
				ORDER BY iem_received_time ASC, iem_inbound_email_message_id ASC";
		$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

		$out = array();
		foreach ($rows as $r) {
			$out[] = array(
				'id'                => intval($r['iem_inbound_email_message_id']),
				'alias_id'          => $r['iem_iea_inbound_email_alias_id'] !== null
										? intval($r['iem_iea_inbound_email_alias_id']) : null,
				'sender'            => $r['iem_sender'],
				'recipient'         => $r['iem_recipient'],
				'subject'           => $r['iem_subject'],
				'received_time'     => $r['iem_received_time'],
				'is_read'           => (bool)$this->pgBool($r['iem_is_read']),
				'is_starred'        => (bool)$this->pgBool($r['iem_is_starred']),
				'read_time'         => $r['iem_read_time'],
				'dkim_result'       => $r['iem_dkim_result'],
				'spf_result'        => $r['iem_spf_result'],
				'dmarc_result'      => $r['iem_dmarc_result'],
				'auth_source'       => $r['iem_auth_source'],
				'size_bytes'        => intval($r['iem_size_bytes']),
				'message_id_header' => $r['iem_message_id_header'],
				'direction'         => $r['iem_direction'] ?: 'inbound',
				'body_plain'        => $r['iem_body_plain'],
				'body_html'         => $r['iem_body_html'],
			);
		}
		return $out;
	}

	/**
	 * Resolve a thread key to its in-scope message ids — the only
	 * thread-expansion logic, reused by every thread-level action.
	 *
	 * @return int[]
	 */
	public function messageIdsInThread(?int $aliasId, string $thread_key): array {
		$db = $this->db();
		$scope = $this->readScopeSql($aliasId);

		// Synthetic singleton key (m:<id>) → that one message, if in scope.
		if (strncmp($thread_key, self::SINGLETON_PREFIX, strlen(self::SINGLETON_PREFIX)) === 0) {
			$mid = intval(substr($thread_key, strlen(self::SINGLETON_PREFIX)));
			if ($mid <= 0) {
				return array();
			}
			$sql = "SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
					WHERE iem_inbound_email_message_id = ? AND $scope";
			$stmt = $db->prepare($sql);
			$stmt->execute(array($mid));
		} else {
			$sql = "SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
					WHERE iem_thread_key = ? AND $scope";
			$stmt = $db->prepare($sql);
			$stmt->execute(array($thread_key));
		}

		$ids = array();
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
			$ids[] = intval($id);
		}
		return $ids;
	}

	// ------------------------------------------------------------ mutations

	/**
	 * Mark in-scope rows read/unread. Sets iem_read_time on first read.
	 * @return int rows affected
	 */
	public function markRead(array $message_ids, bool $read): int {
		$ids = $this->intList($message_ids);
		if (!count($ids)) {
			return 0;
		}
		$in = implode(',', $ids);
		// Stamp the flag-dirty signal so two-way sync pushes the change (§7.1); inert
		// for non-IMAP rows, which are never synced.
		if ($read) {
			$sql = "UPDATE iem_inbound_email_messages
					SET iem_is_read = true, iem_read_time = COALESCE(iem_read_time, now()),
						iem_local_state_modified = now()
					WHERE iem_inbound_email_message_id IN ($in) AND " . $this->mutationScopeSql();
		} else {
			$sql = "UPDATE iem_inbound_email_messages
					SET iem_is_read = false, iem_local_state_modified = now()
					WHERE iem_inbound_email_message_id IN ($in) AND " . $this->mutationScopeSql();
		}
		$stmt = $this->db()->prepare($sql);
		$stmt->execute();
		return $stmt->rowCount();
	}

	/**
	 * Star/unstar in-scope rows.
	 * @return int rows affected
	 */
	public function setStarred(array $message_ids, bool $starred): int {
		$ids = $this->intList($message_ids);
		if (!count($ids)) {
			return 0;
		}
		$in = implode(',', $ids);
		$sql = "UPDATE iem_inbound_email_messages
				SET iem_is_starred = " . ($starred ? 'true' : 'false') . ",
					iem_local_state_modified = now()
				WHERE iem_inbound_email_message_id IN ($in) AND " . $this->mutationScopeSql();
		$stmt = $this->db()->prepare($sql);
		$stmt->execute();
		return $stmt->rowCount();
	}

	/**
	 * Manual spam correction (specs/inbound_email_spam_filtering.md): set the verdict
	 * on in-scope rows — 'spam' (Mark as spam) moves them to the Spam view, 'ham'
	 * (Not spam) returns them to the inbox. Rejects any other value.
	 * @return int rows affected
	 */
	public function setSpamVerdict(array $message_ids, string $verdict): int {
		$ids = $this->intList($message_ids);
		if (!count($ids)) {
			return 0;
		}
		if (!in_array($verdict, array(
				InboundEmailMessage::SPAM_VERDICT_SPAM,
				InboundEmailMessage::SPAM_VERDICT_HAM), true)) {
			return 0;
		}
		$in = implode(',', $ids);
		$sql = "UPDATE iem_inbound_email_messages
				SET iem_spam_verdict = " . $this->db()->quote($verdict) . "
				WHERE iem_inbound_email_message_id IN ($in) AND " . $this->mutationScopeSql();
		$stmt = $this->db()->prepare($sql);
		$stmt->execute();
		return $stmt->rowCount();
	}

	/**
	 * Soft-delete in-scope rows (hidden from the list; the retention purge task
	 * hard-deletes later).
	 * @return int rows affected
	 */
	public function softDelete(array $message_ids): int {
		$ids = $this->intList($message_ids);
		if (!count($ids)) {
			return 0;
		}
		$in = implode(',', $ids);
		$sql = "UPDATE iem_inbound_email_messages
				SET iem_delete_time = now()
				WHERE iem_inbound_email_message_id IN ($in) AND " . $this->mutationScopeSql();
		$stmt = $this->db()->prepare($sql);
		$stmt->execute();
		$affected = $stmt->rowCount();

		// Bridge the soft-delete into membership so the one push path moves the
		// source message to Trash (§7.5). Only for reference-backed rows on a
		// two-way, delete-syncing feed; otherwise the soft-delete stays local.
		$this->bridgeDeleteToMembership($ids);

		return $affected;
	}

	/**
	 * Translate a local soft-delete into membership dirtiness: clear every
	 * membership (present_local=false, keeping base so push EXPUNGEs/relocates) and
	 * add a Trash membership (present_local=true, base=false → a dirty add the push
	 * step MOVEs to Trash). Soft-delete and membership are two representations of
	 * one fact, bridged here (§5, §7.5). No-op unless the feed is two-way with
	 * delete sync on.
	 */
	private function bridgeDeleteToMembership(array $ids): void {
		if (!count($ids)) {
			return;
		}
		$in = implode(',', array_map('intval', $ids));
		$db = $this->db();
		$rows = $db->query(
			"SELECT iem_inbound_email_message_id AS id, iem_iia_inbound_imap_account_id AS account_id
			 FROM iem_inbound_email_messages
			 WHERE iem_inbound_email_message_id IN ($in)
			   AND iem_iia_inbound_imap_account_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
		if (!count($rows)) {
			return;
		}

		$accounts = array();   // id → InboundImapAccount (cached)
		$trash = array();      // account id → ?InboundImapFolder (cached)
		foreach ($rows as $r) {
			$accountId = intval($r['account_id']);
			if (!isset($accounts[$accountId])) {
				$accounts[$accountId] = new InboundImapAccount($accountId, TRUE);
			}
			$account = $accounts[$accountId];
			if (!$account->key || !$account->isTwoWay() || !$account->syncDeletes()) {
				continue;
			}
			$msgId = intval($r['id']);

			// Clear current local memberships (dirty removes; (0,0) elements drop).
			$members = new MultiInboundMessageFolder(array('message_id' => $msgId, 'present_local' => true));
			$members->load();
			foreach ($members as $m) {
				$base = (bool)$m->get('imf_present_base');
				InboundMessageFolder::setPresence($msgId, intval($m->get('imf_iif_inbound_imap_folder_id')), false, $base);
			}

			// Add a Trash membership (dirty add) so push MOVEs the source to Trash.
			if (!isset($trash[$accountId])) {
				$trashRows = new MultiInboundImapFolder(array(
					'account_id' => $accountId,
					'role'       => InboundImapFolder::ROLE_TRASH,
				));
				$trashRows->load();
				$trash[$accountId] = count($trashRows) ? new InboundImapFolder($trashRows->get(0)->key, TRUE) : null;
			}
			if ($trash[$accountId] !== null) {
				InboundMessageFolder::setPresence($msgId, intval($trash[$accountId]->key), true, false);
			}
		}
	}

	// --------------------------------------------------------------- helpers

	private function intList(array $ids): array {
		$out = array();
		foreach ($ids as $id) {
			$id = intval($id);
			if ($id > 0) {
				$out[] = $id;
			}
		}
		return array_values(array_unique($out));
	}

	/** Normalize a PDO-returned boolean (PostgreSQL yields 't'/'f' or bool). */
	private function pgBool($value): bool {
		if (is_bool($value)) {
			return $value;
		}
		return ($value === 't' || $value === 'true' || $value === '1' || $value === 1 || $value === true);
	}
}
?>
