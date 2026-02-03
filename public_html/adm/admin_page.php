<?php

	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('/data/users_class.php'));
	require_once(PathHelper::getIncludePath('/data/pages_class.php'));
	require_once(PathHelper::getIncludePath('/data/page_contents_class.php'));
	require_once(PathHelper::getIncludePath('/data/components_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	// Require page ID - redirect to list if not provided
	if (empty($_GET['pag_page_id'])) {
		header("Location: /admin/admin_pages");
		exit();
	}

	$page = new Page($_GET['pag_page_id'], TRUE);

	$search_criteria = array();

	$search_criteria['page_id'] = $page->key;
	$search_criteria['legacy_only'] = true;  // Exclude components from legacy content list

	$page_contents = new MultiPageContent(
		$search_criteria,
		//array($sort=>$sdirection),
		//$numperpage,
		//$offset
		);
	$numrecords = $page_contents->count_all();
	$page_contents->load();

	// Load components for this page (include deleted for superadmins)
	$component_options = array('page_id' => $page->key, 'components_only' => true);
	if ($_SESSION['permission'] < 10) {
		$component_options['deleted'] = false;
	}
	$page_components = new MultiPageContent(
		$component_options,
		array('pac_order' => 'ASC', 'pac_title' => 'ASC')
	);
	$num_components = $page_components->count_all();
	$page_components->load();

	if($_REQUEST['action'] == 'delete'){
		$page->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$page->soft_delete();

		header("Location: /admin/admin_pages");
		exit();
	}
	else if($_REQUEST['action'] == 'undelete'){
		$page->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$page->undelete();

		header("Location: /admin/admin_pages");
		exit();
	}
	else if($_REQUEST['action'] == 'delete_component'){
		$component = new PageContent($_POST['pac_page_content_id'], TRUE);
		$component->soft_delete();

		header("Location: /admin/admin_page?pag_page_id=" . $page->key);
		exit();
	}
	else if($_REQUEST['action'] == 'undelete_component'){
		$component = new PageContent($_POST['pac_page_content_id'], TRUE);
		$component->undelete();

		header("Location: /admin/admin_page?pag_page_id=" . $page->key);
		exit();
	}

	// Check if page uses legacy placeholder system
	$page_body_check = $page->get('pag_body') ?: '';
	$page_has_placeholders = preg_match('/\*!\*\*[^*]+\*\*!\*/', $page_body_check);

	// Build dropdown actions
	$options['altlinks'] = array('Edit Page' => '/admin/admin_page_edit?pag_page_id='.$page->key);
	// Only show "New Content" for legacy pages with placeholders
	if ($page_has_placeholders && $num_components == 0) {
		$options['altlinks'] += array('New Content'=>'/admin/admin_page_content_edit?pag_page_id='.$page->key);
	}
	if(!$page->get('pag_delete_time') && $_SESSION['permission'] >= 8) {
		$options['altlinks']['Soft Delete'] = '/admin/admin_page?action=delete&pag_page_id='.$page->key;
	}
	else if($_SESSION['permission'] >= 8){
		$options['altlinks']['Undelete'] = '/admin/admin_page?action=undelete&pag_page_id='.$page->key;
	}
	$options['altlinks'] += array('Permanent Delete' => '/admin/admin_page_permanent_delete?pag_page_id='.$page->key);

	// Build dropdown button from altlinks
	$dropdown_button = '';
	if (!empty($options['altlinks'])) {
		$dropdown_button = '<div class="dropdown">';
		$dropdown_button .= '<button class="btn btn-falcon-default btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Actions</button>';
		$dropdown_button .= '<div class="dropdown-menu dropdown-menu-end py-0">';
		foreach ($options['altlinks'] as $label => $url) {
			$is_danger = strpos($label, 'Delete') !== false;
			$dropdown_button .= '<a href="' . htmlspecialchars($url) . '" class="dropdown-item' . ($is_danger ? ' text-danger' : '') . '">' . htmlspecialchars($label) . '</a>';
		}
		$dropdown_button .= '</div>';
		$dropdown_button .= '</div>';
	}

	$paget = new AdminPage();
	$paget->admin_header(
	array(
		'menu-id'=> 'pages',
		'page_title' => 'Page',
		'readable_title' => $page->get('pag_title'),
		'breadcrumbs' => array(
			'Pages'=>'/admin/admin_pages',
			$page->get('pag_title')=>'',
		),
		'session' => $session,
		'no_page_card' => true,
		'header_action' => $dropdown_button,
	)
	);
	?>

	<!-- Page Information Card -->
	<div class="card mb-3">
		<div class="card-header bg-body-tertiary">
			<h5 class="mb-0">Page Information</h5>
		</div>
		<div class="card-body">
			<table class="table table-borderless mb-0" style="font-size: 0.875rem;">
				<tbody>
					<tr>
						<td class="p-1 text-800 fw-semi-bold" style="width: 180px;">Title</td>
						<td class="p-1 text-600"><?php echo htmlspecialchars($page->get('pag_title')); ?></td>
					</tr>
					<tr>
						<td class="p-1 text-800 fw-semi-bold">Link</td>
						<td class="p-1 text-600"><a href="<?php echo htmlspecialchars($page->get_url()); ?>" target="_blank"><?php echo htmlspecialchars(LibraryFunctions::get_absolute_url($page->get_url())); ?></a></td>
					</tr>
					<tr>
						<td class="p-1 text-800 fw-semi-bold">Created</td>
						<td class="p-1 text-600"><?php echo LibraryFunctions::convert_time($page->get('pag_create_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A T'); ?></td>
					</tr>
					<tr>
						<td class="p-1 text-800 fw-semi-bold">Status</td>
						<td class="p-1">
							<?php if($page->get('pag_delete_time')): ?>
								<span class="badge badge-danger">Deleted at <?php echo LibraryFunctions::convert_time($page->get('pag_delete_time'), 'UTC', $session->get_timezone()); ?></span>
							<?php elseif($page->get('pag_published_time')): ?>
								<span class="badge badge-subtle-success">Published</span>
							<?php else: ?>
								<span class="badge badge-subtle-secondary">Unpublished</span>
							<?php endif; ?>
						</td>
					</tr>
					<?php if($page->get('pag_published_time')): ?>
					<tr>
						<td class="p-1 text-800 fw-semi-bold">Published</td>
						<td class="p-1 text-600"><?php echo LibraryFunctions::convert_time($page->get('pag_published_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A T'); ?></td>
					</tr>
					<?php endif; ?>
					<?php if($page->get('pag_last_edit_time')): ?>
					<tr>
						<td class="p-1 text-800 fw-semi-bold">Last Edited</td>
						<td class="p-1 text-600"><?php echo LibraryFunctions::convert_time($page->get('pag_last_edit_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A T'); ?></td>
					</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>

	<?php
	// Page Content Table - Legacy placeholder system
	// Only show if page body contains placeholders (*!**slug**!*) and page isn't using components
	$page_body = $page->get('pag_body') ?: '';
	$has_placeholders = preg_match('/\*!\*\*[^*]+\*\*!\*/', $page_body);
	$uses_components = $num_components > 0;

	// Show legacy content section only if placeholders exist and not using components
	if ($has_placeholders && !$uses_components):

	$headers = array("Content", "Creator", "Status");
	$altlinks = array('New Content'=>'/admin/admin_page_content_edit?pag_page_id='.$page->key);
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		'altlinks' => $altlinks,
		'title' => 'Page Content (Legacy)',
		'card' => true
	);
	$paget->tableheader($headers, $table_options, NULL);

	foreach ($page_contents as $page_content){

		$user = new User($page_content->get('pac_usr_user_id'), TRUE);

		$title = $page_content->get('pac_location_name');
		if(!$title){
			$title = 'Untitled';
		}

		$rowvalues = array();
		array_push($rowvalues, "<a href='/admin/admin_page_content?pac_page_content_id=$page_content->key'>".$title."</a>");
		array_push($rowvalues, '<a href="/admin/admin_user?usr_user_id='.$user->key.'">'.$user->display_name() .'</a> ');

		$status = $page_content->get('pac_delete_time') ? 'Deleted' : 'Active';
		array_push($rowvalues, $status);

		$paget->disprow($rowvalues);
	}
	$paget->endtable($pager);

	endif; // has_placeholders && !uses_components

	// Components Table
	// Check if page has legacy body content (prevents adding components)
	$has_legacy_body_content = !empty(trim($page->get('pag_body') ?? ''));

	$comp_headers = array("Component", "Type", "Actions");
	$comp_altlinks = array();
	if (!$has_legacy_body_content) {
		$comp_altlinks['Add Component'] = '/admin/admin_component_edit?pag_page_id='.$page->key;
	}
	$comp_table_options = array(
		'altlinks' => $comp_altlinks,
		'title' => 'Components (' . $num_components . ')',
		'card' => true
	);
	$paget->tableheader($comp_headers, $comp_table_options, NULL);

	// Show warning if page has legacy body content (always show, even if components exist)
	if ($has_legacy_body_content) {
		echo '<tr><td colspan="5" class="py-3">';
		echo '<div class="alert alert-warning mb-0">';
		echo '<i class="fas fa-exclamation-triangle me-2"></i>';
		echo 'This page has legacy body content. To use components, <a href="/admin/admin_page_edit?pag_page_id=' . $page->key . '">edit this page</a> and remove the content there.';
		echo '</div>';
		echo '</td></tr>';
	}

	// Show components if any exist
	foreach ($page_components as $component) {
		$rowvalues = array();
		$is_deleted = (bool)$component->get('pac_delete_time');

		// Component title
		$title = $component->get('pac_title') ?: '(untitled)';
		$title_display = '<a href="/admin/admin_component_edit?pac_page_content_id=' . $component->key . '">' . htmlspecialchars($title) . '</a>';
		if ($is_deleted) {
			$title_display .= ' <span class="badge bg-danger">Deleted</span>';
		}
		array_push($rowvalues, $title_display);

		// Component type
		$comp_type = $component->get_component_type();
		if ($comp_type) {
			array_push($rowvalues, htmlspecialchars($comp_type->get('com_title')));
		} else {
			array_push($rowvalues, '<span class="text-muted">Unknown</span>');
		}

		// Actions
		$actions = '<a href="/admin/admin_component_edit?pac_page_content_id=' . $component->key . '" class="btn btn-sm btn-outline-primary me-1">Edit</a>';
		if ($is_deleted) {
			$actions .= '<form method="POST" style="display:inline">';
			$actions .= '<input type="hidden" name="action" value="undelete_component">';
			$actions .= '<input type="hidden" name="pac_page_content_id" value="' . $component->key . '">';
			$actions .= '<button type="submit" class="btn btn-sm btn-outline-success">Undelete</button>';
			$actions .= '</form>';
		} else {
			$actions .= '<form method="POST" style="display:inline" onsubmit="return confirm(\'Are you sure you want to delete this component?\');">';
			$actions .= '<input type="hidden" name="action" value="delete_component">';
			$actions .= '<input type="hidden" name="pac_page_content_id" value="' . $component->key . '">';
			$actions .= '<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>';
			$actions .= '</form>';
		}
		array_push($rowvalues, $actions);

		$paget->disprow($rowvalues);
	}

	// Show "no components" message only if no legacy content and no components
	if ($num_components == 0 && !$has_legacy_body_content) {
		echo '<tr><td colspan="5" class="text-center text-muted py-3">No components on this page. <a href="/admin/admin_component_edit?pag_page_id=' . $page->key . '">Add one</a></td></tr>';
	}

	$paget->endtable(NULL);
	?>

	<!-- Page Preview Card (Full Width) -->
	<div class="card mt-3">
		<div class="card-header bg-body-tertiary">
			<h5 class="mb-0">Page Preview</h5>
		</div>
		<div class="card-body p-0">
			<iframe src="<?php echo htmlspecialchars($page->get_url()); ?>" width="100%" height="650" style="border:none;"></iframe>
		</div>
	</div>

	<?php

	$paget->admin_footer();
?>

