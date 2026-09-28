<?php
/**
 * Admin: one received bug report — what the member wrote, the image, the
 * callback's verdict and reason, and every section of the bundle. Mark seen,
 * Mark closed and Reopen are POST buttons. Everything shown came from another
 * machine and is escaped.
 *
 * @version 1.1.0 - automatic reports: sender, count, last seen; group link by fingerprint
 * @version 1.0.0
 */
$session = SessionControl::get_instance();
$session->check_permission(9);
$session->set_return();

$id = (int)($_GET['rbr_received_bug_report_id'] ?? $_POST['rbr_received_bug_report_id'] ?? 0);
$report = new ReceivedBugReport($id, TRUE);
if (!$report->key) {
	header('HTTP/1.0 404 Not Found');
	throw new SystemDisplayableError('That bug report no longer exists.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
	switch ($_POST['action'] ?? '') {
		case 'seen':
			$report->set('rbr_status', ReceivedBugReport::STATUS_SEEN);
			$report->save();
			break;
		case 'close':
			$report->close((int)$session->get_user_id());
			break;
		case 'reopen':
			$report->reopen();
			break;
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
	'Status'   => htmlspecialchars(ucfirst((string)$report->get('rbr_status')))
		. ($report->get('rbr_closed_time') ? ', ' . htmlspecialchars(LibraryFunctions::convert_time($report->get('rbr_closed_time'), 'UTC', $tz, 'M j, Y')) : ''),
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
echo '</tbody></table><p>';
if ($report->get('rbr_status') === ReceivedBugReport::STATUS_NEW) {
	echo AdminPage::action_button('Mark seen', $base, array('hidden' => $hidden + array('action' => 'seen'))) . ' ';
}
if ($report->get('rbr_status') === ReceivedBugReport::STATUS_CLOSED) {
	echo AdminPage::action_button('Reopen', $base, array('hidden' => $hidden + array('action' => 'reopen')));
} else {
	echo AdminPage::action_button('Mark closed', $base, array('hidden' => $hidden + array('action' => 'close')));
}
if ($report->group_key() !== '') {
	echo ' <a href="/plugins/bug_reports/admin/admin_bug_reports?view=all&group=' . urlencode($report->group_key()) . '">Other reports with this error</a>';
}
echo '</p>';
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
