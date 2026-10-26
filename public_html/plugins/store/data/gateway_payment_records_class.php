<?php
/**
 * GatewayPaymentRecord — what a gateway payment was made for.
 *
 * A hosted payment (a Stripe Checkout session, a PayPal order or subscription)
 * is paid on the gateway's page and completed later: by the buyer's return, or
 * by the gateway's webhook when the buyer never comes back. Either completes
 * the order from this record, never from the live cart, which may have changed
 * in between. It holds the cart's plain data (items, prices, coupons, billing
 * details) and who was signed in when the payment was started, keyed by the
 * gateway's reference (the Stripe session id, or the custom_id the PayPal
 * order or subscription carries).
 *
 * It lives in the database, not the buyer's session, so a return that lands in
 * another browser session (a phone's in-app browser) and a webhook with no
 * session at all can both find it. The reference is unguessable and is verified
 * with the gateway before the record is read. gpr_session_hash says which
 * browser session started it: only that one is signed in by the completion.
 *
 * Written by the checkout's own POST and by the gateway's webhook, never by a
 * page view (ShoppingCart::record_payment()).
 *
 * @version 1.0.0
 */
class GatewayPaymentRecordException extends SystemBaseException {}

class GatewayPaymentRecord extends SystemBase {
	public static $prefix = 'gpr';
	public static $tablename = 'gpr_gateway_payment_records';
	public static $access_class = 'money';
	public static $owner_column = false;
	public static $pkey_column = 'gpr_cart_payment_record_id';

	/** Waiting for the gateway's return or webhook. */
	const STATE_OPEN = 'open';
	/** Made into its order. */
	const STATE_COMPLETED = 'completed';
	/** Paid, but refused after payment; its order is in error and the operator was told. */
	const STATE_REFUSED = 'refused';

	/**
	 * How long a record can still be completed: two days, longer than a
	 * Stripe Checkout session (a day, the default the session is made with)
	 * or a PayPal approval lives, so a payment made in a session's last
	 * minutes still finds its record.
	 */
	const MAX_AGE = 172800;

	protected static $foreign_key_actions = array(
		'gpr_ord_order_id' => array('action' => 'null'),
	);

	public static $field_specifications = array(
		'gpr_cart_payment_record_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'gpr_ref'           => array('type'=>'varchar(255)', 'required'=>true, 'unique'=>true),
		'gpr_gateway'       => array('type'=>'varchar(32)', 'required'=>true),
		'gpr_record'        => array('type'=>'text', 'required'=>true),
		'gpr_session_hash'  => array('type'=>'varchar(64)'),
		'gpr_state'         => array('type'=>'varchar(16)', 'is_nullable'=>false, 'default'=>'open'),
		'gpr_ord_order_id'  => array('type'=>'int4'),
		'gpr_create_time'   => array('type'=>'timestamp(6)', 'default'=>'now()', 'index'=>true),
	);

	public static function GetByRef(string $ref) {
		if ($ref === '') {
			return null;
		}
		$data = SingleRowFetch('gpr_gateway_payment_records', 'gpr_ref', $ref, PDO::PARAM_STR, SINGLE_ROW_ALL_COLUMNS);
		if (!$data || !isset($data->gpr_cart_payment_record_id)) {
			return null;
		}
		$record = new GatewayPaymentRecord($data->gpr_cart_payment_record_id);
		$record->load_from_data($data, array_keys(self::$field_specifications));
		return $record;
	}

	/** The record's cart data, as ShoppingCart::record_payment() wrote it. */
	public function data(): array {
		$data = json_decode((string)$this->get('gpr_record'), true);
		return is_array($data) ? $data : array();
	}

	/** Whether it can still be completed: open, and not older than MAX_AGE. */
	public function is_open(): bool {
		return $this->get('gpr_state') === self::STATE_OPEN
			&& strtotime((string)$this->get('gpr_create_time') . ' UTC') > time() - self::MAX_AGE;
	}

	/**
	 * The current browser session, as a test stands one in: a command-line run
	 * cannot set a session id once it has printed anything.
	 */
	public static $session_id_stand_in = null;

	/** The hash a record keeps of the browser session that started it ('' outside one). */
	public static function current_session_hash(): string {
		$id = self::$session_id_stand_in ?? session_id();
		return $id !== '' ? hash('sha256', $id) : '';
	}

	/** Whether the current browser session is the one that started this payment. */
	public function started_in_this_session(): bool {
		$hash = self::current_session_hash();
		return $hash !== '' && hash_equals((string)$this->get('gpr_session_hash'), $hash);
	}

	/** Remove records a week old: past MAX_AGE they can no longer be completed, and are kept a while for an operator to read. */
	public static function prune() {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("DELETE FROM gpr_gateway_payment_records WHERE gpr_create_time < now() - (? || ' seconds')::interval");
		$q->execute(array((string)(self::MAX_AGE * 7)));
	}
}

class MultiGatewayPaymentRecord extends SystemMultiBase {
	protected static $model_class = 'GatewayPaymentRecord';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['state'])) {
			$filters['gpr_state'] = array($this->options['state'], PDO::PARAM_STR);
		}
		if (isset($this->options['order_id'])) {
			$filters['gpr_ord_order_id'] = array($this->options['order_id'], PDO::PARAM_INT);
		}
		return $this->_get_resultsv2('gpr_gateway_payment_records', $filters, $this->order_by, $only_count, $debug);
	}
}
