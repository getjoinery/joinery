<?php

function ctlddevice_edit_logic($get_vars, $post_vars){
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
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
			
			$device->set('cdd_timezone',strip_tags($_POST['cdd_timezone']));
			$device->set('cdd_device_name', $device_name);
			$device->set('allow_device_edits', $_POST['allow_device_edits']);
			$device->prepare();
			$device->save();

			$old_device_name = $device->get('cdd_device_name');

			//CHECK IF THERE ARE ANY CHANGES IN THE DEVICE
			if($device_name != $old_device_name){
				$data = array(
					'name' => $device_name
				);
				$result = $cd->modifyDevice($device->get('cdd_device_id'), $data);
			}		

			LibraryFunctions::redirect('/profile');
			
			
		}
		else{

			$deactivation_pin = rand(100000, 999999);
			//CREATE A NEW DEVICE LOCALLY
			$device = new CtldDevice(NULL);
			$device->set('cdd_timezone',strip_tags($_POST['cdd_timezone']));
			$device->set('cdd_usr_user_id', $user->key);
			$device->set('cdd_is_active', false);
			$device->set('allow_device_edits', $_POST['allow_device_edits']);
			$device->set('cdd_deactivation_pin', $deactivation_pin);
			
			
			$device->prepare();
			$device->save();
			$device->load();
			
			
			
			//CREATE THE PRIMARY PROFILE
			
			$profile_name = $user->key . '-' . $user->get('usr_last_name') .'-'. $device->key.'-profile1';
			$result = $cd->createProfile($profile_name);
			$profile1_key = $result['body']['profiles'][0]['PK'];
			
			$profile1 = new CtldProfile(NULL);
			$profile1->set('cdp_profile_id', $profile1_key);
			$profile1->set('cdp_usr_user_id', $user->key);
			$profile1->set('cdp_is_active', true);
			$profile1->prepare();
			$profile1->save();
			$profile1->load();
			
			
			//CREATE THE SECOND PROFILE (SCHEDULED STUFF)
			
			$profile_name = $user->key . '-' . $user->get('usr_last_name') .'-'. $device->key.'-profile2';
			$result = $cd->createProfile($profile_name);
			$profile2_key = $result['body']['profiles'][0]['PK'];
			
			
			$profile2 = new CtldProfile(NULL);
			$profile2->set('cdp_profile_id', $profile2_key);
			$profile2->set('cdp_usr_user_id', $user->key);
			$profile2->set('cdp_is_active', true);
			$profile2->prepare();
			$profile2->save();
			$profile2->load();
			
			
			
			
			
			
			//CREATE THE DEVICE
			$device_name = trim(preg_replace("/[^a-zA-Z0-9\s'-]/", "", $_POST['device_name']));
			$device_type = trim(preg_replace("/[^a-zA-Z0-9\s'-]/", "", $_POST['device_type']));;
			$data = array(
				'name' => $device_name,
				'icon' => 'mobile-android',
				'profile_id' => $profile1_key,
				'stats' => 0,
				'desc' => 'User '.$user->key . '-' . $user->get('usr_last_name'),
				'deactivation_pin' => $deactivation_pin,
				
			);

			if($profile2){
				//$data['profile_id2'] = $profile2->get('cdp_profile_id');
			}
			
			$result = $cd->createDevice($data);

			
			$device->set('cdd_cdp_ctldprofile_id_primary', $profile1->key);
			$device->set('cdd_profile_id_primary', $profile1_key);
			$device->set('cdd_cdp_ctldprofile_id_secondary', $profile2->key);
			$device->set('cdd_profile_id_secondary', $profile2_key);
			$device->set('cdd_device_id', $result['body']['PK']);
			$device->set('cdd_device_name', $device_name);
			$device->set('cdd_controld_resolver', $result['body']['resolvers']['uid']);
			
			$device->prepare();
			$device->save();
			
		}
		
		LibraryFunctions::redirect('/profile');
		exit;
	}
	else{
	
		if(!$device){
			$device = new CtldDevice(NULL);
			$device->set('cdd_timezone', 'America/New_York');
			$page_vars['device'] = $device;
		}

		
	}
	
	
	return $page_vars;
}
	
?>
