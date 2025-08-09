<?php

function ctldprofile_delete_logic($get_vars, $post_vars){
	PathHelper::requireOnce('includes/ErrorHandler.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('plugins/controld/includes/ControlDHelper.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	PathHelper::requireOnce('plugins/controld/data/ctldaccounts_class.php');
	PathHelper::requireOnce('plugins/controld/data/ctlddevices_class.php');
	PathHelper::requireOnce('plugins/controld/data/ctldprofiles_class.php');
	PathHelper::requireOnce('plugins/controld/data/ctldfilters_class.php');
	PathHelper::requireOnce('plugins/controld/data/ctldservices_class.php');
	
	$page_vars = array();

	$settings = Globalvars::get_instance(); 
	$page_vars['settings'] = $settings;

	
	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;
	$session->check_permission(0);
	$session->set_return();

	
	$user = new User($session->get_user_id(), TRUE);	
	$page_vars['user'] = $user;
	
	$account = CtldAccount::GetByColumn('cda_usr_user_id', $user->key);

	if(!$account){
		throw new SystemDisplayablePermanentError("User ".$user->key." does not have an Account.");
	}
	$page_vars['account'] = $account;

	
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
		return $page_vars;
	}
}
	
?>
