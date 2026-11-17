<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class WebhookLogException extends SystemBaseException {}

class WebhookLog extends SystemBase {
	public static $prefix = 'wbh';
	public static $tablename = 'wbh_webhook_logs';
	public static $access_class = 'restricted';
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
	 * Was this subscription's payment already reported as failed within the
	 * lookback window? One failure email per subscription a day, however many
	 * times the provider retries the charge; another buyer's failure never
	 * counts. The event being handled is logged only after it is handled, so
	 * any matching row is an earlier one.
	 *
	 * The subscription is read from the logged payload: a Stripe invoice's
	 * 'subscription', a PayPal event's resource id.
	 *
	 * @param string $provider 'stripe' or 'paypal'
	 * @param string $event_type The payment failure event type
	 * @param string $subscription_id The provider's subscription id
	 * @param int $hours Number of hours to look back (default 24)
	 */
	public static function hasRecentPaymentFailure($provider, $event_type, $subscription_id, $hours = 24) {
		if (trim((string)$subscription_id) === '') {
			return false;
		}
		$dblink = DbConnector::get_instance()->get_db_link();
		$sql = "SELECT COUNT(*) AS cnt FROM wbh_webhook_logs
				WHERE wbh_provider = ? AND wbh_event_type = ? AND wbh_processed = TRUE
				AND wbh_create_time > NOW() - make_interval(hours => ?)
				AND (wbh_payload->>'subscription' = ? OR wbh_payload#>>'{resource,id}' = ?)";
		$q = $dblink->prepare($sql);
		$q->execute([$provider, $event_type, (int)$hours, (string)$subscription_id, (string)$subscription_id]);
		$row = $q->fetch(PDO::FETCH_ASSOC);
		return ((int)$row['cnt'] > 0);
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
