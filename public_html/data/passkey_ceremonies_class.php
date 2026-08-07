<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class PasskeyCeremonyException extends SystemBaseException {}

/**
 * Transient WebAuthn ceremony state, keyed by the browser-session id.
 *
 * Browser-session API requests are read-only on the web session (ApiAuth
 * releases the session lock before dispatch), so ceremony state that must
 * survive from an options call to its verify call lives here instead of in
 * $_SESSION. Two kinds:
 *
 *   - 'challenge': the pending challenge for the session's one in-flight
 *     ceremony. Single-use - consumed (deleted) before verification.
 *   - 'stepup': the step-up-verified marker; its created time is what
 *     PasskeyService::hasRecentStepUp() measures against.
 *
 * Rows are short-lived; expired rows are swept opportunistically on every
 * stash. PasskeyService is the only reader/writer.
 */
class PasskeyCeremony extends SystemBase {
	public static $prefix = 'pks';
	public static $tablename = 'pks_passkey_ceremonies';
	public static $pkey_column = 'pks_passkey_ceremony_id';

	public static $api_readable = false;
	public static $api_writable = false;

	public static $field_specifications = array(
		'pks_passkey_ceremony_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'pks_session_id'          => array('type'=>'varchar(128)', 'is_nullable'=>false, 'index'=>true),
		'pks_kind'                => array('type'=>'varchar(16)', 'is_nullable'=>false),
		'pks_purpose'             => array('type'=>'varchar(255)', 'is_nullable'=>false),
		'pks_challenge'           => array('type'=>'text', 'is_nullable'=>true),
		'pks_expires_time'        => array('type'=>'timestamp(6)', 'is_nullable'=>false),
		'pks_created_time'        => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	function __construct($key, $and_load = FALSE) {
		parent::__construct($key, $and_load);
	}
}

class MultiPasskeyCeremony extends SystemMultiBase {
	protected static $model_class = 'PasskeyCeremony';

	protected function getMultiResults($only_count=false, $debug=false) {
		$filters = [];
		if (isset($this->options['session_id']))
			$filters['pks_session_id'] = [$this->options['session_id'], PDO::PARAM_STR];
		if (isset($this->options['kind']))
			$filters['pks_kind'] = [$this->options['kind'], PDO::PARAM_STR];
		return $this->_get_resultsv2('pks_passkey_ceremonies', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
