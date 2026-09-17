<?php
/**
 * Migration: calendar reminder template gains the entry's details
 *
 * The shipped calendar_reminder body (migration_calendar_email_templates.php)
 * learns three optional lines — Where, the link, the notes — rendered inside
 * conditionals so an entry without them reads exactly as before. Only a body
 * that is still the factory text is touched; an admin-edited template is left
 * alone (the vars are available to it). Line endings are normalized before
 * comparing: the admin editor re-saves blocks as CRLF.
 *
 * @version 1.0
 */
function migration_calendar_reminder_details() {
	$dblink = DbConnector::get_instance()->get_db_link();

	$factory = '{title}<p>Coming up: <strong>*title*</strong></p>{end}
{~title}<p>You have a calendar entry coming up.</p>{end}
{tentative}<p><em>This entry is tentative.</em></p>{end}
<p><strong>When:</strong> *start_display* &ndash; *end_display*</p>
<p><a href="*calendar_url*">Open your calendar</a></p>
<p><a href="*settings_url*">Change or turn off reminders</a></p>';

	$updated = '{title}<p>Coming up: <strong>*title*</strong></p>{end}
{~title}<p>You have a calendar entry coming up.</p>{end}
{tentative}<p><em>This entry is tentative.</em></p>{end}
<p><strong>When:</strong> *start_display* &ndash; *end_display*</p>
{location}<p><strong>Where:</strong> *location*</p>{end}
{link}<p><a href="*link*">*link*</a></p>{end}
{notes}<p>*notes|nl2br*</p>{end}
<p><a href="*calendar_url*">Open your calendar</a></p>
<p><a href="*settings_url*">Change or turn off reminders</a></p>';

	$q = $dblink->prepare("SELECT emt_email_template_id, emt_body FROM emt_email_templates WHERE emt_name = 'calendar_reminder'");
	$q->execute();
	$norm = function ($s) { return str_replace(array("\r\n", "\r"), "\n", trim((string)$s)); };
	foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
		if ($norm($row['emt_body']) !== $norm($factory)) {
			continue;
		}
		$u = $dblink->prepare("UPDATE emt_email_templates SET emt_body = ?, emt_update_time = now() WHERE emt_email_template_id = ?");
		$u->execute(array($updated, $row['emt_email_template_id']));
	}

	return TRUE;
}
