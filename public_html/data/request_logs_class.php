<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class RequestLogException extends SystemBaseException {}

class RequestLog extends SystemBase {
	public static $prefix = 'rql';

	// REST API: audit/log table — admin-only (permission >= 5) read and write via the API; not user-scoped content.
	function authenticate_read($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError('Current user does not have permission to view this entry in '. static::$tablename);
		}
	}

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError('Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}
	public static $tablename = 'rql_request_logs';
	public static $pkey_column = 'rql_request_log_id';

	protected static $foreign_key_actions = [
		'rql_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED],
	];

	// Retention: the daily sweep deletes rows older than the window.
	// 0 in the setting means never purge. See docs/scheduled_tasks.md.
	public static $retention_policy = array(
		'label'          => 'Request log',
		'age_column'     => 'rql_create_time',
		'age_unit'       => 'days',
		'window_setting' => 'request_log_retention_days',
	);

	/**
	 * Field specifications define database column properties and validation rules
	 *
	 * @version 1.0
	 */
	public static $field_specifications = array(
		'rql_request_log_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'rql_feature'        => array('type'=>'varchar(50)', 'is_nullable'=>false),
		'rql_action'         => array('type'=>'varchar(100)'),
		'rql_ip_address'     => array('type'=>'varchar(45)', 'is_nullable'=>false),
		'rql_usr_user_id'    => array('type'=>'int4'),
		'rql_was_success'    => array('type'=>'bool', 'is_nullable'=>false, 'default'=>true),
		'rql_status_code'    => array('type'=>'int2'),
		'rql_error_type'     => array('type'=>'varchar(50)'),
		'rql_note'           => array('type'=>'varchar(255)'),
		'rql_api_key_type'   => array('type'=>'varchar(16)'),
		'rql_response_ms'    => array('type'=>'int4'),
		'rql_create_time'    => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	public static $timestamp_fields = array('rql_create_time');
}

class MultiRequestLog extends SystemMultiBase {
	protected static $model_class = 'RequestLog';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['feature'])) {
			$filters['rql_feature'] = [$this->options['feature'], PDO::PARAM_STR];
		}

		if (isset($this->options['user_id'])) {
			$filters['rql_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['ip_address'])) {
			$filters['rql_ip_address'] = [$this->options['ip_address'], PDO::PARAM_STR];
		}

		if (isset($this->options['was_success'])) {
			$filters['rql_was_success'] = $this->options['was_success'] ? "= TRUE" : "= FALSE";
		}

		return $this->_get_resultsv2('rql_request_logs', $filters, $this->order_by, $only_count, $debug);
	}
}

?>
