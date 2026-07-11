<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

/**
 * DriveUsageReconcile — recompute every file-owning user's Drive usage total as
 * a drift backstop. Usage is normally recomputed inline after each mutation;
 * this daily sweep catches any total that fell out of sync.
 */
class DriveUsageReconcile implements ScheduledTaskInterface {
	public function run(array $config) {
		require_once(PathHelper::getIncludePath('data/drive_usage_class.php'));

		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->query(
			"SELECT uid FROM (
			    SELECT DISTINCT fil_usr_user_id AS uid FROM fil_files WHERE fil_usr_user_id IS NOT NULL
			    UNION
			    SELECT dru_usr_user_id AS uid FROM dru_drive_usage
			 ) u WHERE uid IS NOT NULL");

		$count = 0;
		foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $uid) {
			DriveUsage::recompute((int)$uid);
			$count++;
		}

		return array('status' => 'success', 'message' => 'Reconciled Drive usage for ' . $count . ' user(s)');
	}
}
