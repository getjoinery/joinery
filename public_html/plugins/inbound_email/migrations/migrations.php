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
 * @version 1.1
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
];
