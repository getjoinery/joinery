<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('includes/UploadHandler.php'));

	require_once(PathHelper::getIncludePath('adm/logic/admin_file_upload_logic.php'));

	$page_vars = process_logic(admin_file_upload_logic($_GET, $_POST));

	$session = SessionControl::get_instance();

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
	$formwriter = $page->getFormWriter('fileupload2');
	echo '<form id="fileupload2"  name="fileupload2" method="post" action="/admin/admin_file_upload_process" enctype="multipart/form-data">';
	echo '<fieldset class="inlineLabels">';
	echo $formwriter->fileinput("File to Upload", "files[]", "ctrlHolder", 30, '');
		echo '</fieldset>';
				echo $formwriter->submitbutton('btn_submit', 'Submit', ['class' => 'btn btn-primary']);
	echo $formwriter->end_form();
	echo '<hr>';
	*/

	$formwriter = $page->getFormWriter('fileupload');
	echo $formwriter->file_upload_full();
	echo $formwriter->end_form();

	$page->end_box();
	$page->admin_footer();

?>
