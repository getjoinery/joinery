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
 * ENCRYPTION AT REST (specs/implemented/inbound_email_encryption_at_rest.md). When the
 * owning user (the alias's single grantee) holds a Sealed Vault (docs/sealed_vault.md),
 * InboundEmailRouter::storeMessage seals iem_sender/iem_subject/iem_body_plain/
 * iem_body_html under a per-message DEK (iem_sealed_key, sealed to the owner's vault
 * public key); iem_content_sealed marks a row sealed and iem_key_generation matches the
 * vault generation the DEK is sealed to (0 = never sealed — a Standard-tier mailbox with
 * no vault, or a row from before the owner had one). $sealed_fields + decryptSealedField()/
 * decryptSealedFieldStatic() are the generic Sealed Vault read hook: SystemBase::get()
 * decrypts automatically for a loaded model, and the raw-row paths (MailboxService,
 * ModelQueryExecutor) call decryptSealedFieldStatic() directly. A locked vault raises
 * VaultLockedException; an unsealed row (iem_content_sealed = false) is returned as-is —
 * there is nothing to decrypt. iem_sealed_owner_user_id records WHOSE vault the row is
 * sealed to at seal time — decryption resolves the owner from the row itself, immune to
 * later grant-list or alias changes. sealAd()/attachmentAd()/rawAd() are the single
 * source of the AD (additional data) row-binding convention every sealer/opener must
 * agree on byte-for-byte. Attachment Files record their own sealed state per file
 * (ima_is_sealed); a sealed-mailbox extraction-failure fallback stores the raw as one
 * AEAD blob (iem_raw_sealed) that getRawMessage() opens in-window.
 *
 * AI TRIAGE (specs/implemented/joinery_ai_email_triage.md). iem_ai_summary is the one AI-authored
 * message field the email_triage pipeline job writes — a one-line gist for the inbox,
 * content in miniature, so it is a $sealed_fields member like the body columns above.
 *
 * DEFERRED INGEST (specs/inbound_email_hardened_ingest_relay_executor.md § Phase 5). On a
 * relay-fronted deployment, MX-path Fortress mail arrives sealed to the owner's vault public
 * key and is stored PENDING-PARSE (iem_pending_parse) with the sealed blob in
 * iem_relay_sealed_raw until the next unlock, when DeferredIngest parses and seals it under a
 * fresh DEK. iem_relay_spool_id is the pull dedup key.
 *
 * COMPOSE MATURITY (specs/mailbox_compose_maturity.md). iem_bcc holds a composed row's
 * Bcc list in its own sealed column (never merged into iem_recipient, so reply-all on a
 * Sent copy can't re-leak it). iem_draft_state is a sealed JSON scratch column on
 * iem_direction='draft' rows (saved drafts). Both are compose-only sealed fields — see
 * isComposeOnlyField()/isComposedDirection() and the direction guard in decryptSealedField*().
 *
 * @version 1.14
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

	// Sealed Vault generic read hook (docs/sealed_vault.md) — decrypted transparently
	// by SystemBase::get() for a loaded model; raw-row readers call
	// decryptSealedFieldStatic() directly (see class docblock). iem_recipient is
	// sealed ONLY on an outbound row (§ 4.6 — the recipient list is real content
	// worth sealing there; an inbound row's iem_recipient is the receiving
	// alias address, routing metadata, and is never sealed even on an otherwise
	// sealed row) — see the iem_direction check in decryptSealedField() below.
	// iem_recipient and iem_bcc are "compose-only" sealed fields: real content on a
	// composed row (outbound Sent copy or a saved draft), routing-only/absent on an
	// inbound row — the direction guard in decryptSealedField*() seals them only when
	// iem_direction is 'outbound' or 'draft'. iem_draft_state (compose scratch JSON)
	// is likewise sealed and only ever set on a draft row.
	public static $sealed_fields = array('iem_sender', 'iem_subject', 'iem_body_plain', 'iem_body_html', 'iem_recipient', 'iem_bcc', 'iem_draft_state', 'iem_ai_summary');

	// AI surface (docs/example_class.php § AI): recipes may read mail through the
	// query_model tool. On a protected domain a locked row is EXCLUDED from
	// results (never a placeholder) and stays pending for post-unlock catch-up —
	// ModelQueryExecutor::decryptSealedFields() enforces this against $sealed_fields.
	// Message content is untrusted input (anyone can mail you), so the executor
	// wraps sender/subject/body with the per-run injection nonce.
	//
	// Member read-scope: a non-admin member's AI reads are contained to rows they
	// own by iem_sealed_owner_user_id — so a member's recipe reads their OWN
	// sealed mail (in-window), never anyone else's. Standard (unsealed) rows carry
	// no owner in that column, so they stay invisible to members' recipes; admins
	// always read cross-user.
	public static $ai_readable = true;
	public static $ai_description = 'Received and sent email messages (subject, sender, body, AI triage summary).';
	// Drafts (specs/mailbox_compose_maturity.md § Phase 2) are compose scratch: never
	// triaged, summarized, or readable through the query_model AI tool. ModelQueryExecutor
	// appends this fixed predicate to every AI read of this model.
	public static $ai_read_filter = "iem_direction IS DISTINCT FROM 'draft'";
	public static $ai_owner_field = 'iem_sealed_owner_user_id';
	public static $ai_untrusted_fields = array('iem_sender', 'iem_subject', 'iem_body_plain');

	protected static $foreign_key_actions = [
		'iem_ied_inbound_email_domain_id' => ['action' => 'cascade'],
		'iem_iea_inbound_email_alias_id'  => ['action' => 'null'],
		'iem_iia_inbound_imap_account_id' => ['action' => 'null'],
	];

	public static $field_specifications = array(
		'iem_inbound_email_message_id'    => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'iem_ied_inbound_email_domain_id' => array('type'=>'int4', 'is_nullable'=>false),
		'iem_iea_inbound_email_alias_id'  => array('type'=>'int4'),
		// iem_sender/iem_subject/iem_recipient hold ciphertext once sealed, so all
		// are 'text' — varchar caps are too small for base64 + AEAD overhead
		// (iem_recipient carries a sealed outbound row's full recipient list).
		'iem_sender'              => array('type'=>'text'),
		'iem_recipient'           => array('type'=>'text'),
		// Bcc on a composed row (specs/mailbox_compose_maturity.md § Phase 1). Its OWN
		// sealed column, never merged into iem_recipient — so reply-all on your own Sent
		// copy can structurally never re-leak a bcc'd address. NULL on inbound rows.
		'iem_bcc'                 => array('type'=>'text', 'is_nullable'=>true),
		// Draft scratch state (specs/mailbox_compose_maturity.md § Phase 2): a sealed JSON
		// string {mode, source_id, to, cc} holding what the existing columns can't (To vs Cc
		// split, reply/forward source + mode) so reopening a draft restores the exact fields.
		// Only ever set on an iem_direction='draft' row; NULL everywhere else.
		'iem_draft_state'         => array('type'=>'text', 'is_nullable'=>true),
		// Draft author (compose maturity fix pack): a draft is PERSONAL compose state,
		// owned by the user who is writing it — never shared with co-grantees of the
		// alias and never visible to an all-access superadmin. Set only while
		// iem_direction='draft'; cleared on morph to outbound.
		'iem_draft_author_user_id' => array('type'=>'int8', 'is_nullable'=>true),
		'iem_subject'             => array('type'=>'text'),
		'iem_body_plain'          => array('type'=>'text'),
		'iem_body_html'           => array('type'=>'text'),
		'iem_raw_message'         => array('type'=>'text'), // legacy/'inline' only — new push writes leave this empty
		// Encryption at rest (specs/implemented/inbound_email_encryption_at_rest.md).
		// iem_sealed_key is the per-message DEK, sealed to the owner's vault public key
		// (null when never sealed). iem_key_generation matches the vault generation the
		// DEK is sealed to; 0 = not sealed (Standard-tier / no vault). iem_content_sealed
		// marks a row whose content columns hold ciphertext; a row can exist with
		// iem_content_sealed = false and empty content columns mid-ingest (the crash
		// window InboundEmailRouter::storeMessage's UPDATE closes) or permanently for a
		// Standard-tier message, which is never sealed and holds plaintext throughout.
		'iem_sealed_key'          => array('type'=>'text', 'is_nullable'=>true),
		'iem_key_generation'      => array('type'=>'int4', 'is_nullable'=>false, 'default'=>0),
		'iem_content_sealed'      => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		// Whose vault this row's DEK is sealed to, recorded AT SEAL TIME — the
		// decrypt paths resolve the owner from here, so later grant-list or
		// alias changes can never strand already-sealed mail (null only on a
		// legacy row; readers fall back to the live single-grantee resolution).
		'iem_sealed_owner_user_id' => array('type'=>'int8', 'is_nullable'=>true),
		// The stored raw (fallback shape) is itself an AEAD blob under the
		// message DEK — set by the sealed-mailbox extraction-failure fallback
		// (InboundEmailRouter::persistRawAndManifest); getRawMessage() opens it.
		'iem_raw_sealed'          => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		// Deferred ingest — hardened ingest relay (specs/inbound_email_hardened_ingest_relay_executor.md
		// § Phase 5). For MX-path Fortress mail the relay seals the WHOLE raw message to the
		// owner's vault public key (crypto_box_seal → SealedBox::openDek, NOT the per-message
		// DEK). While the owner is logged out the pull consumer (PullRelaySpool) can only store
		// operational metadata + this sealed blob in a PENDING-PARSE state: threading and unread
		// counts work, but subject/sender/body/attachments do not exist as fields yet. At the next
		// unlock DeferredIngest opens iem_relay_sealed_raw, runs the full pipeline (parse, filters,
		// attachment split, seal fields under a fresh per-message DEK), then clears both columns.
		// Standard/Private MX mail is opened at pull with the ambient transport key and ingested
		// immediately, so it is never pending. iem_relay_spool_id is the pull dedup key (a re-pull
		// of an un-acked-but-stored item is a no-op via the unique constraint).
		'iem_pending_parse'       => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		'iem_relay_sealed_raw'    => array('type'=>'text', 'is_nullable'=>true),
		'iem_relay_spool_id'      => array('type'=>'varchar(255)', 'is_nullable'=>true, 'unique'=>true),
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
		// AI triage (specs/implemented/joinery_ai_email_triage.md). One-line gist for the
		// inbox, written ONLY by EmailTriageJob::recordVerdict() (not
		// $ai_writable_fields). Content in miniature, so it is a sealed field
		// like the message body on a protected domain (see $sealed_fields) —
		// labels stay cleartext (operational metadata).
		'iem_ai_summary'          => array('type'=>'varchar(280)'),
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

	// ---------------------------------------------------------- encryption at rest

	/**
	 * Fields sealed only on a COMPOSED row (an outbound Sent copy or a saved draft):
	 * iem_recipient/iem_bcc carry real content there, but on an inbound row
	 * iem_recipient is the routing alias address (never sealed) and iem_bcc is absent;
	 * iem_draft_state exists only on a draft. The direction guard in decryptSealedField*()
	 * uses this so a broad read never tries to open an unsealed inbound recipient.
	 */
	public static function isComposeOnlyField(string $field): bool {
		return $field === 'iem_recipient' || $field === 'iem_bcc' || $field === 'iem_draft_state';
	}

	/** True for a row direction whose recipient/bcc/draft columns are sealed content. */
	public static function isComposedDirection(?string $direction): bool {
		return $direction === 'outbound' || $direction === 'draft';
	}

	/**
	 * The AD (additional data) row-binding string for a sealed content field —
	 * the single source of this convention (docs/sealed_vault.md § AD). Every
	 * sealer (InboundEmailRouter::storeMessage, MailboxSender::storeOutboundRow,
	 * the rotation re-seal callback) and every opener (decryptSealedField(),
	 * decryptSealedFieldStatic()) must build the identical string, or the AEAD
	 * open fails (by design — the splice defense).
	 */
	public static function sealAd(int $message_id, string $field): string {
		return 'mail:' . $message_id . ':' . $field;
	}

	/**
	 * The AD for a sealed attachment File's bytes — see sealAd(). Bound to the
	 * MIME part id (e.g. "2", "1.2"), not the ima_ manifest row's serial id:
	 * the part id is known before the manifest row is inserted (the seal
	 * happens while minting the File, one step before the manifest insert —
	 * InboundEmailRouter::extractAttachmentsToFiles), so there is no
	 * chicken-and-egg with an id that doesn't exist yet.
	 */
	public static function attachmentAd(int $message_id, string $mime_part): string {
		return 'mail:' . $message_id . ':att:' . $mime_part;
	}

	/**
	 * The AD for a sealed stored raw (the extraction-failure fallback shape on
	 * a sealed mailbox — see InboundEmailRouter::persistRawAndManifest) — see
	 * sealAd().
	 */
	public static function rawAd(int $message_id): string {
		return 'mail:' . $message_id . ':raw';
	}

	/**
	 * The single grantee who owns this alias's mailbox — the vault the message
	 * seals to. Mirrors InboundEmailRouter::attachmentOwnerId(): sealing only
	 * applies to a single-reader mailbox (the ProtonMail model this package
	 * targets); a shared alias or NULL/catch-all alias has no single owner to
	 * seal to and is never sealed (specs/implemented/inbound_email_encryption_at_rest.md § 4.3).
	 * Returns null when there is no single owner.
	 */
	public static function singleOwnerUserId(?int $alias_id): ?int {
		if (!$alias_id) {
			return null;
		}
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
		$grantees = InboundEmailMailboxGrant::user_ids_for_alias($alias_id);
		return (count($grantees) === 1) ? intval($grantees[0]) : null;
	}

	/**
	 * The vault owner a sealed row decrypts against: the owner recorded at
	 * seal time (iem_sealed_owner_user_id — immune to later grant/alias
	 * changes), falling back to the live single-grantee resolution only for a
	 * legacy row sealed before the column existed.
	 */
	private static function sealedOwnerUserId($sealed_owner, $alias_id): ?int {
		if ($sealed_owner !== null && intval($sealed_owner) > 0) {
			return intval($sealed_owner);
		}
		return self::singleOwnerUserId($alias_id !== null ? intval($alias_id) : null);
	}

	/**
	 * Sealed Vault generic read hook (docs/sealed_vault.md), the SystemBase::get()
	 * path for a loaded model. Returns the ciphertext unchanged for a row that was
	 * never sealed (iem_content_sealed = false / no iem_sealed_key — Standard-tier,
	 * or a row mid-ingest before Phase 4's UPDATE completes). Throws
	 * VaultLockedException when the owner's vault window is closed.
	 */
	protected function decryptSealedField($field, $ciphertext) {
		if (!$this->get('iem_content_sealed') || !$this->get('iem_sealed_key')) {
			return $ciphertext;
		}
		if (self::isComposeOnlyField($field) && !self::isComposedDirection($this->get('iem_direction'))) {
			return $ciphertext; // inbound row: recipient is the routing alias; bcc/draft absent
		}
		$owner_id = self::sealedOwnerUserId($this->get('iem_sealed_owner_user_id'), $this->get('iem_iea_inbound_email_alias_id'));
		if ($owner_id === null) {
			require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
			throw new VaultLockedException();
		}
		return self::openSealedField(intval($this->key), $owner_id, (string)$this->get('iem_sealed_key'), $field, $ciphertext);
	}

	/**
	 * Same as decryptSealedField(), for the raw-row path (MailboxService's direct
	 * SQL reads; plugins/joinery_ai/includes/ModelQueryExecutor.php) that never
	 * instantiates a model.
	 */
	public static function decryptSealedFieldStatic($field, $ciphertext, array $row) {
		if (empty($row['iem_content_sealed']) || empty($row['iem_sealed_key'])) {
			return $ciphertext;
		}
		if (self::isComposeOnlyField($field) && !self::isComposedDirection($row['iem_direction'] ?? 'inbound')) {
			return $ciphertext; // inbound row: recipient is the routing alias; bcc/draft absent
		}
		$owner_id = self::sealedOwnerUserId($row['iem_sealed_owner_user_id'] ?? null, $row['iem_iea_inbound_email_alias_id'] ?? null);
		if ($owner_id === null) {
			require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
			throw new VaultLockedException();
		}
		$message_id = intval($row['iem_inbound_email_message_id'] ?? 0);
		return self::openSealedField($message_id, $owner_id, (string)$row['iem_sealed_key'], $field, $ciphertext);
	}

	/** Shared opener: unwrap the per-message DEK (in-window), then the AEAD field. */
	private static function openSealedField(int $message_id, int $owner_id, string $sealed_key, string $field, string $ciphertext): string {
		$crypto = self::openMessageDekCrypto($owner_id, $sealed_key);
		return $crypto['crypto']->openField($ciphertext, $crypto['dek'], self::sealAd($message_id, $field));
	}

	/**
	 * Open one attachment's stored bytes for a message — the one
	 * implementation the File decrypt hook (plugins/mailbox/includes/
	 * bootstrap.php), the download endpoints (includes/attachment_retrieval.php)
	 * and a forward's re-attach path (MailboxSender::readOriginalPartBytes) all
	 * call, so the owner/window/vault resolution lives once. Whether the bytes
	 * are sealed is the ATTACHMENT row's ima_is_sealed — a per-file fact (a
	 * backfilled message's pre-vault Files stay plaintext while its body is
	 * sealed), never inferred from the message's own flags. Throws
	 * VaultLockedException when the owner's window is closed or no owner is
	 * resolvable.
	 */
	public static function openSealedAttachment(InboundEmailMessage $msg, InboundMessageAttachment $att, string $bytes): string {
		if (!$att->get('ima_is_sealed')) {
			return $bytes; // stored plaintext - nothing to open
		}
		$owner_id = self::sealedOwnerUserId($msg->get('iem_sealed_owner_user_id'), $msg->get('iem_iea_inbound_email_alias_id'));
		if ($owner_id === null || !$msg->get('iem_sealed_key')) {
			require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
			throw new VaultLockedException();
		}
		$message_id = intval($msg->key);
		$crypto = self::openMessageDekCrypto($owner_id, (string)$msg->get('iem_sealed_key'));
		return $crypto['crypto']->openField($bytes, $crypto['dek'], self::attachmentAd($message_id, (string)$att->get('ima_mime_part')));
	}

	/**
	 * Unwrap a sealed row's per-message DEK (raw bytes) for its owner, in-window.
	 * Returns null when the owner's unlock window is closed. Used by the draft
	 * save path to re-seal an existing draft's content under its SAME DEK, keeping
	 * already-persisted draft attachments (sealed under that DEK) readable.
	 */
	public static function unwrapDekInWindow(int $owner_id, string $sealed_key): ?string {
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
		require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
		$secret = VaultUnlock::secretKey($owner_id);
		if ($secret === null) {
			return null;
		}
		$crypto = new VaultCrypto();
		return $crypto->openItemDek($sealed_key, $secret);
	}

	/** @return array{crypto:VaultCrypto,dek:string} */
	private static function openMessageDekCrypto(int $owner_id, string $sealed_key): array {
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
		require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));

		$secret = VaultUnlock::secretKey($owner_id);
		if ($secret === null) {
			throw new VaultLockedException();
		}
		$crypto = new VaultCrypto();
		$dek = $crypto->openItemDek($sealed_key, $secret);
		return array('crypto' => $crypto, 'dek' => $dek);
	}

	/**
	 * Seal a just-inserted row's content columns and UPDATE it in place —
	 * shared by InboundEmailRouter::storeMessage (inbound) and
	 * MailboxSender::storeOutboundRow (outbound; also seals $recipient, since
	 * an outbound row's recipient list is real content — see $sealed_fields).
	 * $vault is the OWNER's vault (the alias's single grantee), which is who
	 * the DEK seals to either direction. Returns the per-message DEK (raw
	 * bytes) so the caller can also seal this message's attachments under the
	 * same key.
	 */
	public static function sealAndPersistContent(int $message_id, UserEncryptionVault $vault, string $sender,
			string $recipient, string $subject, string $body_plain, string $body_html,
			bool $seal_recipient = false, string $bcc = '', ?string $draft_state = null,
			?string $reuse_dek = null): string {
		require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

		$crypto = new VaultCrypto();
		// A saved draft re-seals its content under the SAME DEK on every save
		// ($reuse_dek), so its already-persisted attachments (sealed under that DEK)
		// stay readable; the DEK wrapping (iem_sealed_key/generation/owner) is left
		// untouched in that case. A fresh row mints a new DEK and records its wrapping.
		$reuse = ($reuse_dek !== null);
		$dek = $reuse ? $reuse_dek : $crypto->newItemDek();
		$sealed_key = $reuse ? null : $crypto->sealItemDek($dek, (string)$vault->get('uev_public_key'));

		// The always-sealed content columns. iem_recipient/iem_bcc are added only
		// for a composed row ($seal_recipient) — an inbound row's iem_recipient is
		// the routing alias, already written at insert and left alone here; iem_bcc
		// and iem_draft_state exist only on composed/draft rows.
		$columns = array(
			'iem_sender'     => $crypto->sealField($sender, $dek, self::sealAd($message_id, 'iem_sender')),
			'iem_subject'    => $crypto->sealField($subject, $dek, self::sealAd($message_id, 'iem_subject')),
			'iem_body_plain' => $crypto->sealField($body_plain, $dek, self::sealAd($message_id, 'iem_body_plain')),
			'iem_body_html'  => $crypto->sealField($body_html, $dek, self::sealAd($message_id, 'iem_body_html')),
		);
		if ($seal_recipient) {
			$columns['iem_recipient'] = $crypto->sealField($recipient, $dek, self::sealAd($message_id, 'iem_recipient'));
			if ($bcc !== '') {
				$columns['iem_bcc'] = $crypto->sealField($bcc, $dek, self::sealAd($message_id, 'iem_bcc'));
			}
		}
		if ($draft_state !== null) {
			$columns['iem_draft_state'] = $crypto->sealField($draft_state, $dek, self::sealAd($message_id, 'iem_draft_state'));
		}

		// The owner is recorded ON the row at seal time (iem_sealed_owner_user_id)
		// so decryption never depends on the grant list as it happens to look
		// later — a grant addition or alias deletion must not strand sealed mail.
		$sets = array();
		$params = array();
		foreach ($columns as $col => $val) {
			$sets[] = $col . ' = ?';
			$params[] = $val;
		}
		// Only a freshly-minted DEK writes the wrapping columns; a reused DEK leaves
		// the existing iem_sealed_key/generation/owner in place.
		if (!$reuse) {
			$sets[] = 'iem_sealed_key = ?';           $params[] = $sealed_key;
			$sets[] = 'iem_key_generation = ?';       $params[] = intval($vault->get('uev_key_generation'));
			$sets[] = 'iem_sealed_owner_user_id = ?'; $params[] = intval($vault->get('uev_usr_user_id'));
		}
		$sets[] = 'iem_content_sealed = true';
		$params[] = $message_id;

		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('UPDATE iem_inbound_email_messages SET ' . implode(', ', $sets)
			. ' WHERE iem_inbound_email_message_id = ?');
		$stmt->execute($params);
		return $dek;
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

		$raw = null;
		if ($driver === 'inline') {
			$inline = $this->get('iem_raw_message');
			$raw = ($inline === null || $inline === '') ? null : (string)$inline;
		} elseif ($driver === 'local' || $driver === 'cloud') {
			require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RawMessageStore.php'));
			try {
				$raw = RawMessageStore::read($driver, $key);
			} catch (Throwable $e) {
				error_log('InboundEmailMessage::getRawMessage failed (driver=' . $driver
					. ', id=' . $this->key . '): ' . $e->getMessage());
				return null;
			}
		}
		// 'remote' — the whole raw is never reconstructed; callers fetch the one
		// part they need via ImapIngestor::fetchPart().
		if ($raw === null) {
			return null;
		}

		// A sealed-mailbox extraction-failure fallback stores the raw as one
		// AEAD blob under the message DEK (InboundEmailRouter::
		// persistRawAndManifest) — open it in-window; a closed window raises
		// VaultLockedException like every other sealed read.
		if ($this->get('iem_raw_sealed')) {
			$owner_id = self::sealedOwnerUserId($this->get('iem_sealed_owner_user_id'), $this->get('iem_iea_inbound_email_alias_id'));
			if ($owner_id === null || !$this->get('iem_sealed_key')) {
				require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
				throw new VaultLockedException();
			}
			$crypto = self::openMessageDekCrypto($owner_id, (string)$this->get('iem_sealed_key'));
			return $crypto['crypto']->openField($raw, $crypto['dek'], self::rawAd(intval($this->key)));
		}
		return $raw;
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
			require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_message_attachment_class.php'));
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
				require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RawMessageStore.php'));
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

	/**
	 * Targeted single-row UPDATE of exactly the given columns — never a full-row
	 * save(). Sealed-mailbox ingest writes some columns behind the model's back
	 * (sealAndPersistContent / persistRawAndManifest UPDATE by id), so a full
	 * save() from a stale in-memory object would clobber the sealed sender/subject/
	 * body/key/raw-storage descriptor with empty values. Callers that only need to
	 * flip a few columns (pending-parse clear, spool-id stamp, spam verdict) use
	 * this instead. $columns maps column name => value (null allowed).
	 */
	static function updateColumns(int $message_id, array $columns): void {
		if ($message_id <= 0 || empty($columns)) {
			return;
		}
		$sets = array();
		$params = array();
		foreach ($columns as $col => $value) {
			if (!array_key_exists($col, static::$field_specifications)) {
				continue; // never build SQL from an unknown column name
			}
			$sets[] = $col . ' = ?';
			$params[] = $value;
		}
		if (empty($sets)) {
			return;
		}
		$params[] = $message_id;
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			'UPDATE iem_inbound_email_messages SET ' . implode(', ', $sets)
			. ' WHERE iem_inbound_email_message_id = ?'
		);
		// Typed binding: pdo_pgsql stringifies an untyped PHP false to '', which
		// PostgreSQL rejects for boolean columns (22P02).
		foreach (array_values($params) as $i => $value) {
			$type = PDO::PARAM_STR;
			if (is_bool($value))     { $type = PDO::PARAM_BOOL; }
			elseif ($value === null) { $type = PDO::PARAM_NULL; }
			elseif (is_int($value))  { $type = PDO::PARAM_INT; }
			$stmt->bindValue($i + 1, $value, $type);
		}
		$stmt->execute();
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

		// subject/body/sender ILIKE search removed (specs/implemented/
		// inbound_email_encryption_at_rest.md § 4.5) — those columns hold
		// ciphertext once sealed and can never be scanned in SQL. Full-text
		// search is MailboxIndex's FTS5 id-whitelist (plugins/mailbox/includes/
		// MailboxIndex.php), joined into MailboxService::listThreads() instead.

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
