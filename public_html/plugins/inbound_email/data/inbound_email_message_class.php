<?php
/**
 * InboundEmailMessage - Stored inbound email messages (local mailbox).
 *
 * Persisted by InboundEmailRouter when an alias or catch-all is in
 * "store" or "forward_and_store" delivery mode. The admin Mailbox tab
 * reads from this table; tests query it instead of the Mailgun-stored
 * legacy iem_inbound_emails table.
 *
 * The unique_with constraint on (iem_message_id_header, iem_recipient)
 * is the dedup mechanism — see InboundEmailRouter::storeMessage().
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundEmailMessageException extends SystemBaseException {}

class InboundEmailMessage extends SystemBase {
	public static $prefix = 'iem';
	public static $tablename = 'iem_inbound_email_messages';
	public static $pkey_column = 'iem_inbound_email_message_id';

	protected static $foreign_key_actions = [
		'iem_ied_inbound_email_domain_id' => ['action' => 'cascade'],
		'iem_iea_inbound_email_alias_id'  => ['action' => 'null'],
	];

	public static $field_specifications = array(
		'iem_inbound_email_message_id'    => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'iem_ied_inbound_email_domain_id' => array('type'=>'int4', 'is_nullable'=>false),
		'iem_iea_inbound_email_alias_id'  => array('type'=>'int4'),
		'iem_sender'              => array('type'=>'varchar(500)'),
		'iem_recipient'           => array('type'=>'varchar(500)'),
		'iem_subject'             => array('type'=>'varchar(1000)'),
		'iem_body_plain'          => array('type'=>'text'),
		'iem_body_html'           => array('type'=>'text'),
		'iem_raw_message'         => array('type'=>'text'),
		'iem_message_id_header'   => array('type'=>'varchar(255)', 'unique_with'=>array('iem_recipient')),
		'iem_dkim_result'         => array('type'=>'varchar(10)'),
		'iem_size_bytes'          => array('type'=>'int4'),
		'iem_received_time'       => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'iem_create_time'         => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'iem_delete_time'         => array('type'=>'timestamp(6)'),
	);

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	/**
	 * Create and persist a stored inbound message in one call.
	 *
	 * Returns the saved InboundEmailMessage. The caller is responsible for
	 * detecting unique-violation retries (SQLSTATE 23505) — when dedup
	 * fires, the underlying save() throws and the caller swallows it as
	 * "already stored, retry succeeded."
	 */
	static function CreateEntry(array $row): InboundEmailMessage {
		$msg = new InboundEmailMessage(NULL);
		foreach ($row as $field => $value) {
			$msg->set($field, $value);
		}
		$msg->save();
		return $msg;
	}
}

class MultiInboundEmailMessage extends SystemMultiBase {
	protected static $model_class = 'InboundEmailMessage';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['domain_id'])) {
			$filters['iem_ied_inbound_email_domain_id'] = [$this->options['domain_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['alias_id'])) {
			$filters['iem_iea_inbound_email_alias_id'] = [$this->options['alias_id'], PDO::PARAM_INT];
		}

		$dblink = DbConnector::get_instance()->get_db_link();

		if (isset($this->options['recipient']) && $this->options['recipient'] !== '') {
			$term = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), $this->options['recipient']) . '%';
			$filters['iem_recipient'] = 'ILIKE ' . $dblink->quote($term);
		}

		if (isset($this->options['sender']) && $this->options['sender'] !== '') {
			$term = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), $this->options['sender']) . '%';
			$filters['iem_sender'] = 'ILIKE ' . $dblink->quote($term);
		}

		if (isset($this->options['message_id_header'])) {
			$filters['iem_message_id_header'] = [$this->options['message_id_header'], PDO::PARAM_STR];
		}

		if (isset($this->options['received_since'])) {
			$filters['iem_received_time'] = '>= ' . $dblink->quote($this->options['received_since']);
		}

		if (isset($this->options['deleted'])) {
			$filters['iem_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('iem_inbound_email_messages', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
