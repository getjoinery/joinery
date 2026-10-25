<?php
/**
 * SandboxLog and MultiSandboxLog — what each call to a sandboxed plugin did.
 *
 * Core writes one row per call it makes to the plugin host (SandboxHost): what
 * the plugin was asked (a UI handler, a signal, a scheduled run, a test), how
 * it ended, how long it took, and the host's record of every door the module
 * used during the call — each API request and outbound request it made, and
 * each one refused with the reason. It is the per-plugin log an author reads
 * (plugin_tiers.md WP8) and an admin checks when a plugin misbehaves.
 *
 * @version 1.0
 */

class SandboxLogException extends SystemBaseException {}

class SandboxLog extends SystemBase {
	public static $prefix = 'sbl';
	public static $tablename = 'sbl_sandbox_logs';
	public static $access_class = 'restricted';
	public static $pkey_column = 'sbl_sandbox_log_id';

	const KIND_UI       = 'ui';
	const KIND_SIGNAL   = 'signal';
	const KIND_SCHEDULE = 'schedule';
	const KIND_TEST     = 'test';

	const STATUS_OK          = 'ok';
	const STATUS_ERROR       = 'error';
	const STATUS_TIMEOUT     = 'timeout';
	/** The host was not running or not reachable. */
	const STATUS_UNAVAILABLE = 'unavailable';
	/** Both UI call slots were in use. */
	const STATUS_BUSY        = 'busy';

	public static $retention_policy = array(
		'label'          => 'Sandboxed plugin call log',
		'age_column'     => 'sbl_create_time',
		'age_unit'       => 'days',
		'window_setting' => 'sandbox_log_retention_days',
	);

	protected static $foreign_key_actions = array(
		'sbl_usr_user_id' => array('action' => 'null'),
	);

	public static $field_specifications = array(
		'sbl_sandbox_log_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'sbl_plugin'         => array('type' => 'varchar(20)', 'is_nullable' => false, 'required' => true,
			'index_with' => array('sbl_create_time')),
		'sbl_kind'           => array('type' => 'varchar(20)', 'is_nullable' => false, 'required' => true,
			'allowed_values' => array(self::KIND_UI, self::KIND_SIGNAL, self::KIND_SCHEDULE, self::KIND_TEST)),
		'sbl_name'           => array('type' => 'varchar(100)'),
		'sbl_status'         => array('type' => 'varchar(20)', 'is_nullable' => false, 'required' => true,
			'allowed_values' => array(self::STATUS_OK, self::STATUS_ERROR, self::STATUS_TIMEOUT, self::STATUS_UNAVAILABLE, self::STATUS_BUSY)),
		'sbl_duration_ms'    => array('type' => 'int4'),
		'sbl_message'        => array('type' => 'varchar(500)'),
		// The host's record of the doors the module used: a JSON list of
		// {door, target, status, detail}.
		'sbl_calls'          => array('type' => 'jsonb'),
		// Who asked, for a UI call made from a page.
		'sbl_usr_user_id'    => array('type' => 'int4'),
		'sbl_create_time'    => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	public static $test_fixture = array(
		'values' => array('sbl_kind' => self::KIND_TEST, 'sbl_status' => self::STATUS_OK, 'sbl_calls' => '[]'),
		'update_field' => 'sbl_message',
	);

	/** @return array[] the door calls the host recorded */
	public function calls(): array {
		$calls = $this->get_json_decoded('sbl_calls');
		return is_array($calls) ? $calls : array();
	}
}

class MultiSandboxLog extends SystemMultiBase {
	protected static $model_class = 'SandboxLog';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['plugin'])) {
			$filters['sbl_plugin'] = array($this->options['plugin'], PDO::PARAM_STR);
		}
		if (isset($this->options['kind'])) {
			$filters['sbl_kind'] = array($this->options['kind'], PDO::PARAM_STR);
		}
		if (isset($this->options['status'])) {
			$filters['sbl_status'] = array($this->options['status'], PDO::PARAM_STR);
		}
		return $this->_get_resultsv2('sbl_sandbox_logs', $filters, $this->order_by, $only_count, $debug);
	}
}
