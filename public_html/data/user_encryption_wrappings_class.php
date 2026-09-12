<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class UserEncryptionWrappingException extends SystemBaseException {}

/**
 * UserEncryptionWrapping - one row per enrolled unlocker on a vault: the
 * vault's X25519 secret key, AEAD-wrapped (AD = {vault id, wrapping id}) under
 * a KEK derived from that unlocker (a passkey's PRF output, a recovery code,
 * or a passphrase). The secret key exists at rest ONLY as these wrappings —
 * never in `uev_user_encryption_vaults` itself.
 *
 * `uew_is_used` is a one-time flag for recovery-code wrappings: consuming a
 * code to unlock marks it used (still counted as "enrolled" for audit, but
 * excluded from the unlocker-floor's unused-recovery-code count). A soft
 * delete (`uew_delete_time`) is how a wrapping is retired — regenerated
 * codes, a removed passphrase, or a revoked passkey's wrapping.
 *
 * Every wrapping is self-contained: `uew_key_generation` says which vault
 * generation's secret it wraps, and `uew_salt` records the KDF salt its KEK
 * was derived under (recovery/passphrase wrappings only; passkey PRF KEKs are
 * salt-independent and store null). Unlock paths read the wrapping's own
 * salt, so a rotation replacing `uev_salt` never strands a live wrapping.
 *
 * A row whose wrapping is empty is RESERVED, not enrolled: it exists only
 * between reserve() and storeWrapped() inside one request. The collection
 * excludes such rows unless asked (`include_reserved`), so a request that
 * died between the two calls leaves nothing that counts as an unlocker, opens
 * a vault or blocks a re-enrolment; and reserve() retires any stale reserved
 * row for the same vault, type and credential before saving its own.
 *
 * @version 1.3 - reserved (empty) rows are excluded by default and swept by the
 *   next reserve() for the same unlocker; storeWrapped() refuses a row that was
 *   superseded meanwhile
 * @version 1.2 - reserve()/storeWrapped() replace createWrapped(): the wrapping
 *   is produced by VaultUnlock::open()/openKey() from the row's AD, never here
 *   (specs/unseal_daemon.md B1)
 * @version 1.1
 */
class UserEncryptionWrapping extends SystemBase {
	public static $prefix = 'uew';
	public static $tablename = 'uew_user_encryption_wrappings';
	public static $pkey_column = 'uew_user_encryption_wrapping_id';

	protected static $foreign_key_actions = [
		'uew_uev_user_encryption_vault_id' => ['action' => 'cascade'],
		'uew_pkc_credential_id' => ['action' => 'cascade'],
	];

	public static $api_readable = false;
	public static $api_writable = false;

	const TYPE_PASSKEY    = 'passkey';
	const TYPE_RECOVERY   = 'recovery';
	const TYPE_PASSPHRASE = 'passphrase';

