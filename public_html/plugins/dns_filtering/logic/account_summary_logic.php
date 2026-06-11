<?php
/**
 * The acting user's subscription summary for ScrollDaddy clients: tier name,
 * the five ScrollDaddy feature flags, and device count vs. limit. Clients
 * use the flags to render locked states; the server enforces every gate
 * regardless.
 *
 * Exposed as POST /api/v1/action/dns_filtering/account_summary.
 *
 * @version 1.0
 */

function account_summary_logic(array $input): LogicResult{
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('plugins/dns_filtering/data/devices_class.php'));

	$session = SessionControl::get_instance();

	$user_id = $session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Not logged in.');
	}

	$tier = SubscriptionTier::GetUserTier($user_id);

	$max_devices = (int)SubscriptionTier::getUserFeature($user_id, 'scrolldaddy_max_devices', 0);

	$devices = new MultiSdDevice(array(
		'user_id' => $user_id,
		'deleted' => false,
	));
	$device_count = $devices->count_all();

	return LogicResult::render(array(
		'tier_name' => $tier ? $tier->get('sbt_name') : null,
		'features' => array(
			'scrolldaddy_max_devices' => $max_devices,
			'scrolldaddy_max_scheduled_blocks' => (int)SubscriptionTier::getUserFeature($user_id, 'scrolldaddy_max_scheduled_blocks', 1),
			'scrolldaddy_custom_rules' => (bool)SubscriptionTier::getUserFeature($user_id, 'scrolldaddy_custom_rules', false),
			'scrolldaddy_advanced_filters' => (bool)SubscriptionTier::getUserFeature($user_id, 'scrolldaddy_advanced_filters', false),
			'scrolldaddy_query_logging' => (bool)SubscriptionTier::getUserFeature($user_id, 'scrolldaddy_query_logging', false),
		),
		'device_count' => $device_count,
		'device_max' => $max_devices,
	));
}

function account_summary_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Subscription tier, ScrollDaddy feature flags, and device count vs. limit for the acting user',
	];
}

?>
