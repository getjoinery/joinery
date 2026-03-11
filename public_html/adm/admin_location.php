<?php

	require_once(PathHelper::getIncludePath('includes/Activation.php'));

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('data/locations_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$settings = Globalvars::get_instance();

	$location = new Location($_REQUEST['loc_location_id'], TRUE);

	if($_REQUEST['action'] == 'delete'){
		$location->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$location->soft_delete();

		header("Location: /admin/admin_locations");
		exit();
	}
	else if($_REQUEST['action'] == 'undelete'){
		$location->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$location->undelete();

		header("Location: /admin/admin_locations");
		exit();
	}

	$session->set_return();

	// Build dropdown actions
	$options['altlinks'] = array();
	if(!$location->get('loc_delete_time')) {
		$options['altlinks'] += array('Edit Location' => '/admin/admin_location_edit?loc_location_id='.$location->key);
	}

	if(!$location->get('loc_delete_time') && $_SESSION['permission'] >= 8) {
		$options['altlinks']['Soft Delete'] = '/admin/admin_location?action=delete&loc_location_id='.$location->key;
	}

	// Build dropdown button from altlinks
	$dropdown_button = '';
	if (!empty($options['altlinks'])) {
		$dropdown_button = '<div class="dropdown">';
		$dropdown_button .= '<button class="btn btn-soft-default btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Actions</button>';
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
		'menu-id'=> 'locations',
		'page_title' => 'Location',
		'readable_title' => $location->get('loc_name'),
		'breadcrumbs' => array(
			'Locations'=>'/admin/admin_locations',
			$location->get('loc_name') => '',
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
			<!-- Location Information Card -->
			<div class="card mb-3">
				<div class="card-header bg-body-tertiary">
					<h5 class="mb-0">Location Information</h5>
				</div>
				<div class="card-body">
					<table class="table table-borderless mb-0" style="font-size: 0.875rem;">
						<tbody>
							<tr>
								<td class="p-1 text-800 fw-semi-bold" style="width: 180px;">Name</td>
								<td class="p-1 text-600"><?php echo htmlspecialchars($location->get('loc_name')); ?></td>
							</tr>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Address</td>
								<td class="p-1 text-600"><?php echo nl2br(htmlspecialchars($location->get('loc_address'))); ?></td>
							</tr>
							<?php if($location->get('loc_website')): ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Website</td>
								<td class="p-1 text-600"><a href="<?php echo htmlspecialchars($location->get('loc_website')); ?>" target="_blank"><?php echo htmlspecialchars($location->get('loc_website')); ?></a></td>
							</tr>
							<?php endif; ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Link</td>
								<td class="p-1 text-600"><a href="<?php echo htmlspecialchars($location->get_url()); ?>"><?php echo htmlspecialchars(LibraryFunctions::get_absolute_url($location->get_url())); ?></a></td>
							</tr>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Status</td>
								<td class="p-1">
									<?php if($location->get('loc_delete_time')): ?>
										<span class="badge badge-danger">Deleted at <?php echo LibraryFunctions::convert_time($location->get('loc_delete_time'), 'UTC', $session->get_timezone()); ?></span>
									<?php elseif($location->get('loc_is_published')): ?>
										<span class="badge badge-subtle-success">Published</span>
									<?php else: ?>
										<span class="badge badge-subtle-secondary">Unpublished</span>
									<?php endif; ?>
								</td>
							</tr>
							<?php if($location->get('loc_create_time')): ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Created</td>
								<td class="p-1 text-600"><?php echo LibraryFunctions::convert_time($location->get('loc_create_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A T'); ?></td>
							</tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Short Description Card -->
			<?php if($location->get('loc_short_description')): ?>
			<div class="card mb-3">
				<div class="card-header bg-body-tertiary">
					<h5 class="mb-0">Short Description</h5>
				</div>
				<div class="card-body">
					<p class="mb-0 fs-10"><?php echo htmlspecialchars($location->get('loc_short_description')); ?></p>
				</div>
			</div>
			<?php endif; ?>
		</div>

		<!-- Right Column -->
		<div class="col-xxl-6">
			<!-- Location Image Card -->
			<?php if($location->get('loc_fil_file_id')): ?>
				<?php $file = new File($location->get('loc_fil_file_id'), true); ?>
			<div class="card mb-3">
				<div class="card-header bg-body-tertiary">
					<h5 class="mb-0">Location Image</h5>
				</div>
				<div class="card-body text-center">
					<img src="<?php echo htmlspecialchars($file->get_url('content', 'full')); ?>" alt="Location Image" class="img-fluid rounded" style="max-height: 300px;">
				</div>
			</div>
			<?php endif; ?>

			<!-- Full Description Card -->
			<?php if($location->get('loc_description')): ?>
			<div class="card mb-3">
				<div class="card-header bg-body-tertiary">
					<h5 class="mb-0">Full Description</h5>
				</div>
				<div class="card-body">
					<div class="fs-10">
						<?php echo $location->get('loc_description'); ?>
					</div>
				</div>
			</div>
			<?php endif; ?>
		</div>
	</div>

	<?php
	$page->admin_footer();
?>
