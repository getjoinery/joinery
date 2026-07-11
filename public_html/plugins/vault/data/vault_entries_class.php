<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class VaultEntryException extends SystemBaseException {}

/**
 * VaultEntry - one encrypted password-manager entry.
 *
 * `vle_ciphertext` is a single opaque AES-256-GCM blob over a JSON record
 * (type, title, username, password, url, notes, totp_seed, ...) encrypted under
 * the store DEK in the browser. EVERYTHING is inside the blob - even the coarse
 * `type` - so there is deliberately no searchable plaintext column: the list is
 * rendered only after client-side decryption, and a cleartext column would only
 * leak. The server stores and returns the blob and never inspects it.
 *
 * Soft delete (`vle_delete_time`) is trash/restore - a trashed ciphertext is no
 * less protected than a live one.
 *
 * @version 1.0
 */
class VaultEntry extends SystemBase {
	public static $prefix = 'vle';
	public static $tablename = 'vle_vault_entries';
	public static $pkey_column = 'vle_vault_entry_id';

	public static $api_readable = false;
	public static $api_writable = false;

	public static $field_specifications = array(
		'vle_vault_entry_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'vle_usr_user_id' => array('type'=>'int8', 'is_nullable'=>false, 'index'=>true,
			'foreign_key'=>array('table'=>'usr_users', 'column'=>'usr_user_id', 'on_delete'=>'CASCADE')),
		'vle_ciphertext'   => array('type'=>'text', 'is_nullable'=>false),
		'vle_created_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'vle_updated_time' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
		'vle_delete_time'  => array('type'=>'timestamp(6)', 'is_nullable'=>true),
	);

	public static $permanent_delete_actions = array();

	function __construct($key, $and_load = FALSE) {
		parent::__construct($key, $and_load);
	}
}

class MultiVaultEntry extends SystemMultiBase {
	protected static $model_class = 'VaultEntry';

	protected function getMultiResults($only_count=false, $debug=false) {
		$filters = [];
		if (isset($this->options['user_id']))
			$filters['vle_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		$filters['vle_delete_time'] = (isset($this->options['deleted']) && $this->options['deleted']) ? "IS NOT NULL" : "IS NULL";
		return $this->_get_resultsv2('vle_vault_entries', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
