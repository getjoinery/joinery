<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/files_class.php'));

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
		return;
	}

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'files-parent',
		'page_title' => 'File Edit',
		'readable_title' => 'File Edit',
		'breadcrumbs' => NULL,
		'session' => $session,
	)
	);

	$pageoptions['title'] = 'File Edit: '.$file->get('fil_title');
	$page->begin_box($pageoptions);

	// Editing an existing file
	$formwriter = $page->getFormWriter('form1', 'v2', [
		'model' => $file,
		'edit_primary_key_value' => $file->key
	]);
	$formwriter->begin_form();

	$formwriter->textinput('fil_title', 'File title');

	$formwriter->textbox('fil_description', 'File Description', [
		'htmlmode' => 'no'
	]);

	$optionvals = array('Public (anyone)' => null, 'Any logged in user (0)'=>0, 'Assistant (5)'=>5, 'Admin (8)'=>8, 'Master Admin (10)' => 10);
	$formwriter->dropinput("fil_min_permission", "Permission level can access", [
		'options' => $optionvals
	]);

	$groups = new MultiGroup(
		array('category'=>'user'),  //SEARCH
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$groups->load();

	$optionvals1['All'] = NULL;
	$optionvals2 = $groups->get_dropdown_array();
	$optionvals = array_merge($optionvals1, $optionvals2);
	$formwriter->dropinput("fil_grp_group_id", "Group can access", [
		'options' => $optionvals
	]);

	$events = new MultiEvent(
		array(),  //SEARCH
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$events->load();

	$optionvals['All'] = NULL;
	$optionvals2 = $events->get_dropdown_array();
	$optionvals = array_merge($optionvals1, $optionvals2);
	$formwriter->dropinput("fil_evt_event_id", "Event can access", [
		'options' => $optionvals
	]);

	if($file->is_image()){
	/*
		echo $formwriter->checkboxinput("Include this image in the gallery", "fil_gal_gallery_id", "checkbox", "left", $file->get('fil_gal_gallery_id'), 1, "");
		*/
	}

	$formwriter->submitbutton('btn_submit', 'Submit');
	$formwriter->end_form();

$page->end_box();
	$page->admin_footer();

?>
