<?php

	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/UploadHandler.php');




	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();	

		
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 9,
		'page_title' => 'File Upload',
		'readable_title' => 'File Upload',
		'breadcrumbs' => array(
			'Files'=>'/admin/admin_files', 
			'File upload'=>'',
		),
		'session' => $session,
		'uploader' => true
	)
	);
	
	$pageoptions['title'] = "File Upload";
	$page->begin_box($pageoptions);
	echo '<p>Maximum upload file size is 40 megabytes</p>';
	
	/*
	$formwriter = new FormWriterMaster("fileupload2");
	echo '<form id="fileupload2"  name="fileupload2" method="post" action="/admin/admin_file_upload_process" enctype="multipart/form-data">';			
	echo '<fieldset class="inlineLabels">';
	echo $formwriter->fileinput("File to Upload", "files[]", "ctrlHolder", 30, '');
		echo '</fieldset>';
						echo $formwriter->start_buttons();
				echo $formwriter->new_form_button('Submit');
				echo $formwriter->end_buttons();
	echo $formwriter->end_form();
	echo '<hr>';
	*/
	
	
	$formwriter = new FormWriterMaster("fileupload");
	FormWriterMaster::file_upload_full();
	$formwriter->end_form();
	
	$page->end_box();
	$page->admin_footer();

		
		
		
	


?>
