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

	function __construct($key, $and_load = FALSE) {
		parent::__construct($key, $and_load);
	}

	/** The row-binding AD every wrapping is sealed with - splices a wrapping's
	 *  ciphertext onto a different row and it fails to open. */
	public static function adFor(int $vault_id, int $wrapping_id): string {
		return 'vault:' . $vault_id . ':' . $wrapping_id;
	}

	/**
	 * Wrap $secret_key under $kek and persist a new row. Two-phase insert:
	 * the AD binds in the wrapping's own id, which only exists after the
	 * first save(), so it saves once to allocate the row then again with the
	 * real ciphertext. $key_generation tags which vault generation the
	 * wrapped secret belongs to; null resolves to the vault's CURRENT
	 * generation (correct for every enrollment ceremony — the in-window
	 * secret being wrapped is the current generation's). Rotation passes its
	 * computed new generation explicitly. $salt records the KDF salt the KEK
	 * was derived under (recovery/passphrase only; null for passkeys).
	 */
	public static function createWrapped(int $vault_id, string $unlocker_type, string $secret_key, string $kek, $credential_id = null, $label = null, ?int $key_generation = null, ?string $salt = null): UserEncryptionWrapping {
		require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
		$box = new SealedBox();

		if ($key_generation === null) {
			$vault = new UserEncryptionVault($vault_id, TRUE);
			$key_generation = (int)$vault->get('uev_key_generation');
		}

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

		$ad = self::adFor($vault_id, $wrapping->key);
		$wrapping->set('uew_wrapped_secret_key', $box->wrapKey($secret_key, $kek, $ad));
		$wrapping->save();

		return $wrapping;
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
		$filters['uew_delete_time'] = (isset($this->options['deleted']) && $this->options['deleted']) ? "IS NOT NULL" : "IS NULL";
		return $this->_get_resultsv2('uew_user_encryption_wrappings', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
