<?php
/**
 * Event Manager plugin migrations.
 *
 * @version 1.0 - em_001 seeds the post-event survey email SendPostEventSurveys sends
 */
return [
	[
		// The survey invitation SendPostEventSurveys sends once an event with an
		// after-event survey has ended. Seeded once and editable on the email
		// templates page: an existing row of the same name is never overwritten.
		'id' => 'em_001_post_event_survey_template',
		'version' => '1.1.22',
		'up' => function($dbconnector) {
			$dblink = $dbconnector->get_db_link();
			$insert = $dblink->prepare("INSERT INTO emt_email_templates (emt_name, emt_type, emt_subject, emt_body, emt_create_time, emt_update_time)
				SELECT ?, 2, ?, ?, now(), now()
				WHERE NOT EXISTS (SELECT 1 FROM emt_email_templates WHERE emt_name = ?)");
			$insert->execute(array('post_event_survey', 'How was *event_name*?',
				'<p>Hi *recipient->usr_first_name*,</p>
<p>Thanks for coming to <strong>*event_name*</strong>. We would like to hear how it went. The survey takes a few minutes:</p>
<p><a href="*survey_url*">*survey_url*</a></p>
<p>Thanks,<br>*site_name*</p>',
				'post_event_survey'));
			return true;
		},
	],
];
