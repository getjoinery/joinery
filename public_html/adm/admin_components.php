<?php
/**
 * Admin Components List
 *
 * List all component instances in the system.
 *
 * @see /specs/page_component_system.md
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/components_class.php'));
require_once(PathHelper::getIncludePath('data/page_contents_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(5); // Admin level
$session->set_return();

// Handle delete action
if (isset($_POST['action']) && $_POST['action'] == 'delete') {
	$content = new PageContent($_POST['pac_page_content_id'], TRUE);
	$content->soft_delete();
	header("Location: /admin/admin_components");
	exit();
}

// Handle toggle published action
if (isset($_POST['action']) && $_POST['action'] == 'toggle_published') {
	$content = new PageContent($_POST['pac_page_content_id'], TRUE);
	$content->set('pac_is_published', !$content->get('pac_is_published'));
	$content->save();
	header("Location: /admin/admin_components");
	exit();
}

// Get filter parameters
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$searchterm = isset($_GET['searchterm']) ? trim($_GET['searchterm']) : '';

// Pagination
$numperpage = 25;
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;

// Build search options - only show component instances (not legacy content)
$search_options = array(
	'deleted' => false,
	'components_only' => true
);
if ($filter === 'published') {
	$search_options['published'] = true;
}

// Load components
$contents = new MultiPageContent(
	$search_options,
	array('pac_order' => 'ASC', 'pac_title' => 'ASC'),
	$numperpage,
	$offset
);
$numrecords = $contents->count_all();
$contents->load();

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'pages',
	'page_title' => 'Components',
	'readable_title' => 'Components',
	'breadcrumbs' => array(
		'Pages' => '/admin/admin_pages',
		'Components' => '',
	),
	'session' => $session,
));

// Table
$headers = array("Slug", "Title", "Type", "Order", "Published", "Actions");
$altlinks = array('Add Component' => '/admin/admin_component_edit');
$pager = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage, 'offset' => $offset));
$table_options = array(
	'filteroptions' => array("All Components" => "all", "Published" => "published"),
	'altlinks' => $altlinks,
	'title' => 'Components',
	'search_on' => TRUE
);
$page->tableheader($headers, $table_options, $pager);

foreach ($contents as $content) {
	$rowvalues = array();

	// Slug
	$slug = $content->get('pac_location_name');
	array_push($rowvalues, '<code>' . htmlspecialchars($slug) . '</code>');

	// Title
	$title = $content->get('pac_title') ?: '(no title)';
	array_push($rowvalues, htmlspecialchars($title));

	// Component Type
	$component_type = $content->get_component_type();
	if ($component_type) {
		$type_name = $component_type->get('com_title');
		$type_key = $component_type->get('com_type_key');
		array_push($rowvalues, htmlspecialchars($type_name) . ' <small class="text-muted">(' . htmlspecialchars($type_key) . ')</small>');
	} else {
		array_push($rowvalues, '<span class="text-muted">Unknown</span>');
	}

	// Order
	$order = $content->get('pac_order') ?: 0;
	array_push($rowvalues, $order);

	// Published status with toggle button
	$is_published = $content->get('pac_is_published');
	$toggle_form = AdminPage::action_button(
		$is_published ? 'Published' : 'Draft',
		'',
		[
			'hidden' => ['action' => 'toggle_published', 'pac_page_content_id' => $content->key],
			'class'  => $is_published ? 'btn btn-sm btn-success' : 'btn btn-sm btn-secondary',
		]
	);
	array_push($rowvalues, $toggle_form);

	// Actions
	$edit_link = '<a href="/admin/admin_component_edit?pac_page_content_id=' . $content->key . '" class="btn btn-sm btn-outline-primary me-1">Edit</a>';

	$delete_form = AdminPage::action_button('Delete', '', [
		'hidden'  => ['action' => 'delete', 'pac_page_content_id' => $content->key],
		'confirm' => 'Are you sure you want to delete this component?',
		'class'   => 'btn btn-sm btn-outline-danger',
	]);

	array_push($rowvalues, $edit_link . $delete_form);

	$page->disprow($rowvalues);
}

$page->endtable($pager);

$page->admin_footer();
?>
