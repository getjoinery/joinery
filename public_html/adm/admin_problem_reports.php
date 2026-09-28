<?php
/**
 * Admin: Problem Reports. See adm/logic/admin_problem_reports_logic.php.
 *
 * @version 1.1.0 - automatic reports: no reporter, and how often the error happened
 * @version 1.0.0
 */
require_once(PathHelper::getIncludePath('adm/logic/admin_problem_reports_logic.php'));

$page_vars = process_logic(admin_problem_reports_logic(array_merge($_GET, $_POST)));
$session = $page_vars['session'];
$tz = $session->get_timezone();

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => 'problem-reports',
	'page_title'     => 'Problem Reports',
	'readable_title' => 'Problem Reports',
	'breadcrumbs'    => $page_vars['report']
		? array('Problem Reports' => '/admin/admin_problem_reports', 'Report ' . (int)$page_vars['report']->key => '')
		: array('Problem Reports' => ''),
	'session'        => $session,
));

if ($page_vars['sending_enabled']) {
	echo '<p>Members report problems from error pages and error messages. Each report is sent to <strong>'
		. htmlspecialchars($page_vars['destination']) . '</strong>, the upgrade source, and kept here for the retention window. '
		. (ProblemReport::autoSendEnabled()
			? 'This site also reports unexpected errors on its own: one report per error and version, then a count of how often it recurs. '
			: '')
		. 'The switches and the window are under <a href="/admin/admin_settings">Settings</a>, Problem reports.</p>';
} else {
	echo '<p>Sending problem reports is switched off, so reports are kept here only. '
		. 'Turn it on under <a href="/admin/admin_settings">Settings</a>, Problem reports.</p>';
}

$report = $page_vars['report'];
if ($report) {
	$reporter = $page_vars['reporter'];
	$page->begin_box(array('title' => 'Report ' . (int)$report->key));
	echo '<table class="data"><tbody>';
	$rows = array(
		'Status'   => htmlspecialchars($report->status_label()),
		'Reason'   => htmlspecialchars((string)$report->get('prr_last_reason')),
		'Sent to'  => htmlspecialchars((string)$report->get('prr_destination')),
		'Their id' => htmlspecialchars((string)$report->get('prr_remote_report_id')),
		'Tries'    => (int)$report->get('prr_attempts'),
		'Reporter' => $reporter === null
			? 'Automatic: sent by this site when the error happened'
			: ($reporter->key
				? '<a href="/admin/admin_user?usr_user_id=' . (int)$reporter->key . '">' . htmlspecialchars($reporter->display_name()) . '</a>'
				: 'User ' . (int)$report->get('prr_usr_user_id')),
		'Times seen' => (bool)$report->get('prr_automatic')
			? (int)$report->get('prr_occurrences') . ' (the receiver has heard ' . (int)$report->get('prr_occurrences_sent') . '), last '
				. htmlspecialchars(LibraryFunctions::convert_time($report->get('prr_last_seen_time'), 'UTC', $tz, 'M j, Y g:i A'))
			: '',
		'Made'     => htmlspecialchars(LibraryFunctions::convert_time($report->get('prr_create_time'), 'UTC', $tz, 'M j, Y g:i A')),
	);
	foreach ($rows as $label => $value) {
		if ($value === '' ) {
			continue;
		}
		echo '<tr><th scope="row">' . $label . '</th><td>' . $value . '</td></tr>';
	}
	echo '</tbody></table>';
	if ($report->is_sendable()) {
		echo AdminPage::action_button('Send now', '/admin/admin_problem_reports', array(
			'hidden' => array('action' => 'send_now', 'prr_problem_report_id' => (int)$report->key),
		));
	}
	$page->end_box();

	if ($reporter !== null) {
		$page->begin_box(array('title' => 'What the member wrote'));
		echo '<p style="white-space: pre-wrap">' . htmlspecialchars((string)$report->get('prr_comment')) . '</p>';
		if ($page_vars['image']) {
			$url = $page_vars['image']->get_url('original');
			echo '<p><a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener">'
				. '<img src="' . htmlspecialchars($url) . '" alt="Screenshot attached to the report" style="max-width: 100%; max-height: 480px"></a></p>';
		}
		$page->end_box();
	}

	$page->begin_box(array('title' => 'What was sent'));
	foreach ($page_vars['sections'] as $section) {
		echo '<h4>' . htmlspecialchars($section[0]) . '</h4><table class="data"><tbody>';
		foreach ($section[1] as $row) {
			echo '<tr><th scope="row" style="width: 30%">' . htmlspecialchars($row[0]) . '</th>'
				. '<td style="white-space: pre-wrap; overflow-wrap: anywhere; font-family: monospace; font-size: .85em">' . htmlspecialchars($row[1]) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
	$page->end_box();
} else {
	$pager = new Pager(array('numrecords' => $page_vars['numrecords'], 'numperpage' => $page_vars['numperpage']));
	$page->tableheader(array('Report', 'Made', 'Reporter', 'Error', 'Status', 'Reason'), array(
		'title'         => 'Problem reports',
		'filteroptions' => array(
			'Waiting to send' => ProblemReport::STATUS_QUEUED,
			'Sent'            => ProblemReport::STATUS_SENT,
			'Not sent'        => ProblemReport::STATUS_FAILED,
			'Kept here'       => ProblemReport::STATUS_KEPT,
		),
	), $pager);
	foreach ($page_vars['reports'] as $r) {
		$bundle = $r->bundle();
		$error = $bundle['error'] ?? array();
		$where = isset($error['file']) ? $error['file'] . ':' . ($error['line'] ?? '') : ($error['message'] ?? '');
		$page->disprow(array(
			'<a href="/admin/admin_problem_reports?prr_problem_report_id=' . (int)$r->key . '">Report ' . (int)$r->key . '</a>',
			htmlspecialchars(LibraryFunctions::convert_time($r->get('prr_create_time'), 'UTC', $tz, 'M j, g:i A')),
			(bool)$r->get('prr_automatic')
				? 'Automatic (' . (int)$r->get('prr_occurrences') . ' ' . ((int)$r->get('prr_occurrences') === 1 ? 'time' : 'times') . ')'
				: 'User ' . (int)$r->get('prr_usr_user_id'),
			'<small>' . htmlspecialchars(mb_substr((string)$where, 0, 80)) . '</small>',
			htmlspecialchars($r->status_label()),
			'<small>' . htmlspecialchars(mb_substr((string)$r->get('prr_last_reason'), 0, 120)) . '</small>',
		));
	}
	$page->endtable($pager);
}

$page->admin_footer();
