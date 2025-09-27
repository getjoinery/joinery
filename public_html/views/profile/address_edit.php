<?php
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getThemeFilePath('address_edit_logic.php', 'logic'));
	
	$page_vars = address_edit_logic($_GET, $_POST);
// Handle LogicResult return format
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;
	$address_id = $page_vars['usa_address_id'];

	$page = new PublicPage();
	$hoptions=array(
		'title'=>'Edit Address',
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			'Edit Address' => '',
		),
		);
	$page->public_header($hoptions);
	echo PublicPage::BeginPage('Edit Address', $hoptions);

	echo PublicPage::tab_menu($page_vars['tab_menus'], 'Edit Address');
	
	$settings = Globalvars::get_instance();
	$formwriter = $page->getFormWriter('form1');
	
	$validation_rules = array();
	$validation_rules['usa_type']['required']['value'] = 'true';
	$validation_rules['usa_city']['required']['value'] = 'true';
	$validation_rules['usa_state']['required']['value'] = 'true';
	$validation_rules['usa_zip_code_id']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);					
	
	echo $formwriter->begin_form("", "post", "/profile/address_edit");

	foreach($page_vars['display_messages'] AS $display_message) {
		if($display_message->identifier == 'addressbox') {	
			echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
		}
	}	

	Address::PlainForm($formwriter, $page_vars['address']);

	echo '<a href="/profile/account_edit">Cancel</a> ';
	echo $formwriter->new_form_button('Submit');

	echo $formwriter->end_form();

	$page->endtable();

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
