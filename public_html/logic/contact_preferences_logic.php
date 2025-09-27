<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function contact_preferences_logic($get_vars, $post_vars){

	PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LogicResult.php');
	PathHelper::requireOnce('includes/SessionControl.php');

	PathHelper::requireOnce('data/users_class.php');
	PathHelper::requireOnce('data/mailing_lists_class.php');

	$session = SessionControl::get_instance();

	if($get_vars['hash']){
		$user = new User($get_vars['user'], TRUE);

		if(!$get_vars['hash'] == $user->get('usr_authhash')){
			echo "Users don't match.  You cannot edit someone else's info.";
			exit;
		}
	}
	else{
		$session->check_permission(0);
		$user = new User($session->get_user_id(), TRUE);
	}

	$search_criteria = array('deleted' => false, 'active' => true);
	$mailing_lists = new MultiMailingList(
		$search_criteria,
		array('name'=>'ASC'));
	$mailing_lists->load();

	if($post_vars){
		$page_vars['messages'] = $user->add_user_to_mailing_lists($_POST['new_list_subscribes']);

	}

	$user_subscribed_list = array();
	$search_criteria = array('deleted' => false, 'user_id' => $user->key);
	$user_lists = new MultiMailingListRegistrant(
		$search_criteria);
	$user_lists->load();
	foreach ($user_lists as $user_list){
		$user_subscribed_list[] = $user_list->get('mlr_mlt_mailing_list_id');
	}

	$page_vars['optionvals'] = $mailing_lists->get_dropdown_array();
	//REMOVE ALL OF THE PRIVATE AND UNLISTED LISTS THE USER IS NOT SUBSCRIBED TO
	foreach($page_vars['optionvals'] as $key=>$value){
		$mailing_list = new MailingList($value, TRUE);
		if($mailing_list->get('mlt_visibility') == MailingList::VISIBILITY_PRIVATE || $mailing_list->get('mlt_visibility') == MailingList::VISIBILITY_PUBLIC_UNLISTED){
			if(!in_array($value, $user_subscribed_list)){
				unset($page_vars['optionvals'][$key]);
			}
		}
	}

	$page_vars['checkedvals'] = $user_subscribed_list;
	$page_vars['readonlyvals'] = array(); //DEFAULT
	$page_vars['disabledvals'] = array();

	//$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);

	$page_vars['tab_menus'] = array(
		'Edit Account' => '/profile/account_edit',
		'Change Password' => '/profile/password_edit',
		'Edit Address' => '/profile/address_edit',
		'Edit Phone Number' => '/profile/phone_numbers_edit',
		'Change Contact Preferences' => '/profile/contact_preferences',
	);

	return LogicResult::render($page_vars);
}
?>
