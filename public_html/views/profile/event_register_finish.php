<?php
	require_once(__DIR__ . '/../../includes/PathHelper.php');
	PathHelper::requireOnce('includes/Globalvars.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('includes/AdminPage.php');
	require_once(LibraryFunctions::get_logic_file_path('event_register_finish_logic.php'));

	$settings = Globalvars::get_instance();
	$page = new PublicPage();
	$hoptions=array(
		'title'=>'Edit Event Info'
		);
	$page->public_header($hoptions);

	echo PublicPage::BeginPage('Edit Registrant Info');

			
	echo '<h3>Please fill out this extra info for your registration in the <strong>'. $event->get('evt_name') . '</strong> event.</h3>';

	$settings = Globalvars::get_instance();
	$formwriter = $page->getFormWriter('form1');
	$validation_rules = array();
	$validation_rules['phn_phone_number']['required']['value'] = 'true';
	$validation_rules['privacy_policy']['required']['value'] = 'true';
	$validation_rules['evr_first_event']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);			
	
	
	echo $formwriter->begin_form("", "post", "/profile/event_register_finish");


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
		echo $formwriter->dropinput("Country code", "phn_cco_country_code_id", NULL, $optionvals, ($user_phone ? $user_phone->get('phn_cco_country_code_id') : ''), '', FALSE);
		echo $formwriter->textinput("Phone Number*", "phn_phone_number", NULL, 20, ($user_phone ? $user_phone->get('phn_phone_number') : ''), NULL , 20, "");
		echo '<hr><br><br>';
		*/
	}	
	$nickname_display = $settings->get_setting('nickname_display_as');
	if($nickname_display){
		echo $formwriter->textinput($nickname_display, "usr_nickname", NULL, 20, @$form_fields->usr_nickname, "" , 255, "");
	}
	$optionvals = array("Yes"=>"1", "No"=>"0");
	echo $formwriter->dropinput("Is this your first event with us?*", "evr_first_event", NULL, $optionvals, $settings->get_setting('comments_unregistered_users'), '', FALSE);	

	echo $formwriter->textinput("If no, what other events have you attended?", "evr_other_events", NULL, 20, ($event_registrant ? $event_registrant->get('evr_other_events') : ''), "", 255,"");
	echo '<br />';		
	echo $formwriter->checkboxinput("I have read and agree to the <a href='/privacy-policy/'>privacy policy</a>", "privacy_policy", "checkbox", "left", NULL, 1, "");


	echo $formwriter->new_form_button('Submit');

	echo $formwriter->end_form();

	$page->endtable();

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>