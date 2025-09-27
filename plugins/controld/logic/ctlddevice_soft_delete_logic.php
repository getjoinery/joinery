<?php

function ctlddevice_soft_delete_logic($get_vars, $post_vars){
	
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	
	PathHelper::requireOnce('plugins/controld/includes/ControlDHelper.php');

	PathHelper::requireOnce('data/users_class.php');
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

	$device = new CtldDevice($_REQUEST['device_id'], TRUE);
	$device->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
	$page_vars['device'] = $device;

	if(isset($_POST['device_id'])){
		$cd = new ControlDHelper();

		$data = array(
			'status' => 2
		);
		$result = $cd->modifyDevice($device->get('cdd_device_id'), $data);
		if(!$result['success']){
			throw new SystemDisplayablePermanentError('Unable to modify device.');
			exit;
		}
		$device->set('cdd_is_active', false);
		$device->set('cdd_delete_time', 'now()'); 
		$device->save();

		LibraryFunctions::redirect('/profile/devices');
		exit;
	}
	
	return LogicResult::render($page_vars);
}
	
?>
