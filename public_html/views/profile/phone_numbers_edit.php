<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');
	require_once(LibraryFunctions::get_logic_file_path('phone_numbers_edit_logic.php'));

	
	$page = new PublicPage();
		$hoptions=array(
			'title'=>'Edit Phone Number'
			);
	$page->public_header($hoptions);
	
	$options=array();
	$options['subtitle'] = '<a href="/profile/profile">Back to my profile</a>';
	echo PublicPage::BeginPage('Add/Edit Phone Number', $options);

	echo '<div class="section padding-top-20">
			<div class="container">';

	?>
	<ul class="nav nav-tabs margin-bottom-20">
	  <li class="nav-item">
		<a class="nav-link" href="/profile/account_edit">Edit Account</a>
	  </li>
	  <li class="nav-item">
		<a class="nav-link" href="/profile/password_edit">Change Password</a>
	  </li>
	  <li class="nav-item">
		<a class="nav-link " href="/profile/address_edit">Edit Address</a>
	  </li>
	  <li class="nav-item">
		<a class="nav-link active" href="/profile/phone_numbers_edit">Edit Phone Number</a>
	  </li>
	  <li class="nav-item">
		<a class="nav-link" href="/profile/contact_preferences">Change Contact Preferences</a>
	  </li>
	</ul>
	<?php

	$formwriter = new FormWriterPublic("form1");
	
	$validation_rules = array();
	$validation_rules['phn_phone_number']['required']['value'] = 'true';
	$validation_rules['privacy_policy']['required']['value'] = 'true';
	$validation_rules['evr_first_event']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);	
	
	echo $formwriter->begin_form("", "post", "/profile/phone_numbers_edit");

	PhoneNumber::PlainForm($formwriter, $phone_number);

	echo '<a href="/profile/account_edit">Cancel</a> ';
	echo $formwriter->new_form_button('Submit','button button-lg button-dark');

	echo $formwriter->end_form();

	$page->endtable();
	
	echo '</div></div>';
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
