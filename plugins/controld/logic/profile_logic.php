<?php

function profile_logic($get_vars, $post_vars){
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
	require_once(PathHelper::getIncludePath('includes/ErrorHandler.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/address_class.php'));
	require_once(PathHelper::getIncludePath('data/phone_number_class.php'));
	require_once(PathHelper::getIncludePath('data/messages_class.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/event_registrants_class.php'));
	require_once(PathHelper::getIncludePath('data/event_sessions_class.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('data/order_items_class.php'));

	$page_vars = array();

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	$composer_dir = $settings->get_setting('composerAutoLoad');
	require_once $composer_dir.'autoload.php';

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;
	$session->check_permission(0);
	$session->set_return();


	$user = new User($session->get_user_id(), TRUE);
	$page_vars['user'] = $user;

	//VERIFY THE EMAIL IF THE ACTIVATION CODE IS PRESENT
	$act_code = LibraryFunctions::fetch_variable('act_code', NULL);
	if ($act_code) {
		Activation::ActivateUser($act_code);
	}


	$tier = SubscriptionTier::GetUserTier($user->key);
	$page_vars['tier'] = $tier;

	$page_vars['tab_menus'] = array(
		'My Profile' => '/profile',
		'Edit Account' => '/profile/account_edit',
		'Change Password' => '/profile/password_edit',
		'Edit Address' => '/profile/address_edit',
		'Edit Phone Number' => '/profile/phone_numbers_edit',
		'Change Contact Preferences' => '/profile/contact_preferences',
		'Change Subscription' => '/profile/subscription_edit',
	);

	//ORDERS
	$numperpage = 5;
	$conoffset = LibraryFunctions::fetch_variable('conoffset', 0, 0, '');
	$consort = LibraryFunctions::fetch_variable('consort', 'ord_order_id', 0, '');
	$consdirection = LibraryFunctions::fetch_variable('consdirection', 'DESC', 0, '');
	$search_criteria = NULL;

	$search_criteria = array();
	$search_criteria['user_id'] = $session->get_user_id();
	$search_criteria['deleted'] = false;


	$orders = new MultiOrder(
		$search_criteria,
		array($consort=>$consdirection),
		$numperpage,
		$conoffset);
	$numorders = $orders->count_all();
	$orders->load();
	$page_vars['numorders'] = $numorders;
	$page_vars['orders'] = $orders;


	//MESSAGES
	$messages = new MultiMessage(
	array('user_id_recipient' => $user->key, 'deleted' => false), //SEARCH CRITERIA
	array('message_id'=>'DESC'),  // SORT, SORT DIRECTION
	5, //NUMBER PER PAGE
	NULL //OFFSET
	);
	$messages->load();
	$page_vars['messages'] = $messages;

	//SUBSCRIPTIONS
	$subscriptions = new MultiOrderItem(
		array('user_id' => $session->get_user_id(), 'is_active_subscription' => true),
		array('order_item_id' => 'DESC')
	);
	$subscriptions->load();

	if($subscriptions->count() > 0){
		$page_vars['active_subscription'] = $subscriptions->get(0);
	}
	else{
		$page_vars['active_subscription'] = NULL;
	}

	$user_subscribed_list = array();
	$search_criteria = array('deleted' => false, 'user_id' => $user->key);
	$user_lists = new MultiMailingListRegistrant(
		$search_criteria);
	$user_lists->load();

	foreach ($user_lists as $user_list){
		$mailing_list = new MailingList($user_list->get('mlr_mlt_mailing_list_id'), TRUE);
		$user_subscribed_list[] = $mailing_list->get('mlt_name');
	}



	$page_vars['user_subscribed_list'] = $user_subscribed_list;

	$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);

	return $page_vars;
}

?>