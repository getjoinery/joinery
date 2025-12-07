<?php

function ctldprofile_delete_logic($get_vars, $post_vars){

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	
	require_once(PathHelper::getIncludePath('plugins/controld/includes/ControlDHelper.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('plugins/controld/data/ctlddevices_class.php'));
	require_once(PathHelper::getIncludePath('plugins/controld/data/ctldprofiles_class.php'));
	require_once(PathHelper::getIncludePath('plugins/controld/data/ctldfilters_class.php'));
	require_once(PathHelper::getIncludePath('plugins/controld/data/ctldservices_class.php'));

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

	$profile = new CtldProfile($_REQUEST['profile_id'], TRUE);
	$profile->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));

	if(isset($_POST['confirm'])){	
		$profile->permanent_delete();			

		LibraryFunctions::redirect('/profile/devices');
		exit;
	}
	else{
		if($profile->is_primary_or_secondary() == 'primary'){
			throw new SystemDisplayablePermanentError("You cannot delete a default profile.");
		}
		$page_vars['profile'] = $profile;
		return LogicResult::render($page_vars);
	}
}
	
?>
