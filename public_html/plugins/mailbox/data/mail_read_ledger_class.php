<?php
/**
 * MailReadLedger - one row per (person, credential kind, hour, message) whose
 * content that person was handed: the read budget's count
 * (specs/mail_access_hardening.md WP2, MailReadBudget).
 *
 * A read is counted once an hour however many times the message is opened:
 * the unique index on (user, credential kind, kind, hour bucket, message id)
 * lets the writer INSERT ... ON CONFLICT DO NOTHING, so a repeat read costs
 * one row and no second write, and the window's distinct count is one SUM over
 * one index range. The bucket comes before the message id so that range is
 * walked after three equalities, while the same index serves the conflict
 * check.
 *
 * Two kinds: `message` rows name a message (mrb_count 1), or carry a weight
 * with no message id for a whole mailbox handed over at once (a download
 * started, mrb_count = its message count); `page` rows count one thread-list
 * page each, with no message id. Three credential kinds, metered apart so a
 * mail program's first sync never spends the browser's budget: browser,
 * device (a linked phone or desktop), app (a mail program).
 *
 * Integers only: no subject, no address, no body, so the egress guard passes
 * a row written on a request that has opened sealed mail. The retention sweep
 * deletes rows older than mailbox_read_ledger_retention_days (two days by
 * default; the window is an hour, so nothing reads them after that).
 *
 * @version 1.0
 */

class MailReadLedger extends SystemBase {
	public static $prefix = 'mrb';
	public static $tablename = 'mrb_mail_read_ledger';
	public static $access_class = 'restricted';
	public static $owner_column = false;
	public static $pkey_column = 'mrb_mail_read_ledger_id';

	const KIND_MESSAGE = 'message';
	const KIND_PAGE    = 'page';

	const CREDENTIAL_BROWSER = 'browser';
	const CREDENTIAL_DEVICE  = 'device';
	const CREDENTIAL_APP     = 'app';

	public static $retention_policy = array(
		'label'          => 'Mail read ledger',
		'age_column'     => 'mrb_bucket_time',
		'age_unit'       => 'days',
		'window_setting' => 'mailbox_read_ledger_retention_days',
	);

	protected static $foreign_key_actions = array(
		// The count belongs to the person; it leaves with them.
		'mrb_usr_user_id'                => array('action' => 'permanent_delete'),
		// A message erased for good takes its ledger rows with it.
		'mrb_iem_inbound_email_message_id' => array('action' => 'permanent_delete'),
	);

	public static $field_specifications = array(
		'mrb_mail_read_ledger_id'          => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'mrb_usr_user_id'                  => array('type'=>'int4', 'is_nullable'=>false),
		'mrb_credential_kind'              => array('type'=>'varchar(8)', 'is_nullable'=>false),
		'mrb_kind'                         => array('type'=>'varchar(8)', 'is_nullable'=>false),
		// The hour the read fell in (UTC, truncated to the hour).
		'mrb_bucket_time'                  => array('type'=>'timestamp(6)', 'is_nullable'=>false),
		// NULL for a page row and for a whole-mailbox weight.
		'mrb_iem_inbound_email_message_id' => array('type'=>'int8', 'is_nullable'=>true),
		// 1 for a message or a page; a download's message count for its one row.
		'mrb_count'                        => array('type'=>'int4', 'is_nullable'=>false, 'default'=>1),
		'mrb_create_time'                  => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	public static $index_specifications = array(
		array('columns' => array('mrb_usr_user_id', 'mrb_credential_kind', 'mrb_kind', 'mrb_bucket_time',
			'mrb_iem_inbound_email_message_id'), 'unique' => true),
	);
}

class MultiMailReadLedger extends SystemMultiBase {
	protected static $model_class = 'MailReadLedger';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['user_id'])) {
			$filters['mrb_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}
		return $this->_get_resultsv2('mrb_mail_read_ledger', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
