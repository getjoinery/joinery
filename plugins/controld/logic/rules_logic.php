<?php

function rules_logic($get_vars, $post_vars){
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/includes/ControlDHelper.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldaccounts_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctlddevices_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldprofiles_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldrules_class.php');
	
	
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
	if(isset($_POST['action']) && $_POST['action'] == 'delete'){
	
		$profile_choice = LibraryFunctions::fetch_variable_local($post_vars, 'profile_choice', 0, 'required', 'Profile choice is required.', 'safemode', NULL);
		$rule_id = LibraryFunctions::fetch_variable_local($post_vars, 'rule_id', 0, 'required', 'Rule choice is required.', 'safemode', NULL);
		$page_vars['profile_choice'] = $profile_choice;
	
		$device_id = LibraryFunctions::fetch_variable_local($post_vars, 'device_id', NULL, 'required', 'Device id is required.', 'safemode', 'int');
		$device = new CtldDevice($device_id, TRUE);
		$device->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$page_vars['device'] = $device;
		
		if($profile_choice == 'primary'){
			$profile = new CtldProfile($device->get('cdd_cdp_ctldprofile_id_primary'), TRUE);
		}
		else{
			$profile = new CtldProfile($device->get('cdd_cdp_ctldprofile_id_secondary'), TRUE);
		}		
		$page_vars['profile'] = $profile;	
	
		$result = $profile->delete_rule($rule_id);
		
		LibraryFunctions::redirect('/profile/rules?device_id='.$device->key.'&profile_choice='.$profile_choice);
	

	}
	else if(isset($_POST['cdr_rule_hostname'])){

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
			$profile = new CtldProfile($device->get('cdd_cdp_ctldprofile_id_secondary'), TRUE);
		}		
		$page_vars['profile'] = $profile;

		$result = $profile->add_rule($_POST['cdr_rule_hostname'], $_POST['cdr_rule_action']);

		LibraryFunctions::redirect('/profile/rules?device_id='.$device->key.'&profile_choice='.$profile_choice);
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
			$profile = new CtldProfile($device->get('cdd_cdp_ctldprofile_id_secondary'), TRUE);
		}
		$page_vars['profile'] = $profile;
		
		$rules = new MultiCtldRule(
				array(
					'profile_id' => $profile->key,
				),
			);
			$rules->load();

		//$page_vars['num_filters'] = $num_devices;
		//$rules_out = array();
		//foreach($rules as $rule){
		//	$filter_out[$filter->get('cdf_filter_pk')] = $filter->get('cdf_is_active');
		//}

		
		$page_vars['rules'] = $rules;

			
		
	}
	
	
	
	return $page_vars;
}
	
?>
