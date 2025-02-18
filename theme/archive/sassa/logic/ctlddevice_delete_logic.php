<?php

function ctlddevice_delete_logic($get_vars, $post_vars){
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/includes/ControlDHelper.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldaccounts_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctlddevices_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldprofiles_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldfilters_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldservices_class.php');
	
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


	if(isset($_POST['confirm'])){
		$cd = new ControlDHelper('debug_send');
		
		//DELETE THE SCHEDULE
		if($device->get('cdd_schedule_id')){
			$cd->deleteSchedule($device->get('cdd_schedule_id'));
			$device->set('cdd_schedule_id', NULL);
			$device->save();
		}

		//NOW DELETE THE DEVICE
		$result = $cd->deleteDevice($device->get('cdd_device_id'));		
		$cd_profile_id_primary = $device->get('cdd_profile_id_primary');
		$profile_id_primary = $device->get('cdd_cdp_ctldprofile_id_primary');
		$cd_profile_id_secondary = $device->get('cdd_profile_id_secondary');
		$profile_id_secondary = $device->get('cdd_cdp_ctldprofile_id_secondary');
		$device->permanent_delete();			
		
		//DELETE THE PROFILES 
		if($cd_profile_id_primary)){
			$result = $cd->deleteProfile($cd_profile_id_primary);
			$profile = new CtldProfile($profile_id_primary, TRUE);


			$filters = new MultiCtldFilter(
					array(
						'profile_id' => $profile_id_primary,
					),
				);
				$filters->load();

			foreach($filters as $filter){
				$filter->permanent_delete();
			}
			
			$services = new MultiCtldService(
					array(
						'profile_id' => $profile_id_primary,
					),
				);
				$services->load();
			foreach($services as $service){
				$service->permanent_delete();
			}

			$profile->permanent_delete();
			
		}

		if($cd_profile_id_secondary){
			$result = $cd->deleteProfile($cd_profile_id_secondary);
			$profile = new CtldProfile($profile_id_secondary, TRUE);
			
			$filters = new MultiCtldFilter(
					array(
						'profile_id' => $profile_id_secondary,
					),
				);
				$filters->load();

			foreach($filters as $filter){
				$filter->permanent_delete();
			}
			
			$services = new MultiCtldService(
					array(
						'profile_id' => $profile_id_secondary,
					),
				);
				$services->load();
			foreach($services as $service){
				$service->permanent_delete();
			}			
			
			
			$profile->permanent_delete();
		}	


		LibraryFunctions::redirect('/profile');
		exit;
	}
	
	return $page_vars;
}
	
?>
