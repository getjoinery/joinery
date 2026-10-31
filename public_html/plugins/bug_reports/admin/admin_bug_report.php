<?php
/** @joinery-admin level=9 */
/**
 * Admin: one received bug report — what the member wrote, the image, the
 * callback's verdict and reason, and every section of the bundle. Resolve,
 * Ignore, Reopen and Snooze are an incident's choices, and apply to every
 * report of its fault (this report alone when it recorded no error).
 * Everything shown came from another machine and is escaped.
 *
 * @version 1.3.0 - Resolve, Ignore, Snooze and Reopen on the fault; Mark seen, Mark closed and Unmute are gone
 * @version 1.2.1 - a missing report answers 404 through assert_found(), not a raw status line
 *                  the error page then tried to set again (a warning on PHP 8.5)
 * @version 1.2.0 - muted reports: Unmute
 * @version 1.1.0 - automatic reports: sender, count, last seen; group link by fingerprint
 * @version 1.0.0
 */
$session = SessionControl::get_instance();
$session->set_return();

$id = (int)($_GET['rbr_received_bug_report_id'] ?? $_POST['rbr_received_bug_report_id'] ?? 0);
$report = new ReceivedBugReport($id, TRUE);
$report->assert_found();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'triage') {
	// One UPDATE across every report of the fault: the reports' own write level.
	Access::demand(ReceivedBugReport::access_level('write'), $session);
	try {
		$report->triage((string)($_POST['do'] ?? ''), (int)$session->get_user_id());
	} catch (ReceivedBugReportException $e) {
		// A value the page never offers: nothing changed.
	}
	header('Location: /plugins/bug_reports/admin/admin_bug_report?rbr_received_bug_report_id=' . (int)$report->key);
	exit;
}

$tz = $session->get_timezone();
$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => 'bug-reports',
	'page_title'     => 'Bug Report ' . (int)$report->key,
	'readable_title' => 'Bug Report ' . (int)$report->key,
	'breadcrumbs'    => array('Bug Reports' => '/plugins/bug_reports/admin/admin_bug_reports', 'Report ' . (int)$report->key => ''),
	'session'        => $session,
));

$verdicts = array(
	ReceivedBugReport::VERDICT_VERIFIED   => 'Verified',
	ReceivedBugReport::VERDICT_MISMATCH   => 'Version mismatch',
	ReceivedBugReport::VERDICT_UNVERIFIED => 'Unverified',
);
$base = '/plugins/bug_reports/admin/admin_bug_report';
$hidden = array('rbr_received_bug_report_id' => (int)$report->key);

