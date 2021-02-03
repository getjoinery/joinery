<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	$logic_path = LibraryFunctions::get_logic_file_path('event_register_finish_logic.php');
	require_once ($logic_path);	
	
	$settings = Globalvars::get_instance();
	$site_template = $settings->get_setting('site_template');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/includes/PublicPage.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/includes/FormWriterPublic.php');	

	$page = new PublicPage();
	$hoptions=array(
		'title'=>'Edit Event Info',
		'currentmain'=>'Account');
	$page->public_header($hoptions);

	echo PublicPage::BeginPage('Edit Registrant Info');
		
	echo '<h3>Please fill out this extra info for your registration in the <strong>'. $event->get('evt_name') . '</strong> event.</h3>';

	$formwriter = new FormWriterPublic("form1");
	$validation_rules = array();
	$validation_rules['phn_phone_number']['required']['value'] = 'true';
	$validation_rules['privacy_policy']['required']['value'] = 'true';
	$validation_rules['evr_first_event']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);			
	
	
	echo $formwriter->begin_form("uniForm", "post", "/profile/event_register_finish");


	echo '<fieldset class="inlineLabels">';

	echo $formwriter->hiddeninput("eventregistrantid", $evr_event_registrant_id);
	echo $formwriter->hiddeninput("userid", $user->key);
	echo $formwriter->hiddeninput("act_code", $act_code);
	
	if(!Address::GetDefaultAddressForUser($user_id)){
		//echo $formwriter->hiddeninput("address_id", $usa_users_addr_id);
		$user_address = $user->address();
		Address::PlainForm($formwriter, $user_address, array('privacy' => 1, 'usa_type' => 'HM'));	
		echo '<hr><br><br>';
	}
	
	if(!$phone_number = $user->phone()){
		PhoneNumber::PlainForm($formwriter, $phone_number);
		/*
		$user_phone = $user->phone();
		$optionvals = PhoneNumber::get_country_code_drop_array();
		echo $formwriter->dropinput("Country code", "phn_cco_country_code_id", "ctrlHolder", $optionvals, ($user_phone ? $user_phone->get('phn_cco_country_code_id') : ''), '', FALSE);
		echo $formwriter->textinput("Phone Number*", "phn_phone_number", "ctrlHolder", 20, ($user_phone ? $user_phone->get('phn_phone_number') : ''), NULL , 20, "");
		echo '<hr><br><br>';
		*/
	}	
	
	echo $formwriter->textinput("Dharma name (leave blank if no)", "usr_nickname", "ctrlHolder", 20, ($event_registrant ? $user->get('usr_nickname') : ''), "", 255,"");
	$optionvals = array("Yes"=>"1", "No"=>"0");
	echo $formwriter->dropinput("Is this your first event with us?*", "evr_first_event", "ctrlHolder", $optionvals, $settings->get_setting('comments_unregistered_users'), '', FALSE);	

	echo $formwriter->textinput("If no, what other events have you attended?", "evr_other_events", "ctrlHolder", 20, ($event_registrant ? $event_registrant->get('evr_other_events') : ''), "", 255,"");
	echo '<br />';		
	echo $formwriter->checkboxinput("I have read and agree to the <a href='/privacy-policy/'>privacy policy</a>", "privacy_policy", "checkbox", "left", NULL, 1, "");


	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit', '', 'submit1');
	echo $formwriter->end_buttons();
	echo '</fieldset>';

	echo $formwriter->end_form();

	$page->endtable();

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>