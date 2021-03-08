<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');
	require_once(LibraryFunctions::get_logic_file_path('account_edit_logic.php'));	
	
	$settings = Globalvars::get_instance();
	$site_template = $settings->get_setting('site_template');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/includes/PublicPage.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/includes/FormWriterPublic.php');	

	$page = new PublicPage();
	$hoptions=array(
		'title'=>'Edit Account', 
	);
	$page->public_header($hoptions);

	echo PublicPage::BeginPage('Account Edit');
	
	echo '<div class="section padding-top-20">
			<div class="container">';

	foreach($display_messages AS $display_message) {
		if($display_message->identifier == 'userbox') {			
			echo '<div class="'.$display_message->get_message_class().'">'.$display_message->message.'</div>';
		}
	}			

	?>
	<ul class="nav nav-tabs margin-bottom-20">
	  <li class="nav-item">
		<a class="nav-link active" href="/profile/account_edit">Edit Account</a>
	  </li>
	  <li class="nav-item">
		<a class="nav-link" href="/profile/password_edit">Change Password</a>
	  </li>
	  <li class="nav-item">
		<a class="nav-link" href="/profile/address_edit">Edit Address</a>
	  </li>
	  <li class="nav-item">
		<a class="nav-link" href="/profile/phone_numbers_edit">Edit Phone Number</a>
	  </li>
	  <li class="nav-item">
		<a class="nav-link" href="/profile/contact_preferences">Change Contact Preferences</a>
	  </li>
	</ul>
	<?php
	
	$formwriter = new FormWriterPublic("form1");
	echo $formwriter->begin_form("", "post", "/logic/users_edit_logic");

	//$optionvals = array(""=>'', "Male"=>0, "Female"=>1);
	echo $formwriter->textinput("First Name", "usr_first_name", "ctrlHolder", 20, $user->get('usr_first_name'), "",255, "");
	echo $formwriter->textinput("Last Name", "usr_last_name", "ctrlHolder", 20, $user->get('usr_last_name'), "" , 255, "");
	echo $formwriter->textinput("Dharma Name", "usr_nickname", "ctrlHolder", 20, $user->get('usr_nickname'), "" , 255, "");
	//echo $formwriter->dropinput("Gender (optional)", "usr_gender", "ctrlHolder", $optionvals, $user->get('usr_gender'), '', FALSE);
	$optionvals = Address::get_timezone_drop_array();
	echo $formwriter->dropinput("Your Time Zone", "usr_timezone", "ctrlHolder", $optionvals, $user->get('usr_timezone'), '', FALSE);
	//TODO ALLOW THE USER TO CHANGE EMAILS
	//echo $formwriter->textinput("Email", "usr_email_new", "ctrlHolder", 20, $user->get('usr_email'), "" , 255, "");

	echo $formwriter->new_form_button('Submit', 'button button-lg button-dark');


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
		
	echo '</div></div>';
	echo PublicPage::EndPage();	
	$page->public_footer($foptions=array('track'=>TRUE));
?>
