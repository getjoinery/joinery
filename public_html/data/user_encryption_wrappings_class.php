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
 * @version 1.0
 */
class UserEncryptionWrapping extends SystemBase {
	public static $prefix = 'uew';
	public static $tablename = 'uew_user_encryption_wrappings';
	public static $pkey_column = 'uew_user_encryption_wrapping_id';

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
		'uew_key_generation'     => array('type'=>'int4', 'is_nullable'=>false, 'default'=>1),
		'uew_is_used'            => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		'uew_label'              => array('type'=>'varchar(255)', 'is_nullable'=>true),
		'uew_created_time'       => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'uew_used_time'          => array('type'=>'timestamp(6)', 'is_nullable'=>true),
		'uew_delete_time'        => array('type'=>'timestamp(6)', 'is_nullable'=>true),
	);

	public static $permanent_delete_actions = array();

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
	 * wrapped secret belongs to (defaults to 1 - the pre-rotation generation
	 * every consumer of this method other than key rotation is creating
	 * wrappings for); rotation passes the new generation explicitly.
	 */
	public static function createWrapped(int $vault_id, string $unlocker_type, string $secret_key, string $kek, $credential_id = null, $label = null, int $key_generation = 1): UserEncryptionWrapping {
		require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
		$box = new SealedBox();

		$wrapping = new UserEncryptionWrapping(NULL);
		$wrapping->set('uew_uev_user_encryption_vault_id', $vault_id);
		$wrapping->set('uew_unlocker_type', $unlocker_type);
		if ($credential_id !== null) {
			$wrapping->set('uew_pkc_credential_id', $credential_id);
		}
		if ($label !== null) {
			$wrapping->set('uew_label', $label);
		}
		$wrapping->set('uew_key_generation', $key_generation);
		$wrapping->set('uew_wrapped_secret_key', '');
		$wrapping->save();

		$ad = self::adFor($vault_id, $wrapping->key);
		$wrapping->set('uew_wrapped_secret_key', $box->wrapKey($secret_key, $kek, $ad));
		$wrapping->save();

		return $wrapping;
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
