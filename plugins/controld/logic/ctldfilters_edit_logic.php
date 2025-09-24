<?php

function ctldfilters_edit_logic($get_vars, $post_vars){
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

	
	if(isset($_POST['action'])){
		$profile_choice = LibraryFunctions::fetch_variable_local($post_vars, 'profile_choice', 0, 'required', 'Profile choice is required.', 'safemode', NULL);
		$page_vars['profile_choice'] = $profile_choice;
	
		$device_id = LibraryFunctions::fetch_variable_local($post_vars, 'device_id', NULL, 'required', 'Device id is required.', 'safemode', 'int');
		$device = new CtldDevice($device_id, TRUE);
		$device->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$page_vars['device'] = $device;
		
		if($profile_choice == 'primary'){
			$profile = new CtldProfile($device->get('cdd_cdp_ctldprofile_id_primary'), TRUE);
		}
		else{
			//CREATE THE SECOND PROFILE IF NEEEDED (SCHEDULED STUFF)
			if($device->get('cdd_cdp_ctldprofile_id_secondary')){
				$profile = new CtldProfile($device->get('cdd_cdp_ctldprofile_id_secondary'), TRUE);
			}
			else{
				$profile_name = 'user'.$user->key . '-'.$empty_device->key.'-profile2';
				$profile = CtldProfile::createProfile($profile_name, $user);
				$device->set('cdd_cdp_ctldprofile_id_secondary', $profile->key);
				$device->set('cdd_profile_id_secondary', $profile->get('cdp_profile_id'));
				$device->save();
			}
		}		
		$page_vars['profile'] = $profile;

		
		//CHANGE DROPDOWN STRUCTURE
		if(isset($_POST['block_malware'])){
			if($_POST['block_malware'] != 0){
				$new_key = 'block_'.$_POST['block_malware'];
				$_POST['block_'.$_POST['block_malware']] = 1;
				$_POST['block_malware'] = 0;
			}
		}
		
		if(isset($_POST['block_ads'])){
			if($_POST['block_ads'] != 0){
				$new_key = 'block_'.$_POST['block_ads'];
				$_POST['block_'.$_POST['block_ads']] = 1;
				$_POST['block_ads'] = 0;
			}
		}
		
		
		if($profile == 'primary'){

			//NOW FIGURE OUT WHAT UPDATES WE HAVE TO THE FILTERS
			$profile->update_remote_filters($_POST);
			$profile->update_remote_services($_POST);

		}
		else{
			
			//NOW FIGURE OUT WHAT UPDATES WE HAVE TO THE FILTERS
			$profile->update_remote_filters($_POST);
			$profile->update_remote_services($_POST);
					
					
			$result = $profile->add_or_edit_schedule($device, $_POST);

			
		}
		LibraryFunctions::redirect('/profile/devices');
		exit;
	}
	else{
		$profile_choice = LibraryFunctions::fetch_variable_local($get_vars, 'profile_choice', 0, 'required', 'Profile choice is required.', 'safemode', NULL);
		$page_vars['profile_choice'] = $profile_choice;
		
		$device_id = LibraryFunctions::fetch_variable_local($get_vars, 'device_id', NULL, 'required', 'Device id is required.', 'safemode', 'int');
		$device = new CtldDevice($device_id, TRUE);
		$device->authenticate_read(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$page_vars['device'] = $device;
			
		if($profile_choice == 'primary'){
			$profile = new CtldProfile($device->get('cdd_cdp_ctldprofile_id_primary'), TRUE);
		}
		else{
			if($device->get('cdd_cdp_ctldprofile_id_secondary')){
				$profile = new CtldProfile($device->get('cdd_cdp_ctldprofile_id_secondary'), TRUE);
			}
			else{
				$profile = new CtldProfile(NULL);
			}
		}
		$page_vars['profile'] = $profile;
		
		$filters = new MultiCtldFilter(
				array(
					'profile_id' => $profile->key,
				),
			);
			//$num_filters = $filters->count_all();
			$filters->load();

		//$page_vars['num_filters'] = $num_devices;
		$filter_out = array();

	
		
		
		
		foreach($filters as $filter){
			$filter_out[$filter->get('cdf_filter_pk')] = $filter->get('cdf_is_active');
		}
		

		//DROPDOWN FORMATTING
		if($filter_out['ads']){
			$filter_out['ads'] = 'ads';
		}
		else if($filter_out['ads_medium']){
			$filter_out['ads'] = 'ads_medium';
		}
		else if($filter_out['ads_small']){
			$filter_out['ads'] = 'ads_small';
		}	

		//DROPDOWN FORMATTING
		if($filter_out['malware']){
			$filter_out['malware'] = 'malware';
		}
		else if($filter_out['ip_malware']){
			$filter_out['malware'] = 'ip_malware';
		}
		else if($filter_out['ai_malware']){
			$filter_out['malware'] = 'ai_malware';
		}
		
		$page_vars['filters'] = $filter_out;

		$services = new MultiCtldService(
				array(
					'profile_id' => $profile->key,
				),
			);
			//$num_services = $services->count_all();
			$services->load();

		//$page_vars['num_services'] = $num_devices;
		$service_out = array();
		foreach($services as $service){
			$service_out[$service->get('cds_service_pk')] = $service->get('cds_is_active');
		}

		$page_vars['services'] = $service_out;
			
		
	}
	
	
	
	return LogicResult::render($page_vars);
}
	
?>
