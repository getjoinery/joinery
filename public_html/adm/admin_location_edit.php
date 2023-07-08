<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/locations_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/files_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance(); 

	if (isset($_REQUEST['loc_location_id'])) {
		$location = new Location($_REQUEST['loc_location_id'], TRUE);
	} 
	else {
		$location = new Location(NULL);
	}

	
	if($_POST){


		$editable_fields = array('loc_name', 'loc_link','loc_description','loc_short_description', 'loc_is_published', 'loc_fil_file_id', 'loc_address', 'loc_website');

		$_POST['loc_link'] = $location->create_url($_POST['loc_link']);

		foreach($editable_fields as $field) {
			$location->set($field, $_POST[$field]);
		}
				

		$location->prepare();
		$location->save();
		$location->load();
		
		LibraryFunctions::redirect('/admin/admin_location?loc_location_id='. $location->key);
		exit;
	}

	$title = $location->get('loc_name');
	$content = $location->get('loc_description');
	
	
	//LOAD THE ALTERNATE CONTENT VERSION IF NEEDED
	if($_GET['cnv_content_version_id']){
		$content_version = new ContentVersion($_GET['cnv_content_version_id'], TRUE);
		$content = $content_version->get('cnv_content');
		$title = $content_version->get('cnv_title');
	}

	$locationt = new AdminPage();
	$locationt->admin_header(	
	array(
		'menu-id'=> 'locations',
		'breadcrumbs' => array(
			'Locations'=>'/admin/admin_locations', 
			'Edit Location' => '',
		),
		'session' => $session,
	)
	);	

	
	$locationoptions['title'] = "Edit Location";
	$locationt->begin_box($locationoptions);


	echo '<div uk-grid>
    <div class="uk-width-2-3@m"><div style="padding: 20px">';
	
	// Editing an existing email
	$formwriter = new FormWriterMaster('form1');
	
	$validation_rules = array();
	$validation_rules['loc_link']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);	



	echo $formwriter->begin_form('form', 'POST', '/admin/admin_location_edit');

	if($location->key){
		echo $formwriter->hiddeninput('loc_location_id', $location->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}
	
	echo $formwriter->textinput('Location name', 'loc_name', NULL, 100, $title, '', 255, '');		
	
	echo $formwriter->textinput('Location street address', 'loc_address', NULL, 100, $location->get('loc_address'), '', 255, '');
	echo $formwriter->textinput('Location website', 'loc_website', NULL, 100, $location->get('loc_website'), '', 255, '');

	echo $formwriter->textinput('Link (optional): '.$settings->get_setting('webDir').'/location/', 'loc_link', NULL, 100, $location->get('loc_link'), '', 255, '');	


	$optionvals = array("No"=>0, "Yes"=>1);
	echo $formwriter->dropinput("Published", "loc_is_published", "", $optionvals, $location->get('loc_is_published'), '', FALSE);
	
	$files = new MultiFile(
		array('deleted'=>false, 'picture'=>true),
		array('file_id' => 'DESC'),		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$files->load();
	$optionvals = $files->get_image_dropdown_array();
	echo $formwriter->imageinput("Image", "loc_fil_file_id", "", $optionvals, $location->get('loc_fil_file_id'), '', TRUE, TRUE, FALSE, TRUE);	
	
	echo $formwriter->textinput('Short description:', 'loc_short_description', NULL, 100, $location->get('loc_short_description'), '', 255, '');

	echo $formwriter->textbox('Description', 'loc_description', '', 5, 80, $content, '', 'yes');	

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();



	echo '	</div>
	</div>
	<div class="uk-width-1-3@m"><div style="padding: 20px">';

	$content_versions = new MultiContentVersion(
		array('type'=>ContentVersion::TYPE_LOCATION, 'foreign_key_id' => $location->key),
		array('create_time' => 'DESC'),		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$content_versions->load();
	
	$optionvals = $content_versions->get_dropdown_array(FALSE, $session);
	
	if(count($optionvals)){

		$formwriter = new FormWriterMaster('form_load_version');
		echo $formwriter->begin_form('form_load_version', 'GET', '/admin/admin_location_edit');
		echo $formwriter->hiddeninput('loc_location_id', $location->key);
		echo $formwriter->dropinput("Load another version", "cnv_content_version_id", "ctrlHolder", $optionvals, NULL, '', TRUE);
		echo $formwriter->new_form_button('Load');	
		echo $formwriter->end_form();
	}
	else{
		echo 'No saved versions.';
	}

	echo '	</div>
	</div>
</div>	';

	$locationt->end_box();
	

	$locationt->admin_footer();

?>
