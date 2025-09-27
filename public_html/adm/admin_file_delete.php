<?php
	
	// ErrorHandler.php no longer needed - using new ErrorManager system
	
	PathHelper::requireOnce('includes/AdminPage.php');
	
	PathHelper::requireOnce('includes/LibraryFunctions.php');

	PathHelper::requireOnce('data/users_class.php');
	PathHelper::requireOnce('data/files_class.php');
	PathHelper::requireOnce('data/events_class.php');
	PathHelper::requireOnce('data/event_sessions_class.php');

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

	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');

	if($file->is_image()){
		echo '<div style="float:left; margin-right:30px; margin-bottom:30px;"><img src="/uploads/small/'.$file->get('fil_name').'"/></div>';
	}
	echo '<strong>Name:</strong> '.$file->get('fil_name') .'<br />';	
	echo '<strong>Title:</strong> '.$file->get('fil_title') .'<br />';	
	
	if($file->get('fil_delete_time')) {
		echo 'Soft Deleted';
	} 
	
	echo '<br /><br /><div>';
	$formwriter = LibraryFunctions::get_formwriter_object('form2', 'admin');
	$delform = $formwriter->begin_form('form2', 'POST', '/admin/admin_file?fil_file_id='. $file->key);
	$delform .= $formwriter->hiddeninput('action', 'remove');
	$delform .= $formwriter->new_form_button('Delete this file permanently', 'secondary');
	$delform .= $formwriter->end_form();
	echo $delform;
	echo '</div>';
	
	$formwriter->end_form();	

	$page->end_box();
	
	$page->admin_footer();
?>

