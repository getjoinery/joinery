<?php

function ctlddevice_delete_logic($get_vars, $post_vars){

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/ctlddevices_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/ctldprofiles_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/ctldfilters_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/ctldservices_class.php'));

	$page_vars = array();

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;
	$session->check_permission(0);
	$session->set_return();

	$user = new User($session->get_user_id(), TRUE);
	$page_vars['user'] = $user;

	$tier = SubscriptionTier::GetUserTier($user->key);
	$page_vars['tier'] = $tier;

	$device = new CtldDevice($_REQUEST['device_id'], TRUE);
	$device->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
	$page_vars['device'] = $device;

	if(isset($_POST['confirm'])){	
		$device->permanent_delete();			

		return LogicResult::redirect('/profile/devices');
	}
	
	return LogicResult::render($page_vars);
}
	
?>
