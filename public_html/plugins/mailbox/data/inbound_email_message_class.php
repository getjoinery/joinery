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
 * AuthenticationResults. iem_auth_source records where the verdict came from —
 * 'milter' (our own MTA), 'relay' (the fronting relay's milters), a webhook
 * provider key ('mailgun' / 'sendgrid' / 'ses'), or 'none'. When no trusted
 * verdict is present the columns read 'unverified', never a hand-rolled 'fail'.
 * authIsVerified() / authReadout() below are the ONE place that turns a source
 * into "does this row's verdict mean anything, and what does it mean to a
 * person" — display surfaces ask them rather than keeping their own list.
 * See InboundEmailRouter and AuthenticationResults.
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
 * LOWERING UNSEAL (specs/mailbox_lowering_unseal.md). unsealAndPersistContent() is the
 * per-row inverse of sealing — owner-window-only, recovery-safe ordering (key wrapping
 * cleared last). aliasSealedContentActive() is the search-path key: the sealed FTS index
 * serves a mailbox only while sealed content actually remains.
 *
 * @version 1.24
 * @changelog 1.24 - iem_raw_headers: the RFC822 header block retained at push
 *   ingest (specs/mailbox_show_original_coverage.md), a sealed optional field
 *   on every direction, so Show original can answer a lean record with the
 *   wire headers instead of nothing.
 * @version 1.23
 * @changelog 1.23 - openSealedAttachment() honors a redeemed serve grant
 *   (includes/FileServeGrant.php) for both sealed shapes, so a cookie-less
 *   signed fetch decrypts what its signature already authorizes
 *   (specs/bugfix_sealed_inline_images.md). No grant → the owner's window,
 *   exactly as before.
 * @version 1.22
 * @changelog 1.22 - iem_reseal_pending + a narrow decryptSealedFieldStatic()
 *   override (specs/bugfix_promoted_sent_row_sealing.md): a Sent-folder
 *   direction promotion leaves the inbound-written plaintext recipient on a now-
 *   outbound sealed row. The override hands that one enumerated shape back as
 *   the true value instead of tripping the corruption check; PromotedRowRepair
 *   seals it under the row DEK at the owner's next unlocked visit.
 * @version 1.21
 * @changelog 1.21 - getRawMimePart() parses through MimeParse, so a message
 *   whose body quotes its own MIME boundary answers null instead of hanging
 *   the request
 * @version 1.20
 * @changelog 1.20 - openSealedAttachment() understands the self-sealed File
 *   shape (specs/mailbox_attachment_byte_custody.md) alongside the legacy
 *   message-DEK one, so no reader can hand back container bytes as plaintext.
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundEmailMessageException extends SystemBaseException {}

class InboundEmailMessage extends SystemBase {
	public static $prefix = 'iem';
	public static $tablename = 'iem_inbound_email_messages';
	public static $pkey_column = 'iem_inbound_email_message_id';

	// Retention: Trash. A method rather than an age column because each row owns
	// attachment Files and a stored raw object that a bulk DELETE would leak.
	// 0 in the setting means never purge — the mail reader shows each trashed
	// message its purge date from this same setting.
	public static $retention_policy = array(
		'label'          => 'Mailbox trash',
		'purge_method'   => 'purgeExpiredTrash',
		'window_setting' => 'mailbox_trash_retention_days',
	);

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
	public static $sealed_fields = array('iem_sender', 'iem_subject', 'iem_body_plain', 'iem_body_html', 'iem_recipient', 'iem_bcc', 'iem_draft_state', 'iem_ai_summary', 'iem_ai_scan', 'iem_raw_headers');

	// Sealing runs through this class's own sealAndPersistContent() /
	// sealExistingRow() paths,
	// which reuse the message DEK so attachments and the raw message stay
	// readable under the same key.
	public static $seal_on_save = false;

	/**
	 * Sealed columns that are legitimately absent on a given row: a message may
	 * never have been AI-triaged, and only a composed row carries bcc or draft
	 * state. An empty value in one of these is nothing rather than ciphertext,
	 * so the unseal pass skips it instead of trying to AEAD-open ''.
	 *
	 * Declared beside $sealed_fields on purpose. It used to be an array literal
	 * buried in unsealAndPersistContent(), which meant adding a sealed optional
	 * column silently broke unsealing until someone ran the lowering test — the
	 * same "the safe thing is the thing you have to remember" shape this file's
	 * updateContentColumns() note describes.
	 */
	public static $optional_sealed_fields = array('iem_bcc', 'iem_draft_state', 'iem_ai_summary', 'iem_ai_scan', 'iem_raw_headers');

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
		// permanent_delete, not cascade: this model's permanent_delete() override
		// deletes file-backed attachment bytes, and a flat SQL delete skips it.
		'iem_ied_inbound_email_domain_id' => ['action' => 'permanent_delete'],
		'iem_iea_inbound_email_alias_id'  => ['action' => 'null'],
		'iem_iia_inbound_imap_account_id' => ['action' => 'null'],
		// Deleting the import run must never delete the mail it brought in — that
		// is what Undo is for, and it is an explicit choice. Losing the tag only
		// costs the ability to reverse the run later.
		'iem_mir_mail_import_run_id'      => ['action' => 'null'],
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
		// The RFC822 header block, retained at push ingest even though the lean
		// record discards the raw (specs/mailbox_show_original_coverage.md): the
		// wire truth (Received chain, charsets, DKIM as sent) that debugging
		// needs and no parsed column preserves. Sealed content (a $sealed_fields
		// member — headers name correspondents); absent on rows stored before
		// the column existed and on composed rows, which have no wire original.
		'iem_raw_headers'         => array('type'=>'text', 'is_nullable'=>true),
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
		// DEK). While the owner is logged out the pull consumer (the relay reconcile task) can only store
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
		// Sealing debt from a Sent-folder direction promotion (specs/bugfix_promoted_sent_row_sealing.md).
		// An IMAP-stored row is sealed as INBOUND — recipient in the clear, which is
		// correct there — and a later promotion to outbound makes that plaintext a
		// sealed-contract violation the promoting cron process cannot pay (sealing
		// under the row's existing DEK needs the owner's in-window secret). This flag
		// marks the debt; PromotedRowRepair (a VaultDeferredWork consumer) pays it at
		// the owner's next unlocked visit. The repair predicate ALSO matches rows
		// where the flag was never set (a plaintext recipient on a sealed outbound
		// row is the debt, flag or no flag), so pre-existing broken rows heal too.
		'iem_reseal_pending'      => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		// How this message reached the box, and whether it earned the
		// verified-direct mark (docs/joinery_direct.md § The social signal).
		//
		// The mark asserts exactly two things: the sending INSTANCE was
		// cryptographically verified, and the sender is in THIS recipient's
		// contacts. Not "trusted human". It is applied by the receiver from
		// verified transport plus contact membership, never from anything in the
		// message itself, which is what makes it unforgeable from content — and
		// it never appears on the SMTP fallback path.
		'iem_transport'           => array('type'=>'varchar(20)', 'is_nullable'=>true), // null = SMTP/IMAP as before; 'joinery_direct'
		'iem_direct_verified'     => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
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
		// {verdict, red_flags, summary, model, recipe_id}, stored as JSON text.
		// text rather than jsonb because it is a $sealed_fields member: on a
		// sealed row the column holds an AEAD blob, which is not JSON. Readers
		// json_decode it after decryption, exactly as before.
		'iem_ai_scan'             => array('type'=>'text'),
		'iem_ai_scan_time'        => array('type'=>'timestamp(6)'),
		// AI triage (specs/implemented/joinery_ai_email_triage.md). One-line gist for the
		// inbox, written ONLY by EmailTriageJob::recordVerdict() (not
		// $ai_writable_fields). Content in miniature, so it is a sealed field
		// like the message body on a protected domain (see $sealed_fields) —
		// labels stay cleartext (operational metadata).
		// The prompt caps the summary at 280 characters, but this column is a
		// $sealed_fields member and must hold the SEALED form: 'v1.aead.' plus a
		// base64 nonce and ciphertext, which is roughly 1.4x the plaintext plus
		// 42 characters. varchar(280) overflowed for any summary past ~170
		// characters, so a sealed row's first summary was a Postgres error.
		'iem_ai_summary'          => array('type'=>'text'),
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
		// The archive import run that created this row (specs/mail_archive_import.md
		// §3.3). NULL for everything that arrived normally, and this tag IS the undo
		// mechanism: reversing a run permanently deletes exactly the rows carrying its
		// id. A message that deduped against mail already present is never tagged —
		// the tag is only written on a fresh insert — so undo cannot remove mail the
		// import did not create.
		'iem_mir_mail_import_run_id' => array('type'=>'int8', 'is_nullable'=>true, 'index'=>true),
		'iem_received_time'       => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'iem_create_time'         => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'iem_delete_time'         => array('type'=>'timestamp(6)'),
	);

	/**
	 * "Has mail ever arrived for this address" — the Setup tab's end-to-end check
	 * and the Accounts listing's badge both ask it, and both must filter on
	 * lower(iem_recipient) because stored addresses are genuinely mixed-case.
	 * A plain btree on the raw column cannot serve that, so this is an
	 * expression index.
	 *
	 * Partial on inbound, which is both smaller and semantically right:
	 * iem_recipient is the plain routing address only on an inbound row. On a
	 * composed row (outbound or draft) it is sealed content, and indexing
	 * ciphertext by lower() would be meaningless.
	 */
	public static $index_specifications = array(
		array('columns' => array('LOWER(iem_recipient)'), 'where' => "iem_direction = 'inbound'"),
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
	 * Human names for the things that can verify a message, keyed by
	 * iem_auth_source. A source absent from this map is a source nothing here
	 * checked — see authIsVerified().
	 */
	private static $AUTH_SOURCE_NAMES = array(
		'milter'   => 'this mail server',
		'relay'    => 'your mail relay',
		'mailgun'  => 'Mailgun',
		'sendgrid' => 'SendGrid',
		'ses'      => 'Amazon SES',
	);

	/**
	 * Whether a stored verdict came from something trusted, i.e. whether the
	 * SPF/DKIM/DMARC columns mean anything on this row.
	 *
	 * Derived from the source map, never from a hand-kept list at each call site:
	 * every consumer that hardcoded its own pair of source names silently called
	 * relay- and SES-verified mail "unverified" for as long as that list lagged
	 * the router.
	 */
	public static function authIsVerified(?string $source): bool {
		return isset(self::$AUTH_SOURCE_NAMES[strtolower(trim((string)$source))]);
	}

	/**
	 * A plain-language readout of a message's authentication state, for every
	 * surface that shows one (the reader, the admin message view).
	 *
	 * Leads with what it means to a person — did this really come from who it
	 * says? — and keeps SPF/DKIM/DMARC as supporting detail rather than the
	 * headline. Where nothing checked the message it says WHY, because the
	 * commonest reason by far is benign: imported and IMAP-collected mail was
	 * never received by a mail server of ours, so there was nothing to verify.
	 *
	 * This is a READOUT, not a disposition. What a verdict does to a message is
	 * InboundEmailRouter::classifySpam()'s call and nothing here should be read
	 * as duplicating it — the states below are deliberately coarser (a reader
	 * needs "can I trust this sender", not the filing rule).
	 *
	 * @param string|null $source  iem_auth_source
	 * @param string|null $spf     iem_spf_result
	 * @param string|null $dkim    iem_dkim_result
	 * @param string|null $dmarc   iem_dmarc_result
	 * @param string|null $origin  'import' | 'imap' | null — how the row arrived,
	 *                             used only to explain an unchecked message.
	 * @return array{state:string,headline:string,detail:string,checked_by:?string}
	 *         state is 'verified' | 'failed' | 'partial' | 'unchecked'.
	 */
	public static function authReadout(?string $source, ?string $spf, ?string $dkim,
			?string $dmarc, ?string $origin = null): array {
		$norm = function ($v) { return strtolower(trim((string)$v)); };

		if (!self::authIsVerified($source)) {
			if ($origin === 'import') {
				$detail = 'imported from a mail archive, so it never arrived here to be checked';
			} elseif ($origin === 'imap') {
				$detail = 'collected from another mailbox, which did its own checks';
			} else {
				$detail = 'this message did not arrive through your mail server';
			}
			return array('state' => 'unchecked', 'headline' => 'Sender not checked',
				'detail' => $detail, 'checked_by' => null);
		}

		$spf = $norm($spf); $dkim = $norm($dkim); $dmarc = $norm($dmarc);
		$checked_by = self::$AUTH_SOURCE_NAMES[$norm($source)];

		// DMARC is alignment-based and subsumes the other two, so where it has an
		// opinion it is the whole answer. Otherwise fall back to the pair.
		if ($dmarc === 'pass') {
			$state = 'verified';
		} elseif ($dmarc === 'fail') {
			$state = 'failed';
		} elseif ($spf === 'pass' && $dkim === 'pass') {
			$state = 'verified';
		} elseif ($spf === 'fail' && $dkim === 'fail') {
			$state = 'failed';
		} else {
			$state = 'partial';
		}

		$headlines = array(
			'verified' => 'Sender verified',
			'failed'   => 'Sender could NOT be verified',
			'partial'  => 'Sender partly verified',
		);
		$detail = 'SPF ' . ($spf ?: 'none') . ' · DKIM ' . ($dkim ?: 'none')
			. ' · DMARC ' . ($dmarc ?: 'none');

		return array('state' => $state, 'headline' => $headlines[$state],
			'detail' => $detail, 'checked_by' => $checked_by);
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
	 * The single grantee who owns this alias's mailbox. Sealing only applies to
	 * a single-reader mailbox (the ProtonMail model this package targets), so a
	 * shared mailbox has no single owner and returns null.
	 *
	 * This answers only "who owns this MAILBOX". For "whose key does this
	 * MESSAGE seal to" — which also covers mail that belongs to no mailbox —
	 * use sealOwnerUserId().
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
	 * Whose vault a message seals to (specs/mailbox_unmatched_sealing.md).
	 *
	 * A message in a mailbox seals to that mailbox's single owner. A message
	 * that belongs to NO mailbox — accepted by the domain's catch-all for an
	 * address nobody created, like postmaster@ or a typo — seals to the DOMAIN's
	 * owner. It arrived for that domain, so the domain's owner is whose it is.
	 * Without this fallback such mail has no key at all and is written in
	 * plaintext on a domain whose whole purpose is that it is not, and the only
	 * remedy would be creating a mailbox for every address a stranger invents.
	 *
	 * The fallback is deliberately ONLY for "no mailbox". A mailbox with no
	 * owner, or with several, still returns null: sealing that to the domain
	 * owner would hand someone else's mail to a third party and quietly defeat
	 * the one-reader rule the protection ceremony enforces. Those stay blocked
	 * until an operator fixes the mailbox.
	 *
	 * Returns null when nothing resolves — the caller stores plaintext (Standard)
	 * or leaves the row in the backlog (a sealing level).
	 */
	public static function sealOwnerUserId(?int $alias_id, ?int $domain_id): ?int {
		if ($alias_id) {
			return self::singleOwnerUserId($alias_id);
		}
		return self::domainOwnerUserId($domain_id);
	}

	/**
	 * The domain's owner (ied_owner_usr_user_id) — the same person whose vault
	 * seals the domain's DKIM key. Null when the domain has none, which the
	 * protection ceremony refuses to allow at a sealing level.
	 */
	public static function domainOwnerUserId(?int $domain_id): ?int {
		if (!$domain_id) {
			return null;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare('SELECT ied_owner_usr_user_id FROM ied_inbound_email_domains
			WHERE ied_inbound_email_domain_id = ? AND ied_delete_time IS NULL');
		$q->execute(array($domain_id));
		$owner = $q->fetchColumn();
		return ($owner !== false && intval($owner) > 0) ? intval($owner) : null;
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
	 * Whose vault a message's sealed content belongs to: the owner recorded at
	 * seal time, with a live grantee lookup as the fallback for a row sealed
	 * before that column existed. Public so a consumer sealing something ONTO an
	 * existing message (the byte-custody upgrade,
	 * specs/mailbox_attachment_byte_custody.md) wraps it to the same vault the
	 * message's own content is under, rather than re-deriving the answer and
	 * risking a different one.
	 */
	public static function sealedOwnerFor(InboundEmailMessage $msg): ?int {
		return self::sealedOwnerUserId(
			$msg->get('iem_sealed_owner_user_id'),
			$msg->get('iem_iea_inbound_email_alias_id')
		);
	}

	/**
	 * Sealed Vault read hooks (docs/sealed_vault.md). Both the loaded-model path
	 * (SystemBase::get()) and the raw-row path (MailboxService's direct SQL reads,
	 * joinery_ai's ModelQueryExecutor) are the SystemBase Layer 0 generics; the
	 * four convention columns are declared above, so this class supplies only the
	 * two things that are genuinely its own.
	 *
	 * A row that was never sealed (iem_content_sealed = false / no iem_sealed_key —
	 * Standard tier, or a row mid-ingest before Phase 4's UPDATE lands) reads back
	 * unchanged; a sealed row with the owner's window closed throws
	 * VaultLockedException.
	 *
	 * First: iem_recipient/iem_bcc/iem_draft_state hold real content only on a
	 * composed row. On an inbound row the recipient IS the routing alias, written
	 * in the clear at insert, so a broad read must not try to open it.
	 */
	protected static function sealedFieldIsActive(string $field, array $row): bool {
		if (!self::isComposeOnlyField($field)) {
			return true;
		}
		return self::isComposedDirection($row['iem_direction'] ?? 'inbound');
	}

	/**
	 * Second: the owner recorded at seal time wins, with a live grantee lookup as
	 * the fallback for a row sealed before that column existed.
	 */
	protected static function sealedOwnerUserIdFor(array $row): ?int {
		return self::sealedOwnerUserId(
			$row['iem_sealed_owner_user_id'] ?? null,
			$row['iem_iea_inbound_email_alias_id'] ?? null
		);
	}

	/**
	 * Third: one enumerated exception to the plaintext-on-sealed-row tripwire
	 * (specs/bugfix_promoted_sent_row_sealing.md). A Sent-folder direction
	 * promotion (ImapIngestor::markDirectionOutbound) turns an inbound row —
	 * whose iem_recipient is legitimately cleartext routing metadata — into an
	 * outbound row, where the direction guard expects that column sealed. The
	 * promoting cron process cannot seal it (no unlock window), so until
	 * PromotedRowRepair pays the debt in-window, the stored plaintext IS the
	 * true recipient: hand it back rather than throwing.
	 *
	 * Deliberately narrow — iem_recipient only, outbound only. A draft, any
	 * other column, or any other shape of plaintext under a sealed flag still
	 * trips the parent's corruption check, which is the tripwire's whole job.
	 */
	public static function decryptSealedFieldStatic($field, $ciphertext, array $row) {
		if ($field === 'iem_recipient'
				&& ($row['iem_direction'] ?? '') === 'outbound'
				&& is_string($ciphertext) && $ciphertext !== ''
				&& strpos($ciphertext, 'v1.aead.') !== 0
				&& static::rowArrayIsSealed($row)) {
			return $ciphertext;
		}
		return parent::decryptSealedFieldStatic($field, $ciphertext, $row);
	}

	/**
	 * Open one attachment's stored bytes for a message — the one
	 * implementation the File decrypt hook (plugins/mailbox/includes/
	 * bootstrap.php), the download endpoints (includes/attachment_retrieval.php)
	 * and a forward's re-attach path (MailboxSender::readOriginalPartBytes) all
	 * call, so the owner/window/vault resolution lives once. Throws
	 * VaultLockedException when the owner's window is closed or no owner is
	 * resolvable.
	 *
	 * TWO SEALED SHAPES, dispatched in this order — self-sealed File, then
	 * legacy message-DEK, then plaintext:
	 *
	 *  - fil_content_sealed on the FILE (specs/mailbox_attachment_byte_custody.md):
	 *    the bytes are a SealedFileContainer under the File's OWN key, wrapped to
	 *    the owner's vault. This is the shape every other sealed consumer uses,
	 *    and the only one that can be written without an open window — which is
	 *    what lets a background import seal bytes onto an existing message.
	 *  - ima_is_sealed on the ATTACHMENT row: an AEAD blob under the owning
	 *    MESSAGE's DEK. A per-file fact (a backfilled message's pre-vault Files
	 *    stay plaintext while its body is sealed), never inferred from the
	 *    message's own flags.
	 *
	 * The two are never both true on one attachment. $file is optional purely to
	 * save a re-load: a caller that does not pass it still gets plaintext, never
	 * container bytes, because the File is resolved from the row.
	 */
	public static function openSealedAttachment(InboundEmailMessage $msg, InboundMessageAttachment $att, string $bytes,
			?File $file = null): string {
		// Self-sealed File first: it carries its own key, so nothing about the
		// message is consulted. A redeemed serve grant (includes/
		// FileServeGrant.php) supplies the key on a cookie-less signed fetch;
		// otherwise the owner's window, as always.
		$fil_id = intval($att->get('ima_fil_file_id'));
		if ($fil_id > 0) {
			if ($file === null || intval($file->key) !== $fil_id) {
				$file = new File($fil_id, TRUE);
			}
			if ($file->key && $file->get('fil_content_sealed')) {
				$fk = FileServeGrant::activeKey($fil_id, FileServeGrant::SHAPE_FILE_KEY);
				if ($fk === null) {
					$fk = DriveSealed::fileKey($file); // throws VaultLockedException when closed
				}
				return SealedFileContainer::openBytes($bytes, $fk);
			}
		}

		if (!$att->get('ima_is_sealed')) {
			return $bytes; // stored plaintext - nothing to open
		}
		$message_id = intval($msg->key);
		$ad = self::attachmentAd($message_id, (string)$att->get('ima_mime_part'));
		if ($fil_id > 0) {
			// Message-DEK shape under a grant: the DEK was unwrapped at mint
			// time, in-window; the AD is rebuilt from the manifest row exactly
			// as the vault path below rebuilds it.
			$dek = FileServeGrant::activeKey($fil_id, FileServeGrant::SHAPE_MESSAGE_DEK);
			if ($dek !== null) {
				require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
				$crypto = new VaultCrypto();
				return $crypto->openField($bytes, $dek, $ad);
			}
		}
		$owner_id = self::sealedOwnerUserId($msg->get('iem_sealed_owner_user_id'), $msg->get('iem_iea_inbound_email_alias_id'));
		if ($owner_id === null || !$msg->get('iem_sealed_key')) {
			require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
			throw new VaultLockedException();
		}
		$crypto = self::openMessageDekCrypto($owner_id, (string)$msg->get('iem_sealed_key'));
		return $crypto['crypto']->openField($bytes, $crypto['dek'], $ad);
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
			?string $reuse_dek = null, ?string $raw_headers = null): string {
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

		// The always-sealed content columns. iem_recipient/iem_bcc are added only
		// for a composed row ($seal_recipient) — an inbound row's iem_recipient is
		// the routing alias, already written at insert and left alone here; iem_bcc
		// and iem_draft_state exist only on composed/draft rows.
		$columns = array(
			'iem_sender'     => $sender,
			'iem_subject'    => $subject,
			'iem_body_plain' => $body_plain,
			'iem_body_html'  => $body_html,
		);
		if ($seal_recipient) {
			$columns['iem_recipient'] = $recipient;
			if ($bcc !== '') {
				$columns['iem_bcc'] = $bcc;
			}
		}
		if ($draft_state !== null) {
			$columns['iem_draft_state'] = $draft_state;
		}
		// The retained wire header block (specs/mailbox_show_original_coverage.md).
		// Present only on ingested push rows — composed rows have no wire original,
		// so their callers pass nothing and the optional column stays empty.
		if ($raw_headers !== null && $raw_headers !== '') {
			$columns['iem_raw_headers'] = $raw_headers;
		}

		// SystemBase::sealColumns() mints or reuses the DEK, seals each value under
		// this class's sealAd(), records the wrapping (iem_sealed_key/generation/
		// owner) on a fresh DEK only, sets the row flag, and UPDATEs — one statement.
		// Recording the owner ON the row is what keeps decryption independent of how
		// the grant list looks later: a grant addition or alias deletion must never
		// strand sealed mail.
		return static::sealColumns($message_id, $vault, $columns, $reuse_dek);
	}

	/**
	 * Seal an EXISTING plaintext row — the level-raise path (a Standard domain
	 * promoted to Private/Fortress, whose stored mail must catch up).
	 *
	 * Unlike sealAndPersistContent(), which is handed the values at ingest, this
	 * reads the row and seals every $sealed_fields column that currently holds
	 * plaintext. That difference matters: a message triaged while the domain was
	 * Standard carries derived content — iem_ai_summary, iem_ai_scan — that an
	 * enumerated column list silently leaves in the clear under a sealed flag.
	 * Which is both the leak this whole area exists to close, and a broken read:
	 * every later read of that column would try to AEAD-open plaintext.
	 *
	 * Column selection uses sealedFieldIsActive() — the same predicate the READ
	 * path uses — so the two can never disagree about which columns hold content
	 * on this row's direction, and a sealed column added later is covered here
	 * without anyone remembering to add it.
	 *
	 * Takes UNSEALED rows only — an already-sealed row throws, because sealing
	 * mints a fresh DEK and replacing the row's key wrapping would strand the
	 * existing ciphertext. Returns the row DEK for sealing this message's
	 * attachments.
	 */
	public static function sealExistingRow(InboundEmailMessage $msg, UserEncryptionVault $vault): string {
		$message_id = intval($msg->key);
		if ($message_id <= 0) {
			throw new InboundEmailMessageException('sealExistingRow() needs a persisted row.');
		}

		// Straight from the row: the model's get() would decrypt, and this pass
		// needs the stored bytes to tell plaintext from an existing seal.
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT * FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
		$stmt->execute(array($message_id));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$row) {
			throw new InboundEmailMessageException('sealExistingRow(): row ' . $message_id . ' not found.');
		}
		// Refuse an already-sealed row rather than silently re-wrapping it:
		// sealColumns() below MINTS a fresh DEK, and writing that wrapping over
		// iem_sealed_key would strand every column and attachment sealed under
		// the old one. Both callers select iem_content_sealed = false; this
		// guard is for the caller that one day doesn't.
		if (static::rowArrayIsSealed($row)) {
			throw new InboundEmailMessageException(
				'sealExistingRow(): row ' . $message_id . ' is already sealed — '
				. 're-sealing would replace its key wrapping and strand the existing ciphertext.');
		}

		$columns = array();
		foreach (static::$sealed_fields as $field) {
			if (!static::sealedFieldIsActive($field, $row)) {
				continue; // metadata on this direction, not content
			}
			$value = $row[$field] ?? null;
			if (!is_string($value) || $value === '') {
				continue; // nothing stored yet
			}
			if (strpos($value, 'v1.aead.') === 0) {
				continue; // already sealed
			}
			$columns[$field] = $value;
		}

		return static::sealColumns($message_id, $vault, $columns);
	}

	/**
	 * Unseal one row back to plaintext — the inverse of sealAndPersistContent(),
	 * for a domain that no longer seals (specs/mailbox_lowering_unseal.md). Runs
	 * only inside the sealed OWNER's unlock window: unsealing needs the
	 * per-message DEK, which unwraps only with their in-window secret key.
	 * Returns false — row untouched — when the window is closed, no owner
	 * resolves, or any decrypt fails (logged).
	 *
	 * Recovery-safe ordering: every ciphertext decrypts into memory FIRST (any
	 * failure aborts before a byte is written), attachment and raw bytes write
	 * back next (per-file/per-flag as each write lands — readers key on those
	 * flags, so a partial pass stays consistent), and the column UPDATE that
	 * clears the key wrapping runs LAST. An interruption always leaves a
	 * still-sealed row whose next pass converges — never a stranded ciphertext
	 * whose key was already discarded.
	 */
	public static function unsealAndPersistContent(InboundEmailMessage $msg): bool {
		require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_message_attachment_class.php'));
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RawMessageStore.php'));

		$message_id = intval($msg->key);
		if (!$message_id || !$msg->get('iem_content_sealed')) {
			return false;
		}
		$owner_id = self::sealedOwnerUserId($msg->get('iem_sealed_owner_user_id'), $msg->get('iem_iea_inbound_email_alias_id'));
		$db = DbConnector::get_instance()->get_db_link();

		// Ciphertext straight from the row — the model's get() decrypts sealed
		// fields, and this pass needs the stored bytes.
		$stmt = $db->prepare('SELECT * FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
		$stmt->execute(array($message_id));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$row || empty($row['iem_sealed_key']) || $owner_id === null) {
			return false;
		}
		$dek = self::unwrapDekInWindow($owner_id, (string)$row['iem_sealed_key']);
		if ($dek === null) {
			return false; // window closed — locked, not an error
		}
		$crypto = new VaultCrypto();

		try {
			// 1. Decrypt every sealed column into memory. The compose-only
			// fields open only on composed directions; optional columns only
			// when they hold ciphertext.
			$composed = self::isComposedDirection((string)($row['iem_direction'] ?? 'inbound'));
			$columns = array();
			foreach (static::$sealed_fields as $field) {
				if (self::isComposeOnlyField($field) && !$composed) {
					continue;
				}
				$stored = (string)($row[$field] ?? '');
				// Only what is actually sealed comes back. A column can hold
				// nothing (an empty body, an optional column never written) —
				// the seal path skips those, so the unseal path must too, or a
				// row with one empty content column can never be lowered.
				if ($stored === '' || strpos($stored, 'v1.aead.') !== 0) {
					continue;
				}
				$columns[$field] = $crypto->openField($stored, $dek, self::sealAd($message_id, $field));
			}

			// 2. Decrypt sealed attachment bytes into memory. Both sealed shapes
			// have to be lowered here, or a message that has gone plaintext would
			// keep an attachment that still demands an unlock window.
			$att_writes = array();
			$self_sealed = array();
			$atts = new MultiInboundMessageAttachment(array('message_id' => $message_id));
			$atts->load();
			foreach ($atts as $att) {
				if (!$att->get('ima_fil_file_id')) {
					continue;
				}
				$file = new File(intval($att->get('ima_fil_file_id')), TRUE);
				if (!$file->key) {
					continue;
				}
				// A self-sealed File (specs/mailbox_attachment_byte_custody.md)
				// holds its bytes under its OWN key, so this message's DEK cannot
				// open it and the Drive lower does the work instead.
				if ($file->get('fil_content_sealed')) {
					$self_sealed[] = $file;
					continue;
				}
				if (!$att->get('ima_is_sealed')) {
					continue;
				}
				$bytes = $file->read_bytes('original');
				if ($bytes === null) {
					throw new \RuntimeException('attachment bytes unreadable for File ' . $file->key);
				}
				$att_writes[] = array(
					'att' => $att, 'file' => $file,
					'plain' => $crypto->openField($bytes, $dek, self::attachmentAd($message_id, (string)$att->get('ima_mime_part'))),
				);
			}

			// 3. Decrypt a sealed stored raw (extraction-failure fallback shape).
			$raw_plain = null;
			if (!empty($row['iem_raw_sealed'])) {
				$raw = $msg->getRawMessage(); // opens in-window; plaintext out
				if ($raw === null) {
					throw new \RuntimeException('sealed raw unreadable');
				}
				$raw_plain = $raw;
			}
		} catch (\Throwable $e) {
			error_log('unsealAndPersistContent: decrypt failed for message ' . $message_id . ': ' . $e->getMessage());
			return false;
		}

		// 4. Write back. Attachments first (per-file flags keep readers
		// consistent mid-pass), raw next, the key-clearing column UPDATE last.
		try {
			foreach ($att_writes as $w) {
				if (!$w['file']->replace_bytes($w['plain'])) {
					throw new \RuntimeException('attachment write-back failed for File ' . $w['file']->key);
				}
				$w['att']->set('ima_is_sealed', false);
				$w['att']->save();
			}
			// Self-sealed attachment Files lower through the shared Drive helper,
			// which is idempotent and resumes an interrupted pass — so it obeys
			// the same "a partial pass stays consistent" rule as the loop above.
			foreach ($self_sealed as $file) {
				DriveSealed::unsealExistingFile($file);
			}
			if ($raw_plain !== null) {
				RawMessageStore::write($message_id, $raw_plain);
				$columns['iem_raw_sealed'] = false;
			}
			$columns['iem_content_sealed'] = false;
			$columns['iem_sealed_key'] = null;
			$columns['iem_sealed_owner_user_id'] = null;
			$columns['iem_key_generation'] = 0;
			self::updateColumns($message_id, $columns);
		} catch (\Throwable $e) {
			error_log('unsealAndPersistContent: write-back failed for message ' . $message_id . ': ' . $e->getMessage());
			return false;
		}
		return true;
	}

	/**
	 * Whether a single-mailbox scope still has sealed content to honor — its
	 * domain seals, or sealed rows remain from an earlier protection level
	 * (specs/mailbox_lowering_unseal.md § search follows posture). The search
	 * path keys on this: the sealed FTS index serves the scope only while this
	 * is true; a fully-converged lowered mailbox searches plain Postgres FTS
	 * with no unlock.
	 */
	public static function aliasSealedContentActive(int $alias_id): bool {
		if ($alias_id <= 0) {
			return false;
		}
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
		try {
			// The mailbox's own posture where it has one, the domain's otherwise
			// (specs/mailbox_connect_flow.md § D).
			$alias = new InboundEmailAlias($alias_id, TRUE);
			if ($alias->key && $alias->seals_content()) {
				return true;
			}
		} catch (\Throwable $e) {
			return true; // unresolvable — fail toward the sealed path, never a silent leak
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			'SELECT 1 FROM iem_inbound_email_messages
			 WHERE iem_iea_inbound_email_alias_id = ?
			   AND (iem_content_sealed = true OR iem_pending_parse = true)
			   AND iem_delete_time IS NULL LIMIT 1');
		$stmt->execute(array($alias_id));
		return (bool)$stmt->fetchColumn();
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

		try {
			$message = MimeParse::parseMessage($raw);
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
	 * Write content columns onto an existing message, sealing whichever of them
	 * are $sealed_fields when the row itself is sealed.
	 *
	 * This is the ONLY correct way for a later consumer — the AI triage and
	 * security-scan jobs, anything that annotates a message after ingest — to
	 * store derived content on a message it has read.
	 *
	 * The two wrong ways it exists to replace:
	 *
	 *  - `save()`, which rebuilds every column from get() and therefore writes
	 *    DECRYPTED sender/subject/bodies back into the sealed columns while
	 *    iem_content_sealed stays true. That is a leak and a corruption at once:
	 *    every later read AEAD-opens plaintext and throws 'malformed AEAD blob'.
	 *  - `updateColumns()` with a raw value for a sealed field, which stores
	 *    plaintext in a column every reader will try to decrypt.
	 *
	 * A row that is not sealed takes the values as-is, so a standard mailbox is
	 * unaffected. Sealing reuses the row's existing DEK, so the values sit under
	 * the same key as the body they describe, and the caller must hold the
	 * owner's unlock window — which any consumer that just READ the message
	 * necessarily does.
	 *
	 * @param array $columns column name => plaintext value
	 * @throws VaultLockedException when the row is sealed and no window is open
	 */
	public static function updateContentColumns(int $message_id, array $columns): void {
		if ($message_id <= 0 || empty($columns)) {
			return;
		}

		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			'SELECT iem_content_sealed, iem_sealed_key, iem_sealed_owner_user_id,
			        iem_iea_inbound_email_alias_id
			 FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?'
		);
		$q->execute(array($message_id));
		$row = $q->fetch(PDO::FETCH_ASSOC);
		if ($row === false) {
			return;
		}

		$sealed = !empty($row['iem_content_sealed']) && $row['iem_content_sealed'] !== 'f'
			&& !empty($row['iem_sealed_key']);

		if ($sealed) {
			$owner_id = self::sealedOwnerUserId(
				$row['iem_sealed_owner_user_id'] ?? null,
				$row['iem_iea_inbound_email_alias_id'] ?? null
			);
			if ($owner_id === null) {
				require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
				throw new VaultLockedException();
			}
			$crypto = self::openMessageDekCrypto($owner_id, (string)$row['iem_sealed_key']);
			foreach ($columns as $col => $value) {
				if (!in_array($col, static::$sealed_fields, true)) continue;
				$columns[$col] = $crypto['crypto']->sealField(
					(string)$value, $crypto['dek'], self::sealAd($message_id, $col)
				);
			}
		}

		self::updateColumns($message_id, $columns);
	}

	// Targeted column writes (pending-parse clear, spool-id stamp, spam verdict,
	// trash stamp) go through SystemBase::updateColumns() — sealed-mailbox ingest
	// writes columns behind the model's back (sealAndPersistContent /
	// persistRawAndManifest UPDATE by id), so a full save() from a stale
	// in-memory object would clobber the sealed sender/subject/body/key/raw-
	// storage descriptor with empty values.

	/** Backlog cap per run, so a long-neglected Trash drains over several runs. */
	const PURGE_MAX_PER_RUN = 500;

	/**
	 * Permanently delete mail that has sat in Trash past the retention window.
	 *
	 * Trashing is column-driven (iem_delete_time); this is the only thing that
	 * ever makes it final.
	 *
	 * Row-by-row through permanent_delete(), which reclaims the attachment Files
	 * and the stored raw object. A bulk DELETE would drop the row in one
	 * statement and leak both.
	 *
	 * Sealed mailboxes purge locked: permanent_delete() works on columns and
	 * storage keys, never on plaintext, so a Fortress mailbox needs no vault
	 * window here. Each id is queued for refold BEFORE the row goes — the refold
	 * pass re-inserts only if the message still exists, so a purged id drops out
	 * of the owner's sealed search index at their next fold.
	 *
	 * @param int $days  Retention window from the setting
	 * @return array     removed, message
	 */
	public static function purgeExpiredTrash($days) {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxIndex.php'));

		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT iem_inbound_email_message_id AS id, iem_iea_inbound_email_alias_id AS alias_id
			   FROM iem_inbound_email_messages
			  WHERE iem_delete_time IS NOT NULL
			    AND iem_delete_time < now() - (INTERVAL '1 day' * :days)
			  ORDER BY iem_delete_time ASC
			  LIMIT :cap");
		$stmt->bindValue(':days', (int)$days, PDO::PARAM_INT);
		$stmt->bindValue(':cap', self::PURGE_MAX_PER_RUN, PDO::PARAM_INT);
		$stmt->execute();
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		if (!count($rows)) {
			return array('removed' => 0, 'message' => 'no mail past the ' . (int)$days . '-day window');
		}

		$purged = 0;
		$failed = 0;
		foreach ($rows as $row) {
			$id = (int)$row['id'];
			$alias_id = (int)$row['alias_id'];
			try {
				if ($alias_id > 0) {
					MailboxIndex::enqueueRefold($alias_id, $id);
				}
				$message = new self($id, TRUE);
				if (!$message->key) {
					continue;
				}
				$message->permanent_delete();
				$purged++;
			} catch (Throwable $e) {
				// One unreclaimable message must not strand the rest of the backlog.
				$failed++;
				error_log('InboundEmailMessage::purgeExpiredTrash: failed for message ' . $id . ': ' . $e->getMessage());
			}
		}

		$message = $purged . ' message(s)';
		if (count($rows) >= self::PURGE_MAX_PER_RUN) {
			$message .= ' (hit the ' . self::PURGE_MAX_PER_RUN . '-per-run cap; the rest drains next run)';
		}
		if ($failed) {
			$message .= '; ' . $failed . ' failed (see the error log)';
		}
		return array('removed' => $purged, 'message' => $message);
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

		// Everything one archive import brought in — what Undo reverses.
		if (isset($this->options['import_run_id'])) {
			$filters['iem_mir_mail_import_run_id'] = [$this->options['import_run_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['received_since'])) {
			$filters['iem_received_time'] = '>= ' . $dblink->quote($this->options['received_since']);
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
