<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	$logic_path = LibraryFunctions::get_logic_file_path('address_edit_logic.php');
	require_once ($logic_path);	
	
	$settings = Globalvars::get_instance();
	$site_template = $settings->get_setting('site_template');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/includes/PublicPage.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/includes/FormWriterPublic.php');	
		

	$page = new PublicPage();
	$hoptions=array(
		'title'=>'Edit Address - My Profile',
		'currentmain'=>'Account');
	$page->public_header($hoptions);
	echo '<a class="back-link" href="/profile/profile">My Profile</a> > Address Edit<br />';
	echo PublicPage::BeginPage('Edit Address');
	/*
	if ($address && $address->get('usa_is_bad')) {
		echo '<div class="status_error"><p>We could not map the entered address.  Please double check you have entered it correctly.</p></div>';
	}
	*/
	
	$formwriter = new FormWriterPublic("form1");
	
	$validation_rules = array();
	$validation_rules['usa_type']['required']['value'] = 'true';
	$validation_rules['usa_city']['required']['value'] = 'true';
	$validation_rules['usa_state']['required']['value'] = 'true';
	$validation_rules['usa_zip_code_id']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);					
	
	echo $formwriter->begin_form("uniForm", "post", "/profile/address_edit");

	echo '<fieldset class="inlineLabels">';

	/*
	if ($address->key) {
		// Don't put the existing address if we are adding a new one
		echo $formwriter->hiddeninput("a", LibraryFunctions::encode($address->key));
	}
	*/

	echo '<div id="newaddressblock">';
	Address::PlainForm($formwriter, $address);
	echo '</div>';

		echo $formwriter->start_buttons();
		echo '<a href="/profile/account_edit">Cancel</a>';
		echo $formwriter->new_form_button('Submit', '');
		echo $formwriter->end_buttons();
		echo '</fieldset>';

	echo $formwriter->end_form();

	$page->endtable();

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
