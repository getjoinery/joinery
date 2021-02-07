<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	$logic_path = LibraryFunctions::get_logic_file_path('account_edit_logic.php');
	require_once ($logic_path);	
	
	$settings = Globalvars::get_instance();
	$site_template = $settings->get_setting('site_template');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/includes/PublicPage.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/includes/FormWriterPublic.php');	

	$page = new PublicPage();
	$hoptions=array(
		'title'=>'Edit Account - My Profile', 
		'currentmain'=>'Account'
	);
	$page->public_header($hoptions);
	echo '<a class="back-link" href="/profile/profile">My Profile</a> > Account Edit<br />';
	echo PublicPage::BeginPage('Account Edit');

	foreach($display_messages AS $display_message) {
		if($display_message->identifier == 'userbox') {			
			echo '<div class="'.$display_message->get_message_class().'">'.$display_message->message.'</div>';
		}
	}			

	
	$formwriter = new FormWriterPublic("form1");
	echo $formwriter->begin_form("uniForm", "post", "/logic/users_edit_logic");
	echo '<fieldset class="inlineLabels">';
	//$optionvals = array(""=>'', "Male"=>0, "Female"=>1);
	echo $formwriter->textinput("First Name", "usr_first_name", "ctrlHolder", 20, $user->get('usr_first_name'), "",255, "");
	echo $formwriter->textinput("Last Name", "usr_last_name", "ctrlHolder", 20, $user->get('usr_last_name'), "" , 255, "");
	echo $formwriter->textinput("Dharma Name", "usr_nickname", "ctrlHolder", 20, $user->get('usr_nickname'), "" , 255, "");
	//echo $formwriter->dropinput("Gender (optional)", "usr_gender", "ctrlHolder", $optionvals, $user->get('usr_gender'), '', FALSE);
	$optionvals = Address::get_timezone_drop_array();
	echo $formwriter->dropinput("Your Time Zone", "usr_timezone", "ctrlHolder", $optionvals, $user->get('usr_timezone'), '', FALSE);
	//TODO ALLOW THE USER TO CHANGE EMAILS
	//echo $formwriter->textinput("Email", "usr_email_new", "ctrlHolder", 20, $user->get('usr_email'), "" , 255, "");
	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit', '');
	echo $formwriter->end_buttons();
	echo '</fieldset>';

	echo $formwriter->end_form();

	

	foreach($display_messages AS $display_message) {
		if($display_message->identifier == 'addressbox') {			
			echo '<div class="'.$display_message->get_message_class().'">'.$display_message->message.'</div>';
		}
	}			

	$page->tableheader(array('Address'));
	foreach($addresses as $address){
		$rowvalues = array();
		array_push($rowvalues, $address->get_address_string(', ') . ' (<a href="/profile/address_edit?usa_address_id=' . $address->key . '" >edit</a>)');
		$page->disprow($rowvalues);
	}
		
	$page->endtable();
	if(!$numaddressrecords){
		echo '<a class="add-address" href="/profile/address_edit" title="Add New Address">Add New Address</a>';
	}
	
	
	
	$page->tableheader(array('Phone Number'));
	foreach($phone_numbers as $phone_number){
		$rowvalues = array();
		array_push($rowvalues, $phone_number->get('phn_is_verified') ? $phone_number->get_phone_string() : $phone_number->get_phone_string() . ' (<a href="/profile/phone_numbers_edit.php?phn_phone_number_id='.$phone_number->key.'">edit</a>)');
		$page->disprow($rowvalues);
	}
		
	$page->endtable();
	if(!$numphonerecords){
		echo '<a class="add-phonenumber" href="/profile/phone_numbers_edit" title="Add New Phone Number">Add New Phone Number</a>';
	}
		
	echo PublicPage::EndPage();	
	$page->public_footer($foptions=array('track'=>TRUE));
?>
