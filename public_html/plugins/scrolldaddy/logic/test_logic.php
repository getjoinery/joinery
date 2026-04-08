<?php

function test_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/devices_class.php'));

	$page_vars = array();

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	if (!$session->is_logged_in()) {
		return LogicResult::redirect('/login');
	}
	$session->check_permission(0);

	$device_id = isset($get_vars['device_id']) ? (int)$get_vars['device_id'] : 0;
	if (!$device_id) {
		return LogicResult::redirect('/profile/scrolldaddy/devices');
	}

	try {
		$device = new SdDevice($device_id, TRUE);
		$device->authenticate_read(array(
			'current_user_id'         => $session->get_user_id(),
			'current_user_permission' => $session->get_permission(),
		));
	} catch (Exception $e) {
		return LogicResult::redirect('/profile/scrolldaddy/devices');
	}

	if (!$device->key) {
		return LogicResult::redirect('/profile/scrolldaddy/devices');
	}

	$page_vars['device'] = $device;

	$tier = SubscriptionTier::GetUserTier($session->get_user_id());
	$page_vars['tier'] = $tier;
	$page_vars['can_add_rules'] = $tier
		&& $tier->getFeature('scrolldaddy_custom_rules', false)
		&& $device->are_filters_editable();

	return LogicResult::render($page_vars);
}

?>
