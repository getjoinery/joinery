<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class WebhookLogException extends SystemBaseException {}

class WebhookLog extends SystemBase {
	public static $prefix = 'wbh';
	public static $tablename = 'wbh_webhook_logs';
	public static $pkey_column = 'wbh_webhook_log_id';

	public static $field_specifications = array(
		'wbh_webhook_log_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'wbh_provider' => array('type'=>'varchar(50)', 'is_nullable'=>false),
		'wbh_event_type' => array('type'=>'varchar(100)', 'is_nullable'=>false),
		'wbh_event_id' => array('type'=>'varchar(255)', 'is_nullable'=>true),
		'wbh_payload' => array('type'=>'jsonb', 'is_nullable'=>true),
		'wbh_processed' => array('type'=>'bool', 'is_nullable'=>false, 'default'=>true),
		'wbh_error_message' => array('type'=>'text', 'is_nullable'=>true),
		'wbh_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	/**
	 * Check if an event has already been processed (idempotency check)
	 * @param string $event_id The provider's event ID
	 * @return bool True if already processed
	 */
	public static function isDuplicate($event_id) {
		if (!$event_id) return false;
		$existing = self::GetByColumn('wbh_event_id', $event_id);
		return ($existing && $existing->key);
	}

	/**
	 * Log a webhook event
	 * @param string $provider 'stripe' or 'paypal'
	 * @param string $event_type The event type string
	 * @param string|null $event_id The provider's unique event ID
	 * @param mixed $payload The raw webhook payload (will be json_encoded if not string)
	 * @param bool $processed Whether the event was successfully processed
	 * @param string|null $error_message Error message if processing failed
	 * @return WebhookLog The created log entry
	 */
	public static function logEvent($provider, $event_type, $event_id = null, $payload = null, $processed = true, $error_message = null) {
		$log = new WebhookLog(NULL);
		$log->set('wbh_provider', $provider);
		$log->set('wbh_event_type', $event_type);
		$log->set('wbh_event_id', $event_id);
		if ($payload !== null) {
			$log->set('wbh_payload', is_string($payload) ? $payload : json_encode($payload));
		}
		$log->set('wbh_processed', $processed);
		$log->set('wbh_error_message', $error_message);
		$log->save();
		return $log;
	}

	/**
	 * Check if a payment failure email was recently sent for this event type
	 * Used for dedup of payment failure notification emails
	 * @param string $provider 'stripe' or 'paypal'
	 * @param string $event_type The payment failure event type
	 * @param int $hours Number of hours to look back (default 24)
	 * @return bool True if a matching event was processed in the lookback window
	 */
	public static function hasRecentPaymentFailure($provider, $event_type, $hours = 24) {
		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();
		$sql = "SELECT COUNT(*) as cnt FROM wbh_webhook_logs
				WHERE wbh_provider = ? AND wbh_event_type = ? AND wbh_processed = TRUE
				AND wbh_create_time > NOW() - INTERVAL '{$hours} hours'";
		$q = $dblink->prepare($sql);
		$q->execute([$provider, $event_type]);
		$row = $q->fetch(PDO::FETCH_ASSOC);
		return ($row['cnt'] > 1); // > 1 because current event is already logged
	}
}

class MultiWebhookLog extends SystemMultiBase {
	protected static $model_class = 'WebhookLog';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['wbh_provider'])) {
			$filters['wbh_provider'] = [$this->options['wbh_provider'], PDO::PARAM_STR];
		}

		if (isset($this->options['wbh_event_type'])) {
			$filters['wbh_event_type'] = [$this->options['wbh_event_type'], PDO::PARAM_STR];
		}

		if (isset($this->options['wbh_event_id'])) {
			$filters['wbh_event_id'] = [$this->options['wbh_event_id'], PDO::PARAM_STR];
		}

		if (isset($this->options['wbh_processed'])) {
			if ($this->options['wbh_processed'] === true) {
				$filters['wbh_processed'] = '= TRUE';
			} elseif ($this->options['wbh_processed'] === false) {
				$filters['wbh_processed'] = '= FALSE';
			}
		}

		$sorts = [];
		if (!empty($this->order_by)) {
			$sorts = $this->order_by;
		}

		return $this->_get_resultsv2('wbh_webhook_logs', $filters, $sorts, $only_count, $debug);
	}
}
?>
