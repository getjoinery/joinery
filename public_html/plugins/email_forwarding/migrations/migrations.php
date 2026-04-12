<?php
/**
 * Email Forwarding Plugin Migrations
 *
 * Tables are created automatically from data class field specifications.
 * Admin menus are now managed declaratively via plugin.json adminMenu.
 * Migrations are only for settings and initial data.
 *
 * @version 1.1
 */

return [
	[
		'id' => '001_email_forwarding_initial_setup',
		'version' => '1.0.0',
		'description' => 'Add email forwarding settings',
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

			return true;
		},
		'down' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();

			$sql = "DELETE FROM stg_settings WHERE stg_name LIKE 'email_forwarding_%'";
			$q = $dblink->prepare($sql);
			$q->execute();

			return true;
		}
	]
];
