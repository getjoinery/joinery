<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

require_once('includes/PublicPage.php');
require_once('includes/FormWriterPublic.php');
/*
$token = LibraryFunctions::fetch_variable('token', '', 1, 'You did not pass the needed information');

if($token != '3la8ghs8'){
	throw new SystemDisplayablePermanentError(
		'Sorry, this form has expired.  Please <a href="/password-reset-1">click here</a> to send a password reset email.');
}
*/
$session = SessionControl::get_instance();

if ($_POST) {
	
	if(!$session->get_user_id()){
		throw new SystemDisplayableError('You must be logged in to set a password.');
		exit();
	}
	else{
		$user = new User($session->get_user_id(), TRUE);
	}

	if(!$user || $user->get('usr_password') !== NULL){
		throw new SystemDisplayablePermanentError(
			'Sorry, your password is already set.  If you need to reset it, <a href="/password-reset-1">click here</a> to send a password reset email.');	
	}
	
	if(!isset($_POST['usr_password']) || !isset($_POST['usr_password_again'])){
			throw new SystemDisplayableError(
				'The following required fields were not set: passwords');
	}
	

	if ($_POST['usr_password'] != $_POST['usr_password_again']) {
		throw new SystemDisplayableError(
			'Your password did not match in both fields.');
	}


	$user->set('usr_password', User::GeneratePassword($_POST['usr_password']));
	$user->save();


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
	echo PublicPage::BeginPage('Password Set');
	
	?><p>Your password has been set. <a href="/login">Click here to log in</a>.</p><?php
	
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

	echo PublicPage::BeginPage('Set a Password');

	$formwriter = new FormWriterIntegralZen("form1", TRUE, TRUE);

	$validation_rules = array();
	$validation_rules['usr_password']['required']['value'] = 'true';
	$validation_rules['usr_password']['minlength']['value'] = 5;
	$validation_rules['usr_password_again']['required']['value'] = 'true';
	$validation_rules['usr_password_again']['required']['message'] = "'You must enter your password twice to confirm'";
	$validation_rules['usr_password_again']['equalTo']['value'] = "'#usr_password'";
	$validation_rules['usr_password_again']['equalTo']['message'] = "'Your password did not match the one you entered above'";
	echo $formwriter->set_validate($validation_rules);	

	echo $formwriter->begin_form("uniForm", "post", "/password-set");
	echo '<fieldset class="inlineLabels">';
	echo $formwriter->passwordinput("New Password", "usr_password", "ctrlHolder", 20, NULL , 'Must be at least 5 characters.',255, "");
	echo $formwriter->passwordinput("Retype New Password", "usr_password_again", "ctrlHolder", 20, "" , "", 255,"");

	echo $formwriter->hiddeninput('token',$token);

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons(); 
	echo '</fieldset>';

	echo $formwriter->end_form();

	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE, 'formvalidate'=>TRUE));
	}
?>
