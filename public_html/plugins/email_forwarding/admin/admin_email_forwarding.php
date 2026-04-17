<?php
/**
 * Email Forwarding - Aliases List
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/email_forwarding/data/email_forwarding_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/email_forwarding/data/email_forwarding_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/email_forwarding/logic/admin_email_forwarding_logic.php'));

$page_vars = process_logic(admin_email_forwarding_logic($_GET, $_POST));
extract($page_vars);

$numperpage = 30;
$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
$sort = LibraryFunctions::fetch_variable('sort', 'efa_email_forwarding_alias_id', 0, '');
$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
$filter_domain = LibraryFunctions::fetch_variable('domain_id', '', 0, '');

$page = new AdminPage();
$page->admin_header(
	array(
		'menu-id' => 'incoming',
		'breadcrumbs' => array(
			'Email Forwarding' => '',
		),
		'session' => $session,
	)
);

// Tab navigation
echo '<ul class="nav nav-tabs mb-3">';
echo '<li class="nav-item"><a class="nav-link active" href="/plugins/email_forwarding/admin/admin_email_forwarding">Forwarding Aliases</a></li>';
echo '<li class="nav-item"><a class="nav-link" href="/plugins/email_forwarding/admin/admin_email_forwarding_domains">Domains</a></li>';
echo '<li class="nav-item"><a class="nav-link" href="/plugins/email_forwarding/admin/admin_email_forwarding_logs">Logs</a></li>';
echo '</ul>';

// Display session messages
$display_messages = $session->get_messages('/plugins\/email_forwarding\/admin\//');
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

$aliases = new MultiEmailForwardingAlias(
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
	$domain_cache[$d->key] = $d->get('efd_domain');
}

$headers = array('Alias', 'Destinations', 'Description', 'Enabled', 'Forwards', 'Last Forward', 'Actions');
$altlinks = array('New Alias' => '/plugins/email_forwarding/admin/admin_email_forwarding_alias');
$pager = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage));
$table_options = array(
	'altlinks' => $altlinks,
	'title' => 'Forwarding Aliases',
);
$page->tableheader($headers, $table_options, $pager);

foreach ($aliases as $alias) {
	$domain_name = $domain_cache[$alias->get('efa_efd_email_forwarding_domain_id')] ?? '?';
	$full_address = $alias->get('efa_alias') . '@' . $domain_name;

	$rowvalues = array();
	array_push($rowvalues, '<a href="/plugins/email_forwarding/admin/admin_email_forwarding_alias?efa_email_forwarding_alias_id=' . $alias->key . '">' . htmlspecialchars($full_address) . '</a>');
	array_push($rowvalues, htmlspecialchars($alias->get('efa_destinations')));
	array_push($rowvalues, htmlspecialchars($alias->get('efa_description')));
	array_push($rowvalues, $alias->get('efa_is_enabled') ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>');
	array_push($rowvalues, intval($alias->get('efa_forward_count')));

	$last_forward = $alias->get('efa_last_forward_time');
	array_push($rowvalues, $last_forward ? LibraryFunctions::convert_time($last_forward, 'UTC', $session->get_timezone(), 'M j, Y g:i A') : '-');

	$actions = '<form method="post" style="display:inline">'
		. '<input type="hidden" name="action" value="toggle_enabled">'
		. '<input type="hidden" name="efa_email_forwarding_alias_id" value="' . $alias->key . '">'
		. '<button type="submit" class="btn btn-sm btn-outline-secondary">' . ($alias->get('efa_is_enabled') ? 'Disable' : 'Enable') . '</button>'
		. '</form> '
		. '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete this alias?\')">'
		. '<input type="hidden" name="action" value="delete">'
		. '<input type="hidden" name="efa_email_forwarding_alias_id" value="' . $alias->key . '">'
		. '<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>'
		. '</form>';
	array_push($rowvalues, $actions);

	$page->disprow($rowvalues);
}

$page->endtable($pager);
$page->admin_footer();
?>
