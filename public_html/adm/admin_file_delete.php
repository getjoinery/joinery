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

	$file = new File($_GET['fil_file_id'], TRUE);
	$user = new User($file->get('fil_usr_user_id'), TRUE);

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'files-parent',
		'page_title' => 'File delete confirm',
		'readable_title' => 'File delete confirm',
		'breadcrumbs' => array(
			'Files'=>'/admin/admin_files',
			'File: ' . $file->get('fil_name') => '',
		),
		'session' => $session,
	)
	);

	$options['title'] = 'File delete confirm';
	$options['altlinks'] = array();

	$page->begin_box($options);

	$formwriter = $page->getFormWriter('form1');

	if($file->is_image()){
		echo '<div style="float:left; margin-right:30px; margin-bottom:30px;"><img src="/uploads/profile_card/'.$file->get('fil_name').'"/></div>';
	}
	echo '<strong>Name:</strong> '.$file->get('fil_name') .'<br />';
	echo '<strong>Title:</strong> '.$file->get('fil_title') .'<br />';

	if($file->get('fil_delete_time')) {
		echo 'Soft Deleted';
	}

	echo '<br /><br /><div>';
	$formwriter = $page->getFormWriter('form2');
	echo $formwriter->begin_form();
	$formwriter->hiddeninput('action', ['value' => 'remove']);
	$formwriter->submitbutton('btn_delete', 'Delete this file permanently', ['class' => 'btn-secondary']);
	echo $formwriter->end_form();
	echo '</div>';

	$page->end_box();

	$page->admin_footer();
?>

