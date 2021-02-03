<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/EmailTemplate.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/integralzen/includes/FormWriterPublic.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/integralzen/includes/PublicPage.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');

$settings = Globalvars::get_instance();
if(!$settings->get_setting('register_active')){
	include("404.php");
	exit();
}

if (isset($_POST['email'])){

	$email = strtolower(trim($_POST['email']));

	$user = User::GetByEmail($email);

	if ($user) {
		Activation::email_forgotpw_send($email);
	}

	$page = new PublicPage();
	$hoptions=array(
		'title'=>'Password Reset', 
		'disptitle'=>'Password Reset Step 1 of 2',
		'crumbs'=>array('Home'=>'/', 'Password Reset'=>''),		
		'showmap'=>FALSE,
		'showheader'=>TRUE, 
		'sectionstyle'=>'neutral');
	$page->public_header($hoptions,NULL);

	echo PublicPage::BeginPage();
		if($user){ ?>
			<h2>Reset code sent</h2>
			<p>
			Next step: Check your email for a message from us with a link to enter your new password.
			</p>

			<p>
			If you don't receive an email from us within a few minutes, please check your spam folder.
			</p>
		<?php
		}
		else{
		?>
			<h2>Email not found</h2>
			<p>
			We could not find that email address.  Please use your back button to go back and double check the one you entered.
			</p>

		<?php 
		} 
		
		echo PublicPage::EndPage();
		$page->public_footer($foptions=array('track'=>TRUE));


}
else{

	$email = '';
	if (isset($_GET['e'])) {
		$e = rawurldecode($_GET['e']);
		if (LibraryFunctions::IsValidEmail($e)) {
			$email = $e;
		}
	}

	$page = new PublicPage();
	$hoptions=array(
		'title'=>'Password Reset', 
		'disptitle'=>'Password Reset Step 1 of 2',
		'crumbs'=>array('Home'=>'/', 'Password Reset'=>''),		
		'showmap'=>FALSE,
		'showheader'=>TRUE, 
		'sectionstyle'=>'neutral');	
	$page->public_header($hoptions,NULL);


	$formwriter = new FormWriterPublic("form1");
	echo PublicPage::BeginPage('Reset Password - Step 1 of 2');
	echo $formwriter->begin_form("uniForm", "post", "/password-reset-1"); 
	echo '<fieldset class="inlineLabels">';
	echo $formwriter->textinput("Enter the Email Address you registered with", "email", "ctrlHolder", 20, htmlspecialchars($email), '', 64, NULL);
	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit', '', 'submit1');
	echo $formwriter->end_buttons();
	echo '</fieldset>';
	echo $formwriter->end_form();

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

}
?>
