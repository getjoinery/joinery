<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php'));
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php'));
	require_once(LibraryFunctions::get_logic_file_path('phone_numbers_edit_logic.php'));

	
	$page = new PublicPageTW();
		$hoptions=array(
			'title'=>'Edit Phone Number',
			'breadcrumbs' => array(
				'My Profile' => '/profile/profile',
				'Edit Phone Number' => '',
			),
			);
	$page->public_header($hoptions);
	echo PublicPageTW::BeginPage('Add/Edit Phone Number', $hoptions);


	echo PublicPageTW::tab_menu($tab_menus);

	$formwriter = new FormWriterPublicTW("form1");
	
	$validation_rules = array();
	$validation_rules['phn_phone_number']['required']['value'] = 'true';
	$validation_rules['privacy_policy']['required']['value'] = 'true';
	$validation_rules['evr_first_event']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);	
	
	echo $formwriter->begin_form("", "post", "/profile/phone_numbers_edit");

	PhoneNumber::PlainForm($formwriter, $phone_number);

	echo '<a href="/profile/account_edit">Cancel</a> ';
	echo $formwriter->new_form_button('Submit');

	echo $formwriter->end_form();

	$page->endtable();
	
	echo PublicPageTW::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
