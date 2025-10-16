<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/event_sessions_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$settings = Globalvars::get_instance();

	$file = new File($_GET['fil_file_id'], TRUE);
	$user = new User($file->get('fil_usr_user_id'), TRUE);

	if($_POST['action'] == 'remove'){
		$file->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$file->permanent_delete();

		//$returnurl = $session->get_return();
		header("Location: /admin/admin_files");
		exit();
	}
	else if($_POST['action'] == 'fileremove'){
		$event_session = new EventSession($_POST['evs_event_session_id'], TRUE);
		$event_session->remove_file($_POST['fil_file_id']);

		//$returnurl = $session->get_return();
		header("Location: /admin/admin_file?fil_file_id=".$file->key);
		exit();
	}
	else if($_POST['action'] == 'fileadd'){
		$event_session = new EventSession($_POST['evs_event_session_id'], TRUE);
		$event_session->add_file($_POST['fil_file_id']);

		//$returnurl = $session->get_return();
		header("Location: /admin/admin_file?fil_file_id=".$file->key);
		exit();
	}
	else if($_REQUEST['action'] == 'delete'){
		$file->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$file->soft_delete();

		header("Location: /admin/admin_files");
		exit();
	}
	else if($_REQUEST['action'] == 'undelete'){
		$file->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$file->undelete();

		header("Location: /admin/admin_files");
		exit();
	}

	// Build dropdown actions
	$options['altlinks'] = array();
	$options['altlinks'] += array('Edit File' => '/admin/admin_file_edit?fil_file_id='.$file->key);
	if($file->get('fil_delete_time')){
		$options['altlinks'] += array('Undelete' => '/admin/admin_file?action=undelete&fil_file_id='.$file->key);
	}
	else{
		$options['altlinks'] += array('Soft Delete' => '/admin/admin_file?action=delete&fil_file_id='.$file->key);
	}
	if($session->get_user_id() == 1){
		$options['altlinks'] += array('Permanently Delete' => '/admin/admin_file_delete?fil_file_id='.$file->key);
	}

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

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'files-parent',
		'page_title' => 'File',
		'readable_title' => $file->get('fil_title'),
		'breadcrumbs' => array(
			'Files'=>'/admin/admin_files',
			$file->get('fil_title') => '',
		),
		'session' => $session,
		'no_page_card' => true,
		'header_action' => $dropdown_button,
	)
	);

	// Get permission text
	$permission_text = '';
	$group_or_event = false;
	if($file->get('fil_grp_group_id')){
		$group = new Group($file->get('fil_grp_group_id'), TRUE);
		$permission_text .= 'Only logged in users in the "'.$group->get('grp_name').'" group ';
		$group_or_event = true;
	}
	if($file->get('fil_evt_event_id')){
		$event = new Event($file->get('fil_evt_event_id'), TRUE);
		$permission_text .= 'Only logged in users registered for the "'.$event->get('evt_name').'" event ';
		$group_or_event = true;
	}
	if($group_or_event){
		if($file->get('fil_min_permission') > 0){
			$permission_text .= 'with minimum permission ('.$file->get('fil_min_permission').') ';
		}
	}
	else{
		if($file->get('fil_min_permission') === NULL){
			$permission_text .= 'Anyone ';
		}
		else if($file->get('fil_min_permission') === 0){
			$permission_text .= 'Anyone logged in';
		}
		else{
			$permission_text .= 'Minimum permission ('.$file->get('fil_min_permission').') ';
		}
	}
	$permission_text .= 'can access this file.';
	?>

	<!-- Two Column Layout -->
	<div class="row g-3">
		<!-- Left Column -->
		<div class="col-xxl-6">
			<!-- File Preview Card (for images) -->
			<?php if($file->is_image()): ?>
			<div class="card mb-3">
				<div class="card-header bg-body-tertiary">
					<h5 class="mb-0">File Preview</h5>
				</div>
				<div class="card-body text-center">
					<img src="/uploads/medium/<?php echo htmlspecialchars($file->get('fil_name')); ?>" alt="File Preview" class="img-fluid rounded" style="max-height: 400px;">
				</div>
			</div>
			<?php endif; ?>

			<!-- File Information Card -->
			<div class="card mb-3">
				<div class="card-header bg-body-tertiary">
					<h5 class="mb-0">File Information</h5>
				</div>
				<div class="card-body">
					<table class="table table-borderless mb-0" style="font-size: 0.875rem;">
						<tbody>
							<tr>
								<td class="p-1 text-800 fw-semi-bold" style="width: 180px;">Title</td>
								<td class="p-1 text-600"><?php echo htmlspecialchars($file->get('fil_title')); ?></td>
							</tr>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Filename</td>
								<td class="p-1 text-600"><code><?php echo htmlspecialchars($file->get('fil_name')); ?></code></td>
							</tr>
							<?php if($file->get('fil_mime_type')): ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">File Type</td>
								<td class="p-1"><span class="badge badge-subtle-info"><?php echo htmlspecialchars($file->get('fil_mime_type')); ?></span></td>
							</tr>
							<?php endif; ?>
							<?php if($file->get('fil_size')): ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">File Size</td>
								<td class="p-1 text-600"><?php echo LibraryFunctions::format_bytes($file->get('fil_size')); ?></td>
							</tr>
							<?php endif; ?>
							<?php if($file->get('fil_description')): ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Description</td>
								<td class="p-1 text-600"><?php echo htmlspecialchars($file->get('fil_description')); ?></td>
							</tr>
							<?php endif; ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Uploader</td>
								<td class="p-1 text-600"><a href="/admin/admin_user?usr_user_id=<?php echo $user->key; ?>"><?php echo htmlspecialchars($user->display_name()); ?> (ID: <?php echo $user->key; ?>)</a></td>
							</tr>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Uploaded</td>
								<td class="p-1 text-600"><?php echo LibraryFunctions::convert_time($file->get('fil_create_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A T'); ?></td>
							</tr>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Status</td>
								<td class="p-1">
									<?php if($file->get('fil_delete_time')): ?>
										<span class="badge badge-danger">Deleted</span>
									<?php else: ?>
										<span class="badge badge-subtle-success">Active</span>
									<?php endif; ?>
								</td>
							</tr>
							<?php if($file->get('fil_gal_gallery_id')): ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Gallery</td>
								<td class="p-1 text-600"><a href="/admin/admin_gallery?gal_gallery_id=<?php echo $file->get('fil_gal_gallery_id'); ?>">Gallery</a></td>
							</tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>

		<!-- Right Column -->
		<div class="col-xxl-6">
			<!-- Access Permissions Card -->
			<div class="card mb-3">
				<div class="card-header bg-body-tertiary">
					<h5 class="mb-0">Access Permissions</h5>
				</div>
				<div class="card-body">
					<table class="table table-borderless mb-0" style="font-size: 0.875rem;">
						<tbody>
							<tr>
								<td class="p-1 text-800 fw-semi-bold" style="width: 180px;">Access Level</td>
								<td class="p-1">
									<?php if($group_or_event): ?>
										<span class="badge badge-subtle-warning">Restricted</span>
									<?php else: ?>
										<span class="badge badge-subtle-success">Public</span>
									<?php endif; ?>
								</td>
							</tr>
							<?php if($file->get('fil_grp_group_id')): ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Restricted to Group</td>
								<td class="p-1 text-600">
									<a href="/admin/admin_group?grp_group_id=<?php echo $file->get('fil_grp_group_id'); ?>" class="badge badge-subtle-primary"><?php echo htmlspecialchars($group->get('grp_name')); ?></a>
								</td>
							</tr>
							<?php endif; ?>
							<?php if($file->get('fil_evt_event_id')): ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Restricted to Event</td>
								<td class="p-1 text-600">
									<a href="/admin/admin_event?evt_event_id=<?php echo $file->get('fil_evt_event_id'); ?>" class="badge badge-subtle-info"><?php echo htmlspecialchars($event->get('evt_name')); ?></a>
								</td>
							</tr>
							<?php endif; ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Min. Permission Level</td>
								<td class="p-1 text-600"><?php echo ($file->get('fil_min_permission') !== NULL) ? $file->get('fil_min_permission') : 'None'; ?></td>
							</tr>
						</tbody>
					</table>
					<div class="alert alert-info mt-3 mb-0" style="font-size: 0.875rem;">
						<i class="bi bi-info-circle me-2"></i>
						<?php echo $permission_text; ?>
					</div>
				</div>
			</div>

			<!-- Image Size Links Card (for images only) -->
			<?php if($file->is_image()): ?>
			<div class="card mb-3">
				<div class="card-header bg-body-tertiary">
					<h5 class="mb-0">Image Size Variations</h5>
				</div>
				<div class="card-body">
					<table class="table table-borderless mb-0" style="font-size: 0.875rem;">
						<tbody>
							<tr>
								<td class="p-1 text-800 fw-semi-bold" style="width: 140px;">Full Size</td>
								<td class="p-1">
									<a href="<?php echo htmlspecialchars($file->get_url('standard')); ?>" target="_blank"><?php echo htmlspecialchars($file->get_url('standard','short')); ?></a>
								</td>
							</tr>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Large</td>
								<td class="p-1">
									<a href="<?php echo htmlspecialchars($file->get_url('large')); ?>" target="_blank"><?php echo htmlspecialchars($file->get_url('large','short')); ?></a>
								</td>
							</tr>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Medium</td>
								<td class="p-1">
									<a href="<?php echo htmlspecialchars($file->get_url('medium')); ?>" target="_blank"><?php echo htmlspecialchars($file->get_url('medium','short')); ?></a>
								</td>
							</tr>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Small</td>
								<td class="p-1">
									<a href="<?php echo htmlspecialchars($file->get_url('small')); ?>" target="_blank"><?php echo htmlspecialchars($file->get_url('small','short')); ?></a>
								</td>
							</tr>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Large Thumb</td>
								<td class="p-1">
									<a href="<?php echo htmlspecialchars($file->get_url('lthumbnail')); ?>" target="_blank"><?php echo htmlspecialchars($file->get_url('lthumbnail','short')); ?></a>
								</td>
							</tr>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Thumbnail</td>
								<td class="p-1">
									<a href="<?php echo htmlspecialchars($file->get_url('thumbnail')); ?>" target="_blank"><?php echo htmlspecialchars($file->get_url('thumbnail','short')); ?></a>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
			<?php else: ?>
			<!-- Direct Link Card (for non-images) -->
			<div class="card mb-3">
				<div class="card-header bg-body-tertiary">
					<h5 class="mb-0">Direct Link</h5>
				</div>
				<div class="card-body">
					<a href="/uploads/<?php echo htmlspecialchars($file->get('fil_name')); ?>" target="_blank" class="btn btn-falcon-default">
						<i class="bi bi-download me-1"></i> Download File
					</a>
					<div class="mt-3">
						<small class="text-600">Direct URL:</small><br>
						<code style="font-size: 0.75rem;">/uploads/<?php echo htmlspecialchars($file->get('fil_name')); ?></code>
					</div>
				</div>
			</div>
			<?php endif; ?>
		</div>
	</div>

	<?php
	$page->admin_footer();
?>

