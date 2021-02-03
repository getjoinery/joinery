<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/files_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	if (isset($_REQUEST['fil_file_id'])) {
		$file = new File($_REQUEST['fil_file_id'], TRUE);
	} else {
		echo 'Must pass a file';
		exit();
	}

	if($_POST){

		
		
		if($_POST['fil_description']){
				$_POST['fil_description'] = LibraryFunctions::ToUTF8($_POST['fil_description']);
		}
			

		
		$editable_fields = array('fil_description', 'fil_title');

		foreach($editable_fields as $field) {
			$file->set($field, $_REQUEST[$field]);
		}
		

		
		$file->prepare();
		$file->save();
		$file->load();
		
		LibraryFunctions::redirect('/admin/admin_file?fil_file_id='.$file->key);
		exit;
	}


	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 9,
		'page_title' => 'File Edit',
		'readable_title' => 'File Edit',
		'breadcrumbs' => NULL,
		'session' => $session,
	)
	);
	

	$pageoptions['title'] = 'File Edit: '.$file->get('fil_name');
	$page->begin_box($pageoptions);



	// Editing an existing file
	$formwriter = new FormWriterMaster('form1');
	echo $formwriter->begin_form('form1', 'POST', '/admin/admin_file_edit');
	echo $formwriter->hiddeninput('fil_file_id', $file->key);
	 
	//$optionvals = array("No"=>0, "Yes"=>1);
	//echo $formwriter->dropinput("Deleted", "fil_is_deleted", "ctrlHolder", $optionvals, $file->get('fil_is_deleted'), '', FALSE);

	echo $formwriter->textinput('File title', 'fil_title', NULL, 100, $file->get('fil_title'), '', 255, '');


	echo $formwriter->textbox('File Description', 'fil_description', 'ctrlHolder', 10, 80, $file->get('fil_description'), '', 'no');

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();



	
$page->end_box();
	$page->admin_footer();

?>
