<?php
/**
 * Admin: Bug Reports — problem reports received from sites that upgrade from
 * this one.
 *
 * Two views. Grouped (the default) puts the same error together, however many
 * sites reported it: how many sites and reports, how many still open, the
 * newest version seen, and when it was first and last reported. Every report
 * lists them one by one, filtered by host, version, verification or status.
 * "Mark all seen" (POST) clears the new-report notice.
 *
 * @version 1.0.0
 */
$session = SessionControl::get_instance();
$session->check_permission(9);
$session->set_return();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'mark_all_seen') {
	foreach (new MultiReceivedBugReport(array('status' => ReceivedBugReport::STATUS_NEW)) as $r) {
		$r->set('rbr_status', ReceivedBugReport::STATUS_SEEN);
		$r->save();
	}
	header('Location: /plugins/bug_reports/admin/admin_bug_reports');
	exit;
}

$view = ($_GET['view'] ?? '') === 'all' ? 'all' : 'grouped';
$tz = $session->get_timezone();

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => 'bug-reports',
	'page_title'     => 'Bug Reports',
	'readable_title' => 'Bug Reports',
	'breadcrumbs'    => array('Bug Reports' => ''),
	'session'        => $session,
));

echo '<p>Sites that upgrade from this one send a report when a member reports an error. '
	. 'Each report is checked by calling the site back: <strong>verified</strong> means it answered as the Joinery version it claimed, '
	. '<strong>version mismatch</strong> that it runs another version, and <strong>unverified</strong> that it did not answer as a Joinery site '
	. '(a site behind an IP allowlist answers that way too). Report contents come from another machine; treat them as untrusted.</p>';

echo '<p><a href="?view=grouped" class="btn btn-' . ($view === 'grouped' ? 'primary' : 'outline-secondary') . '">Grouped by error</a> '
	. '<a href="?view=all" class="btn btn-' . ($view === 'all' ? 'primary' : 'outline-secondary') . '">Every report</a> ';
echo AdminPage::action_button('Mark all seen', '/plugins/bug_reports/admin/admin_bug_reports', array(
	'hidden' => array('action' => 'mark_all_seen'),
));
echo '</p>';

$verdicts = array(
	ReceivedBugReport::VERDICT_VERIFIED   => 'Verified',
	ReceivedBugReport::VERDICT_MISMATCH   => 'Version mismatch',
	ReceivedBugReport::VERDICT_UNVERIFIED => 'Unverified',
);

if ($view === 'grouped') {
	$page->tableheader(array('Error', 'Where', 'Sites', 'Reports', 'Open', 'Newest version', 'First', 'Last'),
		array('title' => 'Grouped by error'));
	foreach (ReceivedBugReport::groups() as $g) {
		$link = '?view=all&hash=' . urlencode($g['hash']);
		$label = $g['hash'] === '' ? 'No recorded error (message only)' : ($g['kind'] ?: 'Error');
		$page->disprow(array(
			'<a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($label) . '</a><br><small>'
				. htmlspecialchars(mb_substr((string)$g['message'], 0, 120)) . '</small>',
			'<small>' . htmlspecialchars((string)$g['location']) . '</small>',
			(int)$g['sites'],
			(int)$g['reports'],
			(int)$g['open'],
			htmlspecialchars((string)$g['newest_version']),
			htmlspecialchars(LibraryFunctions::convert_time($g['first_time'], 'UTC', $tz, 'M j, Y')),
			htmlspecialchars(LibraryFunctions::convert_time($g['last_time'], 'UTC', $tz, 'M j, Y g:i A')),
		));
	}
	$page->endtable();
} else {
	$options = array();
	foreach (array('host', 'version', 'verdict', 'status', 'hash') as $key) {
		if ((isset($_GET[$key]) && $_GET[$key] !== '') || ($key === 'hash' && isset($_GET['hash']))) {
			$options[$key] = (string)$_GET[$key];
		}
	}
	$numperpage = 30;
	$offset = (int)($_GET['offset'] ?? 0);
	$reports = new MultiReceivedBugReport($options, array('rbr_received_time' => 'DESC'), $numperpage, $offset);
	$pager = new Pager(array('numrecords' => $reports->count_all(), 'numperpage' => $numperpage));
	if ($options) {
		$shown = array();
		foreach ($options as $k => $v) {
			$shown[] = $k . ' = ' . ($v === '' ? '(none)' : $v);
		}
		echo '<p>Showing ' . htmlspecialchars(implode(', ', $shown)) . '. <a href="?view=all">Show every report</a></p>';
	}
	$page->tableheader(array('Report', 'Received', 'Site', 'Version', 'Check', 'Error', 'Status'),
		array('title' => 'Every report'), $pager);
	foreach ($reports as $r) {
		$page->disprow(array(
			'<a href="/plugins/bug_reports/admin/admin_bug_report?rbr_received_bug_report_id=' . (int)$r->key . '">Report ' . (int)$r->key . '</a>',
			htmlspecialchars(LibraryFunctions::convert_time($r->get('rbr_received_time'), 'UTC', $tz, 'M j, g:i A')),
			'<a href="?view=all&host=' . urlencode((string)$r->get('rbr_claimed_host')) . '">' . htmlspecialchars((string)$r->get('rbr_claimed_host')) . '</a>',
			'<a href="?view=all&version=' . urlencode((string)$r->get('rbr_claimed_version')) . '">' . htmlspecialchars((string)$r->get('rbr_claimed_version')) . '</a>',
			htmlspecialchars($verdicts[$r->get('rbr_verdict')] ?? (string)$r->get('rbr_verdict')),
			'<small>' . htmlspecialchars(mb_substr((string)($r->get('rbr_error_location') ?: $r->get('rbr_error_message')), 0, 80)) . '</small>',
			htmlspecialchars(ucfirst((string)$r->get('rbr_status'))),
		));
	}
	$page->endtable($pager);
}

$page->admin_footer();
