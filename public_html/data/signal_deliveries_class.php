<?php
/**
 * SignalDelivery and MultiSignalDelivery
 *
 * One signal owed to one receiver. SignalDeliveryQueue writes the row inline
 * when the signal fires (the only work done in the producing request) and the
 * SignalDeliveryDrain task sends it, retrying on failure with growing waits
 * until it is delivered or the attempts run out. Delivered and given-up rows
 * stay visible on the receiver's page until the retention sweep removes them.
 * See docs/webhooks.md.
 *
 * @version 1.0
 */

class SignalDeliveryException extends SystemBaseException {}

class SignalDelivery extends SystemBase {
	public static $prefix = 'sgd';
	public static $tablename = 'sgd_signal_deliveries';
	public static $access_class = 'config';
	public static $pkey_column = 'sgd_signal_delivery_id';

	/** Waiting for its first or next attempt. */
	const STATUS_PENDING = 'pending';
	/** The receiver accepted it (a 2xx answer). */
	const STATUS_DELIVERED = 'delivered';
	/** Every attempt failed; no more will be made. */
	const STATUS_FAILED = 'failed';
	/**
	 * Not sent, and never will be: the signal fired in a request that had
	 * opened sealed content, so its payload was not written down at all.
	 */
	const STATUS_WITHHELD = 'withheld';

	/**
	 * Seconds to wait after each failed attempt. Its length is the number of
	 * retries: the first attempt plus these seven is eight in all, spread over
	 * about two days, after which the row is given up.
	 */
	const RETRY_DELAYS = array(60, 300, 1800, 7200, 21600, 43200, 86400);

	/**
	 * The type of the test message the receiver's page sends on request. It
	 * goes to that one receiver only and is never dispatched on the bus.
	 */
	const TEST_SIGNAL = 'webhook.test';

	public static $retention_policy = array(
		'label'          => 'Webhook deliveries',
		'age_column'     => 'sgd_create_time',
		'age_unit'       => 'days',
		// A row still owed to its receiver is never swept, however old.
		'only_where'     => "sgd_status <> 'pending'",
		'window_setting' => 'signal_delivery_retention_days',
	);

	protected static $foreign_key_actions = array(
		'sgd_sgr_signal_receiver_id' => array('action' => 'cascade'),
	);

	public static $field_specifications = array(
		'sgd_signal_delivery_id'     => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'sgd_sgr_signal_receiver_id' => array('type' => 'int8', 'is_nullable' => false, 'required' => true,
			'index_with' => array('sgd_create_time'),
			'foreign_key' => array('table' => 'sgr_signal_receivers', 'column' => 'sgr_signal_receiver_id', 'on_delete' => 'CASCADE')),
		'sgd_signal'                 => array('type' => 'varchar(100)', 'is_nullable' => false, 'required' => true),
		// The same id for every receiver of one dispatch, and across retries,
		// so a receiver can tell a resend from a new event.
		'sgd_message_id'             => array('type' => 'varchar(64)', 'is_nullable' => false, 'required' => true),
		// The signal's payload as dispatched. NULL only on a withheld row.
		'sgd_payload'                => array('type' => 'jsonb'),
		'sgd_status'                 => array('type' => 'varchar(20)', 'is_nullable' => false, 'required' => true,
			'default' => self::STATUS_PENDING,
			'allowed_values' => array(self::STATUS_PENDING, self::STATUS_DELIVERED, self::STATUS_FAILED, self::STATUS_WITHHELD)),
		'sgd_attempts'               => array('type' => 'int2', 'is_nullable' => false, 'zero_on_create' => true),
		'sgd_next_attempt_time'      => array('type' => 'timestamp(6)'),
		'sgd_last_attempt_time'      => array('type' => 'timestamp(6)'),
		'sgd_last_response_code'     => array('type' => 'int4'),
		'sgd_last_error'             => array('type' => 'varchar(500)'),
		'sgd_delivered_time'         => array('type' => 'timestamp(6)'),
		'sgd_create_time'            => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	// The drain's one question: what is due now?
	public static $index_specifications = array(
		array('columns' => array('sgd_next_attempt_time'), 'where' => "sgd_status = 'pending'"),
	);

	public static $test_fixture = array(
		'values' => array('sgd_status' => self::STATUS_PENDING, 'sgd_payload' => '{}'),
		'update_field' => 'sgd_last_error',
	);

	/** How long to wait after the given number of failed attempts, or null to give up. */
	public static function retry_delay(int $attempts_made): ?int {
		$index = $attempts_made - 1;
		if ($index < 0) {
			return 0;
		}
		return self::RETRY_DELAYS[$index] ?? null;
	}

	public static function max_attempts(): int {
		return count(self::RETRY_DELAYS) + 1;
	}

	public static function new_message_id(): string {
		return 'msg_' . bin2hex(random_bytes(12));
	}

	public static function status_label(string $status): string {
		switch ($status) {
			case self::STATUS_PENDING:   return 'Waiting';
			case self::STATUS_DELIVERED: return 'Delivered';
			case self::STATUS_FAILED:    return 'Gave up';
			case self::STATUS_WITHHELD:  return 'Withheld';
		}
		return $status;
	}

	/**
	 * Per receiver: how many deliveries are waiting, how many were given up,
	 * and when one last got through. Receivers with no deliveries are absent.
	 *
	 * @param int[] $receiver_ids
	 * @return array<int,array{waiting:int,given_up:int,last_delivered:?string}>
	 */
	public static function receiver_summaries(array $receiver_ids): array {
		$receiver_ids = array_values(array_filter(array_map('intval', $receiver_ids)));
		if (empty($receiver_ids)) {
			return array();
		}
		$placeholders = implode(',', array_fill(0, count($receiver_ids), '?'));
		$q = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT sgd_sgr_signal_receiver_id AS id,
			        COUNT(*) FILTER (WHERE sgd_status = 'pending') AS waiting,
			        COUNT(*) FILTER (WHERE sgd_status = 'failed') AS given_up,
			        MAX(sgd_delivered_time) AS last_delivered
			   FROM sgd_signal_deliveries
			  WHERE sgd_sgr_signal_receiver_id IN ($placeholders)
			  GROUP BY sgd_sgr_signal_receiver_id");
		$q->execute($receiver_ids);
		$out = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$out[(int)$row['id']] = array(
				'waiting'        => (int)$row['waiting'],
				'given_up'       => (int)$row['given_up'],
				'last_delivered' => $row['last_delivered'],
			);
		}
		return $out;
	}

	/** Put a given-up delivery back in line for a fresh set of attempts. */
	public function retry_now(): void {
		if ($this->get('sgd_status') !== self::STATUS_FAILED) {
			throw new SignalDeliveryException('Only a delivery that was given up can be sent again.');
		}
		$this->set('sgd_status', self::STATUS_PENDING);
		$this->set('sgd_attempts', 0);
		$this->set('sgd_next_attempt_time', gmdate('Y-m-d H:i:s'));
		$this->save();
	}
}

class MultiSignalDelivery extends SystemMultiBase {
	protected static $model_class = 'SignalDelivery';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['receiver_id'])) {
			$filters['sgd_sgr_signal_receiver_id'] = array($this->options['receiver_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['status'])) {
			$filters['sgd_status'] = array($this->options['status'], PDO::PARAM_STR);
		}

		return $this->_get_resultsv2('sgd_signal_deliveries', $filters, $this->order_by, $only_count, $debug);
	}
}
