<?php
/**
 * InboundEmailLog - Records all inbound email transactions.
 * Also used for rate limiting by counting recent entries.
 *
 * @version 1.2
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundEmailLogException extends SystemBaseException {}

class InboundEmailLog extends SystemBase {
	public static $prefix = 'iel';
	public static $tablename = 'iel_inbound_email_logs';
	public static $pkey_column = 'iel_inbound_email_log_id';

	// Status constants
	const STATUS_FORWARDED = 'forwarded';
	const STATUS_REJECTED = 'rejected';
	const STATUS_DISCARDED = 'discarded';
	const STATUS_RATE_LIMITED = 'rate_limited';
	const STATUS_BOUNCE_FORWARDED = 'bounce_forwarded';
	const STATUS_ERROR = 'error';

	protected static $foreign_key_actions = [
		'iel_iea_inbound_email_alias_id' => ['action' => 'null'],
	];

	public static $field_specifications = array(
		'iel_inbound_email_log_id'        => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'iel_iea_inbound_email_alias_id'  => array('type'=>'int4'),
		'iel_from_address'     => array('type'=>'varchar(500)'),
		'iel_to_address'       => array('type'=>'varchar(500)'),
		'iel_subject'          => array('type'=>'varchar(1000)'),
		'iel_destinations'     => array('type'=>'text'),
		'iel_status'           => array('type'=>'varchar(50)', 'is_nullable'=>false),
		'iel_error_message'    => array('type'=>'text'),
		'iel_create_time'      => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'iel_delete_time'      => array('type'=>'timestamp(6)'),
	);

	/**
	 * Create a log entry from inbound email data.
	 */
	static function CreateEntry($from, $to, $subject, $destinations, $status, $alias_id = null, $error = null) {
		$log = new InboundEmailLog(NULL);
		$log->set('iel_from_address', substr($from, 0, 500));
		$log->set('iel_to_address', substr($to, 0, 500));
		$log->set('iel_subject', substr($subject, 0, 1000));
		$log->set('iel_destinations', $destinations);
		$log->set('iel_status', $status);
		if ($alias_id) {
			$log->set('iel_iea_inbound_email_alias_id', $alias_id);
		}
		if ($error) {
			$log->set('iel_error_message', $error);
		}
		$log->save();
		return $log;
	}
}

class MultiInboundEmailLog extends SystemMultiBase {
	protected static $model_class = 'InboundEmailLog';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['alias_id'])) {
			$filters['iel_iea_inbound_email_alias_id'] = [$this->options['alias_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['status'])) {
			$filters['iel_status'] = [$this->options['status'], PDO::PARAM_STR];
		}

		if (isset($this->options['domain_id'])) {
			// Join through alias table to filter by domain
			$filters['iel_iea_inbound_email_alias_id'] = "IN (SELECT iea_inbound_email_alias_id FROM iea_inbound_email_aliases WHERE iea_ied_inbound_email_domain_id = " . intval($this->options['domain_id']) . ")";
		}

		if (isset($this->options['deleted'])) {
			$filters['iel_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('iel_inbound_email_logs', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
