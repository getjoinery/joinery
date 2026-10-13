<?php
/**
 * Notification preference rows carry the signal they are about.
 *
 * ntp_notification_preferences named its signal in ntp_hook_point; the column
 * is ntp_signal_name, holding the same names. update_database added the new
 * column empty and nothing copied the old values across, so every row written
 * before the rename names no signal, and Notify, which finds a preference by
 * its signal, reads none of them: an admin's opt-ins and mutes are ignored.
 *
 * Where ntp_hook_point is still present, this copies it across and drops it.
 * An old row whose user has since saved a row for the same signal is
 * superseded by that row and goes. A row that still names no signal is read
 * by nothing and goes too — where the old column was already dropped there is
 * nothing to recover it from.
 *
 * Idempotent: a table with no old column and no nameless row is left alone.
 */
function notification_preferences_signal_name() {
	$db = DbConnector::get_instance()->get_db_link();

	$q = $db->prepare(
		"SELECT 1 FROM information_schema.columns
		  WHERE table_schema = 'public' AND table_name = 'ntp_notification_preferences' AND column_name = :c");
	$q->execute(array(':c' => 'ntp_hook_point'));
	$has_hook_point = $q->fetchColumn() !== false;

	if ($has_hook_point) {
		$superseded = $db->exec(
			"DELETE FROM ntp_notification_preferences o
			  WHERE o.ntp_signal_name IS NULL
			    AND EXISTS (SELECT 1 FROM ntp_notification_preferences n
			                 WHERE n.ntp_usr_user_id = o.ntp_usr_user_id
			                   AND n.ntp_signal_name = o.ntp_hook_point)");
		$copied = $db->exec(
			"UPDATE ntp_notification_preferences SET ntp_signal_name = ntp_hook_point
			  WHERE ntp_signal_name IS NULL AND ntp_hook_point IS NOT NULL AND ntp_hook_point <> ''");
		$db->exec("ALTER TABLE ntp_notification_preferences DROP COLUMN ntp_hook_point");
		echo "  ntp_notification_preferences: {$copied} signal names copied from ntp_hook_point, {$superseded} superseded rows removed, ntp_hook_point dropped\n";
	}

	$nameless = $db->exec(
		"DELETE FROM ntp_notification_preferences WHERE ntp_signal_name IS NULL OR ntp_signal_name = ''");
	echo "  ntp_notification_preferences: {$nameless} rows naming no signal removed\n";
}
?>
