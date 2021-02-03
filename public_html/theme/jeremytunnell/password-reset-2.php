<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

require_once('includes/PublicPage.php');
require_once('includes/FormWriterPublic.php');

$act_code = LibraryFunctions::fetch_variable('act_code', '', 1, '');
$success = Activation::checkTempCode($act_code, 2);

if(!$success){
	throw new SystemDisplayablePermanentError(
		'Sorry, this code has expired.  Please <a href="/password-reset-1">click here</a> to send another password reset email.');
}

if ($_POST) {
		
		if(!isset($_POST['usr_password']) || !isset($_POST['usr_password_again'])){
			throw new SystemDisplayableError(
				'The following required fields were not set: passwords');
		}
	
	

	if ($_POST['usr_password'] != $_POST['usr_password_again']) {
		throw new SystemDisplayableError(
			'Your password did not match in both fields.');
	}

	// Attempt to activiate the user if they aren't already activated and get the user
	$user = Activation::ActivateUser($act_code);

	if (!$user) {
		throw new SystemDisplayablePermanentError(
			'Sorry, this form has expired.  Please <a href="/password-reset-1">click here</a> to send another password reset email.');
	}

	$user->set('usr_password', User::GeneratePassword($_POST['usr_password']));
	$user->save();

	// Now delete the code
	Activation::deleteTempCode($act_code);

	// And show the password confirmed form
	$page = new PublicPage(TRUE);
	$hoptions=array(
		'title'=>'Password Reset', 
		'disptitle'=>'Password Reset Confirm',
		'crumbs'=>array('Home'=>'/', 'Password Reset Confirm'=>''),		
		'showmap'=>FALSE,
		'showheader'=>TRUE, 
		'sectionstyle'=>'neutral');
	$page->public_header($hoptions,NULL);
	echo PublicPage::BeginPage('Password Reset');
	?><p>Your password has been reset. <a href="/login">Click here to login</a>.</p><?php
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
	exit;
} else {
	$page = new PublicPage(TRUE);
	$hoptions=array(
		'title'=>'Password Reset', 
		'disptitle'=>'Password Reset Step 2 of 2',
		'crumbs'=>array('Home'=>'/', 'Password Reset'=>''),		
		'showmap'=>FALSE,
		'showheader'=>TRUE, 
		'sectionstyle'=>'neutral');
	$page->public_header($hoptions,NULL);

	echo PublicPage::BeginPage('Reset Password - Step 2 of 2');
	
	$formwriter = new FormWriterIntegralZen("form1", TRUE, TRUE);

	$validation_rules = array();
	$validation_rules['usr_password']['required']['value'] = 'true';
	$validation_rules['usr_password']['minlength']['value'] = 5;
	$validation_rules['usr_password_again']['required']['value'] = 'true';
	$validation_rules['usr_password_again']['required']['message'] = "'You must enter your password twice to confirm'";
	$validation_rules['usr_password_again']['equalTo']['value'] = "'#usr_password'";
	$validation_rules['usr_password_again']['equalTo']['message'] = "'Your password did not match the one you entered above'";
	echo $formwriter->set_validate($validation_rules);		

	echo $formwriter->begin_form("uniForm", "post", "/password-reset-2");
	echo '<fieldset class="inlineLabels">';
	echo $formwriter->passwordinput("New Password", "usr_password", "ctrlHolder", 20, NULL , 'Must be at least 5 characters.',255, "");
	echo $formwriter->passwordinput("Retype New Password", "usr_password_again", "ctrlHolder", 20, "" , "", 255,"");
	echo $formwriter->hiddeninput('act_code',$act_code);
	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit', '', 'submit1');
	echo $formwriter->end_buttons(); 	
	echo '</fieldset>';
	echo $formwriter->end_form();

	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE, 'formvalidate'=>TRUE));
	}
?>
