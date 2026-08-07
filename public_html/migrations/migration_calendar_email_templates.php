<?php
/**
 * Migration: Insert calendar email templates
 *
 * Creates 2 email templates consumed by the CalendarEmails scheduled task:
 * calendar_reminder (sent at the entry's lead time before start) and
 * calendar_summary (the daily/weekly agenda email).
 *
 * The reminder body renders title/tentative inside conditionals on purpose:
 * a future Private-level entry passes only the time vars and the same
 * template produces the generic form ("You have a calendar entry coming up").
 *
 * @version 1.0
 */
function migration_calendar_email_templates() {
	$dbconnector = DbConnector::get_instance();
	$dblink = $dbconnector->get_db_link();

	$templates = array(
		array(
			'name' => 'calendar_reminder',
			'subject' => 'Calendar reminder',
			'body' => '{title}<p>Coming up: <strong>*title*</strong></p>{end}
{~title}<p>You have a calendar entry coming up.</p>{end}
{tentative}<p><em>This entry is tentative.</em></p>{end}
<p><strong>When:</strong> *start_display* &ndash; *end_display*</p>
<p><a href="*calendar_url*">Open your calendar</a></p>
<p><a href="*settings_url*">Change or turn off reminders</a></p>'
		),
		array(
			'name' => 'calendar_summary',
			'subject' => 'Your calendar',
			'body' => '<p><strong>*period_label*</strong></p>
{loop days as day}<p><strong>*day->label*</strong></p>
<ul>
{loop day->lines as line}<li>*line->text*</li>
{end}</ul>
{end}<p><a href="*calendar_url*">Open your calendar</a></p>
<p><a href="*settings_url*">Change how these emails work</a></p>'
		),
	);

	$insert_sql = "INSERT INTO emt_email_templates (emt_name, emt_type, emt_subject, emt_body, emt_create_time, emt_update_time)
	               SELECT ?, 2, ?, ?, now(), now()
	               WHERE NOT EXISTS (SELECT 1 FROM emt_email_templates WHERE emt_name = ?)";
	$q = $dblink->prepare($insert_sql);

	foreach ($templates as $t) {
		$q->execute(array($t['name'], $t['subject'], $t['body'], $t['name']));
	}

	return TRUE;
}
