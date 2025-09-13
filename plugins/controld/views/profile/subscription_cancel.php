<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/PathHelper.php');
PathHelper::requireOnce('includes/ThemeHelper.php');
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('subscription_cancel_logic.php', 'logic', 'system', null, 'controld'));	
	
	$page_vars = subscription_cancel_logic($_GET, $_POST);
	$current_order_item = $page_vars['current_order_item'];
	$account = $page_vars['account'];

	
	
	$page = new PublicPage();
	$hoptions=array(
		'title'=>'Cancel Subscription', 
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			'Cancel Subscription' => '',
		),
	);
	$page->public_header($hoptions); 

	echo PublicPage::BeginPage('Cancel Subscription', $hoptions);
	
/*
	foreach($page_vars['display_messages'] AS $display_message) {
		if($display_message->identifier == 'userbox') {	
			echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
		}
	}		
*/
	//echo PublicPage::tab_menu($tab_menus, 'Change Subscription');
	
	
	echo '<h2 class="sec-title">Cancel Subscription</h2>';
		
	
	$formwriter = $page->getFormWriter();
	echo $formwriter->begin_form("", "post", "/profile/subscription_cancel");


	echo 'You are about to cancel your subscription.  If cancelled, you will have access to our service until the last day of your subscription.';
	echo $formwriter->hiddeninput('order_item_id', $current_order_item->key);
	echo '<br><br>';

	echo $formwriter->new_form_button('Confirm Cancellation', 'th-btn');

	echo $formwriter->end_form();

	
		
	echo PublicPage::EndPage();	
	$page->public_footer($foptions=array('track'=>TRUE));

?>
