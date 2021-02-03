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

require_once('includes/PublicPage.php');
require_once('includes/FormWriterPublic.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');

$settings = Globalvars::get_instance();
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
	
		
	if(!FormWriterIntegralZen::honeypot_check($_POST)){
		throw new SystemDisplayableError(
			'Please leave the "Extra email" field blank.');			
	}
	

	if(!FormWriterIntegralZen::antispam_question_check($_POST)){
		throw new SystemDisplayableError(
			'Please type the correct value into the anti-spam field.');			
	}
			
	
	
	$captcha_success = FormWriterIntegralZen::captcha_check($_POST);
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
		//'usa_zip_code_id' => 'Zip Code'
	);


	$required_fields['usr_password'] = 'Password';
	

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
		
		$user->set('usr_dharma_name', $_POST['usr_dharma_name']);
		
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

	$page = new PublicPage(TRUE);
	$hoptions=array(
		'title'=>'New User Registration',
		'disptitle'=>'New User Registration',
		'crumbs'=>array('Home'=>'/', 'New User Registration'=>''),			
		'showmap'=>FALSE,
		'showheader'=>TRUE, 
		'sectionstyle'=>'neutral');
	$page->public_header($hoptions,NULL);
	
	echo PublicPage::BeginPage('Register');

	if(isset($_GET['msgtext'])){
		if (array_key_exists($_GET['msgtext'], $LOGIN_MESSAGES)) {
			echo '<div class="status_warning">'.htmlspecialchars($LOGIN_MESSAGES[$_GET['msgtext']]).'</div>';
		}
	}		
			

	
	$formwriter = new FormWriterIntegralZen("form1", TRUE);
	
	$validation_rules = array();
	$validation_rules['usr_first_name']['required']['value'] = 'true';
	$validation_rules['usr_first_name']['minlength']['value'] = 1;
	$validation_rules['usr_first_name']['required']['message'] = "'Please enter your first name.'";
	$validation_rules['usr_last_name']['required']['value'] = 'true';
	$validation_rules['usr_last_name']['minlength']['value'] = 2;
	$validation_rules['privacy']['required']['value'] = 'true';
	$validation_rules['usr_email']['required']['value'] = 'true';
	$validation_rules['usr_email']['email']['value'] = 'true';
	$validation_rules['usr_email']['remote']['value'] = "'/ajax/email_check_ajax'";	
	$validation_rules['usr_email']['remote']['message'] = "'This email already exists.'";
	$validation_rules['usr_password']['required']['value'] = 'true';
	$validation_rules['usr_password']['minlength']['value'] = 5;	
	$validation_rules['usr_password']['minlength']['message'] = "'Password must be at least {0} characters'";
	$validation_rules = FormWriterIntegralZen::antispam_question_validate($validation_rules);
	echo $formwriter->set_validate($validation_rules);
	
	echo $formwriter->begin_form("uniForm", "post", "/register");
	echo $formwriter->hiddeninput("prevformname", "register");
	?>
	<div class="body-title bottom-border">
		<h2>Register.</h2>
		<div class="post-links"><a href="/login<?php if(isset($_GET['m'])){ echo '?m='.htmlspecialchars($_GET['m']); } ?>">Already a member? Log in</a></div>
	</div>
	<?php

	echo '<fieldset class="inlineLabels">';
	

	echo $formwriter->textinput("First Name", "usr_first_name", "ctrlHolder", 20, @$form_fields->usr_first_name , "",255, "");	
	echo $formwriter->textinput("Last Name", "usr_last_name", "ctrlHolder", 20, @$form_fields->usr_last_name, "" , 255, "");
	echo $formwriter->textinput("Dharma Name (if you have one)", "usr_dharma_name", "ctrlHolder", 20, @$form_fields->usr_last_name, "" , 255, "");

	echo $formwriter->textinput("Email", "usr_email", "ctrlHolder", 20, '', "" , 255, "");

	echo $formwriter->passwordinput("Create Password", "usr_password", "ctrlHolder", 20, "" , "", 255,"");
	echo $formwriter->antispam_question_input();
	//echo $formwriter->textinput("Zip Code", "usa_zip_code_id", "ctrlHolder", 20, @$form_fields->usa_zip_code_id, "", 255,"");

	echo $formwriter->checkboxinput("I have read and agree to the <a href='/privacy-policy'>privacy policy</a>", "privacy", "ctrlHolder", "normal", NULL, "yes", '');
	echo $formwriter->checkboxinput("Please add me to the mailing list", "mailing_list", "ctrlHolder", "normal", NULL, "yes", '');	
	echo $formwriter->checkboxinput("Keep me logged in", "setcookie", "ctrlHolder", "normal", 'yes', "yes", '');
	echo $formwriter->honeypot_hidden_input();	


	echo $formwriter->start_buttons();
	echo $formwriter->captcha_hidden_input();
	echo $formwriter->new_form_button('Submit', '', 'submit1');
	echo $formwriter->end_buttons();

	echo '</fieldset>';
	echo $formwriter->end_form();

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'fbconnect'=>TRUE));
}
?>
