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
 * setSpamVerdict() is the manual "Mark as spam"/"Not spam" correction — which, with
 * content filtering on, also drives the LearnSpamFeedback reconcile
 * (specs/inbound_email_content_spam_filtering.md). getThread() returns the recorded
 * content-spam score (iem_spam_score) for display only.
 *
 * Archive (specs/implemented/inbound_email_filters.md): the default Inbox view hides
 * archived rows (iem_is_archived); All Mail shows them. setArchived() is the manual
 * Archive / Move-to-Inbox action, symmetric with a filter's archive action.
 *
 * @version 1.5
 */

require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/MailboxViewer.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_imap_folder_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_labels_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_label_members_class.php'));

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
	 * The label rail for a mailbox (folder rail + the move/labels control), keyed by the
	 * custom-label id (ilb_) — a label is global, so the same id space spans local and
	 * IMAP mail and the reader never sees IMAP folder ids.
	 *
	 * A mailbox with a bound IMAP feed lists that feed's tracked custom-label folders
	 * (each carries its bound label id, displayed by its remote folder name), and the
	 * feed's cardinality drives Move-vs-Labels. A mailbox with no feed lists the global
	 * custom labels — applying one is pure (clean) membership with no remote to sync.
	 * Special-use folders (Sent/Trash/Junk/…) and the \All coverage view are excluded:
	 * their state is a column on iem_inbound_email_messages, not a label.
	 *
	 * @return array{folders: array[], exclusive: bool}
	 */
	private function mailboxFolderInfo(int $aliasId): array {
		$accounts = new MultiInboundImapAccount(array(
			'alias_id' => $aliasId, 'enabled' => true, 'deleted' => false,
		));
		$accounts->load();
		$account = count($accounts) ? new InboundImapAccount($accounts->get(0)->key, TRUE) : null;

		if ($account && $account->key) {
			$folders = new MultiInboundImapFolder(array(
				'account_id' => intval($account->key), 'tracked' => true,
			), array('iif_name' => 'ASC'));
			$folders->load();
			$out = array();
			foreach ($folders as $row) {
				$f = new InboundImapFolder($row->key, TRUE);
				$labelId = $f->ensureLabel();
				if ($labelId === null) {
					continue; // special-use / coverage: a column, not a label
				}
				$out[] = array(
					'id'   => $labelId,
					'name' => $f->get('iif_name'),
					'role' => $f->get('iif_role'),
				);
			}
			return array('folders' => $out, 'exclusive' => $account->foldersExclusive());
		}

		// No feed: the global custom-label set, applied as pure membership (never synced).
		$labels = new MultiInboundEmailLabel(array('deleted' => false), array('ilb_name' => 'ASC'));
		$labels->load();
		$out = array();
		foreach ($labels as $l) {
			$out[] = array(
				'id'   => intval($l->key),
				'name' => $l->get('ilb_name'),
				'role' => InboundImapFolder::ROLE_CUSTOM,
			);
		}
		return array('folders' => $out, 'exclusive' => false);
	}

	/**
	 * The custom-label ids a thread currently carries — the union of its in-scope
	 * messages' present_local memberships. Pre-checks the reader's move/labels control.
	 * The reader matches these against the active mailbox's rail and ignores any not shown.
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
			"SELECT DISTINCT ilm_ilb_inbound_email_label_id AS lid
			 FROM ilm_inbound_label_members
			 WHERE ilm_iem_inbound_email_message_id IN ($in) AND ilm_present_local = true")
			->fetchAll(PDO::FETCH_COLUMN);
		$out = array();
		foreach ($rows as $lid) {
			$out[] = intval($lid);
		}
		return $out;
	}

	/**
	 * Apply or remove a custom label for a set of messages — the reader's move / labels
	 * control. InboundLabelMember::apply/remove is the truth write; it resolves whether
	 * the label is bound to the message's feed and records the dirtiness accordingly, so
	 * two-way push later reconciles a bound change to the source as a COPY (label add) /
	 * MOVE (exclusive) / EXPUNGE (label remove), while an unbound (local) label never
	 * touches a remote. Scoped to messages the viewer may mutate. Returns the count changed.
	 */
	public function setMembership(array $message_ids, int $labelId, bool $present): int {
		$ids = $this->intList($message_ids);
		if (!count($ids) || $labelId <= 0) {
			return 0;
		}
		// The label must still exist (a stale rail entry no-ops rather than mis-applies).
		$lk = $this->db()->prepare(
			'SELECT 1 FROM ilb_inbound_email_labels WHERE ilb_inbound_email_label_id = ? AND ilb_delete_time IS NULL LIMIT 1');
		$lk->execute(array($labelId));
		if (!$lk->fetchColumn()) {
			return 0;
		}

		$in = implode(',', $ids);
		$rows = $this->db()->query(
			"SELECT iem_inbound_email_message_id AS id FROM iem_inbound_email_messages
			 WHERE iem_inbound_email_message_id IN ($in) AND " . $this->mutationScopeSql())
			->fetchAll(PDO::FETCH_COLUMN);

		$count = 0;
		foreach ($rows as $mid) {
			$mid = intval($mid);
			if ($present) {
				InboundLabelMember::apply($mid, $labelId);
			} else {
				InboundLabelMember::remove($mid, $labelId);
			}
			$count++;
		}
		return $count;
	}

	/**
	 * Create a label for a mailbox from the reader, returning {id (the label id), name,
	 * role} or null when the viewer can't mutate the mailbox or the name is empty.
	 *
	 * A label is an ilb_ row in the global namespace. When the mailbox has an IMAP feed,
	 * the label is also bound to a tracked, pending `iif_` folder that does not yet exist
	 * on the source — the sync push issues the IMAP CREATE and clears the pending flag
	 * (§14) so the label materializes as a remote folder. With no feed the label is
	 * membership-only. Idempotent: a same-named label/folder is reused (and re-tracked).
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

		// The label (global namespace) — created regardless of feed.
		$label = InboundEmailLabel::findOrCreate($name);
		if ($label === null) {
			return null;
		}
		$labelId = intval($label->key);

		// With a feed, also bind a tracked, pending folder so the sync push CREATEs it on
		// the source and files membership into it. Reuse a same-named folder (re-track it).
		$accounts = new MultiInboundImapAccount(array(
			'alias_id' => $aliasId, 'enabled' => true, 'deleted' => false,
		));
		$accounts->load();
		if (count($accounts)) {
			$accountId = intval($accounts->get(0)->key);
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
			$folder->ensureLabel(); // bind the folder to the (same-named) label
		}
		return array('id' => $labelId, 'name' => $label->get('ilb_name'),
			'role' => InboundImapFolder::ROLE_CUSTOM);
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

		// Inbox view (specs/implemented/inbound_email_filters.md): the default list
		// is the Inbox — archived mail ("Skip the Inbox") is hidden. The "All Mail"
		// view passes no inbox flag, so archived conversations remain reachable.
		// IS NOT TRUE (not "= false") so a NULL iem_is_archived counts as not-archived
		// — messages stored before the column existed are never-archived, not hidden.
		if (!empty($filters['inbox'])) {
			$where[] = "iem_is_archived IS NOT TRUE";
		}

		// Label dimension: restrict to messages carrying the chosen custom label.
		// $folderId is a label id (ilb_). Null = the label-unfiltered "All Mail" view, so
		// unlabeled messages are reachable at the mailbox root. Each message row is
		// unique, so the thread aggregation is not double-counted.
		if ($folderId !== null && $folderId > 0) {
			$where[] = 'iem_inbound_email_message_id IN (SELECT ilm_iem_inbound_email_message_id
						FROM ilm_inbound_label_members
						WHERE ilm_ilb_inbound_email_label_id = ? AND ilm_present_local = true)';
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
		// section_rank buckets each thread for the Gmail-style sectioned list:
		// 0 = has unread, 1 = starred (all read), 2 = everything else. Ordering by
		// it first keeps the buckets contiguous across pages, so the client renders
		// one header per section even with pagination.
		$sql = "SELECT
					$gk AS thread_key,
					MAX(iem_received_time) AS latest_time,
					COUNT(*) AS msg_count,
					COUNT(*) FILTER (WHERE iem_is_read = false) AS unread_count,
					BOOL_OR(iem_is_starred) AS any_starred,
					BOOL_OR(iem_is_archived) AS any_archived,
					CASE
						WHEN COUNT(*) FILTER (WHERE iem_is_read = false) > 0 THEN 0
						WHEN BOOL_OR(iem_is_starred) THEN 1
						ELSE 2
					END AS section_rank,
					STRING_AGG(DISTINCT iem_sender, ', ') AS senders,
					(ARRAY_AGG(iem_sender ORDER BY iem_received_time DESC, iem_inbound_email_message_id DESC))[1] AS latest_sender,
					(ARRAY_AGG(iem_subject ORDER BY iem_received_time DESC, iem_inbound_email_message_id DESC))[1] AS latest_subject,
					(ARRAY_AGG(left(coalesce(iem_body_plain, ''), 400) ORDER BY iem_received_time DESC, iem_inbound_email_message_id DESC))[1] AS preview_plain,
					(ARRAY_AGG(left(coalesce(iem_body_html, ''), 2000) ORDER BY iem_received_time DESC, iem_inbound_email_message_id DESC))[1] AS preview_html,
					(ARRAY_AGG(iem_inbound_email_message_id ORDER BY iem_received_time DESC, iem_inbound_email_message_id DESC))[1] AS latest_id
				FROM iem_inbound_email_messages
				WHERE " . implode(' AND ', $where) . "
				GROUP BY $gk";
		if (count($having)) {
			$sql .= ' HAVING ' . implode(' AND ', $having);
		}
		$sql .= " ORDER BY section_rank ASC, latest_time DESC
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

		$section_for = array(0 => 'unread', 1 => 'starred', 2 => 'other');
		$threads = array();
		foreach ($rows as $r) {
			$rank = intval($r['section_rank']);
			$threads[] = array(
				'thread_key'   => $r['thread_key'],
				'subject'      => $r['latest_subject'],
				'senders'      => $r['senders'],
				'sender'       => $r['latest_sender'],
				'snippet'      => $this->buildSnippet($r['preview_plain'], $r['preview_html']),
				'section'      => $section_for[$rank] ?? 'other',
				'msg_count'    => intval($r['msg_count']),
				'unread_count' => intval($r['unread_count']),
				'any_starred'  => (bool)$this->pgBool($r['any_starred']),
				'any_archived' => (bool)$this->pgBool($r['any_archived']),
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
	 * One-line preview of the latest message for the list row. Prefers the plain
	 * body; falls back to tag-stripped HTML. Whitespace is collapsed and the
	 * result trimmed to a short, single-line snippet (Gmail-style).
	 */
	private function buildSnippet(?string $plain, ?string $html): string {
		$text = trim((string)$plain);
		if ($text === '' && (string)$html !== '') {
			// The HTML preview is capped upstream; a tag may be truncated mid-way,
			// so drop any trailing partial tag before stripping.
			$text = preg_replace('/<[^>]*$/', '', (string)$html);
			$text = strip_tags((string)$text);
			$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}
		$text = trim(preg_replace('/\s+/u', ' ', $text));
		if (function_exists('mb_strimwidth')) {
			return mb_strimwidth($text, 0, 160, '…', 'UTF-8');
		}
		return strlen($text) > 160 ? substr($text, 0, 159) . '…' : $text;
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
					iem_spf_result, iem_dmarc_result, iem_auth_source, iem_spam_score,
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
				// Content-spam score (specs/inbound_email_content_spam_filtering.md):
				// display only, NULL when none reported. Never drives disposition.
				'spam_score'        => ($r['iem_spam_score'] !== null) ? (float)$r['iem_spam_score'] : null,
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
	 * Archive ("Skip the Inbox") or restore in-scope rows
	 * (specs/implemented/inbound_email_filters.md). Archived mail is hidden from the
	 * default Inbox view but stays in All Mail; orthogonal to read/star/spam.
	 * @return int rows affected
	 */
	public function setArchived(array $message_ids, bool $archived): int {
		$ids = $this->intList($message_ids);
		if (!count($ids)) {
			return 0;
		}
		$in = implode(',', $ids);
		$sql = "UPDATE iem_inbound_email_messages
				SET iem_is_archived = " . ($archived ? 'true' : 'false') . ",
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

		// The soft-delete column is the truth; two-way sync's pushTrash relocates the
		// source message to its Trash folder (§7.5). No membership bridge is needed —
		// trashing is column-driven, not a label.

		return $affected;
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
