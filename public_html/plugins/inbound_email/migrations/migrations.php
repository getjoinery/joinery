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
 * @version 1.3
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
];
