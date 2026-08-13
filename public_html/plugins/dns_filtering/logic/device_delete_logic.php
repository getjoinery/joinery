<?php

function device_delete_logic(array $input): LogicResult{

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/devices_class.php'));

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

	if(empty($input['device_id'])){
		return LogicResult::error('device_id is required.');
	}

	$device = new SdDevice($input['device_id'], TRUE);
	$device->assert_can_write($session);
	$page_vars['device'] = $device;

	if(isset($_POST['confirm'])){
		$device->permanent_delete();

		return LogicResult::redirect('/profile/dns_filtering/devices');
	}

	return LogicResult::render($page_vars);
}

function device_delete_logic_descriptor() {
	return [
		'requires_session' => true,
		'description' => 'Permanently delete a DNS-filtering device (pass device_id and confirm=1)',
	];
}

?>
