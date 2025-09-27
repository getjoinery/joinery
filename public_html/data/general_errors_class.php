<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/FieldConstraints.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class GeneralErrorException extends SystemBaseException {}

class GeneralError extends SystemBase {	public static $prefix = 'err';
	public static $tablename = 'err_general_errors';
	public static $pkey_column = 'err_general_error_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

		/**
	 * Field specifications define database column properties and validation rules
	 * 
	 * Database schema properties (used by update_database):
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp' | 'bool' | etc.
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'serial' => true/false - Auto-incrementing field
	 * 
	 * Validation and behavior properties (used by SystemBase):
	 *   'required' => true/false - Field must have non-empty value on save
	 *   'default' => mixed - Default value for new records (applied on INSERT only)
	 *   'zero_on_create' => true/false - Set to 0 when creating if NULL (INSERT only)
	 * 
	 * Note: Timestamp fields are auto-detected based on type for smart_get() and export_as_array()
	 */
	public static $field_specifications = array(
	    'err_general_error_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'err_error' => array('type'=>'varchar(255)'),
	    'err_code' => array('type'=>'varchar(32)'),
	    'err_usr_user_id' => array('type'=>'int4'),
	    'err_description' => array('type'=>'varchar(255)'),
	    'err_file' => array('type'=>'varchar(255)'),
	    'err_line' => array('type'=>'varchar(32)'),
	    'err_context' => array('type'=>'text'),
	    'err_path' => array('type'=>'varchar(255)'),
	    'err_message' => array('type'=>'varchar(255)'),
	    'err_level' => array('type'=>'varchar(255)'),
	    'err_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	public static $field_constraints = array();

	function display_time($session) {
		return LibraryFunctions::convert_time(
			$this->get('err_log_time'), 'UTC', $session->get_timezone(), '%a, %d %b %Y %R:%S');
	}	

	/**
	 * Instance method for logging errors following standard SystemBase patterns
	 * 
	 * @param \Throwable $exception The exception to log
	 * @param array $session Session data (optional)
	 * @param array $request Request data (optional)
	 */
	public function logError(\Throwable $exception, $session = [], $request = []) {
		$session_obj = SessionControl::get_instance();
		$dbhelper = DbConnector::get_instance();
		
		// Sanitize data
		$safe_session = self::sanitizeSessionData($session);
		$safe_request = self::sanitizeSessionData($request);
		
		$error_context = $exception->getTraceAsString() . "\r\n \r\n REQUEST_URI: " . 
		                 $_SERVER['REQUEST_URI'] . "\r\n \r\n $_SESSION: " . 
		                 print_r($safe_session, true) . ' $_REQUEST: ' . 
		                 print_r($safe_request, true);
		
		// Set fields using standard model methods
		if ($exception instanceof PDOException) {
			$this->set('err_level', 'Database Error');
			$error_context .= 'POSTGRES DEBUG INFO:';
			if(count($dbhelper->query_history)){
				$error_context .= print_r($dbhelper->query_history, true);
			}
			if(count($dbhelper->last_query_params)){
				$error_context .= print_r($dbhelper->last_query_params, true);
			}
		} else {
			$this->set('err_level', 'Exception');
		}
		
		$error_context = '<pre>'.htmlentities($error_context).'</pre>';
		
		$this->set('err_code', $exception->getCode());
		$this->set('err_file', $exception->getFile());
		$this->set('err_line', $exception->getLine());
		$this->set('err_context', $error_context);
		$this->set('err_message', $exception->getMessage());
		
		if($session_obj->get_user_id()){
			$this->set('err_usr_user_id', $session_obj->get_user_id());
		}
		
		// Use standard save method
		$this->save();
	}

	private static function sanitizeSessionData($data) {
		if (!is_array($data)) {
			return $data;
		}
		
		$safe_data = $data;
		
		// Remove sensitive keys
		$sensitive_keys = ['password', 'token', 'api_key', 'secret', 'credit_card', 'cvv', 'pin'];
		
		foreach ($sensitive_keys as $key) {
			if (isset($safe_data[$key])) {
				$safe_data[$key] = '[REDACTED]';
			}
			// Also check with different cases and patterns
			foreach ($safe_data as $k => $v) {
				if (stripos($k, $key) !== false) {
					$safe_data[$k] = '[REDACTED]';
				}
			}
		}
		
		// Handle nested arrays recursively
		array_walk_recursive($safe_data, function(&$value, $k) use ($sensitive_keys) {
			foreach ($sensitive_keys as $key) {
				if (stripos($k, $key) !== false) {
					$value = '[REDACTED]';
				}
			}
		});
		
		return $safe_data;
	}
		
}

class MultiGeneralError extends SystemMultiBase {
	protected static $model_class = 'GeneralError';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['user_id'])) {
			$filters['err_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}

		return $this->_get_resultsv2('err_general_errors', $filters, $this->order_by, $only_count, $debug);
	}

}

?>
