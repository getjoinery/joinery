<?php
/**
 * HostedAiLedger — one change to a site's free usage or bought credit
 * (specs/hosted_ai_credit.md §3.2).
 *
 * Kinds:
 *   grant     the month's free top-up (the new A); hal_amount is A itself
 *   purchase  credit bought: hal_money paid, hal_markup_percent in force,
 *             hal_amount = the credit, money ÷ (1 + markup)
 *   refund    money refunded on a credit purchase line; hal_amount is the
 *             credit taken off (negative)
 *   expire    bought credit removed when the site left us (negative)
 *   adjust    an operator's correction, with a reason (either sign)
 *
 * A PURCHASE APPLIES ONCE PER ORDER LINE: hal_purchase_odi_order_item_id is
 * unique and set only on purchase rows. A line can be refunded in parts, so
 * refund rows carry the line in hal_odi_order_item_id, which is not unique;
 * each records the difference between the line's refunded total and the
 * refunds already written for it, so a refund applies once however often the
 * watch reads it.
 *
 * hal_amount is microdollars of TrustedRouter spend; hal_money is the
 * customer's dollars. The account's hai_purchased is the running figure,
 * written in the same transaction as each purchase, refund, expire or adjust.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class HostedAiLedgerException extends SystemBaseException {}

class HostedAiLedger extends SystemBase {
	public static $prefix = 'hal';
	public static $tablename = 'hal_hosted_ai_ledger';
	public static $access_class = 'money';
	public static $pkey_column = 'hal_hosted_ai_ledger_id';

	const KIND_GRANT    = 'grant';
	const KIND_PURCHASE = 'purchase';
	const KIND_REFUND   = 'refund';
	const KIND_EXPIRE   = 'expire';
	const KIND_ADJUST   = 'adjust';
	const KINDS = array(self::KIND_GRANT, self::KIND_PURCHASE, self::KIND_REFUND, self::KIND_EXPIRE, self::KIND_ADJUST);

	protected static $foreign_key_actions = array(
		'hal_hai_hosted_ai_account_id' => array('action' => 'cascade'),
		// History outlives the order line and the operator; the amounts stay.
		'hal_odi_order_item_id'          => array('action' => 'null'),
		'hal_purchase_odi_order_item_id' => array('action' => 'null'),
		'hal_usr_user_id'                => array('action' => 'null'),
	);

	public static $test_fixture = array(
		'values'       => array('hal_kind' => 'purchase', 'hal_amount' => 0, 'hal_purchase_odi_order_item_id' => 1, 'hal_note' => 'fixture'),
		'update_field' => 'hal_note',
	);

	public static $field_specifications = array(
		'hal_hosted_ai_ledger_id'        => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'hal_hai_hosted_ai_account_id'   => array('type'=>'int8', 'required'=>true, 'is_nullable'=>false),
		'hal_kind'                       => array('type'=>'varchar(16)', 'required'=>true, 'is_nullable'=>false),
		'hal_amount'                     => array('type'=>'int8', 'is_nullable'=>false, 'default'=>0),
		'hal_money'                      => array('type'=>'numeric(10,2)'),
		'hal_markup_percent'             => array('type'=>'numeric(6,2)'),
		'hal_odi_order_item_id'          => array('type'=>'int8'),
		'hal_purchase_odi_order_item_id' => array('type'=>'int8', 'unique'=>true),
		'hal_period'                     => array('type'=>'varchar(7)'),
		'hal_note'                       => array('type'=>'varchar(500)'),
		'hal_usr_user_id'                => array('type'=>'int8'),
		'hal_create_time'                => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'hal_update_time'                => array('type'=>'timestamp(6)', 'stamp_on_save'=>true),
		'hal_delete_time'                => array('type'=>'timestamp(6)'),
	);

	function save($debug = false) {
		$kind = (string)$this->get('hal_kind');
		if (!in_array($kind, self::KINDS, true)) {
			throw new HostedAiLedgerException("Unknown AI credit ledger kind '{$kind}'.");
		}
		if ($kind === self::KIND_PURCHASE) {
			if (!(int)$this->get('hal_purchase_odi_order_item_id')) {
				throw new HostedAiLedgerException('A purchase names the order line that paid for it.');
			}
			$this->set('hal_odi_order_item_id', (int)$this->get('hal_purchase_odi_order_item_id'));
		} elseif ($this->get('hal_purchase_odi_order_item_id') !== null && $this->get('hal_purchase_odi_order_item_id') !== '') {
			throw new HostedAiLedgerException('Only a purchase row is keyed by its order line.');
		}
		if ($kind === self::KIND_ADJUST && trim((string)$this->get('hal_note')) === '') {
			throw new HostedAiLedgerException('A credit adjustment says why.');
		}
		return parent::save($debug);
	}

	/** The purchase row for an order line, or null. */
	public static function purchase_for_line($order_item_id): ?HostedAiLedger {
		$order_item_id = (int)$order_item_id;
		if ($order_item_id <= 0) { return null; }
		foreach (new MultiHostedAiLedger(array('purchase_line' => $order_item_id, 'deleted' => false), array(), 1) as $row) {
			return $row;
		}
		return null;
	}

	/** Dollars of money already recorded as refunded for an order line. */
	public static function refunded_money_for_line($order_item_id): float {
		$total = 0.0;
		foreach (new MultiHostedAiLedger(array('order_item_id' => (int)$order_item_id, 'kind' => self::KIND_REFUND,
				'deleted' => false)) as $row) {
			$total += (float)$row->get('hal_money');
		}
		return round($total, 2);
	}
}

class MultiHostedAiLedger extends SystemMultiBase {
	protected static $model_class = 'HostedAiLedger';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['account_id'])) {
			$filters['hal_hai_hosted_ai_account_id'] = array((int)$this->options['account_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['kind'])) {
			$filters['hal_kind'] = array((string)$this->options['kind'], PDO::PARAM_STR);
		}
		if (isset($this->options['order_item_id'])) {
			$filters['hal_odi_order_item_id'] = array((int)$this->options['order_item_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['purchase_line'])) {
			$filters['hal_purchase_odi_order_item_id'] = array((int)$this->options['purchase_line'], PDO::PARAM_INT);
		}
		return $this->_get_resultsv2('hal_hosted_ai_ledger', $filters, $this->order_by, $only_count, $debug);
	}
}