	public static $field_specifications = array(
		'uew_user_encryption_wrapping_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'uew_uev_user_encryption_vault_id' => array('type'=>'int8', 'is_nullable'=>false, 'index'=>true,
			'foreign_key'=>array('table'=>'uev_user_encryption_vaults', 'column'=>'uev_user_encryption_vault_id', 'on_delete'=>'CASCADE')),
		'uew_unlocker_type'      => array('type'=>'varchar(16)', 'is_nullable'=>false),
		'uew_pkc_credential_id'  => array('type'=>'int8', 'is_nullable'=>true, 'index'=>true),
		'uew_wrapped_secret_key' => array('type'=>'text', 'is_nullable'=>false),
		'uew_salt'               => array('type'=>'varchar(64)', 'is_nullable'=>true),
		'uew_key_generation'     => array('type'=>'int4', 'is_nullable'=>false, 'default'=>1),
		'uew_is_used'            => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		'uew_label'              => array('type'=>'varchar(255)', 'is_nullable'=>true),
		'uew_created_time'       => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'uew_used_time'          => array('type'=>'timestamp(6)', 'is_nullable'=>true),
		'uew_delete_time'        => array('type'=>'timestamp(6)', 'is_nullable'=>true),
	);

	/** The row-binding AD every wrapping is sealed with - splices a wrapping's
	 *  ciphertext onto a different row and it fails to open. */
	public static function adFor(int $vault_id, int $wrapping_id): string {
		return 'vault:' . $vault_id . ':' . $wrapping_id;
	}

	/**
	 * Reserve a row for a wrapping about to be produced. The AD binds in the
	 * wrapping's own id, which only exists after the first save(), so every
	 * enrolment is two phases: reserve the row (this), hand its ad() to
	 * VaultUnlock::open()/openKey() in that call's wrap list, then
	 * storeWrapped() what came back. The wrapping itself is produced nowhere
	 * else (specs/unseal_daemon.md B1): the secret leaves the key holder only
	 * in the request that presented an unlocker for it.
	 *
	 * $key_generation tags which vault generation the wrapped secret belongs
	 * to; null resolves to the vault's CURRENT generation (correct for every
	 * enrollment ceremony — the secret being wrapped is the current
	 * generation's). Rotation passes its computed new generation explicitly.
	 * $salt records the KDF salt the KEK was derived under (recovery/passphrase
	 * only; null for passkeys).
	 *
	 * A reserved row that never receives its wrapping (the open failed, the
	 * request died between the two phases) is not an unlocker: every query on
	 * this collection excludes empty rows by default, so it never counts as
	 * enrolled, is never offered at unlock, and never blocks re-enrolling the
	 * same credential. It is retired here, by the next reserve() for the same
	 * vault, type and credential — except rows this request reserved itself,
	 * since a ceremony reserves its whole batch (ten codes) before one open.
	 */
	public static function reserve(int $vault_id, string $unlocker_type, $credential_id = null, $label = null, ?int $key_generation = null, ?string $salt = null): UserEncryptionWrapping {
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

		if ($key_generation === null) {
			$vault = new UserEncryptionVault($vault_id, TRUE);
			$key_generation = (int)$vault->get('uev_key_generation');
		}

		self::retireStaleReserved($vault_id, $unlocker_type, $credential_id);

		$wrapping = new UserEncryptionWrapping(NULL);
		$wrapping->set('uew_uev_user_encryption_vault_id', $vault_id);
		$wrapping->set('uew_unlocker_type', $unlocker_type);
		if ($credential_id !== null) {
			$wrapping->set('uew_pkc_credential_id', $credential_id);
		}
		if ($label !== null) {
			$wrapping->set('uew_label', $label);
		}
		if ($salt !== null) {
			$wrapping->set('uew_salt', $salt);
		}
		$wrapping->set('uew_key_generation', $key_generation);
		$wrapping->set('uew_wrapped_secret_key', '');
		$wrapping->save();
		self::$reserved_this_request[(int)$wrapping->key] = true;
		return $wrapping;
	}

	/** @var array<int,bool> rows reserve() saved in this request — never swept as stale */
	private static $reserved_this_request = array();

	/**
	 * Soft-delete reserved (empty) rows for this vault, type and credential
	 * that an earlier request left behind. Credential-less types (recovery,
	 * passphrase) match on the vault and type alone.
	 */
	private static function retireStaleReserved(int $vault_id, string $unlocker_type, $credential_id): void {
		$options = ['vault_id' => $vault_id, 'unlocker_type' => $unlocker_type, 'include_reserved' => true];
		if ($credential_id !== null) {
			$options['credential_id'] = (int)$credential_id;
		}
		$rows = new MultiUserEncryptionWrapping($options);
		foreach ($rows as $row) {
			if ((string)$row->get('uew_wrapped_secret_key') !== '' || isset(self::$reserved_this_request[(int)$row->key])) {
				continue;
			}
			$row->soft_delete();
		}
	}

	/** Tests only: forget what this process reserved, so a row it reserved reads
	 *  as another request's leftover. */
	public static function forgetReservationsForTests(): void {
		self::$reserved_this_request = array();
	}

	/** True while this row is reserved and not yet enrolled. */
	public function isReserved(): bool {
		return (string)$this->get('uew_wrapped_secret_key') === '';
	}

	/** This row's AD — what its wrapping is bound to. Only a saved row has one. */
	public function ad(): string {
		return self::adFor((int)$this->get('uew_uev_user_encryption_vault_id'), (int)$this->key);
	}

	/** The wrap-list entry for this row: the KEK the unlocker derived, and this row's AD. */
	public function wrapEntry(string $kek): array {
		return array('kek' => $kek, 'ad' => $this->ad());
	}

	/**
	 * Phase two of reserve(): persist the wrapping VaultUnlock produced for this
	 * row's AD. Guarded on the row still being live and still reserved, so a
	 * reservation another request retired meanwhile (a double submit) fails
	 * loudly instead of writing a wrapping onto a dead row.
	 */
	public function storeWrapped(string $wrapped_secret_key): void {
		if ($wrapped_secret_key === '') {
			throw new UserEncryptionWrappingException('A wrapping cannot be empty.');
		}
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'UPDATE ' . self::$tablename . ' SET uew_wrapped_secret_key = ?'
			. ' WHERE ' . self::$pkey_column . ' = ? AND uew_delete_time IS NULL AND uew_wrapped_secret_key = \'\'');
		$stmt->execute([$wrapped_secret_key, (int)$this->key]);
		if ($stmt->rowCount() !== 1) {
			throw new UserEncryptionWrappingException('This wrapping reservation was superseded; run the enrolment again.');
		}
		$this->set('uew_wrapped_secret_key', $wrapped_secret_key);
		unset(self::$reserved_this_request[(int)$this->key]);
	}

	/** The unlocker array VaultUnlock::open()/openKey() takes for this row under $kek. */
	public function unlocker(string $kek): array {
		return array('wrapped' => (string)$this->get('uew_wrapped_secret_key'), 'kek' => $kek, 'ad' => $this->ad());
	}

	/**
	 * Store a batch of wrappings onto the rows they were produced for — the
	 * rows and the wrappings under the same keys, as VaultUnlock hands them
	 * back for a wrap list built from the rows' wrapEntry() calls.
	 *
	 * @param UserEncryptionWrapping[] $rows
	 * @param string[]                 $wrappings
	 */
	public static function storeWrappings(array $rows, array $wrappings): void {
		foreach ($rows as $slot => $row) {
			if (!isset($wrappings[$slot])) {
				throw new UserEncryptionWrappingException('No wrapping was produced for reserved wrapping row ' . (int)$row->key . '.');
			}
			$row->storeWrapped((string)$wrappings[$slot]);
		}
	}

	/**
	 * The distinct key generations with at least one live wrapping on this
	 * vault. More than one entry means a partially-completed rotation whose
	 * only exit is re-running the rotation — enrollment ceremonies check this
	 * and refuse, since a wrapping they created could not be tagged with a
	 * single truthful generation.
	 *
	 * @return int[]
	 */
	public static function liveGenerations(int $vault_id): array {
		$wrappings = new MultiUserEncryptionWrapping(['vault_id' => $vault_id]);
		$wrappings->load();
		$generations = [];
		foreach ($wrappings as $wrapping) {
			$generations[(int)$wrapping->get('uew_key_generation')] = true;
		}
		return array_keys($generations);
	}
}

class MultiUserEncryptionWrapping extends SystemMultiBase {
	protected static $model_class = 'UserEncryptionWrapping';

	protected function getMultiResults($only_count=false, $debug=false) {
		$filters = [];
		if (isset($this->options['vault_id']))
			$filters['uew_uev_user_encryption_vault_id'] = [$this->options['vault_id'], PDO::PARAM_INT];
		if (isset($this->options['unlocker_type']))
			$filters['uew_unlocker_type'] = [$this->options['unlocker_type'], PDO::PARAM_STR];
		if (isset($this->options['credential_id']))
			$filters['uew_pkc_credential_id'] = [$this->options['credential_id'], PDO::PARAM_INT];
		if (isset($this->options['is_used']))
			$filters['uew_is_used'] = "= " . ($this->options['is_used'] ? 'TRUE' : 'FALSE');
		// A reserved row (empty wrapping) is not an unlocker — see reserve().
		if (empty($this->options['include_reserved']))
			$filters['uew_wrapped_secret_key'] = "<> ''";
		$filters['uew_delete_time'] = (isset($this->options['deleted']) && $this->options['deleted']) ? "IS NOT NULL" : "IS NULL";
		return $this->_get_resultsv2('uew_user_encryption_wrappings', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
