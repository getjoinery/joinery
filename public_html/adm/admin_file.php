<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('adm/logic/admin_file_logic.php'));

	$page_vars = process_logic(admin_file_logic($_GET, $_POST));
	extract($page_vars);

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
					<img src="<?php echo htmlspecialchars($file->get_url('content')); ?>" alt="File Preview" class="img-fluid rounded" style="max-height: 400px;">
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
								<td class="p-1 text-600"><a href="/admin/admin_user?usr_user_id=<?php echo $user->key; ?>"><?php echo htmlspecialchars($user->display_name()); ?></a></td>
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
								<td class="p-1 text-800 fw-semi-bold" style="width: 140px;">Original</td>
								<td class="p-1">
									<a href="<?php echo htmlspecialchars($file->get_url('original')); ?>" target="_blank"><?php echo htmlspecialchars($file->get_url('original','short')); ?></a>
								</td>
							</tr>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Hero</td>
								<td class="p-1">
									<a href="<?php echo htmlspecialchars($file->get_url('hero')); ?>" target="_blank"><?php echo htmlspecialchars($file->get_url('hero','short')); ?></a>
								</td>
							</tr>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Content</td>
								<td class="p-1">
									<a href="<?php echo htmlspecialchars($file->get_url('content')); ?>" target="_blank"><?php echo htmlspecialchars($file->get_url('content','short')); ?></a>
								</td>
							</tr>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Profile Card</td>
								<td class="p-1">
									<a href="<?php echo htmlspecialchars($file->get_url('profile_card')); ?>" target="_blank"><?php echo htmlspecialchars($file->get_url('profile_card','short')); ?></a>
								</td>
							</tr>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">OG Image</td>
								<td class="p-1">
									<a href="<?php echo htmlspecialchars($file->get_url('og_image')); ?>" target="_blank"><?php echo htmlspecialchars($file->get_url('og_image','short')); ?></a>
								</td>
							</tr>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Avatar</td>
								<td class="p-1">
									<a href="<?php echo htmlspecialchars($file->get_url('avatar')); ?>" target="_blank"><?php echo htmlspecialchars($file->get_url('avatar','short')); ?></a>
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

