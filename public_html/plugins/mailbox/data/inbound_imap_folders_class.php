<?php
/**
 * InboundImapFolder - one row per (IMAP feed, folder/mailbox).
 *
 * UIDVALIDITY and MODSEQ are per-folder in IMAP, so the ingestion + sync cursor
 * lives here rather than on the account. A feed with sync off has a single
 * INBOX row (its only ingestion cursor); a sync-enabled feed has one row per
 * folder the operator chose to track, plus the special-use folders discovered
 * from LIST (SPECIAL-USE).
 *
 * iif_role is the special-use role mapped from SPECIAL-USE attributes / provider
 * name maps. The behaviorally significant roles are 'sent' (APPEND target),
 * 'trash' (delete target), and 'all' — the coverage source (Gmail's All Mail):
 * a folder that contains every message but is never a membership element (an
 * all-mail presence bit would be true for everything and carry no information).
 * The rest are descriptive metadata for pre-selection and labeling.
 *
 * See specs/two_way_imap_sync.md (§5, §6) and ImapSyncer.
 *
 * @version 1.5 - access_owner_is(): a feed folder is its feed's mailbox's grant holders'
 * @version 1.4 - ensureLabel() binds the feed's mailbox's label and replaces a binding to a gone or
 *   foreign one; clearLabelBindingsForAccount() for an account pointed at another mailbox
 * @version 1.3
 * @changelog 1.3 - UIDVALIDITY churn counter and the folder pause it trips
 *   (specs/implemented/imap_client_hardening.md F9)
 * @version 1.2
 * @changelog 1.2 - iif_seed_high_uid: where the backfill ends and live ingest
 *   begins, recorded at seed time so the day-window scope guard knows which
 *   UIDs it governs (specs/imap_seed_scope_guard.md)
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundImapFolderException extends SystemBaseException {}

class InboundImapFolder extends SystemBase {
	public static $prefix = 'iif';
	public static $tablename = 'iif_inbound_imap_folders';
	public static $access_class = 'personal';
	public static $pkey_column = 'iif_inbound_imap_folder_id';

	/**
	 * The owner rule (spec declared_permissions 3.2): the holders of a grant on
	 * the mailbox the folder's feed delivers to (a member creating a label on a
	 * mailbox with a feed binds a folder for it).
	 */
	public function access_owner_is($user_id): bool {
		$account_id = (int)$this->access_stored('iif_iia_inbound_imap_account_id');
		if ((int)$user_id <= 0 || $account_id <= 0) {
			return false;
		}
		$q = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT iia_iea_inbound_email_alias_id FROM iia_inbound_imap_accounts WHERE iia_inbound_imap_account_id = ?');
		$q->execute(array($account_id));
		return MailboxViewer::holdsMailbox((int)$user_id, (int)$q->fetchColumn());
	}

	// Special-use roles (mapped from SPECIAL-USE attributes / provider name maps).
	const ROLE_INBOX   = 'inbox';
	const ROLE_SENT    = 'sent';
	const ROLE_TRASH   = 'trash';
	const ROLE_DRAFTS  = 'drafts';
	const ROLE_JUNK    = 'junk';
	const ROLE_ARCHIVE = 'archive';
	const ROLE_ALL     = 'all';      // coverage source, never a membership folder
	const ROLE_CUSTOM  = 'custom';

	protected static $foreign_key_actions = array(
		// permanent_delete, not cascade: a folder owns its label memberships.
		'iif_iia_inbound_imap_account_id' => array('action' => 'permanent_delete'),
		'iif_ilb_inbound_email_label_id'  => array('action' => 'null'),
	);

	public static $field_specifications = array(
		'iif_inbound_imap_folder_id'      => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		// Owning feed. iif_name is unique within the feed (one row per folder).
		'iif_iia_inbound_imap_account_id' => array('type'=>'int8', 'is_nullable'=>false, 'unique_with'=>array('iif_name')),
		'iif_name'                        => array('type'=>'varchar(255)', 'is_nullable'=>false),
		// The custom label this folder is a binding for: the folder mirrors the label
		// (ilb_) of the feed's mailbox to this one remote feed.
		// NULL for special-use (Sent/Trash/Junk/…) and coverage (\All) folders — their
		// state is a column on iem_inbound_email_messages, never a label. See ensureLabel().
		'iif_ilb_inbound_email_label_id'  => array('type'=>'int8'),
		'iif_role'                        => array('type'=>'varchar(20)'),
		'iif_uidvalidity'                 => array('type'=>'int8'),
		'iif_last_seen_uid'               => array('type'=>'int8'),
		// The folder's high UID at the moment its cursor was seeded. The day-window
		// scope guard applies only to UIDs at or below this — the backfill — so a
		// message the member later moves into the folder (a fresh, higher UID) is
		// ingested whatever its age (specs/imap_seed_scope_guard.md §3.2). NULL on
		// folders seeded before the column existed: no guard, the pre-guard behavior.
		'iif_seed_high_uid'               => array('type'=>'int8'),
		'iif_last_sync_modseq'            => array('type'=>'int8'),
		// UIDVALIDITY churn (specs/implemented/imap_client_hardening.md F9). A server that
		// resets a folder's UIDs over and over would otherwise reseed it every
		// poll — a future-only feed never ingests, a full-history feed re-walks
		// the whole folder. Three changes inside a day pause the folder
		// (iif_sync_paused_time) until the operator resumes it.
		'iif_uidvalidity_changes'         => array('type'=>'int4', 'default'=>'0', 'is_nullable'=>false),
		'iif_uidvalidity_changed_time'    => array('type'=>'timestamp(6)'),
		'iif_sync_paused_time'            => array('type'=>'timestamp(6)'),
		'iif_is_tracked'                  => array('type'=>'bool', 'default'=>true, 'is_nullable'=>false),
		// A folder created in Joinery that does not yet exist on the source. The sync
		// push step issues the IMAP CREATE and clears this flag (specs/two_way_imap_sync.md §14).
		'iif_pending_remote_create'       => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'iif_create_time'                 => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'iif_update_time'                 => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		$this->set('iif_update_time', gmdate('Y-m-d H:i:s'));
	}

	/** This folder is the coverage source (\All / [Gmail]/All Mail), not a membership folder. */
	function isCoverage(): bool {
		return $this->get('iif_role') === self::ROLE_ALL;
	}

	/**
	 * This folder mirrors a custom label: a tracked, non-special-use folder. Only these
	 * carry membership shadows (ilm_ rows). Special-use folders (Sent/Trash/Junk/…) and
	 * the \All coverage view are columns/coverage, never labels.
	 */
	function bindsLabel(): bool {
		$role = (string)$this->get('iif_role');
		return $role === '' || $role === self::ROLE_CUSTOM;
	}

	/** A membership folder is any tracked label-binding folder (carries ilm_ shadows). */
	function isMembership(): bool {
		return (bool)$this->get('iif_is_tracked') && $this->bindsLabel();
	}

	/** Created locally and not yet created on the source (sync push will CREATE it). */
	function isPendingRemoteCreate(): bool {
		return (bool)$this->get('iif_pending_remote_create');
	}

	/** The bound custom-label id, or null (special-use/coverage folders are never bound). */
	function labelId(): ?int {
		$l = $this->get('iif_ilb_inbound_email_label_id');
		return $l !== null ? intval($l) : null;
	}

	/**
	 * Find-or-create the custom label this folder binds to and persist
	 * iif_ilb_inbound_email_label_id, returning the label id — or null for a special-use
	 * or coverage folder, which is a column, not a label. The label is the feed's
	 * mailbox's label of the folder's name: two mailboxes' feeds with a folder of the
	 * same name get two labels. A binding to a label that is gone, or that is not the
	 * feed's mailbox's (the account was pointed at another mailbox), is replaced.
	 */
	function ensureLabel(): ?int {
		if (!$this->bindsLabel()) {
			return null; // special-use / coverage: a column, never a label
		}
		$alias_id = $this->accountAliasId();
		$existing = $this->labelId();
		if ($existing && $alias_id !== null && InboundEmailLabel::aliasOf($existing) === $alias_id) {
			return $existing;
		}
		$label = $alias_id !== null ? InboundEmailLabel::findOrCreate($alias_id, (string)$this->get('iif_name')) : null;
		$label_id = $label !== null ? intval($label->key) : null;
		if ($label_id !== $existing) {
			$this->set('iif_ilb_inbound_email_label_id', $label_id);
			$this->prepare();
			$this->save();
		}
		return $label_id;
	}

	/** The mailbox this folder's feed (account) delivers to, or null. */
	function accountAliasId(): ?int {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT iia_iea_inbound_email_alias_id FROM iia_inbound_imap_accounts WHERE iia_inbound_imap_account_id = ?');
		$stmt->execute(array(intval($this->get('iif_iia_inbound_imap_account_id'))));
		$alias = $stmt->fetchColumn();
		return ($alias !== false && $alias !== null) ? intval($alias) : null;
	}

	/**
	 * Clear every label binding of an account's folders: called when the account is
	 * pointed at another mailbox. ensureLabel() then mints the new mailbox's labels on
	 * the next sync; messages already stored keep their own mailbox's labels.
	 */
	static function clearLabelBindingsForAccount(int $account_id): void {
		if ($account_id <= 0) {
			return;
		}
		DbConnector::get_instance()->get_db_link()->prepare(
			'UPDATE iif_inbound_imap_folders SET iif_ilb_inbound_email_label_id = NULL
			 WHERE iif_iia_inbound_imap_account_id = ? AND iif_ilb_inbound_email_label_id IS NOT NULL')
			->execute(array($account_id));
	}

	/** The bound InboundEmailLabel, find-or-creating it if needed, or null for special-use/coverage. */
	function boundLabel(): ?InboundEmailLabel {
		$id = $this->ensureLabel();
		if ($id === null) {
			return null;
		}
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_labels_class.php'));
		return new InboundEmailLabel($id, TRUE);
	}

	/**
	 * Map a LIST SPECIAL-USE attribute set (e.g. ['\Sent']) and/or a folder name
	 * to one of the role constants. SPECIAL-USE wins; the name map is the fallback
	 * for servers (notably Gmail) that name folders distinctively without flags.
	 *
	 * @param string[] $attributes Lower/upper-case SPECIAL-USE attributes from LIST.
	 */
	static function roleFor(array $attributes, string $name): ?string {
		$attrs = array_map(function($a){ return strtolower(ltrim((string)$a, '\\')); }, $attributes);
		$bySpecialUse = array(
			'sent'    => self::ROLE_SENT,
			'trash'   => self::ROLE_TRASH,
			'drafts'  => self::ROLE_DRAFTS,
			'junk'    => self::ROLE_JUNK,
			'archive' => self::ROLE_ARCHIVE,
			'all'     => self::ROLE_ALL,
		);
		foreach ($bySpecialUse as $attr => $role) {
			if (in_array($attr, $attrs, true)) {
				return $role;
			}
		}

		$n = strtolower(trim($name));
		if ($n === 'inbox') {
			return self::ROLE_INBOX;
		}
		// Gmail's German/Austrian locale names its system parent "[Google Mail]".
		if (strpos($n, '[google mail]/') === 0) {
			$n = '[gmail]/' . substr($n, strlen('[google mail]/'));
		}
		// Servers that keep every folder under INBOX (Courier, older Dovecot and
		// many shared hosts) name them INBOX.Sent or INBOX/Trash. Only a direct
		// child of INBOX is read this way — a user's "Projects/Sent" is not Sent.
		if (preg_match('~^inbox[./]([^./]+)$~', $n, $m)) {
			$n = $m[1];
		}
		// Provider name maps (Gmail's [Gmail]/* names carry no SPECIAL-USE in some setups).
		$byName = array(
			'[gmail]/sent mail' => self::ROLE_SENT,
			'[gmail]/trash'     => self::ROLE_TRASH,
			'[gmail]/drafts'    => self::ROLE_DRAFTS,
			'[gmail]/spam'      => self::ROLE_JUNK,
			'[gmail]/all mail'  => self::ROLE_ALL,
			'sent'              => self::ROLE_SENT,
			'sent items'        => self::ROLE_SENT,
			'sent messages'     => self::ROLE_SENT,
			'trash'             => self::ROLE_TRASH,
			'deleted items'     => self::ROLE_TRASH,
			'deleted messages'  => self::ROLE_TRASH,
			'drafts'            => self::ROLE_DRAFTS,
			'junk'              => self::ROLE_JUNK,
			'junk email'        => self::ROLE_JUNK,
			'spam'              => self::ROLE_JUNK,
			'archive'           => self::ROLE_ARCHIVE,
			'all mail'          => self::ROLE_ALL,
		);
		return $byName[$n] ?? null;
	}

	// ── UIDVALIDITY churn (specs/implemented/imap_client_hardening.md F9) ──────────────

	/** Changes within this window count toward a pause. */
	const UIDVALIDITY_CHURN_WINDOW_SECONDS = 86400;
	/** The change that pauses the folder. */
	const UIDVALIDITY_CHURN_LIMIT = 3;

	function isPaused(): bool {
		return $this->get('iif_sync_paused_time') !== null;
	}

	/**
	 * Count one UIDVALIDITY change. Returns TRUE when this change pauses the folder:
	 * the third inside a day. A change after a quiet day starts the count over. The
	 * caller saves.
	 */
	function noteUidvalidityChange(): bool {
		$last = $this->get('iif_uidvalidity_changed_time');
		$recent = $last !== null
			&& (time() - strtotime($last . ' UTC')) < self::UIDVALIDITY_CHURN_WINDOW_SECONDS;
		$changes = $recent ? intval($this->get('iif_uidvalidity_changes')) + 1 : 1;
		$this->set('iif_uidvalidity_changes', $changes);
		$this->set('iif_uidvalidity_changed_time', gmdate('Y-m-d H:i:s'));
		if ($changes >= self::UIDVALIDITY_CHURN_LIMIT) {
			$this->set('iif_sync_paused_time', gmdate('Y-m-d H:i:s'));
			return true;
		}
		return false;
	}

	/** The operator's Resume: clear the pause and the count behind it. Saves. */
	function resume(): void {
		$this->set('iif_sync_paused_time', null);
		$this->set('iif_uidvalidity_changes', 0);
		$this->set('iif_uidvalidity_changed_time', null);
		$this->prepare();
		$this->save();
	}

	/**
	 * Upsert the (feed, folder) row, returning it. Sets the role when supplied and
	 * not already set. Does not change iif_is_tracked on an existing row (the
	 * operator's tracking choice is preserved across rediscovery).
	 */
	static function upsert(int $accountId, string $name, ?string $role = null, bool $trackedDefault = true): InboundImapFolder {
		$existing = new MultiInboundImapFolder(array('account_id' => $accountId, 'name' => $name));
		$existing->load();
		if (count($existing)) {
			$folder = new InboundImapFolder($existing->get(0)->key, TRUE);
			if ($role !== null && $folder->get('iif_role') !== $role) {
				$folder->set('iif_role', $role);
				$folder->prepare();
				$folder->save();
			}
			return $folder;
		}
		$folder = new InboundImapFolder(NULL);
		$folder->set('iif_iia_inbound_imap_account_id', $accountId);
		$folder->set('iif_name', substr($name, 0, 255));
		$folder->set('iif_role', $role);
		$folder->set('iif_is_tracked', $trackedDefault);
		// Discovered/seeded folders already exist on the source.
		$folder->set('iif_pending_remote_create', false);
		$folder->prepare();
		$folder->save();
		$folder->load();
		return $folder;
	}

	/**
	 * Rewind every folder cursor of one feed so the next poll re-seeds from scratch
	 * — what turning "Import full email history" on has to do to a feed that has
	 * already been polled. The ingester reads the iif_ cursor, not the legacy
	 * account-level one, so clearing only the latter leaves the folder positioned
	 * and the backfill never happens. Dedup keeps the re-walk from storing a second
	 * copy of mail already in the mailbox. Returns the number of folders rewound.
	 */
	static function rewindCursors(int $accountId): int {
		if ($accountId <= 0) { return 0; }
		$folders = new MultiInboundImapFolder(array('account_id' => $accountId));
		$folders->load();
		$rewound = 0;
		foreach ($folders as $row) {
			$folder = new InboundImapFolder($row->key, TRUE);
			$folder->set('iif_uidvalidity', null);
			$folder->set('iif_last_seen_uid', null);
			$folder->set('iif_last_sync_modseq', null);
			$folder->prepare();
			$folder->save();
			$rewound++;
		}
		return $rewound;
	}
}

class MultiInboundImapFolder extends SystemMultiBase {
	protected static $model_class = 'InboundImapFolder';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['account_id'])) {
			$filters['iif_iia_inbound_imap_account_id'] = array($this->options['account_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['name'])) {
			$filters['iif_name'] = array($this->options['name'], PDO::PARAM_STR);
		}

		if (isset($this->options['role'])) {
			$filters['iif_role'] = array($this->options['role'], PDO::PARAM_STR);
		}

		if (isset($this->options['label_id'])) {
			$filters['iif_ilb_inbound_email_label_id'] = array($this->options['label_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['paused'])) {
			$filters['iif_sync_paused_time'] = $this->options['paused'] ? 'IS NOT NULL' : 'IS NULL';
		}

		if (isset($this->options['tracked'])) {
			$filters['iif_is_tracked'] = $this->options['tracked'] ? '= true' : '= false';
		}

		if (isset($this->options['pending_create'])) {
			$filters['iif_pending_remote_create'] = $this->options['pending_create'] ? '= true' : '= false';
		}

		return $this->_get_resultsv2('iif_inbound_imap_folders', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
