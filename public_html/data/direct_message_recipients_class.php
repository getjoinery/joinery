<?php
/**
 * DirectMessageRecipient — one person a direct message goes to, and whether
 * it went (spec email_lists_plugin §3).
 *
 * Written when the message is queued (DirectMessage::queue_to), one row per
 * address; the background send (DirectMessage::send_pending) claims each row,
 * sends, and records the outcome. The rows are the message's delivery report
 * and a member's "messages received" history on the admin user page.
 *
 * @version 1.0
 */
class DirectMessageRecipient extends SystemBase {
	public static $prefix = 'dmr';
	public static $tablename = 'dmr_direct_message_recipients';
	public static $access_class = 'personal';
	public static $pkey_column = 'dmr_direct_message_recipient_id';

	protected static $foreign_key_actions = array(
		'dmr_dmg_direct_message_id' => array('action' => 'cascade'),
		'dmr_usr_user_id' => array('action' => 'cascade'),
	);

	const PENDING = 1;   // queued, not yet tried
	const SENDING = 2;   // claimed by a send pass
	const SENT    = 3;
	const FAILED  = 4;   // not sent: refused every try, too late, or its pass died mid-send

	public static $field_specifications = array(
		'dmr_direct_message_recipient_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'dmr_dmg_direct_message_id' => array('type'=>'int8', 'required'=>true, 'index'=>true),
		'dmr_usr_user_id' => array('type'=>'int4', 'index'=>true),
		'dmr_email' => array('type'=>'varchar(255)', 'required'=>true),
		'dmr_name' => array('type'=>'varchar(255)'),
		'dmr_status' => array('type'=>'int2', 'default'=>1, 'index'=>true),
		'dmr_sent_time' => array('type'=>'timestamp(6)'),
		'dmr_attempts' => array('type'=>'int2', 'default'=>0),
		'dmr_claim_time' => array('type'=>'timestamp(6)'),
		'dmr_next_try_time' => array('type'=>'timestamp(6)'),
	);

	public static $status_to_text = array(
		self::PENDING => 'Waiting',
		self::SENDING => 'Sending',
		self::SENT    => 'Sent',
		self::FAILED  => 'Not sent',
	);

	function get_status_text(): string {
		return self::$status_to_text[(int)$this->get('dmr_status')] ?? '';
	}
}

class MultiDirectMessageRecipient extends SystemMultiBase {
	protected static $model_class = 'DirectMessageRecipient';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		// Only rows of messages that are not deleted.
		if (!empty($this->options['live_messages'])) {
			$filters['dmr_dmg_direct_message_id'] = 'IN (SELECT dmg_direct_message_id FROM dmg_direct_messages WHERE dmg_delete_time IS NULL)';
		}
		return $this->_get_resultsv2('dmr_direct_message_recipients', $filters, $this->order_by, $only_count, $debug);
	}
}
