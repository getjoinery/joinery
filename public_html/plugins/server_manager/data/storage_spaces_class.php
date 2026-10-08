<?php
/**
 * StorageSpace — one owner's folder on one backup target
 * (specs/storage_targets.md §3, §5).
 *
 * "Node acme's folder on the B2 target", "customer t12's folder on the Linode
 * target". A space is what the broker signs inside, what the ledger counts,
 * what retention prunes and what a move replaces. Every reader of a stored
 * backup on this management node reaches its bytes through the space that
 * holds them, never through "the target in use now".
 *
 *   active    takes the owner's new backups. An owner has at most one.
 *   draining  the owner moved elsewhere; read, restored and pruned only, kept
 *             whole until the owner's new space holds a complete chain.
 *   retired   a draining space with nothing left in it.
 *
 * The base key is {target folder}/{owner folder}/, composed once, here, from
 * the target's normalised folder. On one target no base key equals another
 * live one or sits inside it, so two owners can never list, prune or adopt
 * each other's objects (customer t5 and a node slugged t5).
 *
 * Exactly one owner column is set: a Managed node, or a customer of backup
 * storage (a service tenant).
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class StorageSpaceException extends SystemBaseException {}

class StorageSpace extends SystemBase {
	public static $prefix = 'sps';
	public static $tablename = 'sps_storage_spaces';
	public static $pkey_column = 'sps_storage_space_id';

	const STATE_ACTIVE   = 'active';
	const STATE_DRAINING = 'draining';
	const STATE_RETIRED  = 'retired';

	const OWNER_NODE   = 'node';
	const OWNER_TENANT = 'tenant';

	protected static $foreign_key_actions = array(
		// Soft-deleting a target is refused while it holds a live space; a
		// target removed for good takes its space records with it.
		'sps_bkt_backup_target_id'  => array('action' => 'cascade'),
		// An owner removed for good leaves its folders unclaimed on the target,
		// where they can be adopted by another owner or deleted by hand.
		'sps_mgn_managed_node_id'   => array('action' => 'cascade'),
		'sps_svt_service_tenant_id' => array('action' => 'cascade'),
	);

	public static $test_fixture = array(
		// Exactly one owner, pinned: the generator cannot infer an either/or.
		'values'       => array('sps_base_key' => 'harness/space/', 'sps_state' => 'retired',
			'sps_mgn_managed_node_id' => 1, 'sps_svt_service_tenant_id' => null),
		'update_field' => 'sps_state',
	);

	public static $field_specifications = array(
		'sps_storage_space_id'      => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'sps_bkt_backup_target_id'  => array('type'=>'int8', 'is_nullable'=>false),
		// {target folder}/{owner folder}/ — every key the space holds starts with it.
		'sps_base_key'              => array('type'=>'varchar(512)', 'is_nullable'=>false),
		'sps_mgn_managed_node_id'   => array('type'=>'int8'),
		'sps_svt_service_tenant_id' => array('type'=>'int8'),
		'sps_state'                 => array('type'=>'varchar(16)', 'is_nullable'=>false, 'default'=>'active',
			'allowed_values'=>array('active', 'draining', 'retired')),
		'sps_opened_time'           => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'sps_draining_time'         => array('type'=>'timestamp(6)'),
		'sps_retired_time'          => array('type'=>'timestamp(6)'),
		'sps_update_time'           => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		$this->set('sps_update_time', gmdate('Y-m-d H:i:s'));
	}

	function save($debug = false) {
		if (!(int)$this->get('sps_bkt_backup_target_id')) {
			throw new StorageSpaceException('A storage space is on a backup target.');
		}
		$owners = (int)(bool)$this->get('sps_mgn_managed_node_id') + (int)(bool)$this->get('sps_svt_service_tenant_id');
		if ($owners !== 1) {
			throw new StorageSpaceException('A storage space belongs to exactly one node or one customer.');
		}
		if (!preg_match('#^[^/].*/$#', (string)$this->get('sps_base_key'))) {
			throw new StorageSpaceException('A storage space has a base key ending in a slash.');
		}
		$this->set('sps_update_time', gmdate('Y-m-d H:i:s'));
		return parent::save($debug);
	}

	// ── Where it is ───────────────────────────────────────────────────────────

	public function base(): string {
		return (string)$this->get('sps_base_key');
	}

	/** The owner's folder: the last segment of the base key. */
	public function folder(): string {
		$parts = explode('/', rtrim($this->base(), '/'));
		return (string)end($parts);
	}

	/** The target's folder the space sits in: the base key less the owner's folder. */
	public function prefix_part(): string {
		$parts = explode('/', rtrim($this->base(), '/'));
		array_pop($parts);
		return implode('/', $parts);
	}

	/** The target, switched on or off; null once it is deleted. */
	public function target(): ?BackupTarget {
		try {
			$target = new BackupTarget((int)$this->get('sps_bkt_backup_target_id'), TRUE);
		} catch (\Throwable $e) {
			return null;
		}
		return ($target->key && !$target->get('bkt_delete_time')) ? $target : null;
	}

	/**
	 * [target, credential, bucket] to read, prune or sign in this space, or a
	 * StorageSpaceException with the sentence saying why not. The one check
	 * of a usable target: every caller asks here.
	 */
	public function reach(): array {
		$target = $this->target();
		if ($target === null) {
			throw new StorageSpaceException('The backup target holding ' . $this->describe() . ' has been deleted.');
		}
		try {
			$creds = (array)$target->get_credentials();
		} catch (\Throwable $e) {
			throw new StorageSpaceException('The credential of the backup target "' . $target->get('bkt_name') . '" cannot be read.');
		}
		$bucket = trim((string)$target->get('bkt_bucket'));
		if ($bucket === '' || empty($creds['access_key']) || empty($creds['secret_key'])) {
			throw new StorageSpaceException('The backup target "' . $target->get('bkt_name') . '" has no bucket or no key.');
		}
		return array($target, $creds, $bucket);
	}

	/** Does a key sit inside this space? */
	public function holds_key(string $key): bool {
		return $key !== '' && strpos(ltrim($key, '/'), $this->base()) === 0;
	}

	// ── Whose it is ───────────────────────────────────────────────────────────

	public function owner_kind(): string {
		return $this->get('sps_mgn_managed_node_id') ? self::OWNER_NODE : self::OWNER_TENANT;
	}

	public function owner_id(): int {
		return (int)($this->get('sps_mgn_managed_node_id') ?: $this->get('sps_svt_service_tenant_id'));
	}

	/** The owner as a person reads it: the node's name, or the customer's slug and host. */
	public function owner_name(): string {
		return self::name_of($this->owner_kind(), $this->owner_id());
	}

	/** "acme's folder on B2 main". */
	public function describe(): string {
		$target = null;
		try {
			$target = new BackupTarget((int)$this->get('sps_bkt_backup_target_id'), TRUE);
		} catch (\Throwable $e) {
		}
		return $this->owner_name() . '\'s folder on ' . ($target && $target->key ? '"' . $target->get('bkt_name') . '"' : 'a deleted target');
	}

	public static function name_of(string $kind, int $owner_id): string {
		if ($kind === self::OWNER_NODE) {
			$node = new ManagedNode($owner_id, TRUE);
			return $node->key ? (string)$node->get('mgn_name') : 'node #' . $owner_id;
		}
		$row = new ServiceTenant($owner_id, TRUE);
		if (!$row->key) {
			return 'customer #' . $owner_id;
		}
		$host = trim((string)$row->get('svt_host'));
		return 'customer ' . (string)$row->get('svt_slug') . ($host !== '' ? ' (' . $host . ')' : '');
	}

	/** The folder an owner's new space is named by: the node's slug, or the customer's t{id}. */
	public static function folder_of(string $kind, int $owner_id): string {
		if ($kind === self::OWNER_NODE) {
			$node = new ManagedNode($owner_id, TRUE);
			return trim((string)$node->get('mgn_slug'));
		}
		$row = new ServiceTenant($owner_id, TRUE);
		return trim((string)$row->get('svt_slug'));
	}

	public static function valid_folder(string $folder): bool {
		return (bool)preg_match('/^[A-Za-z0-9_-]+$/', $folder);
	}

	public static function base_for(BackupTarget $target, string $folder): string {
		return $target->prefix() . '/' . $folder . '/';
	}

	// ── States ────────────────────────────────────────────────────────────────

	public function is_active(): bool   { return (string)$this->get('sps_state') === self::STATE_ACTIVE; }
	public function is_draining(): bool { return (string)$this->get('sps_state') === self::STATE_DRAINING; }
	public function is_retired(): bool  { return (string)$this->get('sps_state') === self::STATE_RETIRED; }

	public function drain(): void {
		$this->set('sps_state', self::STATE_DRAINING);
		$this->set('sps_draining_time', gmdate('Y-m-d H:i:s'));
		$this->save();
	}

	/** A draining space with nothing left in it. */
	public function retire(): void {
		$this->set('sps_state', self::STATE_RETIRED);
		$this->set('sps_retired_time', gmdate('Y-m-d H:i:s'));
		$this->save();
	}

	private function activate(): void {
		$this->set('sps_state', self::STATE_ACTIVE);
		$this->set('sps_draining_time', null);
		$this->set('sps_retired_time', null);
		$this->save();
	}

	// ── Finding spaces ────────────────────────────────────────────────────────

	private static function owner_column(string $kind): string {
		if ($kind === self::OWNER_NODE) { return 'sps_mgn_managed_node_id'; }
		if ($kind === self::OWNER_TENANT) { return 'sps_svt_service_tenant_id'; }
		throw new StorageSpaceException('Unknown owner kind: ' . $kind);
	}

	/** The owner's active space, or null: a node with none backs up on its own disk only. */
	public static function active_for(string $kind, int $owner_id): ?StorageSpace {
		foreach (new MultiStorageSpace(array(self::owner_column($kind) => $owner_id, 'state' => self::STATE_ACTIVE),
				array('sps_storage_space_id' => 'DESC'), 1) as $space) {
			return $space;
		}
		return null;
	}

	/**
	 * Every space the owner has: the active one first, then draining spaces
	 * newest first, then (when asked) the retired ones.
	 *
	 * @return StorageSpace[]
	 */
	public static function of_owner(string $kind, int $owner_id, bool $with_retired = false): array {
		$out = array();
		foreach (new MultiStorageSpace(array(self::owner_column($kind) => $owner_id),
				array('sps_storage_space_id' => 'DESC')) as $space) {
			if ($space->is_retired() && !$with_retired) { continue; }
			$out[] = $space;
		}
		usort($out, function ($a, $b) {
			$rank = array(self::STATE_ACTIVE => 0, self::STATE_DRAINING => 1, self::STATE_RETIRED => 2);
			return ($rank[(string)$a->get('sps_state')] <=> $rank[(string)$b->get('sps_state')])
				?: ((int)$b->key <=> (int)$a->key);
		});
		return $out;
	}

	/**
	 * One of the owner's spaces by id, or null when the id is not the owner's.
	 * 0 means the active space.
	 */
	public static function owned(string $kind, int $owner_id, int $space_id): ?StorageSpace {
		if ($space_id <= 0) {
			return self::active_for($kind, $owner_id);
		}
		$space = new StorageSpace($space_id, TRUE);
		if (!$space->key || $space->owner_kind() !== $kind || $space->owner_id() !== $owner_id) {
			return null;
		}
		return $space;
	}

	/**
	 * The live space (active or draining) whose base key is this one on this
	 * target, or null: what a folder found in a listing belongs to.
	 */
	public static function for_base(int $target_id, string $base): ?StorageSpace {
		foreach (new MultiStorageSpace(array('sps_bkt_backup_target_id' => $target_id, 'sps_base_key' => $base,
				'live' => true), array('sps_storage_space_id' => 'DESC'), 1) as $space) {
			return $space;
		}
		return null;
	}

	/** Every live space on a target, active first. @return StorageSpace[] */
	public static function on_target(int $target_id): array {
		$out = array();
		foreach (new MultiStorageSpace(array('sps_bkt_backup_target_id' => $target_id, 'live' => true),
				array('sps_state' => 'ASC', 'sps_base_key' => 'ASC')) as $space) {
			$out[] = $space;
		}
		return $out;
	}

	/**
	 * The owners of a target's live spaces, by name, for the target's own
	 * refusals (BackupTarget::holdings()).
	 *
	 * @return array{active: string[], draining: string[]}
	 */
	public static function holdings_of(int $target_id): array {
		$out = array('active' => array(), 'draining' => array());
		foreach (self::on_target($target_id) as $space) {
			$out[$space->is_active() ? 'active' : 'draining'][] = $space->owner_name();
		}
		sort($out['active']);
		sort($out['draining']);
		return $out;
	}

	/**
	 * How many objects an owner's live spaces hold, listed from each space's
	 * target: what removing the owner for good would leave unclaimed. A space
	 * that cannot be listed is named in 'unchecked', so a caller that must
	 * not guess can refuse.
	 *
	 * @return array{count: int, unchecked: string[]}
	 */
	public static function owner_object_count(string $kind, int $owner_id): array {
		$out = array('count' => 0, 'unchecked' => array());
		foreach (self::of_owner($kind, $owner_id) as $space) {
			try {
				list($target, $creds, $bucket) = $space->reach();
				$out['count'] += count(S3Signer::list($creds, $bucket, $space->base()));
			} catch (\Throwable $e) {
				$out['unchecked'][] = $space->describe();
			}
		}
		return $out;
	}

	// ── Opening and moving ────────────────────────────────────────────────────

	/**
	 * Why a space with this base key may not be opened on this target, or ''
	 * when it may: no live space's base key may equal it, sit inside it or
	 * contain it. $except is a space that is being re-opened in place.
	 */
	public static function overlap_refusal(int $target_id, string $base, int $except = 0): string {
		foreach (self::on_target($target_id) as $space) {
			if ((int)$space->key === $except) { continue; }
			$other = $space->base();
			if (strpos($base, $other) === 0 || strpos($other, $base) === 0) {
				return 'The folder ' . $base . ' on this target ' . ($other === $base ? 'is' : 'overlaps')
					. ' ' . $space->describe() . ' (' . $other . '). Two owners never share a folder.';
			}
		}
		return '';
	}

	/**
	 * Open a space for an owner on a target, in the given state. An owner
	 * coming back to a folder it once had on this target gets that space
	 * back rather than a second record of the same folder.
	 */
	public static function open(BackupTarget $target, string $kind, int $owner_id, string $folder,
			string $state = self::STATE_ACTIVE): StorageSpace {
		$column = self::owner_column($kind);
		if (!self::valid_folder($folder)) {
			throw new StorageSpaceException('"' . $folder . '" cannot be a folder in a bucket; it may only contain '
				. 'letters, numbers, hyphens and underscores.');
		}
		if (!$target->key || $target->get('bkt_delete_time')) {
			throw new StorageSpaceException('That backup target has been deleted.');
		}
		$base = self::base_for($target, $folder);

		$space = null;
		foreach (new MultiStorageSpace(array('sps_bkt_backup_target_id' => (int)$target->key, 'sps_base_key' => $base,
				$column => $owner_id), array('sps_storage_space_id' => 'DESC'), 1) as $s) {
			$space = $s;
		}
		$why = self::overlap_refusal((int)$target->key, $base, $space ? (int)$space->key : 0);
		if ($why !== '') {
			throw new StorageSpaceException($why);
		}
		if ($space === null) {
			$space = new StorageSpace(NULL);
			$space->set('sps_bkt_backup_target_id', (int)$target->key);
			$space->set('sps_base_key', $base);
			$space->set($column, $owner_id);
			$space->set('sps_opened_time', gmdate('Y-m-d H:i:s'));
		}
		$space->set('sps_state', $state);
		$space->set('sps_draining_time', $state === self::STATE_DRAINING ? gmdate('Y-m-d H:i:s') : null);
		$space->set('sps_retired_time', null);
		$space->save();
		return $space;
	}

	/**
	 * Move one owner's new backups to a target. The target opens (or gives
	 * back) the owner's space there, and the space that was active starts
	 * draining: its backups stay readable and restorable there and age out
	 * by retention, never copied (R3). Moving to where it already is changes
	 * nothing.
	 */
	public static function move(string $kind, int $owner_id, BackupTarget $to): StorageSpace {
		if (!$to->key || $to->get('bkt_delete_time') || !$to->get('bkt_enabled')) {
			throw new StorageSpaceException('New backups can only go to a backup target that is switched on.');
		}
		$current = self::active_for($kind, $owner_id);
		if ($current && (int)$current->get('sps_bkt_backup_target_id') === (int)$to->key) {
			return $current;
		}
		$folder = $current ? $current->folder() : self::folder_of($kind, $owner_id);
		$db = DbConnector::get_instance()->get_db_link();
		$own = !$db->inTransaction();
		if ($own) { $db->beginTransaction(); }
		try {
			if ($current) {
				$current->drain();
			}
			$space = self::open($to, $kind, $owner_id, $folder, self::STATE_ACTIVE);
			if ($own) { $db->commit(); }
		} catch (\Throwable $e) {
			if ($own && $db->inTransaction()) { $db->rollBack(); }
			throw $e;
		}
		return $space;
	}

	/**
	 * Move every owner whose new backups go to $from over to $to. Returns
	 * the owners moved and, by name, the sentence for each that was not.
	 *
	 * @return array{moved: string[], refused: array<string,string>}
	 */
	public static function move_everyone_off(BackupTarget $from, BackupTarget $to): array {
		if ((int)$from->key === (int)$to->key) {
			throw new StorageSpaceException('Choose a different target to move them to.');
		}
		$out = array('moved' => array(), 'refused' => array());
		foreach (self::on_target((int)$from->key) as $space) {
			if (!$space->is_active()) { continue; }
			$name = $space->owner_name();
			try {
				self::move($space->owner_kind(), $space->owner_id(), $to);
				$out['moved'][] = $name;
			} catch (\Throwable $e) {
				$out['refused'][$name] = $e->getMessage();
			}
		}
		return $out;
	}

	/**
	 * Give a folder found on a target, which no space claims, to an owner as a
	 * draining space: its backups become listable, restorable and prunable
	 * as that owner's again.
	 */
	public static function adopt(BackupTarget $target, string $folder, string $kind, int $owner_id): StorageSpace {
		$base = self::base_for($target, $folder);
		if (self::for_base((int)$target->key, $base)) {
			throw new StorageSpaceException('That folder already belongs to ' . self::for_base((int)$target->key, $base)->describe() . '.');
		}
		return self::open($target, $kind, $owner_id, $folder, self::STATE_DRAINING);
	}

	/**
	 * Give an owner with no active space one on the target Where new backups
	 * go names, when that is switched on. Returns the active space, or null
	 * when nothing is named: the owner then has none (R6).
	 */
	public static function open_default(string $kind, int $owner_id): ?StorageSpace {
		$current = self::active_for($kind, $owner_id);
		if ($current) {
			return $current;
		}
		$id = (int)Globalvars::get_instance()->get_setting('server_manager_backup_target_id', false, true);
		if ($id <= 0) {
			return null;
		}
		$target = new BackupTarget($id, TRUE);
		if (!$target->key || $target->get('bkt_delete_time') || !$target->get('bkt_enabled')) {
			return null;
		}
		return self::open($target, $kind, $owner_id, self::folder_of($kind, $owner_id), self::STATE_ACTIVE);
	}
}

class MultiStorageSpace extends SystemMultiBase {
	protected static $model_class = 'StorageSpace';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['state'])) {
			$filters['sps_state'] = array((string)$this->options['state'], PDO::PARAM_STR);
		}
		// Active or draining: a space that still holds or takes something.
		if (!empty($this->options['live'])) {
			$filters['sps_state'] = "IN ('active', 'draining')";
		}
		return $this->_get_resultsv2('sps_storage_spaces', $filters, $this->order_by, $only_count, $debug);
	}
}
