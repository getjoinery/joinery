<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/mailing_lists_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/mailing_list_registrants_class.php');

	$settings = Globalvars::get_instance();
	$user = new User($_REQUEST['usr_user_id'], TRUE);

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	
	$search_criteria = array('deleted' => false, 'active' => true);
	$mailing_lists = new MultiMailingList(
		$search_criteria,
		array('name'=>'ASC'));	
	$mailing_lists->load();

if ($_POST){

	$user->set('usr_calendly_uri', trim($_POST['usr_calendly_uri']));
	$user->set('usr_first_name', trim($_POST['usr_first_name']));
	$user->set('usr_last_name', trim($_POST['usr_last_name']));
	$user->set('usr_password_recovery_disabled', (bool)$_POST['usr_password_recovery_disabled']);

	$user->set('usr_nickname', trim($_POST['usr_nickname']));

	if($_POST['usr_organization_name']){
		$user->set('usr_organization_name', trim($_POST['usr_organization_name']));
	}
	
	if(isset($_POST['usr_email_new']) && $_POST['usr_email_new'] != $user->get('usr_email')) {

		if (User::GetByEmail(trim($_POST['usr_email_new']))) {
			$errorhandler = new ErrorHandler();
			$errorhandler->handle_general_error('An account has already been registered with the email address '. htmlspecialchars($_POST['usr_email_new']) .'.');
		} else {
			if($_SESSION['permission'] == 0){
				Activation::email_change_send($user->key, trim($_POST['usr_email_new']));
			}
			else{
				$user->set('usr_email', trim($_POST['usr_email_new']));
			}
		}
	}

	//HANDLE THE USERS'S MAILING LISTS
	$messages = array();
	$thismessage = array();
	foreach ($mailing_lists as $mailing_list){
		if(empty($_POST['new_list_subscribes'])){
			$new_list_subscribes = array();
		}
		else{
			$new_list_subscribes = $_POST['new_list_subscribes'];
		}
		
		//IF IT IS A CHOICE AND SELECTED
		if(in_array($mailing_list->key, $_POST['new_list_subscribes'])){

			if($mailing_list->is_user_in_list($user->key)){
				//IF USER IS ALREADY SUBSCRIBED
				$thismessage['message_type'] = 'warn';
				$thismessage['message_title'] = 'Notice';
				$thismessage['message'] = 'You are already SUBSCRIBED to the following lists: ' . $mailing_list->get('mlt_name');
				$messages[] = $thismessage;
			}
			else{
				//IF USER IS NOT SUBSCRIBED
				$status = $mailing_list->add_registrant($user->key);
				if($status){
					$thismessage['message_type'] = 'success';
					$thismessage['message_title'] = 'Success';
					$thismessage['message'] = 'You are SUBSCRIBED to the following lists: ' . $mailing_list->get('mlt_name');
					$messages[] = $thismessage;
				}
				else{
					$thismessage['message_type'] = 'error';
					$thismessage['message_title'] = 'Error';
					$thismessage['message'] = 'There was an error adding you to the following lists: ' . $mailing_list->get('mlt_name');
					$messages[] = $thismessage;
				}
			}
		}
		else{
			//IF IT IS A CHOICE AND NOT SELECTED
			if($mailing_list->is_user_in_list($user->key)){
				//IF USER IS SUBSCRIBED
				$status = $mailing_list->remove_registrant($user->key);
				if($status){
					$thismessage['message_type'] = 'success';
					$thismessage['message_title'] = 'Success';
					$thismessage['message'] = 'You are UNSUBSCRIBED from the following lists: ' . $mailing_list->get('mlt_name');
					$messages[] = $thismessage;
				}
				else{
					$thismessage['message_type'] = 'error';
					$thismessage['message_title'] = 'Error';
					$thismessage['message'] = 'There was an error removing you from the following lists: ' . $mailing_list->get('mlt_name');
					$messages[] = $thismessage;
				}
			}	
		}				
	}

	
	$user->set('usr_timezone', $_POST['usr_timezone']);
	
	if($_SESSION['permission'] == 10){
		$user->set('usr_permission', $_POST['usr_permission']);
	}

	$user->prepare();
	$user->save();


	//NOW REDIRECT
	$session = SessionControl::get_instance();
	header("Location: /admin/admin_user?usr_user_id=$user->key");
	exit();

}
else{

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'users-list',
		'page_title' => 'User Edit',
		'readable_title' => 'User Edit',
		'breadcrumbs' => array(
			'Users'=>'/admin/admin_users', 
			'User '.$user->display_name() => '/admin/admin_user?usr_user_id='.$user->key,
			'User Edit'=>'',
		),
		'session' => $session,
	)
	);
	
	$pageoptions['title'] = "User Edit";
	$page->begin_box($pageoptions);

	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	
	$validation_rules = array();
	$validation_rules['usr_email_new']['required']['value'] = 'true';
	$validation_rules['usr_timezone']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);	
	
	echo $formwriter->begin_form("form1", "post", "/admin/admin_users_edit");

	/*
	$optionvals = array(""=>NULL, "Male"=>0, "Female"=>1);
	echo $formwriter->dropinput("Gender (optional)", "usr_gender", "ctrlHolder", $optionvals, $user->get('usr_gender'), '', FALSE);

	$optionvals = array('Unknown'=>NULL, 'True'=>FALSE, 'False'=>TRUE);
	echo $formwriter->dropinput3boolean("Name is Bad", "usr_name_is_bad", "ctrlHolder",$user->get('usr_name_is_bad'), '');
	*/

	echo $formwriter->textinput("First Name", "usr_first_name", "ctrlHolder", 20, $user->get('usr_first_name') , "",255, "");
	echo $formwriter->textinput("Last Name", "usr_last_name", "ctrlHolder", 20, $user->get('usr_last_name'), "" , 255, "");
	
	echo $formwriter->textinput("Organization Name", "usr_organization_name", "ctrlHolder", 20, $user->get('usr_organization_name'), "" , 255, "");
	
	$nickname_display = $settings->get_setting('nickname_display_as');
	if($nickname_display){
		echo $formwriter->textinput($nickname_display, "usr_nickname", "ctrlHolder", 20, $user->get('usr_nickname'), "" , 255, "");
	}



	$user_subscribed_list = array();
	$search_criteria = array('deleted' => false, 'user_id' => $user->key);
	$user_lists = new MultiMailingListRegistrant(
		$search_criteria);	
	$user_lists->load();
	
	foreach ($user_lists as $user_list){
		$user_subscribed_list[] = $user_list->get('mlr_mlt_mailing_list_id');
	}	
	$optionvals = $mailing_lists->get_dropdown_array();	
	$checkedvals = $user_subscribed_list;
	$readonlyvals = array(); //DEFAULT
	$disabledvals = array();

	echo $formwriter->checkboxList("Mailing list subscriptions:", 'new_list_subscribes', "ctrlHolder", $optionvals, $checkedvals, $disabledvals, $readonlyvals);	

	$optionvals = array("On"=>0, "Off"=>1);
	echo $formwriter->dropinput("Password recovery", "usr_password_recovery_disabled", "ctrlHolder", $optionvals, $user->get('usr_password_recovery_disabled'), '', FALSE);

	if(Activation::CheckForActiveCode($user->key, Activation::EMAIL_CHANGE)) {
		echo '<b>*Email change pending*</b><br />';
	}
	echo $formwriter->textinput("Email", "usr_email_new", "ctrlHolder", 20, $user->get('usr_email'), "" , 255, "");

	$optionvals = Address::get_timezone_drop_array();
	echo $formwriter->dropinput("Time Zone", "usr_timezone", "ctrlHolder", $optionvals, $user->get('usr_timezone'), '', FALSE);

	if($_SESSION['permission'] == 10){
		$optionvals = array('Regular User (0)'=>0, 'Assistant (5)'=>5, 'Admin (8)'=>8, 'Master Admin (10)' => 10);
		echo $formwriter->dropinput("Permission level", "usr_permission", "ctrlHolder", $optionvals, $user->get('usr_permission'), FALSE);
	}
	
	echo $formwriter->textinput("Calendly User URI (for calendly integration)", "usr_calendly_uri", "ctrlHolder", 20, $user->get('usr_calendly_uri'), "" , 255, "");
	
	echo $formwriter->hiddeninput("usr_user_id", $user->key);


	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();
	
	$page->end_box();

	$page->admin_footer();
}
?>
