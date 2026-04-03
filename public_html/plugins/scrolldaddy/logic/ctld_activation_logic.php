<?php

function ctld_activation_logic($get_vars, $post_vars){
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/ctlddevices_class.php'));
	require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/ctldfilters_class.php'));

	$page_vars = array();

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;
	$session->check_permission(0);
	$session->set_return();

	$device_id = LibraryFunctions::fetch_variable('device_id', NULL, 1, 'You must pass a device_id');

	$user = new User($session->get_user_id(), TRUE);
	$page_vars['user'] = $user;

	$tier = SubscriptionTier::GetUserTier($user->key);
	$page_vars['tier'] = $tier;

	$device = new CtldDevice($device_id, TRUE);
	$page_vars['device'] = $device;

	$dns_host = $settings->get_setting('scrolldaddy_dns_host');
	$resolver_uid = $device->get('cdd_resolver_uid');

	// Build DoH URL for this device
	$doh_url = '';
	$dot_hostname = '';
	if($dns_host && $resolver_uid){
		$doh_url = 'https://' . $dns_host . '/resolve/' . $resolver_uid;
		$dot_hostname = $resolver_uid . '.' . $dns_host;
	}

	$page_vars['doh_url'] = $doh_url;
	$page_vars['dot_hostname'] = $dot_hostname;
	$page_vars['resolver_uid'] = $resolver_uid;

	return LogicResult::render($page_vars);
}

?>