$page->begin_box(array('title' => 'Report ' . (int)$report->key));
echo '<table class="data"><tbody>';
$rows = array(
	'Status'   => htmlspecialchars(ReceivedBugReport::state_words($report->status(), $report->get('rbr_snooze_until'), $report->get('rbr_ignored_through'), $tz))
		. ($report->status() === ReceivedBugReport::STATUS_RESOLVED && $report->get('rbr_closed_time')
			? ', ' . htmlspecialchars(LibraryFunctions::convert_time($report->get('rbr_closed_time'), 'UTC', $tz, 'M j, Y')) : ''),
	'Received' => htmlspecialchars(LibraryFunctions::convert_time($report->get('rbr_received_time'), 'UTC', $tz, 'M j, Y g:i A')),
	'Site'     => htmlspecialchars((string)$report->get('rbr_claimed_host')),
	'Version'  => htmlspecialchars((string)$report->get('rbr_claimed_version')),
	'Check'    => htmlspecialchars($verdicts[$report->get('rbr_verdict')] ?? (string)$report->get('rbr_verdict'))
		. ': ' . htmlspecialchars((string)$report->get('rbr_verdict_reason')),
	'Sent from' => htmlspecialchars((string)$report->get('rbr_sender_ip')),
	'Sent by'   => (bool)$report->get('rbr_automatic') ? 'The site, automatically, when the error happened' : 'A member',
	'Times seen' => (int)$report->get('rbr_occurrences')
		. ($report->get('rbr_last_seen_time')
			? ', last ' . htmlspecialchars(LibraryFunctions::convert_time($report->get('rbr_last_seen_time'), 'UTC', $tz, 'M j, Y g:i A'))
			: ''),
);
foreach ($rows as $label => $value) {
	echo '<tr><th scope="row" style="width: 25%">' . $label . '</th><td>' . $value . '</td></tr>';
}
echo '</tbody></table>';
echo '<div class="d-flex flex-wrap align-items-end gap-2 mb-2">';
foreach (ReceivedBugReport::VERBS as $state => $verb) {
	if ($state === $report->status()) {
		continue;
	}
	echo AdminPage::action_button($verb, $base, array('hidden' => $hidden + array('action' => 'triage', 'do' => $state)));
}
$snoozes = array();
foreach (ReceivedBugReport::do_options() as $value => $words) {
	if (strpos($value, 'snooze_') === 0) {
		$snoozes[$value] = $words;
	}
}
$fw = new FormWriterV2HTML5('report_snooze', array('action' => $base, 'id' => 'report_snooze'));
$fw->begin_form();
$fw->hiddeninput('action', '', array('value' => 'triage'));
$fw->hiddeninput('rbr_received_bug_report_id', '', array('value' => (string)(int)$report->key));
$fw->dropinput('do', 'Snooze for', array('options' => $snoozes, 'value' => 'snooze_24'));
$fw->submitbutton('btn_report_snooze', 'Snooze', array('class' => 'btn btn-sm btn-outline-primary'));
$fw->end_form();
echo '</div>';
$in_group = ReceivedBugReport::is_group_key($report->group_key())
	? count(new MultiReceivedBugReport(array('group' => $report->group_key()))) : 1;
echo '<p class="small text-muted">' . ($in_group > 1
	? 'Each applies to all ' . $in_group . ' reports of this error, from every site. '
		. '<a href="/plugins/bug_reports/admin/admin_bug_reports?view=all&amp;group=' . urlencode($report->group_key()) . '">See them</a>.'
	: 'Each applies to this report.')
	. ' Resolve: if it happens again it needs you again. Ignore: quiet while it happens on the versions already reported. '
	. 'Snooze: back in Needs you when it ends. Reopen: back now.</p>';
$page->end_box();

$page->begin_box(array('title' => (bool)$report->get('rbr_automatic') ? 'Description' : 'What the member wrote'));
echo '<p style="white-space: pre-wrap">' . htmlspecialchars((string)$report->get('rbr_comment') !== '' ? (string)$report->get('rbr_comment') : '(none: sent automatically)') . '</p>';
$file_id = (int)$report->get('rbr_fil_file_id');
if ($file_id > 0 && File::check_if_exists($file_id)) {
	$url = (new File($file_id, TRUE))->get_url('original');
	echo '<p><a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener">'
		. '<img src="' . htmlspecialchars($url) . '" alt="Screenshot attached to the report" style="max-width: 100%; max-height: 480px"></a></p>';
} elseif ($report->get('rbr_image_note')) {
	echo '<p class="text-muted">' . htmlspecialchars((string)$report->get('rbr_image_note')) . '</p>';
}
$page->end_box();

$page->begin_box(array('title' => 'What the site sent'));
$sections = ProblemReportBundle::displayRows($report->bundle());
if (!$sections) {
	echo '<p>The bundle could not be read.</p>';
}
foreach ($sections as $section) {
	echo '<h4>' . htmlspecialchars($section[0]) . '</h4><table class="data"><tbody>';
	foreach ($section[1] as $row) {
		echo '<tr><th scope="row" style="width: 30%">' . htmlspecialchars($row[0]) . '</th>'
			. '<td style="white-space: pre-wrap; overflow-wrap: anywhere; font-family: monospace; font-size: .85em">' . htmlspecialchars($row[1]) . '</td></tr>';
	}
	echo '</tbody></table>';
}
$page->end_box();

$page->admin_footer();
