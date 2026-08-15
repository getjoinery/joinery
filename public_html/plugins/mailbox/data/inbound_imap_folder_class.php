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
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundImapFolderException extends SystemBaseException {}

class InboundImapFolder extends SystemBase {
	public static $prefix = 'iif';
	public static $tablename = 'iif_inbound_imap_folders';
	public static $pkey_column = 'iif_inbound_imap_folder_id';

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
		// (ilb_) to this one remote feed, shared across feeds by the label's global name.
		// NULL for special-use (Sent/Trash/Junk/…) and coverage (\All) folders — their
		// state is a column on iem_inbound_email_messages, never a label. See ensureLabel().
		'iif_ilb_inbound_email_label_id'  => array('type'=>'int8'),
		'iif_role'                        => array('type'=>'varchar(20)'),
		'iif_uidvalidity'                 => array('type'=>'int8'),
		'iif_last_seen_uid'               => array('type'=>'int8'),
		'iif_last_sync_modseq'            => array('type'=>'int8'),
		'iif_is_tracked'                  => array('type'=>'bool', 'default'=>true, 'is_nullable'=>false),
		// A folder created in Joinery that does not yet exist on the source. The sync
		// push step issues the IMAP CREATE and clears this flag (specs/two_way_imap_sync.md §14).
		'iif_pending_remote_create'       => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'iif_create_time'                 => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'iif_update_time'                 => array('type'=>'timestamp(6)'),
	);

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

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
	 * or coverage folder, which is a column, not a label. A label is keyed by the folder
	 * name in the global namespace, so the same label is shared across feeds and shown in
	 * the reader.
	 */
	function ensureLabel(): ?int {
		if (!$this->bindsLabel()) {
			return null; // special-use / coverage: a column, never a label
		}
		$existing = $this->labelId();
		if ($existing) {
			return $existing;
		}
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_labels_class.php'));
		$label = InboundEmailLabel::findOrCreate((string)$this->get('iif_name'));
		if ($label === null) {
			return null;
		}
		$this->set('iif_ilb_inbound_email_label_id', intval($label->key));
		$this->prepare();
		$this->save();
		return intval($label->key);
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
