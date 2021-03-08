<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');
	require_once(LibraryFunctions::get_logic_file_path('password_edit_logic.php'));

	if ($has_old_password) {
		$page_title = 'Change Password';
	} else {
		$page_title = 'Set Password';
	}

	$page = new PublicPage(TRUE);
	$page->public_header(array(
		'title' => $page_title
	));

	$options=array();
	$options['subtitle'] = '<a href="/profile/profile">Back to my profile</a>';
	echo PublicPage::BeginPage($page_title, $options);
	echo '<div class="section padding-top-20">
			<div class="container">';

	?>
	<ul class="nav nav-tabs margin-bottom-20">
	  <li class="nav-item">
		<a class="nav-link" href="/profile/account_edit">Edit Account</a>
	  </li>
	  <li class="nav-item">
		<a class="nav-link active" href="/profile/password_edit">Change Password</a>
	  </li>
	  <li class="nav-item">
		<a class="nav-link " href="/profile/address_edit">Edit Address</a>
	  </li>
	  <li class="nav-item">
		<a class="nav-link " href="/profile/phone_numbers_edit">Edit Phone Number</a>
	  </li>
	  <li class="nav-item">
		<a class="nav-link" href="/profile/contact_preferences">Change Contact Preferences</a>
	  </li>
	</ul>
	<?php
	
	if($message){
		echo $message;
	}
	else{
		
		$formwriter = new FormWriterPublic("form1");
					
		$validation_rules = array();
		if ($has_old_password) {
			$validation_rules['usr_old_password']['required']['value'] = 'true';
		}
		$validation_rules['usr_password']['required']['value'] = 'true';
		$validation_rules['usr_password']['minlength']['value'] = 5;
		$validation_rules['usr_password_again']['required']['value'] = 'true';
		$validation_rules['usr_password_again']['required']['message'] = "'You must enter your password twice to confirm'";
		$validation_rules['usr_password_again']['equalTo']['value'] = "'#usr_password'";
		$validation_rules['usr_password_again']['equalTo']['message'] = "'Your password did not match the one you entered above'";
		echo $formwriter->set_validate($validation_rules);					
					
		echo $formwriter->begin_form("", "post", "/profile/password_edit");

		if ($has_old_password) {
			echo $formwriter->passwordinput("Old Password", "usr_old_password", "ctrlHolder", 20, NULL , '',255, "");
		}
		echo $formwriter->passwordinput("New Password", "usr_password", "ctrlHolder", 20, NULL , 'Must be at least 5 characters.',255, "");
		echo $formwriter->passwordinput("Retype New Password", "usr_password_again", "ctrlHolder", 20, "" , "", 255,"");
		echo '<a href="/profile/account_edit">Cancel</a> ';
		echo $formwriter->new_form_button('Submit','button button-lg button-dark');

		echo $formwriter->end_form();		
	}	
	echo '</div></div>';
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
