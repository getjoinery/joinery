<?php
/**
 * App Store Server Notifications V2 endpoint (/ajax/app_store_webhook).
 *
 * Apple POSTs {"signedPayload": "<JWS>"}. The JWS (and the inner
 * signedTransactionInfo) is verified against the pinned Apple root before
 * anything is trusted; WebhookLog provides idempotency, mirroring the Stripe
 * webhook. Provider webhooks are the documented /ajax/ exception — they
 * authenticate by payload signature, not session (docs/api.md).
 *
 * @version 1.0.0
 */
require_once(PathHelper::getIncludePath('plugins/store/includes/AppStoreHelper.php'));
require_once(PathHelper::getIncludePath('plugins/store/includes/MobileBilling.php'));
require_once(PathHelper::getIncludePath('data/webhook_logs_class.php'));

$raw_body = file_get_contents('php://input');
$body = json_decode($raw_body, true);

if (!is_array($body) || empty($body['signedPayload'])) {
    http_response_code(400);
    echo 'Invalid payload';
    exit();
}

try {
    $payload = AppStoreHelper::verifySignedPayload($body['signedPayload']);
} catch (AppStoreHelperException $e) {
    error_log('App Store webhook signature failure: ' . $e->getMessage());
    http_response_code(401);
    echo 'Signature verification failed';
    exit();
}

$notification_type = $payload['notificationType'] ?? '';
$subtype = $payload['subtype'] ?? null;
$notification_uuid = $payload['notificationUUID'] ?? null;
$data = $payload['data'] ?? array();

// Idempotency (Apple retries undelivered notifications)
if ($notification_uuid && WebhookLog::isDuplicate($notification_uuid)) {
    http_response_code(200);
    exit();
}

$event_label = $notification_type . ($subtype ? '.' . $subtype : '');
$processed = false;
$error_message = null;
$result_message = null;

try {
    $bundle_id = $data['bundleId'] ?? '';
    $environment = $data['environment'] ?? 'Production';

    if (!in_array($bundle_id, AppStoreHelper::allowedBundleIds())) {
        $result_message = 'Bundle ID not allowed: ' . $bundle_id;
    } elseif (!AppStoreHelper::environmentAllowed($environment)) {
        // Sandbox notifications hitting a production deployment are
        // acknowledged but never touch billing state.
        $result_message = 'Environment not accepted: ' . $environment;
    } elseif ($notification_type === 'TEST') {
        $processed = true;
        $result_message = 'Test notification';
    } elseif (empty($data['signedTransactionInfo'])) {
        $result_message = 'Notification carries no transaction';
    } else {
        $transaction = AppStoreHelper::verifySignedPayload($data['signedTransactionInfo']);
        $renewal = null;
        if (!empty($data['signedRenewalInfo'])) {
            $renewal = AppStoreHelper::verifySignedPayload($data['signedRenewalInfo']);
        }

        $result = MobileBilling::applyAppStoreEvent($notification_type, $subtype, $transaction, $renewal);
        $processed = $result['processed'];
        $result_message = $result['message'];
    }
} catch (Exception $e) {
    $error_message = $e->getMessage();
    error_log('App Store webhook processing error for ' . $event_label . ': ' . $error_message);
}

if (!$processed && $error_message === null && $result_message !== null) {
    $error_message = $result_message;
}

WebhookLog::logEvent(
    'app_store',
    $event_label,
    $notification_uuid,
    json_encode($payload),
    $processed,
    $processed ? null : $error_message
);

http_response_code(200);
?>
