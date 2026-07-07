<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class PasskeyException extends SystemBaseException {}

/**
 * One enrolled WebAuthn credential. `pkc_source_json` is the library's
 * serialized CredentialRecord — the authoritative state every ceremony
 * round-trips through. The other columns are denormalized-on-write
 * conveniences for lookup and UI display.
 */
class Passkey extends SystemBase {
	public static $prefix = 'pkc';
	public static $tablename = 'pkc_passkey_credentials';
	public static $pkey_column = 'pkc_passkey_credential_id';

	public static $api_readable = true;
	public static $api_writable = false;
	public static $api_unreadable_fields = array('pkc_source_json');
	public static $api_unwritable_fields = array();
	public static $api_derived_fields = array();

	public static $field_specifications = array(
		'pkc_passkey_credential_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'pkc_usr_user_id'           => array('type'=>'int8', 'is_nullable'=>false, 'index'=>true),
		'pkc_credential_id'         => array('type'=>'text', 'is_nullable'=>false),
		'pkc_source_json'           => array('type'=>'text', 'is_nullable'=>false),
		'pkc_sign_count'            => array('type'=>'int8', 'is_nullable'=>false, 'default'=>0),
		'pkc_transports'            => array('type'=>'text', 'is_nullable'=>true),
		'pkc_aaguid'                => array('type'=>'varchar(64)', 'is_nullable'=>true),
		'pkc_prf_capable'           => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		'pkc_label'                 => array('type'=>'varchar(255)', 'is_nullable'=>true),
		'pkc_created_time'          => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'pkc_last_used_time'        => array('type'=>'timestamp(6)', 'is_nullable'=>true),
		'pkc_delete_time'           => array('type'=>'timestamp(6)', 'is_nullable'=>true),
	);

	// A revoked row keeps its credential id; a fresh re-enrollment of the
	// same physical credential must still be free to insert.
	public static $index_specifications = array(
		array('columns'=>array('pkc_credential_id'), 'unique'=>true, 'where'=>'pkc_delete_time IS NULL'),
	);

	public static $permanent_delete_actions = array();

	function __construct($key, $and_load = FALSE) {
		parent::__construct($key, $and_load);
	}
}

class MultiPasskey extends SystemMultiBase {
	protected static $model_class = 'Passkey';

	protected function getMultiResults($only_count=false, $debug=false) {
		$filters = [];
		if (isset($this->options['user_id']))
			$filters['pkc_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		if (isset($this->options['credential_id']))
			$filters['pkc_credential_id'] = [$this->options['credential_id'], PDO::PARAM_STR];
		if (isset($this->options['prf_capable']))
			$filters['pkc_prf_capable'] = "= " . ($this->options['prf_capable'] ? 'TRUE' : 'FALSE');
		$filters['pkc_delete_time'] = (isset($this->options['deleted']) && $this->options['deleted']) ? "IS NOT NULL" : "IS NULL";
		return $this->_get_resultsv2('pkc_passkey_credentials', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
