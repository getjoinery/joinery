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
 * Threading + state columns (iem_thread_key, iem_is_read, iem_is_starred,
 * iem_read_time) power the Gmail-style Mailbox Reader. Because each inbound
 * message becomes one row per (message, recipient) — one row per mailbox —
 * read/star state is simply a property of the row, shared among everyone with
 * access to that mailbox (team-inbox semantics). See MailboxService.
 *
 * Authentication verdicts (iem_dkim_result / iem_spf_result / iem_dmarc_result)
 * are NOT computed by the app — they are read from the message's
 * Authentication-Results header (stamped by the verifying MTA milters) via
 * AuthenticationResults. iem_auth_source records where the verdict came from
 * ('milter' / 'none'; 'mailgun' reserved for the deferred webhook path). When
 * no trusted verdict is present the columns read 'unverified', never a
 * hand-rolled 'fail'. See InboundEmailRouter and AuthenticationResults.
 *
 * IMAP-sourced messages are reference-backed: the poller stores headers + body
 * columns but leaves iem_raw_message empty and records a locator
 * (iem_iia_inbound_imap_account_id + iem_imap_uid/uidvalidity/folder) so
 * individual MIME parts can be re-fetched on demand. A non-null
 * iem_iia_inbound_imap_account_id marks a row reference-backed. See ImapIngestor
 * and specs/inbound_imap_provider.md.
 *
 * @version 1.3
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
		'iem_iia_inbound_imap_account_id' => ['action' => 'null'],
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
		'iem_thread_key'          => array('type'=>'varchar(255)'), // indexed via migration iem_001 (no declarative non-unique index support)
		'iem_direction'           => array('type'=>'varchar(10)', 'default'=>'inbound', 'is_nullable'=>false), // inbound | outbound (reply/forward sent from the reader)
		'iem_is_read'             => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'iem_is_starred'          => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'iem_read_time'           => array('type'=>'timestamp(6)'),
		'iem_dkim_result'         => array('type'=>'varchar(16)'),
		'iem_spf_result'          => array('type'=>'varchar(16)', 'default'=>'unverified'),
		'iem_dmarc_result'        => array('type'=>'varchar(16)', 'default'=>'unverified'),
		'iem_auth_source'         => array('type'=>'varchar(20)', 'default'=>'none'),
		'iem_size_bytes'          => array('type'=>'int4'),
		// IMAP locator (populated only for reference-backed, IMAP-sourced rows;
		// a non-null iem_iia_inbound_imap_account_id marks the row reference-backed
		// and tells the attachment endpoint to fetch parts on demand from IMAP).
		'iem_iia_inbound_imap_account_id' => array('type'=>'int8'),
		'iem_imap_uid'            => array('type'=>'int8'),
		'iem_imap_uidvalidity'    => array('type'=>'int8'),
		'iem_imap_folder'         => array('type'=>'varchar(255)'),
		// Two-way sync flag-state tracking (specs/two_way_imap_sync.md §5, §7.1).
		// local_modified is stamped by MailboxService when flags change locally;
		// synced_state_time is stamped by push. A flag row is dirty iff
		// local_modified > synced_state_time. Membership dirtiness lives in imf_.
		'iem_local_state_modified' => array('type'=>'timestamp(6)'),
		'iem_synced_state_time'    => array('type'=>'timestamp(6)'),
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

		// IN-list of alias ids — the Mailbox Reader's scope (the set of mailboxes
		// the viewer may see) is fed in here. An empty list matches nothing.
		if (isset($this->options['alias_ids'])) {
			$ids = array();
			foreach ((array)$this->options['alias_ids'] as $id) {
				$ids[] = intval($id);
			}
			$filters['iem_iea_inbound_email_alias_id'] = count($ids)
				? 'IN (' . implode(',', $ids) . ')'
				: 'IN (NULL)';
		}

		// IN-list of domain ids.
		if (isset($this->options['domain_ids'])) {
			$ids = array();
			foreach ((array)$this->options['domain_ids'] as $id) {
				$ids[] = intval($id);
			}
			$filters['iem_ied_inbound_email_domain_id'] = count($ids)
				? 'IN (' . implode(',', $ids) . ')'
				: 'IN (NULL)';
		}

		$dblink = DbConnector::get_instance()->get_db_link();

		if (isset($this->options['thread_key'])) {
			$filters['iem_thread_key'] = [$this->options['thread_key'], PDO::PARAM_STR];
		}

		if (isset($this->options['subject']) && $this->options['subject'] !== '') {
			$term = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), $this->options['subject']) . '%';
			$filters['iem_subject'] = 'ILIKE ' . $dblink->quote($term);
		}

		// Body search spans both decoded bodies; OR-grouped so it does not widen
		// any other clause. Uses the split-parenthesis option-key convention.
		if (isset($this->options['body']) && $this->options['body'] !== '') {
			$term = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), $this->options['body']) . '%';
			$q = $dblink->quote($term);
			$filters['(iem_body_plain'] = 'ILIKE ' . $q . ' OR iem_body_html ILIKE ' . $q . ')';
		}

		if (isset($this->options['is_read'])) {
			$filters['iem_is_read'] = $this->options['is_read'] ? '= true' : '= false';
		}

		if (isset($this->options['is_starred'])) {
			$filters['iem_is_starred'] = $this->options['is_starred'] ? '= true' : '= false';
		}

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

		if (isset($this->options['direction'])) {
			$filters['iem_direction'] = [$this->options['direction'], PDO::PARAM_STR];
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
