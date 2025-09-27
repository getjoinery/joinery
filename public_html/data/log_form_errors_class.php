<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/FieldConstraints.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class FormErrorException extends SystemBaseException {}

class FormError extends SystemBase {	public static $prefix = 'lfe';
	public static $tablename = 'lfe_log_form_errors';
	public static $pkey_column = 'lfe_log_form_error_id';
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
	    'lfe_log_form_error_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'lfe_error' => array('type'=>'text'),
	    'lfe_usr_user_id' => array('type'=>'int4'),
	    'lfe_log_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'lfe_user_agent' => array('type'=>'varchar(255)'),
	    'lfe_page' => array('type'=>'varchar(100)'),
	    'lfe_url' => array('type'=>'varchar(255)'),
	    'lfe_form' => array('type'=>'text'),
	    'lfe_context' => array('type'=>'varchar(255)'),
	);

	public static $field_constraints = array();

	function display_time($session) {
		return LibraryFunctions::convert_time(
			$this->get('lfe_log_time'), 'UTC', $session->get_timezone(), '%a, %d %b %Y %R:%S');
	}	

	public static function LogFormError($session, $request) { 
		$obj = new FormError(NULL);
		$obj->set('lfe_usr_user_id', $session->get_user_id());
		$obj->set('lfe_error', $request['messages']);
		$obj->set('lfe_log_time', 'NOW()');
		$obj->set('lfe_page', $request['page']);
		$obj->set('lfe_url', $request['url']);
		$obj->set('lfe_form', $request['formfields']);
		$obj->set('lfe_context', $request['context']);
		$obj->set('lfe_user_agent', $_SERVER['HTTP_USER_AGENT']);
		$obj->save();
	}

}

class MultiFormError extends SystemMultiBase {
	protected static $model_class = 'FormError';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['user_id'])) {
			$filters['lfe_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}

		return $this->_get_resultsv2('lfe_log_form_errors', $filters, $this->order_by, $only_count, $debug);
	}

}

?>
