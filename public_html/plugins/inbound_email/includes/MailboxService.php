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
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/MailboxViewer.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));

class MailboxService {

	/** Prefix marking a synthetic singleton thread key (no real thread header). */
	const SINGLETON_PREFIX = 'm:';

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
			$sql = "SELECT iem_iea_inbound_email_alias_id AS alias_id,
						COUNT(*) AS total,
						COUNT(*) FILTER (WHERE iem_is_read = false) AS unread,
						BOOL_OR(iem_is_starred) AS any_starred
					FROM iem_inbound_email_messages
					WHERE iem_delete_time IS NULL
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
			}
		}

		$result = array(
			'all_access' => $this->viewer->isAllAccess(),
			'mailboxes'  => $mailboxes,
		);

		if ($this->viewer->isAllAccess()) {
			// "All mail" — every non-deleted row, including NULL-alias.
			$row = $db->query("SELECT COUNT(*) AS total,
					COUNT(*) FILTER (WHERE iem_is_read = false) AS unread
				FROM iem_inbound_email_messages WHERE iem_delete_time IS NULL")
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

	// -------------------------------------------------------------- threads

	/**
	 * Conversation list within scope, grouped by thread, latest-first.
	 *
	 * @param int|null $aliasId  null = all accessible (unconstrained for superadmin)
	 * @param array    $filters  sender, subject, body, unread_only, starred_only
	 * @param int      $page     1-based
	 * @param int      $perpage
	 * @return array  ['threads'=>[...], 'has_more'=>bool, 'page'=>int]
	 */
	public function listThreads(?int $aliasId, array $filters = array(), int $page = 1, int $perpage = 50): array {
		$db = $this->db();
		$page = max(1, $page);
		$perpage = max(1, min(200, $perpage));
		$offset = ($page - 1) * $perpage;

		$where = array($this->readScopeSql($aliasId));
		$params = array();

		// Row-level text filters: a thread shows if any message matches.
		if (!empty($filters['sender'])) {
			$where[] = 'iem_sender ILIKE ?';
			$params[] = '%' . $this->likeEscape($filters['sender']) . '%';
		}
		if (!empty($filters['subject'])) {
			$where[] = 'iem_subject ILIKE ?';
			$params[] = '%' . $this->likeEscape($filters['subject']) . '%';
		}
		if (!empty($filters['body'])) {
			$where[] = '(iem_body_plain ILIKE ? OR iem_body_html ILIKE ?)';
			$term = '%' . $this->likeEscape($filters['body']) . '%';
			$params[] = $term;
			$params[] = $term;
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
					iem_size_bytes, iem_message_id_header, iem_body_plain, iem_body_html
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
		if ($read) {
			$sql = "UPDATE iem_inbound_email_messages
					SET iem_is_read = true, iem_read_time = COALESCE(iem_read_time, now())
					WHERE iem_inbound_email_message_id IN ($in) AND " . $this->mutationScopeSql();
		} else {
			$sql = "UPDATE iem_inbound_email_messages
					SET iem_is_read = false
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
				SET iem_is_starred = " . ($starred ? 'true' : 'false') . "
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
		return $stmt->rowCount();
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

	private function likeEscape(string $term): string {
		return str_replace(array('%', '_'), array('\\%', '\\_'), $term);
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
