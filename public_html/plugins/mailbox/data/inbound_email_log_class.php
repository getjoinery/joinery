<?php
/**
 * InboundEmailLog - Records all inbound email transactions.
 * Also used for rate limiting by counting recent entries.
 *
 * @version 1.5
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundEmailLogException extends SystemBaseException {}

class InboundEmailLog extends SystemBase {
	public static $prefix = 'iel';
	public static $tablename = 'iel_inbound_email_logs';
	public static $pkey_column = 'iel_inbound_email_log_id';

	// Retention: a delivery log, kept for troubleshooting rather than for the
	// mail itself (stored messages live in iem_inbound_email_messages and have
	// their own window). 0 in the setting means never purge.
	public static $retention_policy = array(
		'label'          => 'Inbound email logs',
		'age_column'     => 'iel_create_time',
		'age_unit'       => 'days',
		'window_setting' => 'mailbox_log_retention_days',
	);

	// Status constants
	const STATUS_FORWARDED = 'forwarded';
	const STATUS_REJECTED = 'rejected';
	const STATUS_DISCARDED = 'discarded';
	const STATUS_RATE_LIMITED = 'rate_limited';
	const STATUS_BOUNCE_FORWARDED = 'bounce_forwarded';
	const STATUS_ERROR = 'error';
	const STATUS_STORED = 'stored';
	const STATUS_STORE_CAPPED = 'store_capped';
	// A forward was suppressed because the message was judged spam — the platform
	// never relays spam (specs/inbound_email_spam_filtering.md).
	const STATUS_SPAM_HELD = 'spam_held';
	// One or more inbound filters matched and applied actions to a stored message
	// (specs/implemented/inbound_email_filters.md). The matched filter ids and the
	// actions taken are recorded in iel_destinations.
	const STATUS_FILTERED = 'filtered';

	protected static $foreign_key_actions = [
		'iel_iea_inbound_email_alias_id'  => ['action' => 'null'],
		'iel_ied_inbound_email_domain_id' => ['action' => 'null'],
	];

	public static $field_specifications = array(
		'iel_inbound_email_log_id'         => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'iel_iea_inbound_email_alias_id'   => array('type'=>'int4'),
		'iel_ied_inbound_email_domain_id'  => array('type'=>'int4'),
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
	 * Serves the "has mail ever arrived for this address" lookups on the Setup
	 * tab and the Accounts listing. Both filter on lower(iel_to_address) —
	 * stored addresses are genuinely mixed-case — which a plain btree on the raw
	 * column cannot answer, hence an expression index.
	 */
	public static $index_specifications = array(
		array('columns' => array('LOWER(iel_to_address)')),
	);

	/**
	 * Create a log entry from inbound email data.
	 *
	 * @param mixed $alias_id Alias id (int) or null. Catch-all stores have no alias.
	 * @param mixed $domain_id Domain id (int) or null. Populated for every transaction
	 *                         so the domain_id filter and per-domain rate limits work
	 *                         without joining through the alias table.
	 */
	static function CreateEntry($from, $to, $subject, $destinations, $status, $alias_id = null, $error = null, $domain_id = null) {
		$log = new InboundEmailLog(NULL);
		$log->set('iel_from_address', substr($from, 0, 500));
		$log->set('iel_to_address', substr($to, 0, 500));
		$log->set('iel_subject', substr($subject, 0, 1000));
		$log->set('iel_destinations', $destinations);
		$log->set('iel_status', $status);
		if ($alias_id) {
			$log->set('iel_iea_inbound_email_alias_id', $alias_id);
		}
		if ($domain_id) {
			$log->set('iel_ied_inbound_email_domain_id', $domain_id);
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
			$filters['iel_ied_inbound_email_domain_id'] = [$this->options['domain_id'], PDO::PARAM_INT];
		}


		return $this->_get_resultsv2('iel_inbound_email_logs', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
