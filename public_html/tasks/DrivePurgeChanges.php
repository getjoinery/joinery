<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

/**
 * DrivePurgeChanges — trim the append-only Drive change feed (fch_file_changes)
 * past the retention window. Sync clients that fall behind the window re-list
 * (drive_changes returns reset).
 */
class DrivePurgeChanges implements ScheduledTaskInterface {
	public function run(array $config) {
		$days = isset($config['days_to_keep']) ? (int)$config['days_to_keep'] : 90;
		if ($days <= 0) {
			$days = 90;
		}

		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare("DELETE FROM fch_file_changes WHERE fch_create_time < now() - (INTERVAL '1 day' * :days)");
		$q->execute(array(':days' => $days));
		$deleted = $q->rowCount();

		if ($deleted === 0) {
			return array('status' => 'success', 'message' => 'No Drive change rows past the retention window');
		}
		return array('status' => 'success', 'message' => 'Purged ' . $deleted . ' Drive change row(s) older than ' . $days . ' days');
	}
}
