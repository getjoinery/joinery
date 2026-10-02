<?php
/**
 * InstanceTransfer — one Managed site's server being handed to its customer's
 * own Linode account (specs/managed_to_self_hosted_transfer.md §4, §7).
 *
 * The instance moves running, with its disks and addresses, through the
 * provider's Service Transfer: we create a code, the customer redeems it in
 * their own Cloud Manager, the provider moves the instance. This row is the
 * record of that from the first ask to the finish.
 *
 * The states:
 *
 *   requested    the customer asked from Your sites; the operator has not
 *                started it yet.
 *   invited      the operator started it; the customer has been told how to
 *                get ready and has not asked for a code (or their code ran
 *                out and they have not asked for another).
 *   code_issued  a transfer code is out; itx_token_expiry says until when.
 *   accepted     the customer redeemed it. Nothing can stop it now.
 *   finishing    the provider says it is done; the finishing pass is working
 *                through its steps (itx_finish_step is the last one done).
 *   done         finished: the site is self-hosted, its mail and backups a
 *                Services subscription.
 *   failed       the provider failed the transfer. The operator fixes the
 *                cause and issues a new code, or cancels.
 *   canceled     withdrawn before it was accepted.
 *
 * THE CODE IS A BEARER SECRET for the whole machine. It is sealed here, shown
 * only on the customer's own signed-in page, and never put in an email, a job,
 * a log line or an error. It is kept while it is the handle the provider's
 * status is asked by — code_issued and accepted — and erased the moment the
 * row is anywhere else.
 *
 * @version 1.1 - the state options combine instead of the last one replacing the rest; the queue's last
 *                email reads customer emails only
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InstanceTransferException extends SystemBaseException {}

class InstanceTransfer extends SystemBase {
	public static $prefix = 'itx';
	public static $tablename = 'itx_instance_transfers';
	public static $pkey_column = 'itx_instance_transfer_id';

	public static $json_vars = array('itx_check_result', 'itx_email_times');

	const STATE_REQUESTED   = 'requested';
	const STATE_INVITED     = 'invited';
	const STATE_CODE_ISSUED = 'code_issued';
	const STATE_ACCEPTED    = 'accepted';
	const STATE_FINISHING   = 'finishing';
	const STATE_DONE        = 'done';
	const STATE_FAILED      = 'failed';
	const STATE_CANCELED    = 'canceled';

	const STATES = array(
		self::STATE_REQUESTED, self::STATE_INVITED, self::STATE_CODE_ISSUED, self::STATE_ACCEPTED,
		self::STATE_FINISHING, self::STATE_DONE, self::STATE_FAILED, self::STATE_CANCELED,
	);

	/** A row in one of these is the provision's one open transfer. */
	const OPEN_STATES = array(
		self::STATE_REQUESTED, self::STATE_INVITED, self::STATE_CODE_ISSUED, self::STATE_ACCEPTED,
		self::STATE_FINISHING, self::STATE_FAILED,
	);

	/** The states the poll asks the provider about (finishing is worked, not asked). */
	const POLLED_STATES = array(self::STATE_CODE_ISSUED, self::STATE_ACCEPTED, self::STATE_FINISHING);

	/** The states in which the code is still held: it is what the provider's status is asked by. */
	const TOKEN_STATES = array(self::STATE_CODE_ISSUED, self::STATE_ACCEPTED);

	/** What each state says, to the customer and on the queue. */
	const STATE_LABELS = array(
		self::STATE_REQUESTED   => 'Requested — waiting for us to start it',
		self::STATE_INVITED     => 'Ready when you are',
		self::STATE_CODE_ISSUED => 'Transfer code issued',
		self::STATE_ACCEPTED    => 'Accepted — Linode is moving the server',
		self::STATE_FINISHING   => 'Moved — finishing up',
		self::STATE_DONE        => 'Done — the server is in your Linode account',
		self::STATE_FAILED      => 'Linode could not finish the move — we are looking into it',
		self::STATE_CANCELED    => 'Canceled',
	);

	protected static $foreign_key_actions = array(
		'itx_cvp_customer_cloud_provision_id' => array('action' => 'cascade'),
		'itx_mgn_managed_node_id'             => array('action' => 'null'),
		// The buyer's account going does not undo a move that happened: the
		// row stays as the record of it, naming nobody.
		'itx_usr_user_id'                     => array('action' => 'null'),
	);

	public static $test_fixture = array(
		'values'       => array('itx_state' => 'requested', 'itx_instance_id' => '1'),
		'update_field' => 'itx_error',
	);

	public static $field_specifications = array(
		'itx_instance_transfer_id'            => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'itx_cvp_customer_cloud_provision_id' => array('type'=>'int8', 'required'=>true, 'is_nullable'=>false),
		'itx_mgn_managed_node_id'             => array('type'=>'int8'),
		'itx_usr_user_id'                     => array('type'=>'int8'),
		// The provider's instance id, copied at the start: the provision's own
		// column is what the finish compares against, and the record of which
		// machine moved has to outlive any edit to it.
		'itx_instance_id'      => array('type'=>'varchar(50)', 'is_nullable'=>false),
		'itx_state'            => array('type'=>'varchar(16)', 'is_nullable'=>false, 'default'=>'requested',
			'allowed_values'=>array('requested', 'invited', 'code_issued', 'accepted', 'finishing', 'done', 'failed', 'canceled')),
		'itx_requested_by'     => array('type'=>'varchar(10)', 'is_nullable'=>false, 'default'=>'operator',
			'allowed_values'=>array('operator', 'customer')),
		// The code, sealed (SecretBox). Held only in code_issued and accepted.
		'itx_token_sealed'     => array('type'=>'text'),
		'itx_token_expiry'     => array('type'=>'timestamp(6)'),
		'itx_code_issued_time' => array('type'=>'timestamp(6)'),
		// What the provider last said and when, as read by the poll.
		'itx_linode_status'       => array('type'=>'varchar(16)'),
		'itx_linode_checked_time' => array('type'=>'timestamp(6)'),
		'itx_accepted_time'       => array('type'=>'timestamp(6)'),
		// The last eligibility check, whole: {checked_time, blockers, warnings, items:[…]}.
		'itx_check_result'     => array('type'=>'jsonb'),
		// The finish's progress: the last step that completed, so a crash
		// resumes at the next one.
		'itx_finish_step'      => array('type'=>'varchar(24)'),
		'itx_completed_time'   => array('type'=>'timestamp(6)'),
		// The day the hosting was paid up to, carried to the Services rows.
		'itx_paid_until'       => array('type'=>'timestamp(6)'),
		// Something only a person can do, and the queue keeps the row flagged
		// until it is ticked off (a remote store's subscription to cancel).
		'itx_operator_todo'    => array('type'=>'text'),
		// The site was shut down for non-payment when this started: its
		// deletion was withdrawn, and the finish brings it back on our books.
		'itx_deletion_withdrawn' => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		'itx_error'            => array('type'=>'text'),
		// Which emails went, and when: {invite, code_ready, code_expired, done, failed, …}.
		'itx_email_times'      => array('type'=>'jsonb'),
		'itx_create_time'      => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'itx_update_time'      => array('type'=>'timestamp(6)'),
		'itx_delete_time'      => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		$this->set('itx_update_time', gmdate('Y-m-d H:i:s'));
	}

	function save($debug = false) {
		if (!(int)$this->get('itx_cvp_customer_cloud_provision_id')) {
			throw new InstanceTransferException('A transfer belongs to a provision.');
		}
		$state = (string)($this->get('itx_state') ?: self::STATE_REQUESTED);
		if (!in_array($state, self::STATES, true)) {
			throw new InstanceTransferException("Unknown transfer state '{$state}'.");
		}
		// The code lives only while the provider's status is asked by it.
		if (!in_array($state, self::TOKEN_STATES, true) && trim((string)$this->get('itx_token_sealed')) !== '') {
			$this->set('itx_token_sealed', null);
		}
		$this->set('itx_update_time', gmdate('Y-m-d H:i:s'));
		return parent::save($debug);
	}

	public function state(): string {
		return (string)($this->get('itx_state') ?: self::STATE_REQUESTED);
	}

	public function is_open(): bool {
		return in_array($this->state(), self::OPEN_STATES, true) && !$this->get('itx_delete_time');
	}

	public function state_label(): string {
		return self::STATE_LABELS[$this->state()] ?? $this->state();
	}

	/** Can the code still be withdrawn? Only before the customer redeems it. */
	public function cancelable(): bool {
		return in_array($this->state(), array(self::STATE_REQUESTED, self::STATE_INVITED,
			self::STATE_CODE_ISSUED, self::STATE_FAILED), true);
	}

	/** Seal the code onto the row. Does not save. */
	public function seal_token(string $token): void {
		$this->set('itx_token_sealed', (new SecretBox())->seal('itx_instance_transfers.itx_token_sealed', $token));
	}

	/** The code, or '' when there is none or it cannot be read. */
	public function open_token(): string {
		$sealed = trim((string)$this->get('itx_token_sealed'));
		if ($sealed === '') {
			return '';
		}
		$opened = (new SecretBox())->open($sealed);
		return $opened['state'] === 'ok' ? (string)$opened['value'] : '';
	}

	/** When an email of this kind last went, or ''. */
	public function email_time(string $kind): string {
		$times = $this->get('itx_email_times');
		if (is_string($times)) { $times = json_decode($times, true); }
		return is_array($times) ? (string)($times[$kind] ?? '') : '';
	}

	/** Note that an email of this kind went now. Does not save. */
	public function mark_emailed(string $kind): void {
		$times = $this->get('itx_email_times');
		if (is_string($times)) { $times = json_decode($times, true); }
		$times = is_array($times) ? $times : array();
		$times[$kind] = gmdate('Y-m-d H:i:s');
		$this->set('itx_email_times', $times);
	}

	/** The emails a customer is sent; the other marks in itx_email_times are one-time acts. */
	const CUSTOMER_EMAILS = array('invite', 'code_ready', 'code_expired', 'failed', 'done');

	/** The newest customer email sent: [kind, time], or ['', '']. */
	public function last_email(): array {
		$times = $this->get('itx_email_times');
		if (is_string($times)) { $times = json_decode($times, true); }
		$times = is_array($times) ? array_intersect_key($times, array_flip(self::CUSTOMER_EMAILS)) : array();
		if (!$times) {
			return array('', '');
		}
		arsort($times);
		$kind = (string)array_key_first($times);
		return array($kind, (string)$times[$kind]);
	}

	/** The last eligibility check, or null. */
	public function check_result(): ?array {
		$r = $this->get('itx_check_result');
		if (is_string($r)) { $r = json_decode($r, true); }
		return is_array($r) ? $r : null;
	}

	/** The provision's one open transfer, or null. */
	public static function open_for_provision(int $provision_id): ?InstanceTransfer {
		if ($provision_id <= 0) {
			return null;
		}
		$rows = new MultiInstanceTransfer(array('provision_id' => $provision_id, 'open' => true, 'deleted' => false),
			array('itx_instance_transfer_id' => 'DESC'), 1);
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}

	/** The provision's newest transfer of any state, or null. */
	public static function latest_for_provision(int $provision_id): ?InstanceTransfer {
		if ($provision_id <= 0) {
			return null;
		}
		$rows = new MultiInstanceTransfer(array('provision_id' => $provision_id, 'deleted' => false),
			array('itx_instance_transfer_id' => 'DESC'), 1);
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}
}

