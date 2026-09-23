<?php
/**
 * Remove the retired restore-point counts.
 *
 * Retention keeps days of backups: `backup_retention_days` for a site's own
 * backups and `server_manager_fleet_backup_keep_days` for a management node's.
 * Both are declared with their defaults and seeded on their own; the count
 * settings they stand in for have no readers.
 */
function backup_retention_by_days() {
	$dblink = DbConnector::get_instance()->get_db_link();
	$doomed = array('backup_retention_count', 'server_manager_fleet_backup_keep');
	$stmt = $dblink->prepare("DELETE FROM stg_settings WHERE stg_name IN (?, ?)");
	$stmt->execute($doomed);
	echo 'Removed ' . $stmt->rowCount() . " retired retention count setting row(s).\n";
	return true;
}
?>
