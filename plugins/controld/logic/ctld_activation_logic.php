<?php

function ctld_activation_logic($get_vars, $post_vars){
	PathHelper::requireOnce('includes/Activation.php');
	PathHelper::requireOnce('includes/ErrorHandler.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('plugins/controld/includes/ControlDHelper.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	PathHelper::requireOnce('plugins/controld/data/ctldaccounts_class.php');
	PathHelper::requireOnce('plugins/controld/data/ctlddevices_class.php');
	PathHelper::requireOnce('plugins/controld/data/ctldfilters_class.php');
	
	$page_vars = array();
	
	$settings = Globalvars::get_instance(); 
	$page_vars['settings'] = $settings;

	
	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;
	$session->check_permission(0);
	$session->set_return();
	
	$device_id = LibraryFunctions::fetch_variable('device_id', NULL,1,'You must pass a device_id');
	
	$user = new User($session->get_user_id(), TRUE);	
	$page_vars['user'] = $user;
	
	$account = CtldAccount::GetByColumn('cda_usr_user_id', $user->key);

	if(!$account){
		throw new SystemDisplayablePermanentError("User ".$user->key." does not have an Account.");
	}
	$page_vars['account'] = $account;

	$device = new CtldDevice($device_id, TRUE);
	
	$device->check_activate();
	

	$page_vars['device'] = $device;
	
	$link = '';
	$linkname = '';
	$link2 = '';
	$linkname2 = '';
	$command = '';
	if($device->get('cdd_device_type') == 'desktop-windows'){
		$link = '/static_files/controld_x86.exe';
		$linkname = 'Windows App (x86)';	
		
		$link2 = '/static_files/controld_arm.exe';;
		$linkname2 = 'Windows App (ARM)';
	}
	else if($device->get('cdd_device_type') == 'desktop-mac'){
		$link = '/static_files/controld_x86.dmg';
		$linkname = 'Mac App x86 (Older Mac)';	
		
		$link2 = '/static_files/controld_arm.dmg';;
		$linkname2 = 'Mac App ARM (Newer Mac)';
	}
	else if($device->get('cdd_device_type') == 'mobile-ios'){
		$link = 'https://apps.apple.com/us/app/control-d-quick-setup/id1518799460';
		$linkname = 'Apple App Store';	
	}
	else if($device->get('cdd_device_type') == 'mobile-android'){
		$link = 'https://play.google.com/store/apps/details?id=com.controld.setuputility';
		$linkname = 'Google Play Store';	
	}
	else if($device->get('cdd_device_type') == 'desktop-linux'){
		$linkname = 'Copy this command and paste it into your admin terminal.';	
		$command = 'sh -c \'sh -c "$(curl -sSL https://api.controld.com/dl)" -s '.$device->get('cdd_controld_resolver').' forced\'';
	}

	$page_vars['link'] = $link;
	$page_vars['linkname'] = $linkname;
	$page_vars['link2'] = $link2;
	$page_vars['linkname2'] = $linkname2;
	$page_vars['command'] = $command;
	
	return $page_vars;
}
	
?>
