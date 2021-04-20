<?php

// Check if the page was requested with jQuery, if so, we should process this page differently
$ajax = !(empty($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] != 'XMLHttpRequest');

if ($ajax) { 
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AjaxErrorHandler.php');
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/EmailTemplate.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');

$settings = Globalvars::get_instance();
if(!$settings->get_setting('register_active')){
	header("HTTP/1.0 404 Not Found");
	echo 'This feature is turned off';
	exit();
}

$composer_dir = $settings->get_setting('composerAutoLoad');	
require $composer_dir.'autoload.php';
use MailchimpAPI\Mailchimp;

$LOGIN_MESSAGES = array(
	'phone_reveal' => 'Before you can view this phone number, please log in or register with us.',
);

$session = SessionControl::get_instance();
if ($session->get_user_id()) {
	LibraryFunctions::Redirect('/profile/profile');
}

if ($_POST) {
	
		
	if(!FormWriterPublic::honeypot_check($_POST)){
		throw new SystemDisplayableError(
			'Please leave the "Extra email" field blank.');			
	}
	

	if(!FormWriterPublic::antispam_question_check($_POST)){
		throw new SystemDisplayableError(
			'Please type the correct value into the anti-spam field.');			
	}
			
	
	
	$captcha_success = FormWriterPublic::captcha_check($_POST);
	if (!$captcha_success) {
		$errormsg = 'Sorry, '.strip_tags($_POST['usr_first_name']).' '.strip_tags($_POST['usr_last_name']).', you must click the CAPTCHA to submit the form.';
		throw new SystemDisplayableError($errormsg);	
	}		
	
	

	if(isset($_POST['prevformname'])){
		$session->save_formfields($_POST['prevformname']);
	}

	$required_fields = array(
		'usr_email' => 'Email Address',
		'usr_first_name' => 'First Name',
		'usr_last_name' => 'Last Name',
		//'usa_zip_code_id' => 'Zip Code',
		'usr_password' => 'Password'
	);
	

	$fixed_fields = array();
	$error_fields = array();

	// Since each registration field may either be "name" or "lbx_reg_name", we have to go
	// through and pull them both out, and put them in fixed_fields
	foreach ($required_fields as $field => $description) {
		if (isset($_POST[$field])) {
			$fixed_fields[$field] = trim($_POST[$field]);
		} else if (isset($_POST['lbx_reg_' . $field])) {
			$fixed_fields[$field] = trim($_POST['lbx_reg_' . $field]);
		} else {
			$error_fields[] = $description;
			continue;
		}

		if (!$fixed_fields[$field]) {
			$error_fields[] = $description;
		}
	}

	if (isset($_POST['setcookie']) || isset($_POST['lbx_reg_setcookie'])) {
		$fixed_fields['setcookie'] = TRUE;
	} else {
		$fixed_fields['setcookie'] = FALSE;
	}

	if ($error_fields) {
		throw new SystemDisplayableError(
			"The following required fields were left blank: " . implode(', ', $error_fields) . '.  Please go back and try again.');
	}

	/*
	$zip_data = SingleRowFetch('zips.zip_codes', 'zip_code_id',
		$fixed_fields['usa_zip_code_id'], PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);

	if (!$zip_data) {
		throw new SystemDisplayableError(
			'We could not find that zip code.  Please go back and try again.');
	}
	*/



	$dbhelper = DbConnector::get_instance();
	$dblink = $dbhelper->get_db_link();

	$dblink->beginTransaction();

	if (User::GetByEmail($fixed_fields['usr_email'])) {
		throw new SystemDisplayableError(
			'An account has already been registered with this email address.  Please go back and double
			check the email you entered or <a href="/password-reset-1.php">click here</a> if you forgot
			your password.');
	}

	try {
		$user = User::CreateNewUser($fixed_fields['usr_first_name'], $fixed_fields['usr_last_name'], $fixed_fields['usr_email'], $fixed_fields['usr_password'], TRUE);
		
		if($_POST['usr_nickname']){
			$user->set('usr_nickname', $_POST['usr_nickname']);
		}
		
		//$user->set('usr_timezone', $zip_data->zip_timezone);
		$user->prepare();
		$user->save();
		
		//ADD TO THE MAILING LIST IF CHOSEN
		if($_REQUEST['mailing_list']){
			$status = $user->add_to_mailing_list();		
		} 

		
		/*
		$address = new Address(NULL);
		$address->set('usa_city', $zip_data->zip_city);
		$address->set('usa_state', $zip_data->zip_state);
		$address->set('usa_zip_code_id', $zip_data->zip_code_id);
		$address->set('usa_type', 'HM');
		$address->set('usa_usr_user_id', $user->key);
		$address->set('usa_is_default', TRUE);
		$address->set('usa_privacy', 2);
		$address->save();
		$address->update_coordinates();
		*/

		$session->clear_formfields();
		$session->store_session_variables($user);
		if ($fixed_fields['setcookie']) {
			$session->save_user_to_cookie();
		}

		$dblink->commit();
	} catch (TTClassException $e) {
		$dblink->rollBack();
		throw $e;
	}
	

	if ($ajax) { 
		echo json_encode(array('success' => 1));	
	} else { 

		$returnurl = $session->get_return();
		$session->set_return(NULL);
		
		// NOW REDIRECT
		if ($returnurl) {
			header("Location: $returnurl");
		} else {
			header("Location: /page/register-thanks");
		}
	}

} else {


	$form_fields = $session->get_formfields('register');


	if ($ajax) { 
		// AJAX calls should never get here.
		exit;
	}

	$session = SessionControl::get_instance();
	$session->set_formfields_save("register");
}


?>
