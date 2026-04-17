<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class RequestLogException extends SystemBaseException {}

class RequestLog extends SystemBase {
	public static $prefix = 'rql';
	public static $tablename = 'rql_request_logs';
	public static $pkey_column = 'rql_request_log_id';
	public static $permanent_delete_actions = array();

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
		'rql_was_success'    => array('type'=>'bool', 'is_nullable'=>false, 'default'=>'true'),
		'rql_status_code'    => array('type'=>'int2'),
		'rql_error_type'     => array('type'=>'varchar(50)'),
		'rql_note'           => array('type'=>'varchar(255)'),
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
