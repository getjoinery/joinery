<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));

if ($_POST){

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$usr_user_id = LibraryFunctions::fetch_variable('usr_user_id', NULL, 1, 'You must provide a user to delete here.');
	$confirm = LibraryFunctions::fetch_variable('confirm', NULL, 1, 'You must confirm the action.');

	if ($confirm) {
		$user = new User($usr_user_id, TRUE);
		$user->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$user->permanent_delete();
	}

	//NOW REDIRECT
	$session = SessionControl::get_instance();
	$returnurl = $session->get_return();
	header("Location: $returnurl");
	exit();

}
else{
	$usr_user_id = LibraryFunctions::fetch_variable('usr_user_id', NULL, 1, 'You must provide a user to edit.');

	$user = new User($usr_user_id, TRUE);

	$session = SessionControl::get_instance();
	$session->set_return("/admin/admin_users");

	// Get dry-run preview
	$dry_run = $user->permanent_delete_dry_run();

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'users-list',
		'page_title' => 'User',
		'readable_title' => 'User',
		'breadcrumbs' => array(
			'Users'=>'/admin/admin_users',
			'Delete ' . $user->display_name() => '',
		),
		'session' => $session,
	)
	);

	// Show deletion preview
	$pageoptions['title'] = 'Deletion Impact Preview';
	$page->begin_box($pageoptions);

	echo '<div class="fields full">';
	echo '<p><strong>Total records that will be affected: ' . $dry_run['total_affected'] . '</strong></p>';

	if (!$dry_run['can_delete']) {
		echo '<div class="alert alert-danger">';
		echo '<strong>⚠ Cannot Delete:</strong><ul>';
		foreach ($dry_run['blocking_reasons'] as $reason) {
			echo '<li>' . htmlspecialchars($reason) . '</li>';
		}
		echo '</ul></div>';
	} else {
		echo '<table class="table table-striped">';
		echo '<thead><tr><th>Table</th><th>Column</th><th>Action</th><th>Count</th><th>Details</th></tr></thead>';
		echo '<tbody>';

		// Primary record
		echo '<tr class="table-warning">';
		echo '<td><strong>' . htmlspecialchars($dry_run['primary']['table']) . '</strong></td>';
		echo '<td>' . htmlspecialchars($dry_run['primary']['key_column']) . '</td>';
		echo '<td><span class="badge bg-danger">DELETE</span></td>';
		echo '<td>1</td>';
		echo '<td>' . htmlspecialchars($user->display_name()) . ' (ID: ' . intval($dry_run['primary']['key']) . ')</td>';
		echo '</tr>';

		// Dependencies
		foreach ($dry_run['dependencies'] as $dep) {
			$badge_class = match($dep['action']) {
				'cascade' => 'bg-danger',
				'set_value' => 'bg-warning',
				'null' => 'bg-info',
				'prevent' => 'bg-secondary',
				default => 'bg-secondary'
			};

			echo '<tr>';
			echo '<td>' . htmlspecialchars($dep['table']) . '</td>';
			echo '<td>' . htmlspecialchars($dep['column']) . '</td>';
			echo '<td><span class="badge ' . $badge_class . '">' . strtoupper($dep['action']) . '</span></td>';
			echo '<td>' . intval($dep['count']) . '</td>';
			echo '<td>';
			if ($dep['action'] === 'set_value') {
				$display_value = $dep['action_value'] ?? 'NULL';
				// Show User::USER_DELETED constant name if that's the value
				if ($display_value == 3) {
					echo 'Set to: User::USER_DELETED (ID: 3)';
				} else {
					echo 'Set to: ' . htmlspecialchars($display_value);
				}
			} elseif ($dep['action'] === 'null') {
				echo 'Set to NULL';
			} elseif ($dep['action'] === 'cascade') {
				echo 'Will be permanently deleted';
			}
			if (!empty($dep['message'])) {
				echo '<br><em>' . htmlspecialchars($dep['message']) . '</em>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
	echo '</div>';
	$page->end_box();

	// Confirmation form
	if ($dry_run['can_delete']) {
		$pageoptions['title'] = 'Delete User '.$user->display_name();
		$page->begin_box($pageoptions);

		$formwriter = $page->getFormWriter('form1');
		echo $formwriter->begin_form("form", "post", "/admin/admin_users_permanent_delete");

		echo '<fieldset><h4>Confirm Delete</h4>';
			echo '<div class="fields full">';
			echo '<p><strong>WARNING:</strong> This will permanently delete this user and affect ' . $dry_run['total_affected'] . ' records as shown above.</p>';

		echo $formwriter->hiddeninput("confirm", 1);
		echo $formwriter->hiddeninput("usr_user_id", $usr_user_id);

		echo $formwriter->start_buttons();
		echo $formwriter->new_form_button('Permanently Delete User', array('class' => 'btn btn-danger'));
		echo ' <a href="/admin/admin_users" class="btn btn-secondary">Cancel</a>';
		echo $formwriter->end_buttons();

			echo '</div>';
		echo '</fieldset>';
		echo $formwriter->end_form();

		$page->end_box();
	}

	$page->admin_footer();

}
?>
