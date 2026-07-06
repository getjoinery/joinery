<?php
/**
 * InboundEmailMessage - Stored inbound email messages (local mailbox).
 *
 * Persisted by InboundEmailRouter when an alias or catch-all is in
 * "store" or "forward_and_store" delivery mode. The admin Mailbox tab
 * reads from this table; tests query it instead of the Mailgun-stored
 * legacy iem_inbound_emails table.
 *
 * The unique_with constraint on (iem_message_id_header, iem_recipient,
 * iem_direction) is the dedup mechanism — see InboundEmailRouter::
 * storeMessage(). Direction is part of the key because mail between two
 * hosted mailboxes produces two legitimate rows for the same Message-ID +
 * address: the sender's outbound (Sent) copy and the recipient's inbound
 * copy. Sent-folder reconciliation (outbound vs outbound) and inbound
 * redelivery (inbound vs inbound) still dedup.
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
 * RAW-MESSAGE STORAGE DESCRIPTOR (specs/inbound_raw_message_storage.md). The heavy
 * RFC822 raw lives OUT of the database; iem_raw_storage_driver is the single source
 * of truth for where:
 *   - 'inline'  → in iem_raw_message (legacy rows + the write-failure fallback)
 *   - 'local'   → a file under {site_root}/storage/, located by iem_raw_storage_key
 *   - 'cloud'   → an object in the verified-private store, same key (set by the
 *                 shared CloudOffloadEngine; reversible)
 *   - 'remote'  → no platform copy; parts fetched on demand from IMAP (locator cols)
 * getRawMessage()/getRawMimePart() resolve the descriptor so callers are
 * transport- and tier-blind; RawMessageStore owns the byte I/O and key scheme.
 *
 * SPAM VERDICT (specs/inbound_email_spam_filtering.md). iem_spam_verdict is the
 * first-class disposition the reader filters on: 'spam' is held out of the inbox
 * and shown in the Spam view; 'ham'/NULL pass. It is set by the router's DMARC
 * rule for locally-received mail, by the IMAP junk-folder mapping for polled mail,
 * and by manual reader corrections. NULL means not evaluated (filtering disabled).
 *
 * CONTENT SPAM (specs/inbound_email_content_spam_filtering.md). A content scanner
 * (rspamd milter on the Postfix path; the provider's own spam flag on webhook paths)
 * is a second source OR'd into iem_spam_verdict by the router. iem_spam_score records
 * its numeric score for display/tuning only (never disposition); iem_learned_verdict
 * tracks what the LearnSpamFeedback task has taught rspamd's Bayes classifier.
 *
 * @version 1.9
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundEmailMessageException extends SystemBaseException {}

class InboundEmailMessage extends SystemBase {
	public static $prefix = 'iem';
	public static $tablename = 'iem_inbound_email_messages';
	public static $pkey_column = 'iem_inbound_email_message_id';

	// Spam disposition (specs/inbound_email_spam_filtering.md). A NULL verdict
	// means the message was not evaluated (filtering disabled).
	const SPAM_VERDICT_HAM  = 'ham';
	const SPAM_VERDICT_SPAM = 'spam';

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
		'iem_raw_message'         => array('type'=>'text'), // legacy/'inline' only — new push writes leave this empty
		// Raw-message storage descriptor (specs/inbound_raw_message_storage.md).
		'iem_raw_storage_driver'    => array('type'=>'varchar(16)', 'default'=>'inline'), // inline | local | cloud | remote
		'iem_raw_storage_key'       => array('type'=>'varchar(500)'),                     // tier-invariant relative key (local/cloud); null for inline/remote
		'iem_raw_sync_failed_count' => array('type'=>'int4', 'default'=>0),               // offload retry counter (engine failure cap)
		'iem_raw_sync_last_attempt' => array('type'=>'timestamp(6)'),                     // offload breadcrumb
		'iem_message_id_header'   => array('type'=>'varchar(255)', 'unique_with'=>array('iem_recipient', 'iem_direction')),
		'iem_thread_key'          => array('type'=>'varchar(255)'), // indexed via migration iem_001 (no declarative non-unique index support)
		'iem_direction'           => array('type'=>'varchar(10)', 'default'=>'inbound', 'is_nullable'=>false), // inbound | outbound (reply/forward sent from the reader)
		'iem_is_read'             => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'iem_is_starred'          => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		// "Skip the Inbox" (specs/implemented/inbound_email_filters.md). An archived
		// message is hidden from the default Inbox view but still reachable in All
		// Mail; orthogonal to read/star. Set by the Archive reader action or a filter.
		'iem_is_archived'         => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'iem_read_time'           => array('type'=>'timestamp(6)'),
		'iem_dkim_result'         => array('type'=>'varchar(16)'),
		'iem_spf_result'          => array('type'=>'varchar(16)', 'default'=>'unverified'),
		'iem_dmarc_result'        => array('type'=>'varchar(16)', 'default'=>'unverified'),
		'iem_auth_source'         => array('type'=>'varchar(20)', 'default'=>'none'),
		// Spam disposition (specs/inbound_email_spam_filtering.md): 'ham' | 'spam';
		// NULL = not evaluated (filtering disabled). Drives the reader's inbox/Spam split.
		'iem_spam_verdict'        => array('type'=>'varchar(10)'),
		// Content spam (specs/inbound_email_content_spam_filtering.md). Recorded score
		// from the scanner/provider (display/tuning only, NEVER read for disposition);
		// NULL = none reported. iem_learned_verdict is the last verdict actually taught
		// to rspamd's Bayes classifier — the LearnSpamFeedback reconcile teaches a row
		// whenever it diverges from iem_spam_verdict; NULL = never taught.
		'iem_spam_score'          => array('type'=>'numeric'),
		'iem_learned_verdict'     => array('type'=>'varchar(10)'),
		// AI security scan (specs/joinery_ai_email_security_scan.md). A danger
		// score (0-10) plus the model's verdict/red-flags/summary for mail that
		// passes the auth/spam filters above but is malicious in content — what
		// those filters structurally cannot catch. Written ONLY by
		// EmailSecurityScanJob::recordVerdict() (not $ai_writable_fields); NULL
		// score/scan/time = not yet scanned by any recipe.
		'iem_ai_danger_score'     => array('type'=>'int2'),
		'iem_ai_scan'             => array('type'=>'jsonb'), // {verdict, red_flags, summary, model, recipe_id}
		'iem_ai_scan_time'        => array('type'=>'timestamp(6)'),
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
		// local_modified > synced_state_time. Custom-label dirtiness lives in the single
		// ilm_ row (present_local vs present_base); see specs/inbound_email_labels.md.
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
	 * The whole raw RFC822 message, resolved through the storage descriptor.
	 * Returns null when there is no stored raw (empty inline, or a 'remote' row
	 * whose whole message is never reconstructed — only its parts are fetched
	 * on demand). 'cloud' reads degrade to null on a transient store outage so
	 * callers never fatal.
	 */
	function getRawMessage(): ?string {
		$driver = (string)$this->get('iem_raw_storage_driver') ?: 'inline';
		$key    = (string)$this->get('iem_raw_storage_key');

		if ($driver === 'inline') {
			$raw = $this->get('iem_raw_message');
			return ($raw === null || $raw === '') ? null : (string)$raw;
		}
		if ($driver === 'local' || $driver === 'cloud') {
			require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/RawMessageStore.php'));
			try {
				return RawMessageStore::read($driver, $key);
			} catch (Throwable $e) {
				error_log('InboundEmailMessage::getRawMessage failed (driver=' . $driver
					. ', id=' . $this->key . '): ' . $e->getMessage());
				return null;
			}
		}
		// 'remote' — the whole raw is never reconstructed; callers fetch the one
		// part they need via ImapIngestor::fetchPart().
		return null;
	}

	/**
	 * One decoded MIME part of the stored raw: ['content','type','filename'].
	 * For stored-raw drivers (inline/local/cloud) it MIME-parses the raw and
	 * extracts the section. Returns null when the raw is unavailable or the part
	 * is gone. 'remote' is NOT handled here — its parts come from the source
	 * mailbox via ImapIngestor::fetchPart(); the caller routes 'remote'
	 * separately (the dispatch is unified on iem_raw_storage_driver).
	 */
	function getRawMimePart(string $section): ?array {
		$driver = (string)$this->get('iem_raw_storage_driver') ?: 'inline';
		if ($driver === 'remote') {
			return null;
		}
		// local parses from the file's bytes, cloud from a pulled-then-unlinked
		// temp, inline from the column — all surfaced as a string by the accessor.
		$raw = $this->getRawMessage();
		if ($raw === null || $raw === '') {
			return null;
		}

		require_once(PathHelper::getComposerAutoloadPath());
		try {
			$message = Horde_Mime_Part::parseMessage($raw);
			$part = $message->getPart($section);
			if ($part === null) {
				return null;
			}
			return array(
				'content'  => (string)$part->getContents(),
				'type'     => (string)$part->getType(),
				'filename' => $part->getName() ?: null,
			);
		} catch (Throwable $e) {
			error_log('InboundEmailMessage::getRawMimePart parse failed (id=' . $this->key
				. ', section=' . $section . '): ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * Hard delete: reclaim the message's attachment bytes before the row goes.
	 * Under the lean-record model those bytes are file-backed attachment Files
	 * (specs/implemented/inbound_email_attachment_storage.md); on the fallback path they are
	 * inside the stored raw object (local file or private-store object). Both are
	 * reclaimed here — the single reclaim path, no separate orphan sweep. Soft
	 * delete leaves everything in place (the row is recoverable). The ima_
	 * manifest itself cascades via $foreign_key_actions.
	 */
	function permanent_delete($debug = false) {
		// File-backed attachments: delete each linked File (bytes live only there).
		// Collect BEFORE the manifest rows cascade away in parent::permanent_delete().
		try {
			require_once(PathHelper::getIncludePath('data/files_class.php'));
			require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_message_attachment_class.php'));
			$manifest = new MultiInboundMessageAttachment(array('message_id' => intval($this->key), 'file_backed' => true));
			$manifest->load();
			foreach ($manifest as $att) {
				$fil_id = intval($att->get('ima_fil_file_id'));
				if ($fil_id > 0) {
					$file = new File($fil_id, TRUE);
					if ($file->key) {
						try { $file->permanent_delete(); }
						catch (Throwable $e) {
							error_log('InboundEmailMessage: attachment File reclaim on purge failed (fil=' . $fil_id
								. ', id=' . $this->key . '): ' . $e->getMessage());
						}
					}
				}
			}
		} catch (Throwable $e) {
			error_log('InboundEmailMessage: attachment File reclaim enumeration failed (id=' . $this->key
				. '): ' . $e->getMessage());
		}

		// Fallback path: reclaim the stored raw object if one was persisted.
		$driver = (string)$this->get('iem_raw_storage_driver');
		$key    = (string)$this->get('iem_raw_storage_key');
		if ($driver === 'local' || $driver === 'cloud') {
			try {
				require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/RawMessageStore.php'));
				RawMessageStore::delete($driver, $key);
			} catch (Throwable $e) {
				// Best-effort: never let a reclaim failure block the row delete.
				error_log('InboundEmailMessage: raw object reclaim on purge failed (id=' . $this->key
					. '): ' . $e->getMessage());
			}
		}
		return parent::permanent_delete($debug);
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

		// Spam disposition (specs/inbound_email_spam_filtering.md).
		if (isset($this->options['spam_verdict'])) {
			$filters['iem_spam_verdict'] = [$this->options['spam_verdict'], PDO::PARAM_STR];
		}

		// not_spam: exclude judged-spam rows (NULL and 'ham' pass). The default
		// inbox view's hide-spam clause.
		if (!empty($this->options['not_spam'])) {
			$filters['iem_spam_verdict'] = "IS DISTINCT FROM 'spam'";
		}

		return $this->_get_resultsv2('iem_inbound_email_messages', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
