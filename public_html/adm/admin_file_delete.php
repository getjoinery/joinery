<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/files_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_sessions_class.php');

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
	$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_file?fil_file_id='. $file->key.'">
	<input type="hidden" class="hidden" name="action" id="action" value="remove" />
	<button class="uk-button" type="submit">Delete this file permanently</button>
	</form>';
	echo $delform;
	echo '</div>';
	
	$formwriter->end_form();	
	

	$page->end_box();
	
	$page->admin_footer();
?>


