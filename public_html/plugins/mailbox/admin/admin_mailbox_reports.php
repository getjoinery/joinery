<?php
/**
 * Mailbox — Deliverability Reports (specs/deliverability_report_ingest.md § Surfaces)
 *
 * The sender inventory: everything that is sending as a hosted domain, and
 * whether it is authorised — built from the DMARC aggregate, TLS-RPT and ARF
 * reports that providers mail back because the domain's published policy asked
 * them to. Reports are detected and filed during ingest; this page reads the
 * derived rows.
 *
 * Unaligned senders are surfaced first: each is either a forgery or a system
 * of yours nobody remembered, and both deserve a look.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/admin_tabs.php'));

$session = SessionControl::get_instance();
$session->check_permission(5);

$domain_id = intval(LibraryFunctions::fetch_variable('domain_id', 0, 0, ''));
$window_days = intval(LibraryFunctions::fetch_variable('window', 30, 0, ''));
if (!in_array($window_days, array(7, 30, 90, 365), true)) { $window_days = 30; }

// Domains that could have reports: enabled, not IMAP mirrors.
$domains = array();
foreach (new MultiInboundEmailDomain(array('deleted' => false)) as $d) {
	if ($d->is_imap_source()) { continue; }
	$domains[intval($d->key)] = $d;
}
if ($domain_id === 0 || !isset($domains[$domain_id])) {
	$domain_id = count($domains) ? array_key_first($domains) : 0;
}

$page = new AdminPage();
$page->admin_header(
	array(
		'menu-id' => 'incoming',
		'breadcrumbs' => array(
			'Inbound Email' => '/plugins/mailbox/admin/admin_mailbox',
			'Reports' => '',
		),
		'session' => $session,
	)
);

// No Reports tab (see admin_tabs.php) — this page is reached from the Setup
// tab's "Deliverability reports" row, the new-sender notice email, and Logs;
// it highlights Setup the way the per-object editors highlight Accounts.
echo AdminPage::tab_menu(mailbox_admin_tabs(), 'Setup');

echo '<div class="card mb-3"><div class="card-body">';
echo '<p class="mb-0">Mail providers send machine-generated reports about mail claiming to come from '
	. 'your domains — who sent it, and whether it carried your authorisation. They are collected '
	. 'automatically as they arrive; nothing lands in an inbox. This is what they say.</p>';
echo '</div></div>';

if ($domain_id === 0) {
	echo '<p>No hosted domains yet — reports appear once a domain is receiving mail here and publishes a DMARC record.</p>';
	$page->admin_footer();
	return;
}

// Filters
echo '<form class="mb-3" method="get">';
echo '<div class="row g-2 align-items-center">';
echo '<div class="col-auto"><label class="col-form-label">Domain:</label></div>';
echo '<div class="col-auto"><select name="domain_id" class="form-select form-select-sm">';
foreach ($domains as $id => $d) {
	$sel = ($id === $domain_id) ? ' selected' : '';
	echo '<option value="' . $id . '"' . $sel . '>' . htmlspecialchars($d->get('ied_domain')) . '</option>';
}
echo '</select></div>';
echo '<div class="col-auto"><label class="col-form-label">Window:</label></div>';
echo '<div class="col-auto"><select name="window" class="form-select form-select-sm">';
foreach (array(7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last year') as $w => $label) {
	$sel = ($w === $window_days) ? ' selected' : '';
	echo '<option value="' . $w . '"' . $sel . '>' . $label . '</option>';
}
echo '</select></div>';
echo '<div class="col-auto"><button type="submit" class="btn btn-sm btn-outline-primary">Show</button></div>';
echo '</div></form>';

$db = DbConnector::get_instance()->get_db_link();
$since = gmdate('Y-m-d H:i:s', time() - $window_days * 86400);

// ── Sender inventory: group source rows by IP, unaligned first ─────────
$stmt = $db->prepare(
	"SELECT dvs_source_ip,
	        SUM(dvs_count) AS messages,
	        COUNT(DISTINCT dvs_dvr_deliverability_report_id) AS reports,
	        BOOL_AND(dvs_aligned) AS always_aligned,
	        BOOL_OR(dvs_aligned)  AS ever_aligned,
	        MIN(dvs_create_time)  AS first_seen,
	        MAX(dvs_create_time)  AS last_seen,
	        MAX(dvs_header_from)  AS header_from,
	        STRING_AGG(DISTINCT NULLIF(dvs_disposition, ''), ', ') AS dispositions
	   FROM dvs_deliverability_report_sources
	  WHERE dvs_ied_inbound_email_domain_id = ?
	    AND dvs_create_time >= ?
	    AND dvs_delete_time IS NULL
	  GROUP BY dvs_source_ip
	  ORDER BY BOOL_AND(dvs_aligned) ASC, SUM(dvs_count) DESC
	  LIMIT 500");
$stmt->execute(array($domain_id, $since));
$sources = $stmt->fetchAll(PDO::FETCH_ASSOC);

$headers = array('Source IP', 'Messages', 'Reports', 'Authorised', 'Claimed From', 'Dispositions', 'First seen', 'Last seen');
$page->tableheader($headers, array('title' => 'Who is sending as ' . htmlspecialchars($domains[$domain_id]->get('ied_domain'))));
if (count($sources) === 0) {
	$page->disprow(array('<em>No sources reported in this window.</em>', '', '', '', '', '', '', ''));
}
foreach ($sources as $s) {
	$always = ($s['always_aligned'] === true || $s['always_aligned'] === 't');
	$ever   = ($s['ever_aligned'] === true || $s['ever_aligned'] === 't');
	if ($always) {
		$verdict = '<span class="badge bg-success">yes</span>';
	} elseif ($ever) {
		$verdict = '<span class="badge bg-warning text-dark">sometimes</span>';
	} else {
		$verdict = '<span class="badge bg-danger">no</span>';
	}
	$page->disprow(array(
		'<code>' . htmlspecialchars($s['dvs_source_ip']) . '</code>',
		(int)$s['messages'],
		(int)$s['reports'],
		$verdict,
		htmlspecialchars((string)$s['header_from']),
		htmlspecialchars((string)$s['dispositions']),
		htmlspecialchars(substr((string)$s['first_seen'], 0, 10)),
		htmlspecialchars(substr((string)$s['last_seen'], 0, 10)),
	));
}
$page->endtable();

echo '<p class="small text-muted">"Authorised" is what the reporting provider concluded: the mail '
	. 'carried this domain\'s aligned DKIM signature or came from its SPF-listed servers. A "no" is '
	. 'either a forgery or a system of yours sending without authorisation — both are worth a look. '
	. 'TLS-RPT failure rows and complaint rows count as authorised; their story is in Dispositions.</p>';

// ── The reports themselves ─────────────────────────────────────────────
$reports = new MultiDeliverabilityReport(
	array('domain_id' => $domain_id, 'received_since' => $since, 'deleted' => false),
	array('dvr_create_time' => 'DESC'), 100);

$headers = array('Received', 'Kind', 'Reporter', 'Report window', 'Sources', 'Messages', 'Status');
$page->tableheader($headers, array('title' => 'Reports received'));
$any = false;
foreach ($reports as $r) {
	$any = true;
	$status = $r->get('dvr_parse_status');
	$status_class = $status === DeliverabilityReport::PARSE_PARSED ? 'bg-success'
		: ($status === DeliverabilityReport::PARSE_FAILED ? 'bg-danger' : 'bg-warning text-dark');
	$badge = '<span class="badge ' . $status_class . '">' . htmlspecialchars($status) . '</span>';
	if ($status !== DeliverabilityReport::PARSE_PARSED && $r->get('dvr_parse_error')) {
		$badge .= ' <span class="small text-muted">' . htmlspecialchars(substr($r->get('dvr_parse_error'), 0, 80)) . '</span>';
	}
	$window = '';
	if ($r->get('dvr_begin_time')) {
		$window = $r->get_local('dvr_begin_time', 'M j') . ' – ' . ($r->get_local('dvr_end_time', 'M j') ?: '?');
	}
	$page->disprow(array(
		$r->get_local('dvr_create_time', 'M j g:i A'),
		htmlspecialchars($r->get('dvr_kind')),
		htmlspecialchars($r->get('dvr_org_name') ?: '(unknown)'),
		$window,
		(int)$r->get('dvr_source_count'),
		(int)$r->get('dvr_message_count'),
		$badge,
	));
}
if (!$any) {
	$page->disprow(array('<em>No reports in this window.</em>', '', '', '', '', '', ''));
}
$page->endtable();

echo '<p class="small text-muted">A report that failed to parse keeps its original for diagnosis; a '
	. 'parsed one keeps only these rows — the source lines above carry everything it said. Rows never '
	. 'expire: they are the domain\'s long-term record of who has sent as it.</p>';

$page->admin_footer();
?>