class MultiInstanceTransfer extends SystemMultiBase {
	protected static $model_class = 'InstanceTransfer';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['provision_id'])) {
			$filters['itx_cvp_customer_cloud_provision_id'] = array((int)$this->options['provision_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['node_id'])) {
			$filters['itx_mgn_managed_node_id'] = array((int)$this->options['node_id'], PDO::PARAM_INT);
		}
		// Every state option narrows the same column, so they are combined
		// into one list rather than the last one quietly replacing the others.
		$states = null;
		$narrow = function (array $set) use (&$states) {
			$states = $states === null ? $set : array_values(array_intersect($states, $set));
		};
		if (isset($this->options['state'])) {
			$narrow(array((string)$this->options['state']));
		}
		if (isset($this->options['states']) && is_array($this->options['states']) && count($this->options['states'])) {
			$narrow($this->options['states']);
		}
		if (!empty($this->options['open'])) {
			$narrow(InstanceTransfer::OPEN_STATES);
		}
		if (!empty($this->options['polled'])) {
			$narrow(InstanceTransfer::POLLED_STATES);
		}
		if ($states === array()) {
			$states = array('none');   // the options exclude each other: no row matches
		}
		if ($states !== null) {
			$quoted = array_map(function ($s) {
				return "'" . preg_replace('/[^a-z_]/', '', $s) . "'";
			}, $states);
			$filters['itx_state'] = 'IN (' . implode(',', $quoted) . ')';
		}

		return $this->_get_resultsv2('itx_instance_transfers', $filters, $this->order_by, $only_count, $debug);
	}
}
