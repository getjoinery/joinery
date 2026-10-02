<?php
/**
 * Server Manager — one incident
 * URL: /admin/server_manager/incident?id=N
 *
 * One condition on one node that needs a person (incident_triage.md,
 * Pages): what it is, whether it is still happening, what is being done about
 * it, everything that happened in order, and what its source attached. A
 * person sets the triage and adds notes here; whether the condition is still
 * there is its source's to say, never this page's.
 *
 * Superadmin only. Every write is a POST carrying the admin CSRF token, and
 * goes through IncidentTriage, or IncidentAnalyst for Analyze (shown only
 * where the Joinery AI plugin is active).
 *
 * @version 1.1 - Analyze: a model reads the incident and says what it thinks (IncidentAnalyst; WP4)
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

$page_regex = '/\/admin\/server_manager/';
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$self_url = IncidentViews::url($id);

$flash = function ($text, $ok) use ($session, $page_regex) {
	$session->save_message(new DisplayMessage($text, $ok ? 'Incident' : 'Error', $page_regex,
		$ok ? DisplayMessage::MESSAGE_ANNOUNCEMENT : DisplayMessage::MESSAGE_ERROR,
		DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
};

if ($_POST) {
	if (!SmAdminCsrf::valid()) { header('Location: ' . $self_url); exit; }
	$inc = IncidentTriage::load($id);
	$action = (string)($_POST['action'] ?? '');
	if ($inc === null) {
		$flash('There is no such incident.', false);
		header('Location: ' . IncidentViews::LIST_URL);
		exit;
	}
	try {
		if ($action === 'triage') {
			$r = IncidentTriage::apply(array($id), (string)($_POST['do'] ?? ''), (int)$session->get_user_id());
			$inc->load();
			$flash($r['changed'] > 0 ? 'Set to ' . (IncidentTriage::LABELS[$inc->triage()] ?? $inc->triage()) . '.' : 'It already read that way.', true);
		} elseif ($action === 'analyze') {
			IncidentAnalyst::request($inc, (int)$session->get_user_id());
			$flash('Analysis started. It shows here when it is done.', true);
		} elseif ($action === 'note') {
			IncidentTriage::note($inc, (string)($_POST['note'] ?? ''), (int)$session->get_user_id());
			$flash('Note added.', true);
		}
	} catch (IncidentTriageException | IncidentAnalystException $e) {
		$flash($e->getMessage(), false);
	}
	header('Location: ' . $self_url);
	exit;
}

$inc = IncidentTriage::load($id);

$page = new AdminPage();
$page->admin_header([
	'menu-id' => 'server-manager-incidents',
	'page_title' => $inc ? $inc->title() : 'Incident',
	'readable_title' => $inc ? $inc->title() : 'Incident',
	'breadcrumbs' => ['Server Manager' => '/admin/server_manager', 'Incidents' => IncidentViews::LIST_URL, 'Incident #' . $id => ''],
	'session' => $session,
]);

$display_messages = $session->get_messages('/admin/server_manager');
foreach ($display_messages as $msg) {
	$cls = ($msg->display_type == DisplayMessage::MESSAGE_ERROR) ? 'alert-danger' : 'alert-success';
	echo '<div class="alert ' . $cls . '" role="alert">' . htmlspecialchars($msg->message)
		. '<button type="button" class="alert-close" aria-label="Close">&times;</button></div>';
}
$session->mark_shown($display_messages);
$session->clear_clearable_messages();

if ($inc === null) {
	$page->begin_box(['title' => 'Incident #' . $id]);
	echo '<p class="mb-0">There is no such incident. <a href="' . IncidentViews::LIST_URL . '">All incidents</a></p>';
	$page->end_box();
	$page->admin_footer();
	return;
}

$e = function ($v) { return IncidentViews::e($v); };
$node_id = (int)$inc->get('inc_mgn_managed_node_id');
$csrf = SmAdminCsrf::token();

// ── What it is, and where it stands ──
$page->begin_box(['title' => 'Incident #' . $id]);
echo '<div class="d-flex flex-wrap gap-2 mb-2">' . IncidentViews::severity_badge($inc) . IncidentViews::condition_badge($inc) . IncidentViews::triage_badge($inc) . '</div>';
$facts = array();
$facts['Node'] = '<a href="/admin/server_manager/node_detail?mgn_managed_node_id=' . $node_id . '">' . $e(IncidentViews::node_label($node_id)) . '</a>';
$facts['From'] = $e(IncidentTitles::kind_label((string)$inc->get('inc_source')))
	. ' <span class="small text-muted">' . $e($inc->get('inc_source'))
	. ((int)$inc->get('inc_node_case_id') > 0 ? ' #' . (int)$inc->get('inc_node_case_id') : '') . '</span>';
$facts['Started'] = $e(IncidentViews::when($inc->get('inc_opened_time') ?: $inc->get('inc_create_time')));
$facts['Now'] = $inc->is_open()
	? 'Still happening. It clears when ' . (strpos((string)$inc->get('inc_source'), 'plane:') === 0
		? 'this management node sees the condition gone.' : 'the node\'s own check passes and the node reports it.')
	: 'Cleared ' . $e(IncidentViews::when($inc->get('inc_closed_time'))) . '.';
$by = IncidentViews::person((int)$inc->get('inc_triage_usr_user_id'));
if ($by !== '') {
	$facts['Triage'] = $e(IncidentTriage::LABELS[$inc->triage()] ?? '') . ', set by ' . $e($by) . ' ' . $e(IncidentViews::when($inc->get('inc_triage_time')));
}
echo '<div class="mb-3">' . IncidentViews::facts($facts) . '</div>';
echo IncidentViews::triage_buttons($self_url, $inc, $csrf);
echo '<div class="mt-2">' . IncidentViews::do_form('incident_snooze', $self_url, $csrf, array('id' => $id), true, 'Snooze') . '</div>';
echo '<p class="small text-muted mt-2 mb-0">Resolve when it is dealt with, or when it cleared and needs nothing more. Ignore when it needs nothing '
	. 'from anyone; the next time it happens is news again. A snooze puts it back in Needs you when it ends.</p>';
$page->end_box();

// ── What a model thinks (needs the Joinery AI plugin) ──
if (IncidentAnalyst::available()) {
	$latest = IncidentAnalyst::latest($id);
	$running = IncidentAnalyst::is_running($latest);
	$page->begin_box(['title' => 'Analysis']);
	echo IncidentViews::analysis($latest, $node_id);
	if (!$running) {
		echo '<form method="post" action="' . $e($self_url) . '" class="mt-3">' . SmAdminCsrf::field()
			. '<input type="hidden" name="action" value="analyze">'
			. '<input type="hidden" name="id" value="' . $id . '">'
			. '<button type="submit" class="btn btn-sm btn-outline-primary">' . ($latest ? 'Analyze again' : 'Analyze') . '</button></form>';
	} else {
		// Reload until the worker has stored its answer.
		echo '<script>setTimeout(function () { window.location.reload(); }, 10000);</script>';
	}
	$page->end_box();
}

// ── What happened ──
$page->begin_box(['title' => 'Timeline']);
echo IncidentViews::timeline(IncidentEvent::for_incident($id));
$fw = $page->getFormWriter('incident_note', ['action' => $self_url]);
echo '<div class="mt-3">';
$fw->begin_form();
$fw->hiddeninput('action', '', ['value' => 'note']);
$fw->hiddeninput('id', '', ['value' => (string)$id]);
$fw->hiddeninput(SmAdminCsrf::FIELD, '', ['value' => $csrf]);
$fw->textarea('note', 'Add a note', ['rows' => 3, 'placeholder' => 'What you saw, what you did, what is next']);
$fw->submitbutton('btn_incident_note', 'Add note', ['class' => 'btn btn-sm btn-outline-primary']);
$fw->end_form();
echo '</div>';
$page->end_box();

// ── What the source attached ──
$page->begin_box(['title' => 'Evidence']);
echo IncidentViews::evidence($inc);
$page->end_box();

$page->admin_footer();
