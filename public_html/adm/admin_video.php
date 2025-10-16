<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/videos_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$video = new Video($_GET['vid_video_id'], TRUE);
	$user = new User($video->get('vid_usr_user_id'), TRUE);

	if($_REQUEST['action'] == 'remove'){
		$video->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$video->permanent_delete();

		//$returnurl = $session->get_return();
		header("Location: /admin/admin_videos");
		exit();
	}

	if($_REQUEST['action'] == 'delete'){
		$video->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$video->soft_delete();

		header("Location: /admin/admin_videos");
		exit();
	}
	else if($_REQUEST['action'] == 'undelete'){
		$video->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$video->undelete();

		header("Location: /admin/admin_videos");
		exit();
	}

	// Build dropdown actions
	$options['altlinks'] = array('Edit Video'=>'/admin/admin_video_edit?vid_video_id='.$video->key);
	if($video->get('vid_delete_time')){
		$options['altlinks']['Undelete'] = '/admin/admin_video?action=undelete&vid_video_id='.$video->key;
	}
	else{
		$options['altlinks']['Soft Delete'] = '/admin/admin_video?action=delete&vid_video_id='.$video->key;
	}
	if($session->get_user_id() == 1){
		$options['altlinks'] += array('Permanently Delete' => '/admin/admin_video?action=remove&vid_video_id='.$video->key);
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
		'menu-id'=> 'videos',
		'page_title' => 'Video',
		'readable_title' => $video->get('vid_title'),
		'breadcrumbs' => array(
			'Videos'=>'/admin/admin_videos',
			$video->get('vid_title') => '',
		),
		'session' => $session,
		'no_page_card' => true,
		'header_action' => $dropdown_button,
	)
	);

	// Get permission text
	$permission_text = '';
	$group_or_event = false;
	if($video->get('vid_grp_group_id')){
		$group = new Group($video->get('vid_grp_group_id'), TRUE);
		$permission_text .= 'Only logged in users in the "'.$group->get('grp_name').'" group ';
		$group_or_event = true;
	}
	if($video->get('vid_evt_event_id')){
		$event = new Event($video->get('vid_evt_event_id'), TRUE);
		$permission_text .= 'Only logged in users registered for the "'.$event->get('evt_name').'" event ';
		$group_or_event = true;
	}
	if($group_or_event){
		if($video->get('vid_min_permission') > 0){
			$permission_text .= 'with minimum permission ('.$video->get('vid_min_permission').') ';
		}
	}
	else{
		if($video->get('vid_min_permission') === NULL){
			$permission_text .= 'Anyone ';
		}
		else if($video->get('vid_min_permission') === 0){
			$permission_text .= 'Anyone logged in';
		}
		else{
			$permission_text .= 'Minimum permission ('.$video->get('vid_min_permission').') ';
		}
	}
	$permission_text .= 'can access this video.';
	?>

	<!-- Two Column Layout -->
	<div class="row g-3">
		<!-- Left Column -->
		<div class="col-xxl-6">
			<!-- Video Information Card -->
			<div class="card mb-3">
				<div class="card-header bg-body-tertiary">
					<h5 class="mb-0">Video Information</h5>
				</div>
				<div class="card-body">
					<table class="table table-borderless mb-0" style="font-size: 0.875rem;">
						<tbody>
							<tr>
								<td class="p-1 text-800 fw-semi-bold" style="width: 180px;">Title</td>
								<td class="p-1 text-600"><?php echo htmlspecialchars($video->get('vid_title')); ?></td>
							</tr>
							<?php if($video->get('vid_description')): ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Description</td>
								<td class="p-1 text-600"><?php echo htmlspecialchars($video->get('vid_description')); ?></td>
							</tr>
							<?php endif; ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Uploader</td>
								<td class="p-1 text-600"><a href="/admin/admin_user?usr_user_id=<?php echo $user->key; ?>"><?php echo htmlspecialchars($user->display_name()); ?></a></td>
							</tr>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Video Source</td>
								<td class="p-1">
									<?php
									$video_type = 'Unknown';
									if(strpos($video->get('vid_video_text'), 'youtube') !== false) {
										$video_type = 'YouTube';
									} elseif(strpos($video->get('vid_video_text'), 'vimeo') !== false) {
										$video_type = 'Vimeo';
									}
									?>
									<span class="badge badge-subtle-danger"><?php echo $video_type; ?></span>
								</td>
							</tr>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Original URL</td>
								<td class="p-1 text-600"><a href="<?php echo htmlspecialchars($video->get('vid_video_text')); ?>" target="_blank"><?php echo htmlspecialchars($video->get('vid_video_text')); ?></a></td>
							</tr>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Public Link</td>
								<td class="p-1 text-600"><a href="<?php echo htmlspecialchars($video->get_url()); ?>" target="_blank"><?php echo htmlspecialchars(LibraryFunctions::get_absolute_url($video->get_url())); ?></a></td>
							</tr>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Created</td>
								<td class="p-1 text-600"><?php echo LibraryFunctions::convert_time($video->get('vid_create_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A T'); ?></td>
							</tr>
							<?php if($video->get('vid_view_count')): ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Total Views</td>
								<td class="p-1 text-600"><?php echo $video->get('vid_view_count'); ?></td>
							</tr>
							<?php endif; ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Status</td>
								<td class="p-1">
									<?php if($video->get('vid_delete_time')): ?>
										<span class="badge badge-danger">Deleted at <?php echo LibraryFunctions::convert_time($video->get('vid_delete_time'), 'UTC', $session->get_timezone()); ?></span>
									<?php else: ?>
										<span class="badge badge-subtle-success">Active</span>
									<?php endif; ?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Permissions Card -->
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
							<?php if($video->get('vid_grp_group_id')): ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Restricted to Group</td>
								<td class="p-1 text-600">
									<a href="/admin/admin_group?grp_group_id=<?php echo $video->get('vid_grp_group_id'); ?>" class="badge badge-subtle-primary"><?php echo htmlspecialchars($group->get('grp_name')); ?></a>
								</td>
							</tr>
							<?php endif; ?>
							<?php if($video->get('vid_evt_event_id')): ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Restricted to Event</td>
								<td class="p-1 text-600">
									<a href="/admin/admin_event?evt_event_id=<?php echo $video->get('vid_evt_event_id'); ?>" class="badge badge-subtle-info"><?php echo htmlspecialchars($event->get('evt_name')); ?></a>
								</td>
							</tr>
							<?php endif; ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Min. Permission Level</td>
								<td class="p-1 text-600"><?php echo ($video->get('vid_min_permission') !== NULL) ? $video->get('vid_min_permission') . ' (Member)' : 'None'; ?></td>
							</tr>
						</tbody>
					</table>
					<div class="alert alert-info mt-3 mb-0" style="font-size: 0.875rem;">
						<i class="bi bi-info-circle me-2"></i>
						<?php echo $permission_text; ?>
					</div>
				</div>
			</div>
		</div>

		<!-- Right Column -->
		<div class="col-xxl-6">
			<!-- Video Player Card -->
			<div class="card mb-3">
				<div class="card-header bg-body-tertiary">
					<h5 class="mb-0">Video Player</h5>
				</div>
				<div class="card-body p-0">
					<div class="ratio ratio-16x9">
						<?php echo $video->get_embed(); ?>
					</div>
				</div>
			</div>

			<!-- Embed Code Card -->
			<div class="card mb-3">
				<div class="card-header bg-body-tertiary">
					<h5 class="mb-0">Embed Code</h5>
				</div>
				<div class="card-body">
					<pre class="mb-0 bg-100 p-3 rounded" style="font-size: 0.75rem;"><code><?php echo htmlspecialchars($video->get_embed()); ?></code></pre>
					<button class="btn btn-falcon-default btn-sm mt-2" onclick="navigator.clipboard.writeText(this.previousElementSibling.textContent)">
						<i class="bi bi-clipboard me-1"></i> Copy to Clipboard
					</button>
				</div>
			</div>
		</div>
	</div>

	<?php

	$page->admin_footer();
?>

