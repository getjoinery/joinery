<?php

	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('/data/phone_number_class.php'));

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

	$formwriter = $page->getFormWriter('form7', [
		'action' => '/profile/phone_verify_send?disptype=returnadmin',
		'values' => $phone->export_as_array()
	]);

	$formwriter->begin_form();

	$optionvals = array();
	foreach ($phone_numbers_unver as $phone_number) {
		$optionvals[$phone_number->key] = $phone_number->get('phn_phone_number');
	}
	$formwriter->dropinput("phn_phone_number_id", "Number to resend text message", [
		'options' => $optionvals
	]);
	$formwriter->dropinput("phn_phone_carrier", "Choose your phone carrier to resend text", [
		'options' => PhoneNumber::$phone_carriers
	]);
	$formwriter->hiddeninput("sendcode", ['value' => 1]);
	$formwriter->submitbutton('btn_resend', 'Resend');
	$formwriter->end_form();

	$formwriter = $page->getFormWriter('form8', [
		'action' => '/profile/phone_verify_check?disptype=returnadmin'
	]);

	$formwriter->begin_form();
	$formwriter->textinput("act_code", "Verification Code");
	$formwriter->submitbutton('btn_verify', 'Verify');
	$formwriter->end_form();

$page->admin_footer();
?>