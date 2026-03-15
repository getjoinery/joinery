<?php
/**
 * Email Forwarding Plugin Migrations
 *
 * Tables are created automatically from data class field specifications.
 * Migrations are only for settings, initial data, and admin menu entries.
 *
 * @version 1.0
 */

return [
	[
		'id' => '001_email_forwarding_initial_setup',
		'version' => '1.0.0',
		'description' => 'Add email forwarding settings and admin menu entry',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();

			// Settings to add
			$settings = array(
				array('email_forwarding_enabled', '0'),
				array('email_forwarding_log_retention_days', '30'),
				array('email_forwarding_max_destinations', '10'),
				array('email_forwarding_rate_limit_per_alias', '50'),
				array('email_forwarding_rate_limit_per_domain', '200'),
				array('email_forwarding_rate_limit_window', '3600'),
				array('email_forwarding_srs_enabled', '0'),
				array('email_forwarding_srs_secret', ''),
				array('email_forwarding_smtp_host', ''),
				array('email_forwarding_smtp_port', ''),
				array('email_forwarding_smtp_username', ''),
				array('email_forwarding_smtp_password', ''),
			);

			foreach ($settings as $setting) {
				$check_sql = "SELECT count(1) as count FROM stg_settings WHERE stg_name = ?";
				$check_q = $dblink->prepare($check_sql);
				$check_q->execute([$setting[0]]);
				$result = $check_q->fetch(PDO::FETCH_ASSOC);

				if ($result['count'] == 0) {
					$sql = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name)
							VALUES (?, ?, 1, NOW(), NOW(), 'email')";
					$q = $dblink->prepare($sql);
					$q->execute([$setting[0], $setting[1]]);
				}
			}

			// Add admin menu entry under Emails (parent ID 11)
			$check_sql = "SELECT count(1) as count FROM amu_admin_menus WHERE amu_slug = 'incoming' AND amu_setting_activate = 'email_forwarding_enabled'";
			$check_q = $dblink->prepare($check_sql);
			$check_q->execute();
			$result = $check_q->fetch(PDO::FETCH_ASSOC);

			if ($result['count'] == 0) {
				$sql = "INSERT INTO amu_admin_menus (amu_menudisplay, amu_defaultpage, amu_parent_menu_id, amu_order, amu_min_permission, amu_slug, amu_setting_activate)
						VALUES ('Incoming', '/plugins/email_forwarding/admin/admin_email_forwarding', 11, 10, 5, 'incoming', 'email_forwarding_enabled')";
				$q = $dblink->prepare($sql);
				$q->execute();
			}

			return true;
		},
		'down' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();

			$sql = "DELETE FROM stg_settings WHERE stg_name LIKE 'email_forwarding_%'";
			$q = $dblink->prepare($sql);
			$q->execute();

			$sql = "DELETE FROM amu_admin_menus WHERE amu_slug = 'incoming' AND amu_setting_activate = 'email_forwarding_enabled'";
			$q = $dblink->prepare($sql);
			$q->execute();

			return true;
		}
	]
];
?>
