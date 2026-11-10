<?php
/**
 * Email Lists plugin migrations.
 *
 * @version 1.0 - el_001 records the consent of subscribers who joined before consent was recorded
 */
return [
	[
		// Double opt-in (spec email_lists_plugin §10) records how each
		// subscription was confirmed. Subscribers from before are kept, marked
		// existing, not asked to confirm again; the address they joined with is
		// the account's address now. Idempotent: only rows with no source.
		'id' => 'el_001_existing_subscribers',
		'version' => '1.0.0',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$dblink->exec("UPDATE mlr_mailing_list_registrants r
				SET mlr_confirm_source = 'existing',
					mlr_confirm_time = COALESCE(r.mlr_change_time, now()),
					mlr_confirm_email = lower(u.usr_email)
				FROM usr_users u
				WHERE u.usr_user_id = r.mlr_usr_user_id AND r.mlr_confirm_source IS NULL");
			return true;
		},
	],
];
