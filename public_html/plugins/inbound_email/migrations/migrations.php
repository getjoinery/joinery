<?php
/**
 * Inbound Email Plugin Migrations
 *
 * Tables and columns are created automatically from data class field
 * specifications. Admin menus are managed declaratively via plugin.json
 * adminMenu. Default settings are managed declaratively via plugin.json
 * settings.
 *
 * The only thing the auto-updater does NOT handle is non-unique indexes, so
 * the Mailbox Reader's thread-key index is created here (same pattern as the
 * server_manager plugin's index migration).
 *
 * @version 1.19.0
 */
return [
	[
		'id' => 'iem_001_thread_key_index',
		'version' => '1.9.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();

			// Threads are grouped by iem_thread_key within a mailbox scope; the
			// reader queries it on every list/thread fetch.
			$dblink->exec(
				"CREATE INDEX IF NOT EXISTS iem_thread_key_idx
				 ON iem_inbound_email_messages (iem_thread_key)"
			);
		},
	],

	[
		'id' => 'iem_002_state_columns_not_null',
		'version' => '1.9.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();

			// The auto-updater adds bool columns but defers DEFAULT/NOT NULL to
			// its --upgrade pass; finalize them here so newly-stored mail and
			// existing rows are never NULL (NULL would break unread counting).
			$dblink->exec("UPDATE iem_inbound_email_messages SET iem_is_read = false WHERE iem_is_read IS NULL");
			$dblink->exec("UPDATE iem_inbound_email_messages SET iem_is_starred = false WHERE iem_is_starred IS NULL");
			$dblink->exec("ALTER TABLE iem_inbound_email_messages ALTER COLUMN iem_is_read SET DEFAULT false");
			$dblink->exec("ALTER TABLE iem_inbound_email_messages ALTER COLUMN iem_is_starred SET DEFAULT false");
			$dblink->exec("ALTER TABLE iem_inbound_email_messages ALTER COLUMN iem_is_read SET NOT NULL");
			$dblink->exec("ALTER TABLE iem_inbound_email_messages ALTER COLUMN iem_is_starred SET NOT NULL");
		},
	],

	[
		// Every pre-existing iem_dkim_result was produced by the now-removed
		// hand-rolled verifier, which false-failed legitimate mail. Reset them
		// to 'unverified' so old rows are honest rather than confidently wrong.
		// The new iem_spf_result/iem_dmarc_result/iem_auth_source columns are
		// backfilled by their ADD COLUMN DEFAULTs (unverified/unverified/none),
		// so only iem_dkim_result needs this flip. Scoped to non-milter rows so
		// it never clobbers a genuine milter-stamped verdict.
		'id' => 'iem_003_reset_handrolled_dkim_verdicts',
		'version' => '1.10.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dblink->exec(
				"UPDATE iem_inbound_email_messages
				 SET iem_dkim_result = 'unverified'
				 WHERE iem_auth_source IS DISTINCT FROM 'milter'"
			);
		},
	],

	[
		// Plugin-table sync adds new columns WITHOUT their field_specifications
		// DEFAULT (the same gap iem_002 finalizes for the bool columns) and does
		// not widen an existing column's type. So set the verdict-column defaults
		// here, backfill the existing NULL rows to honest 'unverified'/'none',
		// and widen iem_dkim_result to match the data class.
		'id' => 'iem_004_verdict_column_defaults',
		'version' => '1.10.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dblink->exec("ALTER TABLE iem_inbound_email_messages ALTER COLUMN iem_spf_result   SET DEFAULT 'unverified'");
			$dblink->exec("ALTER TABLE iem_inbound_email_messages ALTER COLUMN iem_dmarc_result SET DEFAULT 'unverified'");
			$dblink->exec("ALTER TABLE iem_inbound_email_messages ALTER COLUMN iem_auth_source  SET DEFAULT 'none'");
			$dblink->exec("UPDATE iem_inbound_email_messages SET iem_spf_result   = 'unverified' WHERE iem_spf_result   IS NULL");
			$dblink->exec("UPDATE iem_inbound_email_messages SET iem_dmarc_result = 'unverified' WHERE iem_dmarc_result IS NULL");
			$dblink->exec("UPDATE iem_inbound_email_messages SET iem_auth_source  = 'none'       WHERE iem_auth_source  IS NULL");
			$dblink->exec("ALTER TABLE iem_inbound_email_messages ALTER COLUMN iem_dkim_result TYPE varchar(16)");
		},
	],

	[
		// Two-way IMAP sync hot paths (specs/two_way_imap_sync.md). The auto-updater
		// does not create non-unique indexes, so add the per-cycle lookup indexes
		// here: VANISHED correlation by (folder, uid), the folder-membership
		// subquery by folder, and the iem_ locator lookup by (account, folder, uid).
		'id' => 'iem_005_two_way_sync_indexes',
		'version' => '1.11.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dblink->exec(
				"CREATE INDEX IF NOT EXISTS imf_folder_uid_idx
				 ON imf_inbound_message_folders (imf_iif_inbound_imap_folder_id, imf_imap_uid)"
			);
			$dblink->exec(
				"CREATE INDEX IF NOT EXISTS imf_message_idx
				 ON imf_inbound_message_folders (imf_iem_inbound_email_message_id)"
			);
			$dblink->exec(
				"CREATE INDEX IF NOT EXISTS iem_imap_locator_idx
				 ON iem_inbound_email_messages
				 (iem_iia_inbound_imap_account_id, iem_imap_folder, iem_imap_uid)"
			);
		},
	],

	[
		// Sync is gated on CONDSTORE (QRESYNC implies it, but Gmail has CONDSTORE
		// without QRESYNC). Backfill the new gate from the prior QRESYNC flag so a
		// feed already detected as QRESYNC-capable stays sync-capable without a
		// re-poll; CONDSTORE-only feeds (Gmail) pick it up on the next connect/Test.
		'id' => 'iia_001_backfill_condstore_from_qresync',
		'version' => '1.11.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dblink->exec(
				"UPDATE iia_inbound_imap_accounts
				 SET iia_supports_condstore = true
				 WHERE iia_supports_qresync = true"
			);
		},
	],

	[
		// The plugin-table sync adds bool columns without finalizing DEFAULT/NOT NULL
		// (same gap iem_002 handles). Finalize iif_pending_remote_create so existing
		// rows (which already exist on the source) read false, not NULL.
		'id' => 'iif_001_pending_create_not_null',
		'version' => '1.11.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dblink->exec("UPDATE iif_inbound_imap_folders SET iif_pending_remote_create = false WHERE iif_pending_remote_create IS NULL");
			$dblink->exec("ALTER TABLE iif_inbound_imap_folders ALTER COLUMN iif_pending_remote_create SET DEFAULT false");
			$dblink->exec("ALTER TABLE iif_inbound_imap_folders ALTER COLUMN iif_pending_remote_create SET NOT NULL");
		},
	],

	[
		// Raw-message storage descriptor (specs/inbound_raw_message_storage.md).
		// Plugin-table sync adds the new iem_raw_* columns WITHOUT their
		// field_specifications DEFAULT (the gap iem_002/iif_001 finalize), so set
		// the defaults and backfill any NULLs here. Then correct the
		// reference-backed IMAP rows: they were written before the descriptor
		// existed and sit on the 'inline' default, but their raw is 'remote'
		// (re-fetched from the mailbox). MUST land before the driver-flag dispatch
		// goes live, or existing IMAP attachments/forwards route to the empty-raw
		// branch. Idempotent. Mirrors the iia_001/iem_003 pattern.
		'id' => 'iem_006_backfill_imap_remote_driver',
		'version' => '1.16.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dblink->exec("ALTER TABLE iem_inbound_email_messages ALTER COLUMN iem_raw_storage_driver SET DEFAULT 'inline'");
			$dblink->exec("ALTER TABLE iem_inbound_email_messages ALTER COLUMN iem_raw_sync_failed_count SET DEFAULT 0");
			$dblink->exec("UPDATE iem_inbound_email_messages SET iem_raw_storage_driver = 'inline' WHERE iem_raw_storage_driver IS NULL");
			$dblink->exec("UPDATE iem_inbound_email_messages SET iem_raw_sync_failed_count = 0 WHERE iem_raw_sync_failed_count IS NULL");
			$dblink->exec(
				"UPDATE iem_inbound_email_messages
				 SET iem_raw_storage_driver = 'remote'
				 WHERE iem_iia_inbound_imap_account_id IS NOT NULL
				   AND iem_raw_storage_driver = 'inline'"
			);
		},
	],

	[
		// Full-text search index for the Mailbox Reader (specs/inbound_email_fulltext_search.md).
		// The auto-updater does not create non-unique indexes, so the GIN index over
		// the canonical search expression is created here. The expression MUST stay
		// byte-identical to the one MailboxService::listThreads() filters on, or the
		// planner will not use the index.
		'id' => 'iem_007_fulltext_search_index',
		'version' => '1.19.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dblink->exec(
				"CREATE INDEX IF NOT EXISTS iem_fulltext_idx
				 ON iem_inbound_email_messages
				 USING GIN (to_tsvector('english',
						coalesce(iem_sender, '')      || ' ' ||
						coalesce(iem_subject, '')     || ' ' ||
						coalesce(iem_body_plain, '')  || ' ' ||
						coalesce(iem_body_html, '')))"
			);
		},
	],
];
