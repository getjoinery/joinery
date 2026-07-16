<?php
/**
 * API action: store/play_claim — validate a Google Play purchase and grant
 * its plan.
 *
 * POST /api/v1/action/store/play_claim (session key). The Android billing
 * kit posts the purchase token after the Play Billing flow completes (or
 * during restore). The server fetches the authoritative purchase state from
 * the Play Developer API — the client-supplied token is never trusted
 * directly — grants the mapped product's tier through TierBilling, and
 * acknowledges the purchase. Idempotent: re-claiming refreshes.
 *
 * @version 1.0.0
 */

function play_claim_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/store/includes/GooglePlayHelper.php'));
	require_once(PathHelper::getIncludePath('plugins/store/includes/MobileBilling.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('products_active') || !$settings->get_setting('subscriptions_active')) {
		return LogicResult::error('This feature is turned off');
	}

	$purchase_token = $input['purchase_token'] ?? '';
	$package_name = $input['package_name'] ?? '';
	if (!$purchase_token || !$package_name) {
		return LogicResult::error('purchase_token and package_name are required.');
	}

	if (!in_array($package_name, GooglePlayHelper::allowedPackageNames())) {
		return LogicResult::error('This app is not enabled for Google Play billing.');
	}

	try {
		$purchase = GooglePlayHelper::getSubscriptionPurchase($package_name, $purchase_token);
	} catch (GooglePlayHelperException $e) {
		error_log('play_claim verification failed: ' . $e->getMessage());
		return LogicResult::error('The purchase could not be verified.');
	}

	try {
		$summary = MobileBilling::claimPlayPurchase($session->get_user_id(), $package_name, $purchase_token, $purchase);
	} catch (MobileBillingException $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render($summary);
}

function play_claim_logic_descriptor(): array {
	return [
		'description'      => 'Validate a Google Play purchase token and grant the mapped subscription plan.',
		'requires_session' => true,
		'mutates'          => true,
		'ai_agent'         => 'confirm',
		'input'            => [
			'purchase_token' => ['type' => 'text',   'required' => true, 'label' => 'Play purchase token'],
			'package_name'   => ['type' => 'string', 'required' => true, 'label' => 'Android package name'],
		],
	];
}

?>
