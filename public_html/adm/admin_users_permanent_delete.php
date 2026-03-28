<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_users_permanent_delete_logic.php'));

$page_vars = process_logic(admin_users_permanent_delete_logic($_GET, $_POST));
extract($page_vars);

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
	echo $formwriter->begin_form();

	echo '<fieldset><h4>Confirm Delete</h4>';
		echo '<div class="fields full">';
		echo '<p><strong>WARNING:</strong> This will permanently delete this user and affect ' . $dry_run['total_affected'] . ' records as shown above.</p>';

	$formwriter->hiddeninput('confirm', '', ['value' => 1]);
	$formwriter->hiddeninput('usr_user_id', '', ['value' => $usr_user_id]);

	$formwriter->submitbutton('btn_delete', 'Permanently Delete User', ['class' => 'btn-danger']);
	echo ' <a href="/admin/admin_users" class="btn btn-secondary">Cancel</a>';

		echo '</div>';
	echo '</fieldset>';
	echo $formwriter->end_form();

	$page->end_box();
}

$page->admin_footer();
?>
