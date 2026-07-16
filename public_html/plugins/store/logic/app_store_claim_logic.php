<?php
/**
 * API action: store/app_store_claim — validate a StoreKit 2 purchase and
 * grant its plan.
 *
 * POST /api/v1/action/store/app_store_claim (session key). The iOS billing
 * kit posts the transaction's jwsRepresentation after Product.purchase() (or
 * during restore). The JWS is verified against the pinned Apple root, the
 * mapped product's tier is granted through TierBilling, and the subscription
 * becomes a normal order item. Idempotent: re-claiming an already-claimed
 * transaction refreshes it.
 *
 * @version 1.0.0
 */

function app_store_claim_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/store/includes/AppStoreHelper.php'));
	require_once(PathHelper::getIncludePath('plugins/store/includes/MobileBilling.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('products_active') || !$settings->get_setting('subscriptions_active')) {
		return LogicResult::error('This feature is turned off');
	}

	$jws = $input['jws'] ?? '';
	if (!$jws) {
		return LogicResult::error('jws is required.');
	}

	try {
		$transaction = AppStoreHelper::verifySignedPayload($jws);
	} catch (AppStoreHelperException $e) {
		error_log('app_store_claim verification failed: ' . $e->getMessage());
		return LogicResult::error('The purchase could not be verified.');
	}

	try {
		$summary = MobileBilling::claimAppStoreTransaction($session->get_user_id(), $transaction);
	} catch (MobileBillingException $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render($summary);
}

function app_store_claim_logic_descriptor(): array {
	return [
		'description'      => 'Validate a StoreKit 2 transaction JWS and grant the mapped subscription plan.',
		'requires_session' => true,
		'mutates'          => true,
		'ai_agent'         => 'confirm',
		'input'            => [
			'jws' => ['type' => 'text', 'required' => true, 'label' => 'Signed transaction (jwsRepresentation)'],
		],
	];
}

?>
