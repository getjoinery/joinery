<?php

	PathHelper::requireOnce('/includes/AdminPage.php');

	PathHelper::requireOnce('/data/phone_number_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(9);
	$session->set_return();

	$phone = new PhoneNumber($_GET['phn_phone_number_id'], TRUE);
	$act_result = Activation::CheckForActiveCode($phone->get('phn_usr_user_id'), Activation::PHONE_VERIFY);

	$phone_numbers_unver = new MultiPhoneNumber(
		array('user_id'=>$phone->get('phn_usr_user_id'), 'verified'=>FALSE, 'deleted'=>FALSE)
		);
	$phone_numbers_unver->load();
	$numphoneunverified = $phone_numbers_unver->count_all();

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'users',
		'page_title' => 'Phone Verify',
		'readable_title' => 'Phone Verify',
		'breadcrumbs' => NULL,
		'session' => $session,
	)
	);

	if($act_result) {
		$phone_act = new PhoneNumber($act_result->act_phn_phone_number_id, TRUE);
		?>
		<p>The last text message was sent to <?php echo $phone_act->get_phone_string(); ?> at <strong><?php echo  LibraryFunctions::convert_time($act_result->act_created_time, 'UTC', $session->get_timezone()); ?></strong>.</p>
		<?php
	}

	$formwriter = LibraryFunctions::get_formwriter_object('form7', 'admin');

	echo $formwriter->begin_form("uniForm", "post", "/profile/phone_verify_send?disptype=returnadmin");

	$optionvals = array();
	foreach ($phone_numbers_unver as $phone_number) {
		$optionvals[$phone_number->get('phn_phone_number')] = $phone_number->key;
	}
	echo $formwriter->dropinput("Number to resend text message", "phn_phone_number_id", 'ctrlHolder', $optionvals, $phone->key, '', FALSE);
	echo $formwriter->dropinput("Choose your phone carrier to resend text", "phn_phone_carrier", "ctrlHolder",  PhoneNumber::$phone_carriers, $phone->get('phn_phone_carrier'), '', TRUE);
	echo $formwriter->hiddeninput("sendcode", 1);
	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Resend_gray');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

	$formwriter = LibraryFunctions::get_formwriter_object('form8', 'admin');

	echo $formwriter->begin_form("uniForm", "post", "/profile/phone_verify_check?disptype=returnadmin");
	echo $formwriter->textinput("Verification Code", "act_code", "ctrlHolder", 20, @$act_code, '',255, '');
	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Verify');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

$page->admin_footer();
?>