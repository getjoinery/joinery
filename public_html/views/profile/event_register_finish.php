<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('event_register_finish_logic.php', 'logic'));

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

	$formwriter->begin_form();

	$formwriter->hiddeninput("eventregistrantid", "", ['value' => $evr_event_registrant_id]);
	$formwriter->hiddeninput("userid", "", ['value' => $user->key]);
	$formwriter->hiddeninput("act_code", "", ['value' => $act_code]);

	if(!Address::GetDefaultAddressForUser($user_id)){
		$user_address = $user->address();
		Address::renderFormFields($formwriter, [
			'required' => true,
			'include_country' => true,
			'include_user_id' => false,
			'model' => $user_address
		]);
		echo '<hr><br><br>';
	}

	if(!$phone_number = $user->phone()){
		PhoneNumber::renderFormFields($formwriter, [
			'required' => true,
			'include_user_id' => false,
			'model' => $phone_number
		]);
		echo '<hr><br><br>';
	}
	$nickname_display = $settings->get_setting('nickname_display_as');
	if($nickname_display){
		$formwriter->textinput('usr_nickname', $nickname_display, [
			'maxlength' => 255,
			'value' => @$form_fields->usr_nickname
		]);
	}
	$optionvals = array("Yes"=>"1", "No"=>"0");
	$formwriter->dropinput('evr_first_event', 'Is this your first event with us?', [
		'options' => $optionvals,
		'value' => $settings->get_setting('comments_unregistered_users'),
		'validation' => ['required' => true]
	]);

	$formwriter->textinput('evr_other_events', 'If no, what other events have you attended?', [
		'maxlength' => 255,
		'value' => ($event_registrant ? $event_registrant->get('evr_other_events') : '')
	]);

	$formwriter->checkboxinput('privacy_policy', 'I have read and agree to the <a href="/privacy-policy/">privacy policy</a>', [
		'value' => 1,
		'validation' => ['required' => true]
	]);

	$formwriter->submitbutton('btn_submit', 'Submit');

	echo $formwriter->end_form();

	$page->endtable();

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>