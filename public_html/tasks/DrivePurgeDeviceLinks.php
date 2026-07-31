<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

/**
 * DrivePurgeDeviceLinks — remove finished device-link ceremonies.
 *
 * A ceremony is a ten-minute scrap of state, not a record worth keeping: once
 * it has expired, been refused, or had its credential collected, nothing reads
 * it again. Sweeping them keeps a table of one-time codes from becoming a
 * permanent list of one-time codes.
 *
 * Approved rows are given a grace period before removal so a client that
 * crashed mid-collection can still come back for its credential. Rows whose
 * secret was already handed over carry nothing sensitive and go on the same
 * pass as everything else expired.
 */
class DrivePurgeDeviceLinks implements ScheduledTaskInterface {
	public function run(array $config) {
		require_once(PathHelper::getIncludePath('data/device_links_class.php'));

		$grace_minutes = isset($config['grace_minutes']) ? (int)$config['grace_minutes'] : 60;
		if ($grace_minutes < 0) {
			$grace_minutes = 60;
		}

		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"DELETE FROM dlk_device_links
			  WHERE dlk_expires_time < now() - (INTERVAL '1 minute' * :grace)
			     OR (dlk_status <> 'pending' AND dlk_create_time < now() - (INTERVAL '1 minute' * :grace2))");
		$q->execute(array(':grace' => $grace_minutes, ':grace2' => $grace_minutes));
		$removed = $q->rowCount();

		if ($removed === 0) {
			return array('status' => 'success', 'message' => 'No finished device links to purge');
		}
		return array('status' => 'success', 'message' => 'Purged ' . $removed . ' finished device link(s)');
	}
}
