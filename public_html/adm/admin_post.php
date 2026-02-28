<?php

	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('/data/users_class.php'));
	require_once(PathHelper::getIncludePath('/data/posts_class.php'));
	require_once(PathHelper::getIncludePath('/includes/PhotoHelper.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();
	$settings = Globalvars::get_instance();

	$post = new Post($_GET['pst_post_id'], TRUE);

	if($_REQUEST['action'] == 'delete'){
		$post->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$post->soft_delete();

		header("Location: /admin/admin_posts");
		exit();
	}
	else if($_REQUEST['action'] == 'undelete'){
		$post->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$post->undelete();

		header("Location: /admin/admin_posts");
		exit();
	}
	else if($_REQUEST['action'] == 'set_primary_photo'){
		$post->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$post->set_primary_photo((int)$_POST['photo_id']);

		header("Location: /admin/admin_post?pst_post_id=" . $post->key);
		exit();
	}
	else if($_REQUEST['action'] == 'clear_primary_photo'){
		$post->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$post->clear_primary_photo();

		header("Location: /admin/admin_post?pst_post_id=" . $post->key);
		exit();
	}

	// Build dropdown actions
	$options['altlinks'] = array('Edit Post' => '/admin/admin_post_edit?pst_post_id='.$post->key);
	if(!$post->get('pst_delete_time')){
		$options['altlinks']['Soft Delete'] = '/admin/admin_post?action=delete&pst_post_id='.$post->key;
	}
	else{
		$options['altlinks']['Undelete'] = '/admin/admin_post?action=undelete&pst_post_id='.$post->key;
	}

	if($_SESSION['permission'] >= 8) {
		$options['altlinks'] += array('Permanent Delete' => '/admin/admin_post_permanent_delete?pst_post_id='.$post->key);
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
		'menu-id'=> 'blog-posts',
		'page_title' => 'Post',
		'readable_title' => $post->get('pst_title'),
		'breadcrumbs' => array(
			'Posts'=>'/admin/admin_posts',
			$post->get('pst_title')=>'',
		),
		'session' => $session,
		'no_page_card' => true,
		'header_action' => $dropdown_button,
	)
	);
	?>

	<!-- Post Information Card -->
	<div class="card mb-3">
		<div class="card-header bg-body-tertiary">
			<h5 class="mb-0">Post Information</h5>
		</div>
		<div class="card-body">
			<table class="table table-borderless mb-0" style="font-size: 0.875rem;">
				<tbody>
					<tr>
						<td class="p-1 text-800 fw-semi-bold" style="width: 180px;">Title</td>
						<td class="p-1 text-600"><?php echo htmlspecialchars($post->get('pst_title')); ?></td>
					</tr>
					<?php if($post->get('pst_short_description')): ?>
					<tr>
						<td class="p-1 text-800 fw-semi-bold">Short Description</td>
						<td class="p-1 text-600"><?php echo htmlspecialchars($post->get('pst_short_description')); ?></td>
					</tr>
					<?php endif; ?>
					<tr>
						<td class="p-1 text-800 fw-semi-bold">Link</td>
						<td class="p-1 text-600"><a href="<?php echo htmlspecialchars($post->get_url()); ?>" target="_blank"><?php echo htmlspecialchars(LibraryFunctions::get_absolute_url($post->get_url())); ?></a></td>
					</tr>
					<tr>
						<td class="p-1 text-800 fw-semi-bold">Created</td>
						<td class="p-1 text-600"><?php echo LibraryFunctions::convert_time($post->get('pst_create_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A T'); ?></td>
					</tr>
					<tr>
						<td class="p-1 text-800 fw-semi-bold">Status</td>
						<td class="p-1">
							<?php if($post->get('pst_delete_time')): ?>
								<span class="badge badge-danger">Deleted at <?php echo LibraryFunctions::convert_time($post->get('pst_delete_time'), 'UTC', $session->get_timezone()); ?></span>
							<?php elseif($post->get('pst_is_published')): ?>
								<span class="badge badge-subtle-success">Published</span>
							<?php else: ?>
								<span class="badge badge-subtle-secondary">Unpublished</span>
							<?php endif; ?>
						</td>
					</tr>
					<?php if($post->get('pst_published_time')): ?>
					<tr>
						<td class="p-1 text-800 fw-semi-bold">Published</td>
						<td class="p-1 text-600"><?php echo LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A T'); ?></td>
					</tr>
					<?php endif; ?>
					<?php if($post->get('pst_usr_user_id')): ?>
					<tr>
						<td class="p-1 text-800 fw-semi-bold">Author</td>
						<td class="p-1 text-600">
							<?php
							$author = new User($post->get('pst_usr_user_id'), TRUE);
							?>
							<a href="/admin/admin_user?usr_user_id=<?php echo $author->key; ?>"><?php echo htmlspecialchars($author->display_name()); ?></a>
						</td>
					</tr>
					<?php endif; ?>
					<?php if($post->get('pst_last_edit_time')): ?>
					<tr>
						<td class="p-1 text-800 fw-semi-bold">Last Edited</td>
						<td class="p-1 text-600"><?php echo LibraryFunctions::convert_time($post->get('pst_last_edit_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A T'); ?></td>
					</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>

	<!-- Post Preview Card (Full Width) -->
	<div class="card mt-3">
		<div class="card-header bg-body-tertiary">
			<h5 class="mb-0">Post Preview</h5>
		</div>
		<div class="card-body p-0">
			<iframe src="<?php echo htmlspecialchars($post->get_url()); ?>" width="100%" height="500" style="border:none;"></iframe>
		</div>
	</div>

	<!-- Post Photos Card -->
	<?php
	$post_photos = $post->get_photos();
	$photo_editable = !$post->get('pst_delete_time') && $_SESSION['permission'] > 4;
	PhotoHelper::render_photo_card('grid', 'post', $post->key, $post_photos, [
		'set_primary_url' => '/admin/admin_post?pst_post_id=' . $post->key,
		'card_title' => 'Post Photos',
		'editable' => $photo_editable,
		'primary_file_id' => $post->get('pst_fil_file_id'),
	]);
	?>

	<?php if($photo_editable): ?>
	<?php PhotoHelper::render_photo_scripts('grid', 'post', $post->key, [
		'set_primary_url' => '/admin/admin_post?pst_post_id=' . $post->key,
		'confirm_delete_msg' => 'Remove this photo from this post?',
	]); ?>
	<?php endif; ?>

	<?php

	$page->admin_footer();
?>

