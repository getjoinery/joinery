<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

class FormErrorException extends SystemClassException {}

class FormError extends SystemBase {	public static $prefix = 'lfe';
	public static $tablename = 'lfe_log_form_errors';
	public static $pkey_column = 'lfe_log_form_error_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(		'lfe_error' => 'error',
		'lfe_usr_user_id' => 'User this lfe_log_form_error is associated with',
		'lfe_log_time' => 'Time added',
		'lfe_user_agent' => 'User Agent string',
		'lfe_page' => 'The page this log form error occured on',
		'lfe_url' => 'The URL of the page this happened on',
		'lfe_form' => 'The full form',
		'lfe_context' => 'The DOM selector form the form (in case more than one form on the page)',
	);
	
	/**
	 * Field specifications define database column properties and schema constraints
	 * Available options:
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp(6)' | 'numeric(10,2)' | 'bool' | etc.
	 *   'serial' => true/false - Auto-incrementing field
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'unique' => true - Field must be unique (single field constraint)
	 *   'unique_with' => array('field1', 'field2') - Composite unique constraint with other fields
	 */
	public static $field_specifications = array(
		'lfe_log_form_error_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'lfe_error' => array('type'=>'text'),
		'lfe_usr_user_id' => array('type'=>'int4'),
		'lfe_log_time' => array('type'=>'timestamp(6)'),
		'lfe_user_agent' => array('type'=>'varchar(255)'),
		'lfe_page' => array('type'=>'varchar(100)'),
		'lfe_url' =>  array('type'=>'varchar(255)'),
		'lfe_form' =>  array('type'=>'text'),
		'lfe_context' =>  array('type'=>'varchar(255)'),
	);

	public static $required_fields = array();
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	'lfe_log_time'=> 'now()',);

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
