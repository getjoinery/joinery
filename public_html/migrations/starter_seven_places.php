<?php
/**
 * Starter sites run at 448m, seven to a host (hosting_paid_launch B3).
 *
 * At 384m an install, a backup and an upgrade each took a site to within a few
 * MB of its limit. The plugin's declared default is 448m, but a declared
 * default only seeds a missing row, so a plane whose row still holds the old
 * default 384m moves to 448m; any other value is an operator's choice and is
 * left alone. A starter host still at the old eight places moves to seven, so
 * the extra memory per site stays inside a Linode 4 GB; a host an operator set
 * to another figure is left alone.
 *
 * Sites already running keep their old limit until the plane sends them the
 * site_limits word.
 *
 * mgh_managed_hosts belongs to server_manager, so the table and the columns
 * named are checked for first. Idempotent: a second run changes nothing.
 */
function starter_seven_places() {
	$db = DbConnector::get_instance()->get_db_link();

	$q = $db->prepare("UPDATE stg_settings SET stg_value = '448m', stg_update_time = now()
		WHERE stg_name = 'server_manager_starter_memory' AND lower(trim(stg_value)) = '384m'");
	$q->execute();
	echo '  starter memory: ' . $q->rowCount() . " setting row moved to 448m\n";

	$q = $db->prepare("SELECT to_regclass('mgh_managed_hosts')");
	$q->execute();
	if ($q->fetchColumn() === null) {
		echo "  starter places: no managed hosts table here, nothing to change\n";
		return;
	}
	$columns = array('mgh_tier', 'mgh_max_sites');
	$q = $db->prepare("SELECT count(1) FROM information_schema.columns
		WHERE table_schema = current_schema() AND table_name = 'mgh_managed_hosts' AND column_name IN ('" . implode("','", $columns) . "')");
	$q->execute();
	if ((int)$q->fetchColumn() !== count($columns)) {
		echo "  starter places: this site's managed hosts table has no tier column, nothing to change\n";
		return;
	}
	$q = $db->prepare("UPDATE mgh_managed_hosts SET mgh_max_sites = 7 WHERE mgh_tier = 'starter' AND mgh_max_sites = 8");
	$q->execute();
	echo '  starter places: ' . $q->rowCount() . " starter host(s) moved from 8 places to 7\n";
}
