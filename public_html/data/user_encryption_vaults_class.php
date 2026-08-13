<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class UserEncryptionVaultException extends SystemBaseException {}

/**
 * UserEncryptionVault - one row per (user, scope): the vault's identity. An
 * X25519 keypair whose PUBLIC half lives here in cleartext (anyone can seal
 * to it) and whose SECRET half is never stored here — only wrapped, one
 * wrapping per enrolled unlocker, in `uew_user_encryption_wrappings`.
 *
 * `uev_scope` names which vault this is. Which scopes exist is instance
 * configuration, declared in `vault_scopes.json` and in each plugin's
 * `vaultScopes` — see VaultScopes. `uev_custody` names where the secret key is
 * ever unwrapped ('server' = a VaultUnlock APCu window; 'client' = the browser
 * only — a client-custody row's secret key must never be unwrapped
 * server-side).
 *
 * 'user' is the server-custody scope, shared by every consumer that needs the
 * server able to read while the member is present. Every other scope is client
 * custody, one keypair each.
 *
 * @version 1.0
 */
class UserEncryptionVault extends SystemBase {
	public static $prefix = 'uev';
	public static $tablename = 'uev_user_encryption_vaults';
	public static $pkey_column = 'uev_user_encryption_vault_id';

	protected static $foreign_key_actions = [
		'uev_usr_user_id' => ['action' => 'permanent_delete'],
	];

	public static $api_readable = false;
	public static $api_writable = false;

	/**
	 * The server-custody scope, and the only one that can exist: setup, rotation
	 * and every ceremony unlock name it directly, and neither the ceremonies nor
	 * the reseal callback signature carry a scope. It stays a named constant
	 * because core code refers to it constantly and a bare 'user' reads worse.
	 *
	 * Client-custody scope names are not constants here — they come from
	 * VaultScopes, which is where a plugin declares its own.
	 */
	const SCOPE_USER = 'user';

	const CUSTODY_SERVER = 'server';
	const CUSTODY_CLIENT = 'client';

	public static $field_specifications = array(
		'uev_user_encryption_vault_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'uev_usr_user_id' => array('type'=>'int8', 'is_nullable'=>false, 'index'=>true, 'unique_with'=>array('uev_scope'),
			'foreign_key'=>array('table'=>'usr_users', 'column'=>'usr_user_id', 'on_delete'=>'CASCADE')),
		'uev_scope'          => array('type'=>'varchar(32)', 'is_nullable'=>false, 'default'=>'user'),
		'uev_custody'        => array('type'=>'varchar(10)', 'is_nullable'=>false, 'default'=>'server'),
		'uev_public_key'     => array('type'=>'text', 'is_nullable'=>false),
		'uev_salt'           => array('type'=>'text', 'is_nullable'=>false),
		'uev_kdf_params'     => array('type'=>'text', 'is_nullable'=>true),
		'uev_key_generation' => array('type'=>'int4', 'is_nullable'=>false, 'default'=>1),
		'uev_created_time'   => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'uev_updated_time'   => array('type'=>'timestamp(6)', 'is_nullable'=>true),
	);

	/** The one vault row for a (user, scope), or null if not set up yet. */
	public static function loadForUser(int $user_id, string $scope = self::SCOPE_USER): ?UserEncryptionVault {
		$multi = new MultiUserEncryptionVault(['user_id' => $user_id, 'scope' => $scope]);
		$multi->load();
		return $multi->count() > 0 ? $multi->get(0) : null;
	}
}

class MultiUserEncryptionVault extends SystemMultiBase {
	protected static $model_class = 'UserEncryptionVault';

	protected function getMultiResults($only_count=false, $debug=false) {
		$filters = [];
		if (isset($this->options['user_id']))
			$filters['uev_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		if (isset($this->options['scope']))
			$filters['uev_scope'] = [$this->options['scope'], PDO::PARAM_STR];
		if (isset($this->options['custody']))
			$filters['uev_custody'] = [$this->options['custody'], PDO::PARAM_STR];
		if (isset($this->options['public_key']))
			$filters['uev_public_key'] = [$this->options['public_key'], PDO::PARAM_STR];
		return $this->_get_resultsv2('uev_user_encryption_vaults', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
