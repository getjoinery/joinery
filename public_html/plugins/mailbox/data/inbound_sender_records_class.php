<?php
/**
 * InboundSenderRecord - what this mailbox knows about one sender
 * (spam_learning_in_core.md § The sender record).
 *
 * One row per (mailbox, sender fingerprint). The fingerprint is a keyed HMAC of
 * the address (SpamSenderRecords::fingerprint), never the address itself, so a
 * row says nothing about a sender to anyone who does not already know the
 * address and hold the deployment's key.
 *
 * Every fact the spam filter's relationship and history steps read lives here:
 *   - isr_sent_count / isr_last_sent_time: mail the user composed to this
 *     address (never a forward);
 *   - isr_spam_taught / isr_ham_taught: how the user taught this sender's mail,
 *     written in the teaching transaction;
 *   - isr_first_seen_time / isr_message_count: inbound history, which drives the
 *     first_contact meta token.
 *
 * They are COUNTERS, not counts of iem_ rows. Trash retention purges deleted
 * messages, and a count of rows would let "taught spam twice" decay and "never
 * taught spam" flip back. A counter keeps what the user taught after the
 * messages are gone.
 *
 * Written only through SpamSenderRecords, with single-statement upserts.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundSenderRecordException extends SystemBaseException {}

class InboundSenderRecord extends SystemBase {
	public static $prefix = 'isr';
	public static $tablename = 'isr_inbound_sender_records';
	public static $pkey_column = 'isr_inbound_sender_record_id';

	protected static $foreign_key_actions = array(
		// A mailbox going away takes what it knew about its senders with it.
		'isr_iea_inbound_email_alias_id' => array('action' => 'cascade'),
	);

	public static $field_specifications = array(
		'isr_inbound_sender_record_id'   => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'isr_iea_inbound_email_alias_id' => array('type'=>'int4', 'is_nullable'=>false,
			'foreign_key'=>array('table'=>'iea_inbound_email_aliases',
				'column'=>'iea_inbound_email_alias_id', 'on_delete'=>'CASCADE')),
		'isr_sender_fingerprint'         => array('type'=>'varchar(64)', 'is_nullable'=>false,
			'unique_with'=>array('isr_iea_inbound_email_alias_id')),
		'isr_sent_count'                 => array('type'=>'int4', 'is_nullable'=>false, 'default'=>0),
		'isr_last_sent_time'             => array('type'=>'timestamp(6)', 'is_nullable'=>true),
		'isr_spam_taught'                => array('type'=>'int4', 'is_nullable'=>false, 'default'=>0),
		'isr_ham_taught'                 => array('type'=>'int4', 'is_nullable'=>false, 'default'=>0),
		'isr_first_seen_time'            => array('type'=>'timestamp(6)', 'is_nullable'=>true),
		'isr_last_seen_time'             => array('type'=>'timestamp(6)', 'is_nullable'=>true),
		'isr_message_count'              => array('type'=>'int4', 'is_nullable'=>false, 'default'=>0),
		'isr_create_time'                => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}
}

class MultiInboundSenderRecord extends SystemMultiBase {
	protected static $model_class = 'InboundSenderRecord';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['alias_id'])) {
			$filters['isr_iea_inbound_email_alias_id'] = array($this->options['alias_id'], PDO::PARAM_INT);
		}
		return $this->_get_resultsv2('isr_inbound_sender_records', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
