<?php
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getThemeFilePath('account_edit_logic.php', 'logic'));	
	
	$page_vars = account_edit_logic($_GET, $_POST);
// Handle LogicResult return format
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;

	$page = new PublicPage();
	$hoptions=array(
		'title'=>'Account Edit', 
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			'Account Edit' => '',
		),
	);
	$page->public_header($hoptions); 

	echo PublicPage::BeginPage('Edit Account', $hoptions);

	foreach($page_vars['display_messages'] AS $display_message) {
		if($display_message->identifier == 'userbox') {	
			echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
		}
	}		

	echo PublicPage::tab_menu($page_vars['tab_menus'],'Edit Account');

	$settings = Globalvars::get_instance();
	$formwriter = $page->getFormWriter('form1');
	echo $formwriter->begin_form("", "post", "/profile/account_edit");

	echo $formwriter->textinput("First Name", "usr_first_name", NULL, 20, $page_vars['user']->get('usr_first_name'), "",255, "");
	echo $formwriter->textinput("Last Name", "usr_last_name", NULL, 20, $page_vars['user']->get('usr_last_name'), "" , 255, "");
	
	$nickname_display = $page_vars['settings']->get_setting('nickname_display_as');
	if($nickname_display){
		echo $formwriter->textinput($nickname_display, "usr_nickname", NULL, 20, $page_vars['user']->get('usr_nickname'), "" , 255, "");
	}

	$optionvals = Address::get_timezone_drop_array();
	echo $formwriter->dropinput("Your Time Zone", "usr_timezone", NULL, $optionvals, $page_vars['user']->get('usr_timezone'), '', FALSE);

	echo $formwriter->new_form_button('Submit');

	echo $formwriter->end_form();

	echo PublicPage::EndPage();	
	$page->public_footer($foptions=array('track'=>TRUE));
?>
