<?php

	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('/data/users_class.php'));
	require_once(PathHelper::getIncludePath('/data/pages_class.php'));
	require_once(PathHelper::getIncludePath('/data/page_contents_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$page = new Page($_GET['pag_page_id'], TRUE);

	$search_criteria = array();

	$search_criteria['page_id'] = $page->key;

	$page_contents = new MultiPageContent(
		$search_criteria,
		//array($sort=>$sdirection),
		//$numperpage,
		//$offset
		);
	$numrecords = $page_contents->count_all();
	$page_contents->load();

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

	// Build dropdown actions
	$options['altlinks'] = array('Edit Page' => '/admin/admin_page_edit?pag_page_id='.$page->key);
	$options['altlinks'] += array('New Content'=>'/admin/admin_page_content_edit?pag_page_id='.$page->key);
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
	// Page Content Table
	$headers = array("Content",  "Published", "Creator", "Status");
	$altlinks = array('New Content'=>'/admin/admin_page_content_edit?pag_page_id='.$page->key);
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		'altlinks' => $altlinks,
		'title' => 'Page Content',
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
		array_push($rowvalues, LibraryFunctions::convert_time($page_content->get('pac_published_time'), 'UTC', $session->get_timezone()));
		array_push($rowvalues, '<a href="/admin/admin_user?usr_user_id='.$user->key.'">'.$user->display_name() .'</a> ');

		if($page_content->get('pac_delete_time')) {
			$status = 'Deleted';
		}
		else {
			if($page_content->get('pac_published_time')) {
				$status = 'Published';
			}
			else{
				$status = 'Unpublished';
			}
		}
		array_push($rowvalues, $status);

		$paget->disprow($rowvalues);
	}
	$paget->endtable($pager);
	?>

	<!-- Page Preview Card (Full Width) -->
	<div class="card mt-3">
		<div class="card-header bg-body-tertiary">
			<h5 class="mb-0">Page Preview</h5>
		</div>
		<div class="card-body p-0">
			<iframe src="<?php echo htmlspecialchars($page->get_url()); ?>" width="100%" height="500" style="border:none;"></iframe>
		</div>
	</div>

	<?php

	$paget->admin_footer();
?>

