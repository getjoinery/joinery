<?php
/** @joinery-admin class=personal */
/**
 * Admin: Content Reports. See adm/logic/admin_content_reports_logic.php.
 *
 * @version 1.0.0
 */
require_once(PathHelper::getIncludePath('adm/logic/admin_content_reports_logic.php'));

$page_vars = process_logic(admin_content_reports_logic(array_merge($_GET, $_POST)));
$session = $page_vars['session'];
$tz = $session->get_timezone();
$filter = $page_vars['filter'];

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => 'content-reports',
	'page_title'     => 'Content Reports',
	'readable_title' => 'Content Reports',
	'breadcrumbs'    => array('Content Reports' => ''),
	'session'        => $session,
));

echo '<p>Members report other people\'s posts and messages here. Acting on a report, or dismissing it, '
	. 'settles every open report on the same content. Dismissing leaves the content as it is.</p>';

$pager = new Pager(array('numrecords' => $page_vars['numrecords'], 'numperpage' => $page_vars['numperpage']));
$page->tableheader(array('Content', 'Reason', 'Reported', 'Status', 'Actions'), array(
	'title'         => 'Reports',
	'filteroptions' => $page_vars['statuses'],
), $pager);

$back = '/admin/admin_content_reports?filter=' . urlencode($filter);
foreach ($page_vars['rows'] as $row) {
	$report = $row['report'];
	$preview = $row['preview'];
	$id = (int)$report->key;

	if ($preview !== null) {
		// A same-site path only: the handler names where the content lives here.
		$url = (string)($preview['url'] ?? '');
		$title = htmlspecialchars((string)($preview['title'] ?? '') !== '' ? (string)$preview['title'] : 'Open');
		$content = '<strong>' . htmlspecialchars($row['label']) . ':</strong> '
			. (preg_match('~^/(?![/\\\\])~', $url)
				? '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener">' . $title . '</a>'
				: $title);
		if ((string)$preview['excerpt'] !== '') {
			$content .= '<br><small>' . htmlspecialchars(mb_substr((string)$preview['excerpt'], 0, 240)) . '</small>';
		}
		if (!empty($preview['author_user_id'])) {
			$author = new User((int)$preview['author_user_id'], TRUE);
			$content .= '<br><small>By <a href="/admin/admin_user?usr_user_id=' . (int)$author->key . '">'
				. htmlspecialchars($author->display_name()) . '</a></small>';
		}
	} elseif (!$row['active']) {
		$content = '<strong>' . htmlspecialchars($row['label']) . ' #' . (int)$report->get('rpt_entity_id') . '</strong>'
			. '<br><small>The feature this belongs to is not active.</small>';
	} else {
		$content = '<strong>' . htmlspecialchars($row['label']) . ' #' . (int)$report->get('rpt_entity_id') . '</strong>'
			. '<br><small>No longer there.</small>';
	}

	$reason = htmlspecialchars($report->reason_label());
	if ((string)$report->get('rpt_note') !== '') {
		$reason .= '<br><small style="white-space: pre-wrap">' . htmlspecialchars((string)$report->get('rpt_note')) . '</small>';
	}

	$reporter = $row['reporter'];
	$reported = htmlspecialchars(LibraryFunctions::convert_time($report->get('rpt_create_time'), 'UTC', $tz, 'M j, g:i A'));
	if ($reporter && $reporter->key) {
		$reported .= '<br><small>by <a href="/admin/admin_user?usr_user_id=' . (int)$reporter->key . '">'
			. htmlspecialchars($reporter->display_name()) . '</a></small>';
	}

	$status = htmlspecialchars($report->status_label());
	if (!$report->is_open()) {
		$who = $row['handler'];
		$status .= '<br><small>' . htmlspecialchars((string)$report->get('rpt_outcome'));
		if ($who && $who->key) {
			$status .= ', ' . htmlspecialchars($who->display_name());
		}
		$status .= ', ' . htmlspecialchars(LibraryFunctions::convert_time($report->get('rpt_handled_time'), 'UTC', $tz, 'M j, g:i A'))
			. '</small>';
	}

	$buttons = '';
	if ($report->is_open()) {
		foreach ($row['actions'] as $action_key => $action_label) {
			$buttons .= AdminPage::action_button($action_label, $back, array(
				'hidden'  => array('action' => 'act', 'rpt_content_report_id' => $id, 'report_action' => $action_key),
				'confirm' => $action_label . '?',
			)) . ' ';
		}
		$buttons .= AdminPage::action_button('Dismiss', $back, array(
			'hidden' => array('action' => 'dismiss', 'rpt_content_report_id' => $id),
		));
	}

	$page->disprow(array($content, $reason, $reported, $status, $buttons));
}
$page->endtable($pager);

$page->admin_footer();
?>
