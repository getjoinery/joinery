<?php
/**
 * Inbound Email - Aliases List
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/logic/admin_inbound_email_logic.php'));

$page_vars = process_logic(admin_inbound_email_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$numperpage = 30;
$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
$sort = LibraryFunctions::fetch_variable('sort', 'iea_inbound_email_alias_id', 0, '');
$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
$filter_domain = LibraryFunctions::fetch_variable('domain_id', '', 0, '');

$page = new AdminPage();
$page->admin_header(
	array(
		'menu-id' => 'incoming',
		'breadcrumbs' => array(
			'Inbound Email' => '',
		),
		'session' => $session,
	)
);

echo AdminPage::tab_menu(inbound_email_admin_tabs(), 'Accounts');

// Display session messages
$display_messages = $session->get_messages('/plugins\/inbound_email\/admin\//');
if (!empty($display_messages)) {
	foreach ($display_messages as $msg) {
		echo '<div class="alert alert-success">' . htmlspecialchars($msg->message) . '</div>';
	}
	$session->clear_clearable_messages();
}

// Build search criteria
$search_criteria = array('deleted' => false);
if ($filter_domain) {
	$search_criteria['domain_id'] = $filter_domain;
}

$aliases = new MultiInboundEmailAlias(
	$search_criteria,
	array($sort => $sdirection),
	$numperpage,
	$offset
);
$numrecords = $aliases->count_all();
$aliases->load();

// Preload domains for display
$domain_cache = array();
foreach ($domains as $d) {
	$domain_cache[$d->key] = $d->get('ied_domain');
}

$headers = array('Alias', 'Mode', 'Destinations', 'Description', 'Enabled', 'Forwards', 'Last Forward', 'Actions');
$altlinks = array('New Alias' => '/plugins/inbound_email/admin/admin_inbound_email_alias');
$pager = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage));
$table_options = array(
	'altlinks' => $altlinks,
	'title' => 'Forwarding Aliases',
);
$page->tableheader($headers, $table_options, $pager);

foreach ($aliases as $alias) {
	$domain_name = $domain_cache[$alias->get('iea_ied_inbound_email_domain_id')] ?? '?';
	$full_address = $alias->get('iea_alias') . '@' . $domain_name;

	$mode = $alias->get('iea_delivery_mode') ?: 'forward';
	$mode_label = $mode;
	$mode_class = 'bg-secondary';
	if ($mode === 'forward') { $mode_label = 'Forward'; $mode_class = 'bg-primary'; }
	elseif ($mode === 'store') { $mode_label = 'Store'; $mode_class = 'bg-info text-dark'; }
	elseif ($mode === 'forward_and_store') { $mode_label = 'Forward + Store'; $mode_class = 'bg-success'; }

	$rowvalues = array();
	array_push($rowvalues, '<a href="/plugins/inbound_email/admin/admin_inbound_email_alias?iea_inbound_email_alias_id=' . $alias->key . '">' . htmlspecialchars($full_address) . '</a>');
	array_push($rowvalues, '<span class="badge ' . $mode_class . '">' . htmlspecialchars($mode_label) . '</span>');
	array_push($rowvalues, htmlspecialchars($alias->get('iea_destinations') ?: '-'));
	array_push($rowvalues, htmlspecialchars($alias->get('iea_description')));
	array_push($rowvalues, $alias->get('iea_is_enabled') ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>');
	array_push($rowvalues, intval($alias->get('iea_forward_count')));

	$last_forward = $alias->get('iea_last_forward_time');
	array_push($rowvalues, $last_forward ? LibraryFunctions::convert_time($last_forward, 'UTC', $session->get_timezone(), 'M j, Y g:i A') : '-');

	$actions = '<form method="post" class="iem-inline-form">'
		. '<input type="hidden" name="action" value="toggle_enabled">'
		. '<input type="hidden" name="iea_inbound_email_alias_id" value="' . $alias->key . '">'
		. '<button type="submit" class="btn btn-sm btn-outline-secondary">' . ($alias->get('iea_is_enabled') ? 'Disable' : 'Enable') . '</button>'
		. '</form> '
		. '<form method="post" class="iem-inline-form" onsubmit="return confirm(\'Delete this alias?\')">'
		. '<input type="hidden" name="action" value="delete">'
		. '<input type="hidden" name="iea_inbound_email_alias_id" value="' . $alias->key . '">'
		. '<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>'
		. '</form>';
	array_push($rowvalues, $actions);

	$page->disprow($rowvalues);
}

$page->endtable($pager);
$page->admin_footer();
?>
