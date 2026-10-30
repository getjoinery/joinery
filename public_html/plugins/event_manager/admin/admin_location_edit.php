<?php
/** @joinery-admin class=content */
	
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('plugins/event_manager/data/locations_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));

	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance(); 

	// CRITICAL: Check edit_primary_key_value (form submission) first, fallback to GET
	if (isset($_POST['edit_primary_key_value'])) {
		$location = new Location($_POST['edit_primary_key_value'], TRUE);
		$location->assert_found();
	} elseif (isset($_GET['loc_location_id'])) {
		$location = new Location($_GET['loc_location_id'], TRUE);
		$location->assert_found();
	} else {
		$location = new Location(NULL);
	}

	if($_POST){

		if(empty($_POST['loc_fil_file_id'])){
			$_POST['loc_fil_file_id'] = NULL;
		}

		$editable_fields = array('loc_name','loc_description','loc_short_description', 'loc_is_published', 'loc_fil_file_id', 'loc_address', 'loc_website');

		foreach($editable_fields as $field) {
			$location->set($field, $_POST[$field]);
		}

		if(!$location->get('loc_link') || Access::permits(10)){
			if($_POST['loc_link']){
				$location->set('loc_link', $location->create_url($_POST['loc_link']));
			}
			else{
				$location->set('loc_link', $location->create_url($location->get('loc_name')));
			}
		}					

		$location->prepare();
		$location->save();
		$location->load();
		
		LibraryFunctions::redirect('/plugins/event_manager/admin/admin_location?loc_location_id='. $location->key);
		return;
	}

	//LOAD THE ALTERNATE CONTENT VERSION IF NEEDED
	if(!empty($_GET['cvn_content_version_id'])){
		ContentVersion::show_in_record($_GET['cvn_content_version_id'], $location, ContentVersion::TYPE_LOCATION, 'loc_description', 'loc_name');
	}

	$title = $location->get('loc_name');
	$content = $location->get('loc_description');

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'locations',
		'breadcrumbs' => array(
			'Locations'=>'/plugins/event_manager/admin/admin_locations',
			'Edit Location' => '',
		),
		'session' => $session,
	)
	);

	$locationoptions['title'] = "Edit Location";
	$page->begin_box($locationoptions);

	echo '<div class="row">
    <div class="col-md-8">
      <div class="p-3">';

	// Prepare form values - use automatic form filling from Location model
	$form_values = $location->export_as_array();
	// Override with content version values if loaded
	$form_values['loc_name'] = $title;
	$form_values['loc_description'] = $content;
	// Default to published for new locations
	if (!$location->key) {
		$form_values['loc_is_published'] = 1;
	}

	// Editing an existing location - use automatic form filling
	$formwriter = $page->getFormWriter('form1', [
		'debug' => true,
		'values' => $form_values,
		'edit_primary_key_value' => $location->key
	]);

	// Note: FormWriterV2 uses model-based validation auto-detection
	// No need for set_validate() - validation rules come from Location model

	echo $formwriter->begin_form();

	$formwriter->textinput('loc_name', 'Location name');

	$formwriter->textinput('loc_address', 'Location street address');

	$formwriter->textinput('loc_website', 'Location website');

	if(!$location->get('loc_link') || Access::permits(10)){
		// Left blank, the link is made from the name on save, so the model's
		// required column is not required of the person.
		$formwriter->textinput('loc_link', 'Link (optional)', [
			'prepend' => $settings->get_setting('webDir').'/location/',
			'validation' => ['required' => false],
		]);
	}

	$formwriter->dropinput('loc_is_published', 'Published', [
		'options' => [0 => 'No', 1 => 'Yes']
	]);

	$files = new MultiFile(
		array('deleted'=>false, 'picture'=>true),
		array('file_id' => 'DESC'),
		NULL,
		NULL);
	$files->load();
	$optionvals = $files->get_image_dropdown_array();
	$formwriter->imageinput('loc_fil_file_id', 'Image', [
		'options' => $optionvals
	]);

	$formwriter->textinput('loc_short_description', 'Short description');

	$formwriter->textbox('loc_description', 'Description', [
		'htmlmode' => 'yes'
	]);

	$formwriter->submitbutton('btn_submit', 'Submit');  // Changed from 'submit' to avoid shadowing form.submit()
	echo $formwriter->end_form();

	echo '    </div>
    </div>
    <div class="col-md-4">
      <div class="p-3">';

	$content_versions = new MultiContentVersion(
		array('type'=>ContentVersion::TYPE_LOCATION, 'foreign_key_id' => $location->key),
		array('create_time' => 'DESC'),		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$content_versions->load();
	
	$optionvals = $content_versions->get_dropdown_array($session, FALSE);
	
	if(count($optionvals)){

		// A GET: loading a version shows it in the form and saves nothing.
		$formwriter = $page->getFormWriter('form_load_version', ['action' => '/plugins/event_manager/admin/admin_location_edit', 'method' => 'GET']);

		echo $formwriter->begin_form();
		$formwriter->hiddeninput('loc_location_id', '', ['value' => $location->key]);
		$formwriter->dropinput('cvn_content_version_id', 'Load another version', [
			'options' => $optionvals
		]);
		$formwriter->submitbutton('load', 'Load');
		echo $formwriter->end_form();
	}
	else{
		echo 'No saved versions.';
	}

	echo '	</div>
	</div>
</div>	';

	$page->end_box();

	$page->admin_footer();

?>
