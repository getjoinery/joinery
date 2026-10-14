<?php
/**
 * Admin: a sandboxed plugin's call log. See adm/logic/admin_sandbox_log_logic.php.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('adm/logic/admin_sandbox_log_logic.php'));

$page_vars = process_logic(admin_sandbox_log_logic($_GET));
$session = $page_vars['session'];
$tz = $session->get_timezone();
$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => 'system-plugins',
	'page_title'     => $page_vars['title'] . ': call log',
	'readable_title' => 'Call log',
	'breadcrumbs'    => array('Plugins' => '/admin/admin_plugins', $page_vars['title'] . ' call log' => ''),
	'session'        => $session,
));

// Filters: plain links, each a GET of this page.
$links = array();
$base = $page_vars['here'];
$links[] = '<a href="' . $e($base) . '">All</a>';
foreach ($page_vars['kinds'] as $kind) {
	$links[] = '<a href="' . $e($base . '&kind=' . $kind) . '">' . $e(ucfirst($kind)) . '</a>';
}
foreach ($page_vars['statuses'] as $status) {
	if ($status !== SandboxLog::STATUS_OK) {
		$links[] = '<a href="' . $e($base . '&status=' . $status) . '">' . $e(ucfirst($status)) . '</a>';
	}
}

$page->begin_box(array('title' => $page_vars['title'] . ': what it was asked, and what it did'));
echo '<p>Every call the plugin host made for this plugin: a form on its pages, a signal, a scheduled run. '
	. 'Under each, what the module asked of each door (the site\'s API, the web, its log), and whether that was done, '
	. 'refused or failed. ' . (int)$page_vars['total'] . ' kept; see '
	. '<a href="' . $e(SandboxPluginManager::approvalUrl($page_vars['plugin'])) . '">what it may do</a>.</p>';
echo '<p>' . implode(' · ', $links) . '</p>';
$page->end_box();

$page->tableheader(array('When', 'What', 'Outcome', 'Took', 'What it reached'), array('title' => 'Calls'));
foreach ($page_vars['logs'] as $log) {
	$what = $e($log->get('sbl_kind')) . ' <strong>' . $e($log->get('sbl_name')) . '</strong>';
	if ($log->get('sbl_usr_user_id')) {
		$what .= '<br><small>for user ' . (int)$log->get('sbl_usr_user_id') . '</small>';
	}
	$outcome = $e($log->get('sbl_status'));
	if ($log->get('sbl_message')) {
		$outcome .= '<br><small>' . $e($log->get('sbl_message')) . '</small>';
	}
	$reached = array();
	foreach ((array)$log->get_json_decoded('sbl_calls') as $call) {
		if (!is_array($call)) {
			continue;
		}
		$reached[] = $e($call['door'] ?? '') . ' ' . $e($call['target'] ?? '') . ': <strong>' . $e($call['status'] ?? '') . '</strong>'
			. (($call['detail'] ?? '') !== '' ? ' — ' . $e($call['detail']) : '');
	}
	$page->disprow(array(
		$e(LibraryFunctions::convert_time((string)$log->get('sbl_create_time'), 'UTC', $tz, 'M j, g:i:s A')),
		$what,
		$outcome,
		$log->get('sbl_duration_ms') !== null ? (int)$log->get('sbl_duration_ms') . ' ms' : '',
		$reached ? implode('<br>', $reached) : '<span class="text-muted">nothing</span>',
	));
}
$page->endtable();

if ($page_vars['total'] > ($page_vars['page'] + 1) * $page_vars['per_page']) {
	$next = $base . (isset($page_vars['filters']['kind']) ? '&kind=' . $page_vars['filters']['kind'] : '')
		. (isset($page_vars['filters']['status']) ? '&status=' . $page_vars['filters']['status'] : '')
		. '&page=' . ($page_vars['page'] + 1);
	echo '<p><a href="' . $e($next) . '">Older calls</a></p>';
}

$page->admin_footer();
