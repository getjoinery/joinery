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
 * `uev_scope` names which vault this is ('user' = server-custody, shared by
 * mail + chat; 'drive'/'passwords' = future client-custody scopes, each its
 * own keypair). `uev_custody` names where the secret key is ever unwrapped
 * ('server' = a VaultUnlock APCu window; 'client' = the browser only — a
 * client-custody row's secret key must never be unwrapped server-side).
 *
 * This package builds server-custody only: scope 'user', custody 'server'.
 * The drive/passwords scopes and client custody ship as columns now (so the
 * shape is fixed) but are built by their own consumer packages — see
 * docs/sealed_vault.md.
 *
 * @version 1.0
 */
class UserEncryptionVault extends SystemBase {
	public static $prefix = 'uev';
	public static $tablename = 'uev_user_encryption_vaults';
	public static $pkey_column = 'uev_user_encryption_vault_id';

	public static $api_readable = false;
	public static $api_writable = false;

	const SCOPE_USER      = 'user';
	const SCOPE_DRIVE      = 'drive';
	const SCOPE_PASSWORDS  = 'passwords';

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

	public static $permanent_delete_actions = array();

	function __construct($key, $and_load = FALSE) {
		parent::__construct($key, $and_load);
	}

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
