<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/groups_class.php');
	require_once('includes/PublicPage.php');
	require_once('includes/FormWriterPublic.php');
	
	$settings = Globalvars::get_instance();
$composer_dir = $settings->get_setting('composerAutoLoad');	
require $composer_dir.'autoload.php';
	use MailchimpAPI\Mailchimp;
	
	$event_id = LibraryFunctions::fetch_variable('event_id', 0, 1, 'You must pass an event.');
	$event = new Event($event_id, TRUE);

	$session = SessionControl::get_instance();
	//$session->set_return();

	$page = new PublicPage();
	$hoptions = array(
		'title' => 'Integral Zen Waiting List',
		'description' => '',
		'body_id' => 'about-integral-zen',
	);
	$page->public_header($hoptions);
	
	if($_POST){
		
		$user = NULL;
		if($session->get_user_id()){
			$user = new User($session->get_user_id(), TRUE);
		}
		else{
	
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
			
			if(!$user = User::GetByEmail($_POST['usr_email'])){
				$user = User::CreateNewUser($_POST['usr_first_name'], $_POST['usr_last_name'], $_POST['usr_email'], NULL, TRUE);
			}			
		}			

		//ADD TO WAITING LIST
		$group = Group::add_group($event->get('evt_name') . ' waiting list', 1);
		$group->add_user($user->key);
		$display_message = 'You have been added to the '.$event->get('evt_name').' waiting list.';
		$message_type = 'status_announcement';	

		if($_POST['newsletter']){
			$status = $user->add_to_mailing_list();	
		}				
				
	}
	
	
	echo PublicPage::BeginPage('Waiting list for '.$event->get('evt_name'));
?>

	<h3>Add yourself to the waiting list, and we will notify you as soon as registration is available.</h3>
<?php
if($display_message){
	echo '<div class="'.$message_type.'">'.$display_message.'</div>';
}
else{

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
	$validation_rules = FormWriterIntegralZen::antispam_question_validate($validation_rules);
	echo $formwriter->set_validate($validation_rules);		
	
	echo $formwriter->begin_form("uniForm", "post", "/event_waiting_list");
	echo '<fieldset class="inlineLabels">';
	echo $formwriter->hiddeninput("event_id", $event->key);
	echo $formwriter->textinput("First Name", "usr_first_name", "ctrlHolder", 30, '', "", 255, "");
	echo $formwriter->textinput("Last Name", "usr_last_name", "ctrlHolder", 30, '', "", 255, "");
	echo $formwriter->textinput("Email", "usr_email", "ctrlHolder", 30, '', "", 255, "");
	echo $formwriter->antispam_question_input();
	echo $formwriter->honeypot_hidden_input();


	
	echo $formwriter->start_buttons();
	echo $formwriter->checkboxinput("I consent to the privacy policy.", "privacy", "checkbox", "left", NULL, 1, "");
	echo $formwriter->checkboxinput("Add me to the newsletter", "newsletter", "checkbox", "left", NULL, 1, "");
	if(!$session->get_user_id()){
		echo $formwriter->captcha_hidden_input();
	}

	echo $formwriter->new_form_button('Add me to the waiting list', '', 'submit1');
	echo $formwriter->end_buttons();
	echo '</fieldset>';
	echo $formwriter->end_form();
}	
	echo PublicPage::EndPage();	
	$page->public_footer(array('track'=>TRUE));
?>