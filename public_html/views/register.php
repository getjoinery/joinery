<?php
require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');
require_once (LibraryFunctions::get_logic_file_path('register_logic.php'));


$page = new PublicPage(TRUE);
$hoptions=array(
	'title'=>'New User Registration',
);
$page->public_header($hoptions,NULL);

echo PublicPage::BeginPage('Register');

if(isset($_GET['msgtext'])){
	if (array_key_exists($_GET['msgtext'], $LOGIN_MESSAGES)) {
		echo '<div class="status_warning">'.htmlspecialchars($LOGIN_MESSAGES[$_GET['msgtext']]).'</div>';
	}
}		
		


$formwriter = new FormWriterPublic("form1", TRUE);

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
$validation_rules = FormWriterPublic::antispam_question_validate($validation_rules);
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
echo $formwriter->textinput("Dharma Name (if you have one)", "usr_nickname", "ctrlHolder", 20, @$form_fields->usr_nickname, "" , 255, "");

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

?>
