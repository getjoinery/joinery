<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('adm/logic/admin_file_delete_logic.php'));

	$page_vars = process_logic(admin_file_delete_logic($_GET, $_POST));
	extract($page_vars);

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

	$pageoptions['title'] = 'Delete File: ' . $file->get('fil_name');
	$pageoptions['altlinks'] = array();
	$page->begin_box($pageoptions);

	$formwriter = $page->getFormWriter('form1');
	echo $formwriter->begin_form();

	if($file->is_image()){
		echo '<div style="float:left; margin-right:30px; margin-bottom:30px;"><img src="'.htmlspecialchars($file->get_url('profile_card')).'"/></div>';
	}
	echo '<strong>Name:</strong> '.$file->get('fil_name') .'<br />';
	echo '<strong>Title:</strong> '.$file->get('fil_title') .'<br />';

	if($file->get('fil_delete_time')) {
		echo '<br /><span class="badge bg-danger">Soft Deleted</span>';
	}

	echo '<br /><br />';
	echo '<p>WARNING: This will permanently delete this file ('.$file->get('fil_name').').</p>';

	$formwriter->hiddeninput('confirm', ['value' => 1]);
	$formwriter->hiddeninput('fil_file_id', ['value' => $fil_file_id]);

	$formwriter->submitbutton('btn_delete', 'Delete this file permanently', ['class' => 'btn-danger']);

	echo $formwriter->end_form();

	$page->end_box();

	$page->admin_footer();
?>
