<?php
/**
 * EmailForwardingLog - Records all forwarding transactions.
 * Also used for rate limiting by counting recent entries.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class EmailForwardingLogException extends SystemBaseException {}

class EmailForwardingLog extends SystemBase {
	public static $prefix = 'efl';
	public static $tablename = 'efl_email_forwarding_logs';
	public static $pkey_column = 'efl_email_forwarding_log_id';

	// Status constants
	const STATUS_FORWARDED = 'forwarded';
	const STATUS_REJECTED = 'rejected';
	const STATUS_DISCARDED = 'discarded';
	const STATUS_RATE_LIMITED = 'rate_limited';
	const STATUS_BOUNCE_FORWARDED = 'bounce_forwarded';
	const STATUS_ERROR = 'error';

	protected static $foreign_key_actions = [
		'efl_efa_email_forwarding_alias_id' => ['action' => 'null'],
	];

	public static $field_specifications = array(
		'efl_email_forwarding_log_id'          => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'efl_efa_email_forwarding_alias_id'    => array('type'=>'int4'),
		'efl_from_address'     => array('type'=>'varchar(500)'),
		'efl_to_address'       => array('type'=>'varchar(500)'),
		'efl_subject'          => array('type'=>'varchar(1000)'),
		'efl_destinations'     => array('type'=>'text'),
		'efl_status'           => array('type'=>'varchar(50)', 'is_nullable'=>false),
		'efl_error_message'    => array('type'=>'text'),
		'efl_create_time'      => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'efl_delete_time'      => array('type'=>'timestamp(6)'),
	);

	/**
	 * Create a log entry from forwarding data.
	 */
	static function CreateEntry($from, $to, $subject, $destinations, $status, $alias_id = null, $error = null) {
		$log = new EmailForwardingLog(NULL);
		$log->set('efl_from_address', substr($from, 0, 500));
		$log->set('efl_to_address', substr($to, 0, 500));
		$log->set('efl_subject', substr($subject, 0, 1000));
		$log->set('efl_destinations', $destinations);
		$log->set('efl_status', $status);
		if ($alias_id) {
			$log->set('efl_efa_email_forwarding_alias_id', $alias_id);
		}
		if ($error) {
			$log->set('efl_error_message', $error);
		}
		$log->save();
		return $log;
	}
}

class MultiEmailForwardingLog extends SystemMultiBase {
	protected static $model_class = 'EmailForwardingLog';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['alias_id'])) {
			$filters['efl_efa_email_forwarding_alias_id'] = [$this->options['alias_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['status'])) {
			$filters['efl_status'] = [$this->options['status'], PDO::PARAM_STR];
		}

		if (isset($this->options['domain_id'])) {
			// Join through alias table to filter by domain
			$filters['efl_efa_email_forwarding_alias_id'] = "IN (SELECT efa_email_forwarding_alias_id FROM efa_email_forwarding_aliases WHERE efa_efd_email_forwarding_domain_id = " . intval($this->options['domain_id']) . ")";
		}

		if (isset($this->options['deleted'])) {
			$filters['efl_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('efl_email_forwarding_logs', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
