<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php'));
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php'));
	require_once(LibraryFunctions::get_logic_file_path('address_edit_logic.php'));
	
		

	$page = new PublicPageTW();
	$hoptions=array(
		'title'=>'Edit Address',
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			'Edit Address' => '',
		),
		);
	$page->public_header($hoptions);
	echo PublicPageTW::BeginPage('Edit Address', $hoptions);


	echo PublicPageTW::tab_menu($tab_menus);
	
	$formwriter = new FormWriterPublicTW("form1");
	
	$validation_rules = array();
	$validation_rules['usa_type']['required']['value'] = 'true';
	$validation_rules['usa_city']['required']['value'] = 'true';
	$validation_rules['usa_state']['required']['value'] = 'true';
	$validation_rules['usa_zip_code_id']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);					
	
	echo $formwriter->begin_form("", "post", "/profile/address_edit");

	/*
	if ($address->key) {
		// Don't put the existing address if we are adding a new one
		echo $formwriter->hiddeninput("a", LibraryFunctions::encode($address->key));
	}
	*/

	foreach($display_messages AS $display_message) {
		if($display_message->identifier == 'addressbox') {			
			echo '<div class="'.$display_message->get_message_class().'">'.$display_message->message.'</div>';
		}
	}	

	Address::PlainForm($formwriter, $address);

	echo '<a href="/profile/account_edit">Cancel</a> ';
	echo $formwriter->new_form_button('Submit');

	echo $formwriter->end_form();

	$page->endtable();

	echo PublicPageTW::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
