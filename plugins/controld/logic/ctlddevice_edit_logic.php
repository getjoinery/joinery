<?php

function ctlddevice_edit_logic($get_vars, $post_vars){
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/includes/ControlDHelper.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldaccounts_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctlddevices_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldprofiles_class.php');
	
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
		exit;
	}
	$page_vars['account'] = $account;

	$devices = new MultiCtldAccount(
		array(
		'user_id' => $user->key, 
		'deleted' => false
		), 
		
	);
	$num_devices = $devices->count_all();
	$page_vars['num_devices'] = $num_devices;
	
	$device = null;
	if($_REQUEST['device_id']){
		$device = new CtldDevice($_REQUEST['device_id'], TRUE);
		$device->authenticate_read(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$page_vars['device'] = $device;
	}

	if(isset($_POST['device_name'])){
		$cd = new ControlDHelper();
		
		if($device){
			//EDIT
			$device_name = LibraryFunctions::fetch_variable_local($post_vars, 'device_name', 0, 'required', 'Device name is required.', 'safemode', NULL); 
			
			//IF NO PREFIX, ADD IT
			if(!preg_match('/^user\d+-/', $device_name)){
				$device_name = 'user'.$user->key . '-' .$device_name;
			}

			$old_device_name = $device->get('cdd_device_name');		

			//CHECK IF THERE ARE ANY CHANGES IN THE DEVICE
			if($device_name != $old_device_name){

				$data = array(
					'name' => $device_name
				);
				$result = $cd->modifyDevice($device->get('cdd_device_id'), $data);
				if(!$result['success']){
					throw new SystemDisplayablePermanentError('Unable to edit this device.');
					exit;
				}
			}		

			$device->set('cdd_timezone',strip_tags($_POST['cdd_timezone']));
			$device->set('cdd_device_name', $device_name);
			$device->set('cdd_allow_device_edits', $_POST['cdd_allow_device_edits']);
			$device->prepare();
			$device->save();

			LibraryFunctions::redirect('/profile/devices');

		}
		else{	
			if(!$account->can_add_device()){
				throw new SystemDisplayablePermanentError("You cannot add any devices at this time.");
				exit;				
			}
			
			$empty_device = new CtldDevice(NULL);
			$empty_device->save();
			$empty_device->load();
			
			//CREATE THE PRIMARY PROFILE
			$profile_name = 'user'.$user->key . '-'.$empty_device->key.'-profile1';
			$profile1 = CtldProfile::createProfile($profile_name, $user);

			$device = CtldDevice::createDevice($empty_device, $profile1, $profile2, $_POST);

		}
		
		LibraryFunctions::redirect('/profile/devices');
		exit;
	}
	else{
	
		if(!$device){
			$device = new CtldDevice(NULL);
			$device->set('cdd_timezone', 'America/New_York');
			$page_vars['device'] = $device;
		}

	}

	return LogicResult::render($page_vars);
}
	
?>
