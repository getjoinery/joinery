<?php
	PathHelper::requireOnce('includes/Globalvars.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('includes/PathHelper.php');
PathHelper::requireOnce('includes/ThemeHelper.php');
	ThemeHelper::includeThemeFile('includes/PublicPage');
	PathHelper::requireOnce('plugins/controld/logic/ctldprofile_delete_logic.php');

	$page_vars = ctldprofile_delete_logic($_GET, $_POST);
	$profile = $page_vars['profile'];
	$user = $page_vars['user'];
	$session = SessionControl::get_instance();


	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Delete Profile', 
		'breadcrumbs' => array (
			'My Profile' => '/profile',
			'Delete Profile' => ''),
	);
	$page->public_header($hoptions,NULL);

	echo PublicPage::BeginPage('Delete Profile', $hoptions);
	
	$formwriter = LibraryFunctions::get_formwriter_object();
	$validation_rules = array();
	$validation_rules['confirm']['required']['value'] = 'true';

	echo $formwriter->set_validate($validation_rules);	


	echo $formwriter->begin_form('contact-form style2', 'POST', '/profile/ctldprofile_delete', true);
	
		?>
                        <div class="job-content">
                            <div class="job-post_date">
								<h3><?php echo $name; ?></h3>
                                <!--<span class="date"><i class="fa-regular fa-trash"></i></span>-->
                                <!--<div class="icon"><a href="/profile/ctlddevice_delete?device_id=<?php echo $device->key; ?>"><i class="fa-regular fa-trash"></i> Delete</a></div>-->
                            </div>
                        </div>
	<?php
	
		
	echo $formwriter->hiddeninput('profile_id', $profile->key);
	
	echo '<p>You are about to delete this scheduled profile. After your scheduled profile is deleted, your default profile will always be active.</p>';
	


		
	
	echo $formwriter->checkboxinput("Confirm deletion", "confirm", "checkbox", "left", 0, 1, "");
	
	
	
	echo $formwriter->start_buttons('form-btn col-6');
	echo $formwriter->new_form_button('Delete', 'primary');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form(true);	




	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
