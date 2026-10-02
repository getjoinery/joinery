<?php
/**
 * Server Manager — Incidents
 * URL: /admin/server_manager/incidents
 *
 * Every condition on every node that needs, or needed, a person, in one list
 * (incident_triage.md, Pages). Views by triage (Needs you first),
 * filters by node, type and whether it is still happening, and the same
 * triage for several at once. Each row opens the incident's own page.
 *
 * Superadmin only. Every write is a POST carrying the admin CSRF token, and
 * goes through IncidentTriage.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

$page_regex = '/\/admin\/server_manager/';
$self_url = IncidentViews::LIST_URL;

$views = array(
	'needs_you' => 'Needs you',
	'new'       => 'New',
	'looking'   => 'Looking',
	'snoozed'   => 'Snoozed',
	'resolved'  => 'Resolved',
	'ignored'   => 'Ignored',
	'all'       => 'All',
);
$view = (string)($_GET['view'] ?? 'needs_you');
if (!isset($views[$view])) { $view = 'needs_you'; }
$f_node = (int)($_GET['node'] ?? 0);
$f_source = (string)($_GET['source'] ?? '');
$f_active = (string)($_GET['active'] ?? '') === '1';

// The page's own address with its view and filters, so an action returns to
// what the person was looking at.
$query = array_filter(array('view' => $view, 'node' => $f_node ?: null, 'source' => $f_source !== '' ? $f_source : null, 'active' => $f_active ? '1' : null));
$here = $self_url . ($query ? '?' . http_build_query($query) : '');

$flash = function ($text, $ok) use ($session, $page_regex) {
	$session->save_message(new DisplayMessage($text, $ok ? 'Incidents' : 'Error', $page_regex,
		$ok ? DisplayMessage::MESSAGE_ANNOUNCEMENT : DisplayMessage::MESSAGE_ERROR,
		DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
};

if ($_POST) {
	$back = (string)($_POST['back'] ?? '');
	// Only this page's own address, with its own query string.
	if (preg_match('#^' . preg_quote($self_url, '#') . '(\?[A-Za-z0-9_=&%.:\-]*)?$#', $back) !== 1) {
		$back = $self_url;
	}
	if (!SmAdminCsrf::valid()) { header('Location: ' . $back); exit; }
	$action = (string)($_POST['action'] ?? '');
	$uid = (int)$session->get_user_id();
	try {
		if ($action === 'triage') {
			$ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : array();
			if (count($ids) === 0) {
				$flash('Select one or more incidents first.', false);
			} else {
				$r = IncidentTriage::apply($ids, (string)($_POST['do'] ?? ''), $uid);
				$flash($r['changed'] . ' incident' . ($r['changed'] === 1 ? '' : 's') . ' changed'
					. ($r['missing'] > 0 ? '; ' . $r['missing'] . ' no longer exist' . ($r['missing'] === 1 ? 's' : '') : '') . '.', true);
			}
		} elseif ($action === 'resolve_all_cleared') {
			// Within the filters the person was looking at, as the button's count is.
			$n = IncidentTriage::resolve_all_cleared($uid, array('node_id' => (int)($_POST['node'] ?? 0), 'source' => (string)($_POST['source'] ?? '')));
			$flash($n . ' cleared incident' . ($n === 1 ? '' : 's') . ' resolved.', true);
		}
	} catch (IncidentTriageException $e) {
		$flash($e->getMessage(), false);
	}
	header('Location: ' . $back);
	exit;
}

$page = new AdminPage();
$page->admin_header([
	'menu-id' => 'server-manager-incidents',
	'page_title' => 'Incidents',
	'readable_title' => 'Incidents',
	'breadcrumbs' => ['Server Manager' => '/admin/server_manager', 'Incidents' => ''],
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

$e = function ($v) { return IncidentViews::e($v); };
$csrf = SmAdminCsrf::token();

// The rows for this view and filters, newest first.
$shown_max = 200;
$options = array('deleted' => false);
if ($view !== 'all') { $options['view'] = $view; }
if ($f_node > 0) { $options['node_id'] = $f_node; }
if ($f_source !== '') { $options['source'] = $f_source; }
if ($f_active) { $options['status'] = IncidentRecord::STATUS_OPEN; }
$rows = array();
foreach (new MultiIncidentRecord($options, array('inc_incident_record_id' => 'DESC'), $shown_max) as $inc) {
	$rows[] = $inc;
}

// How many in each view, under the same filters, for the view links.
$db = DbConnector::get_instance()->get_db_link();
$where = 'inc_delete_time IS NULL';
$bind = array();
if ($f_node > 0) { $where .= ' AND inc_mgn_managed_node_id = ?'; $bind[] = $f_node; }
if ($f_source !== '') { $where .= ' AND inc_source = ?'; $bind[] = $f_source; }
if ($f_active) { $where .= " AND inc_status = 'open'"; }
$snooze_over = "inc_triage = 'snoozed' AND inc_snooze_until <= now() AT TIME ZONE 'UTC'";
$q = $db->prepare("SELECT
		count(*) FILTER (WHERE " . IncidentRecord::NEEDS_YOU_SQL . ") AS needs_you,
		count(*) FILTER (WHERE inc_triage = 'new' OR ($snooze_over)) AS new,
		count(*) FILTER (WHERE inc_triage = 'looking') AS looking,
		count(*) FILTER (WHERE inc_triage = 'snoozed' AND NOT ($snooze_over)) AS snoozed,
		count(*) FILTER (WHERE inc_triage = 'resolved') AS resolved,
		count(*) FILTER (WHERE inc_triage = 'ignored') AS ignored,
		count(*) AS every,
		count(*) FILTER (WHERE inc_status = 'closed' AND " . IncidentRecord::NEEDS_YOU_SQL . ") AS cleared_needing
	FROM inc_incident_records WHERE $where");
$q->execute($bind);
$counts = $q->fetch(PDO::FETCH_ASSOC) ?: array();

// ── The views ──
echo '<ul class="nav nav-tabs flex-wrap mb-3">';
foreach ($views as $key => $label) {
	$link = $self_url . '?' . http_build_query(array_filter(array('view' => $key, 'node' => $f_node ?: null,
		'source' => $f_source !== '' ? $f_source : null, 'active' => $f_active ? '1' : null)));
	echo '<li class="nav-item"><a class="nav-link' . ($key === $view ? ' active' : '') . '" href="' . $e($link) . '">'
		. $e($label) . ' <span class="badge bg-light text-dark">' . (int)($counts[$key === 'all' ? 'every' : $key] ?? 0) . '</span></a></li>';
}
echo '</ul>';

// ── Filters (a GET form) ──
$node_options = array('' => 'Every node');
$source_options = array('' => 'Every type');
foreach ($db->query("SELECT DISTINCT inc_mgn_managed_node_id FROM inc_incident_records WHERE inc_delete_time IS NULL ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN) as $nid) {
	$node_options[(int)$nid] = IncidentViews::node_label((int)$nid);
}
foreach ($db->query("SELECT DISTINCT inc_source FROM inc_incident_records WHERE inc_delete_time IS NULL ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN) as $src) {
	$source_options[(string)$src] = IncidentTitles::for_source((string)$src);
}
$fw = $page->getFormWriter('incident_filters', ['method' => 'get', 'action' => $self_url]);
$fw->begin_form();
$fw->hiddeninput('view', '', ['value' => $view]);
echo '<div class="d-flex flex-wrap align-items-end gap-3 mb-3">';
$fw->dropinput('node', 'Node', ['options' => $node_options, 'value' => $f_node ?: '']);
$fw->dropinput('source', 'Type', ['options' => $source_options, 'value' => $f_source]);
$fw->checkboxinput('active', 'Only what is still happening', ['value' => '1', 'checked' => $f_active]);
$fw->submitbutton('btn_filter', 'Filter', ['class' => 'btn btn-sm btn-outline-secondary']);
echo '</div>';
$fw->end_form();

// ── The list ──
$page->begin_box(['title' => $views[$view]]);
if (count($rows) === 0) {
	echo '<p class="text-muted mb-0">' . ($view === 'needs_you'
		? 'Nothing needs you. Every incident is resolved, ignored or snoozed.'
		: 'No incidents here.') . '</p>';
} else {
	echo '<div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">';
	echo IncidentViews::do_form('incident_bulk', $self_url, $csrf, array('back' => $here), false, 'Apply');
	if ((int)($counts['cleared_needing'] ?? 0) > 0) {
		echo '<form method="post" action="' . $e($self_url) . '">' . SmAdminCsrf::field()
			. '<input type="hidden" name="action" value="resolve_all_cleared">'
			. '<input type="hidden" name="node" value="' . (int)$f_node . '">'
			. '<input type="hidden" name="source" value="' . $e($f_source) . '">'
			. '<input type="hidden" name="back" value="' . $e($here) . '">'
			. '<button type="submit" class="btn btn-sm btn-outline-success">Resolve all cleared (' . (int)$counts['cleared_needing'] . ')</button></form>';
	}
	echo '</div>';
	echo IncidentViews::table($rows, ['show_node' => true, 'select' => 'incident_bulk']);
	if (count($rows) >= $shown_max) {
		echo '<p class="small text-muted mt-2 mb-0">Showing the newest ' . $shown_max . '. Filter by node or type to see older ones.</p>';
	}
}
$page->end_box();

echo '<p class="small text-muted">An incident is one condition on one node, from when it starts until it is over. Its source says whether it is '
	. 'still happening; you say what is being done about it. Something that clears on its own before anyone looks still waits in Needs you, '
	. 'shown as cleared: Resolve all cleared puts those away in one step.</p>';

$page->admin_footer();
