<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageSassa.php', '/includes'));
	require_once(LibraryFunctions::get_logic_file_path('ctlddevice_delete_logic.php'));

	$page_vars = ctlddevice_delete_logic($_GET, $_POST);
	$device = $page_vars['device'];
	$user = $page_vars['user'];
	$session = SessionControl::get_instance();
	$name = $device->get('cdd_device_name');


	$page = new PublicPageSassa();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Device Add/Edit', 
		'breadcrumbs' => array (
			'My Profile' => '/profile',
			'Delete Device' => ''),
	);
	$page->public_header($hoptions,NULL);

	echo PublicPageSassa::BeginPage('Delete Device', $hoptions);
	



	
	$formwriter = LibraryFunctions::get_formwriter_object();
	
	

	
	$validation_rules = array();
	$validation_rules['confirm']['required']['value'] = 'true';

	echo $formwriter->set_validate($validation_rules);	


	echo $formwriter->begin_form('contact-form style2 ajax-contact', 'POST', '/profile/ctlddevice_delete', true);
	
		?>
                        <div class="job-content">
                            <div class="job-post_date">
								<h3><?php echo $name; ?></h3>
                                <!--<span class="date"><i class="fa-regular fa-trash"></i></span>-->
                                <!--<div class="icon"><a href="/profile/ctlddevice_delete?device_id=<?php echo $device->key; ?>"><i class="fa-regular fa-trash"></i> Delete</a></div>-->
                            </div>
                        </div>
	<?php
	
		
	echo $formwriter->hiddeninput('device_id', $device->key);
	
	echo '<p>You are about to delete this device. </p>';
	


	$optionvals = array("Apple phone or Ipad"=>0, "Android phone or tablet"=>1, 'Windows Computer'=>3, 'Mac Computer'=>4, 'Other type'=>5);
	echo $formwriter->dropinput("Device type", "device_type", "", $optionvals, NULL, '', TRUE);
		
	
	echo $formwriter->checkboxinput("Confirm", "confirm", "checkbox", "left", 0, 1, "");
	
	
	
	echo $formwriter->start_buttons('form-btn col-6');
	echo $formwriter->new_form_button('Submit', 'th-btn');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form(true);	




	echo PublicPageSassa::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
