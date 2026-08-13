<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class VaultKeyringException extends SystemBaseException {}

/**
 * VaultKeyring - one row per user: the password store's data key (DEK), sealed
 * once to the user's client-custody vault public key (uev_scope='passwords').
 *
 * This is ALL it holds. The key derivation, salt, unlocker wrappings, and
 * recovery material are the Sealed Vault's (uev/uew) - this row only binds the
 * store DEK to the user's vault identity. `vlk_wrapped_dek` is an opaque blob
 * the browser produced (the DEK sealed via ECIES to uev_public_key); the server
 * never opens it. Unlocking = the browser unwraps the vault secret key (via a
 * vault unlocker), opens this sealed DEK, and holds it as a non-extractable
 * CryptoKey.
 *
 * @version 1.0
 */
class VaultKeyring extends SystemBase {
	public static $prefix = 'vlk';
	public static $tablename = 'vlk_vault_keyring';
	public static $pkey_column = 'vlk_vault_keyring_id';

	protected static $foreign_key_actions = array(
		'vlk_usr_user_id' => array('action' => 'cascade'),
	);

	public static $api_readable = false;
	public static $api_writable = false;

	public static $field_specifications = array(
		'vlk_vault_keyring_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'vlk_usr_user_id' => array('type'=>'int8', 'is_nullable'=>false, 'unique'=>true, 'index'=>true,
			'foreign_key'=>array('table'=>'usr_users', 'column'=>'usr_user_id', 'on_delete'=>'CASCADE')),
		'vlk_wrapped_dek'   => array('type'=>'text', 'is_nullable'=>false),
		'vlk_created_time'  => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'vlk_updated_time'  => array('type'=>'timestamp(6)', 'is_nullable'=>true),
	);

	/** The keyring row for a user, or null if the store DEK isn't sealed yet. */
	public static function loadForUser(int $user_id): ?VaultKeyring {
		$multi = new MultiVaultKeyring(['user_id' => $user_id]);
		$multi->load();
		return $multi->count() > 0 ? $multi->get(0) : null;
	}
}

class MultiVaultKeyring extends SystemMultiBase {
	protected static $model_class = 'VaultKeyring';

	protected function getMultiResults($only_count=false, $debug=false) {
		$filters = [];
		if (isset($this->options['user_id']))
			$filters['vlk_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		return $this->_get_resultsv2('vlk_vault_keyring', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
