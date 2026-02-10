<?php
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('account_edit_logic.php', 'logic'));	
	
	$page_vars = process_logic(account_edit_logic($_GET, $_POST));

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

	// Photo grid
	require_once(PathHelper::getIncludePath('includes/PhotoHelper.php'));
	PhotoHelper::render_photo_card('grid', 'user', $page_vars['user']->key, $page_vars['user_photos'], [
		'set_primary_url' => '/profile/account_edit',
		'card_title' => 'My Photos',
		'primary_file_id' => $page_vars['user']->get('usr_pic_picture_id'),
	]);

	$settings = Globalvars::get_instance();
	$formwriter = $page->getFormWriter('form1', [
		'model' => $page_vars['user'],
		'action' => '/profile/account_edit'
	]);
	$formwriter->begin_form();

	$formwriter->textinput('usr_first_name', 'First Name', [
		'maxlength' => 255
	]);
	$formwriter->textinput('usr_last_name', 'Last Name', [
		'maxlength' => 255
	]);

	$nickname_display = $page_vars['settings']->get_setting('nickname_display_as');
	if($nickname_display){
		$formwriter->textinput('usr_nickname', $nickname_display, [
			'maxlength' => 255
		]);
	}

	$optionvals = Address::get_timezone_drop_array();
	$formwriter->dropinput('usr_timezone', 'Your Time Zone', [
		'options' => $optionvals
	]);

	$formwriter->submitbutton('btn_submit', 'Submit');

	$formwriter->end_form();

	echo PublicPage::EndPage();

	// Photo grid scripts
	PhotoHelper::render_photo_scripts('grid', 'user', $page_vars['user']->key, [
		'set_primary_url' => '/profile/account_edit',
		'confirm_delete_msg' => 'Remove this photo?',
	]);

	$page->public_footer($foptions=array('track'=>TRUE));
?>
