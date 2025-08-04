<?php

	require_once(__DIR__ . '/../includes/PathHelper.php');
	
	// ErrorHandler.php no longer needed - using new ErrorManager system
	
	PathHelper::requireOnce('includes/AdminPage.php');
	PathHelper::requireOnce('includes/SessionControl.php');

	PathHelper::requireOnce('data/users_class.php');
	PathHelper::requireOnce('includes/UploadHandler.php');




	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();	

		
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'files-parent',
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
	
	/*
	$formwriter = LibraryFunctions::get_formwriter_object('fileupload2', 'admin');
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
	
	
	$formwriter = LibraryFunctions::get_formwriter_object('fileupload', 'admin');
	$formwriter->file_upload_full();
	$formwriter->end_form();
	
	$page->end_box();
	$page->admin_footer();

		
		
		
	


?>
