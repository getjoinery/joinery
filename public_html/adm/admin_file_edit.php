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
				$_POST['fil_description'] = $_POST['fil_description'];
		}
		
		if($_POST['fil_min_permission'] === NULL || $_POST['fil_min_permission'] === ''){
			$file->set('fil_min_permission', NULL);
		} 
		else{
			$file->set('fil_min_permission', $_POST['fil_min_permission']);
		}

		if($_POST['fil_grp_group_id'] === NULL || $_POST['fil_grp_group_id'] === ''){
			$file->set('fil_grp_group_id', NULL);
		} 
		else{
			$file->set('fil_grp_group_id', $_POST['fil_grp_group_id']);
		}
		
		if($_POST['fil_evt_event_id'] === NULL || $_POST['fil_evt_event_id'] === ''){
			$file->set('fil_evt_event_id', NULL);
		} 
		else{
			$file->set('fil_evt_event_id', $_POST['fil_evt_event_id']);
		}



		$editable_fields = array('fil_description', 'fil_title','fil_gal_gallery_id');

		foreach($editable_fields as $field) {
			$file->set($field, $_POST[$field]);
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
	

	$pageoptions['title'] = 'File Edit: '.$file->get('fil_title');
	$page->begin_box($pageoptions);



	// Editing an existing file
	$formwriter = new FormWriterMaster('form1');
	echo $formwriter->begin_form('form1', 'POST', '/admin/admin_file_edit');
	echo $formwriter->hiddeninput('fil_file_id', $file->key);
	 

	echo $formwriter->textinput('File title', 'fil_title', NULL, 100, $file->get('fil_title'), '', 255, '');


	echo $formwriter->textbox('File Description', 'fil_description', 'ctrlHolder', 10, 80, $file->get('fil_description'), '', 'no');
	
	$optionvals = array('Public (anyone)' => null, 'Any logged in user (0)'=>0, 'Assistant (5)'=>5, 'Admin (8)'=>8, 'Master Admin (10)' => 10);
	echo $formwriter->dropinput("Permission level can access", "fil_min_permission", "ctrlHolder", $optionvals, $file->get('fil_min_permission'), '', FALSE, TRUE);
	
	$groups = new MultiGroup(
		array('type'=>Group::GROUP_TYPE_USER),  //SEARCH 
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$groups->load();

	$optionvals1['All'] = NULL;	
	$optionvals2 = $groups->get_dropdown_array();
	$optionvals = array_merge($optionvals1, $optionvals2);
	echo $formwriter->dropinput("Group can access", "fil_grp_group_id", "ctrlHolder", $optionvals, $file->get('fil_grp_group_id'), '', FALSE, TRUE);

	$events = new MultiEvent(
		NULL,  //SEARCH 
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$events->load();

	$optionvals['All'] = NULL;	
	$optionvals2 = $events->get_dropdown_array();
	$optionvals = array_merge($optionvals1, $optionvals2);
	echo $formwriter->dropinput("Event can access", "fil_evt_event_id", "ctrlHolder", $optionvals, $file->get('fil_evt_event_id'), '', FALSE, TRUE);
	
	
	if($file->is_image()){
		echo $formwriter->checkboxinput("Include this image in the gallery", "fil_gal_gallery_id", "checkbox", "left", $file->get('fil_gal_gallery_id'), 1, "");
	}
	
	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();



	
$page->end_box();
	$page->admin_footer();

?>
