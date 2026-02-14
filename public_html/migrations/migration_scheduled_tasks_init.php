<?php
/**
 * Migration: Initialize Scheduled Tasks system
 *
 * Inserts admin menu item and cron heartbeat setting.
 *
 * @version 1.0
 */
function migration_scheduled_tasks_init() {
	$dbconnector = DbConnector::get_instance();
	$dblink = $dbconnector->get_db_link();

	// Insert "Scheduled Tasks" under System menu (parent_menu_id = 59)
	// Use a subquery to find the System menu dynamically
	$sql = "INSERT INTO amu_admin_menus (amu_menudisplay, amu_slug, amu_parent_menu_id, amu_defaultpage, amu_order, amu_min_permission, amu_icon)
			SELECT 'Scheduled Tasks', 'scheduled-tasks', amu_admin_menu_id, 'admin_scheduled_tasks', 7, 10, 'clock'
			FROM amu_admin_menus WHERE amu_menudisplay = 'System' AND amu_parent_menu_id IS NULL
			LIMIT 1";

	$q = $dblink->prepare($sql);
	$q->execute();

	// Insert cron heartbeat setting
	$sql = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name)
			VALUES ('scheduled_tasks_last_cron_run', '', 1, now(), now(), 'system')";

	$q = $dblink->prepare($sql);
	$q->execute();

	return true;
}
