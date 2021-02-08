<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');
	require_once(LibraryFunctions::get_logic_file_path('phone_numbers_edit_logic.php'));

	
	$page = new PublicPage();
		$hoptions=array(
			'title'=>'Edit Phone Number',
			'currentmain'=>'Account');
	$page->public_header($hoptions);
	echo '<a class="back-link" href="/profile/profile">My Profile</a> > Add/Edit Phone Number<br />';
	echo PublicPage::BeginPage('Add/Edit Phone Number');


	$formwriter = new FormWriterPublic("form1");
	
	$validation_rules = array();
	$validation_rules['phn_phone_number']['required']['value'] = 'true';
	$validation_rules['privacy_policy']['required']['value'] = 'true';
	$validation_rules['evr_first_event']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);	
	
	echo $formwriter->begin_form("uniForm", "post", "/profile/phone_numbers_edit");
	echo '<fieldset class="inlineLabels">';

	PhoneNumber::PlainForm($formwriter, $phone_number);

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit','');
	echo $formwriter->end_buttons();
	echo '</fieldset>';

	echo $formwriter->end_form();

	$page->endtable();
	
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
