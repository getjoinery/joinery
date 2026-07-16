<?php
/**
 * Google Play Real-Time Developer Notifications endpoint
 * (/ajax/play_rtdn_webhook).
 *
 * Google Cloud Pub/Sub push-delivers RTDNs here with an OIDC bearer token,
 * verified against Google's signing keys and the store_play_rtdn_audience setting
 * before anything is trusted. The RTDN itself carries no subscription state —
 * the current state is fetched from the Play Developer API and applied.
 * WebhookLog provides idempotency, mirroring the Stripe webhook. Provider
 * webhooks are the documented /ajax/ exception (docs/api.md).
 *
 * @version 1.0.0
 */
require_once(PathHelper::getIncludePath('plugins/store/includes/GooglePlayHelper.php'));
require_once(PathHelper::getIncludePath('plugins/store/includes/MobileBilling.php'));
require_once(PathHelper::getIncludePath('data/webhook_logs_class.php'));

$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (stripos($auth_header, 'Bearer ') !== 0) {
    http_response_code(401);
    echo 'Missing bearer token';
    exit();
}

try {
    GooglePlayHelper::verifyRtdnBearer(trim(substr($auth_header, 7)));
} catch (GooglePlayHelperException $e) {
    error_log('Play RTDN bearer verification failed: ' . $e->getMessage());
    http_response_code(401);
    echo 'Bearer verification failed';
    exit();
}

$raw_body = file_get_contents('php://input');
$body = json_decode($raw_body, true);
$message = is_array($body) ? ($body['message'] ?? null) : null;

if (!is_array($message) || empty($message['data'])) {
    http_response_code(400);
    echo 'Invalid Pub/Sub envelope';
    exit();
}

$message_id = $message['messageId'] ?? null;
$event_id = $message_id ? ('rtdn:' . $message_id) : null;

// Idempotency (Pub/Sub redelivers until acknowledged)
if ($event_id && WebhookLog::isDuplicate($event_id)) {
    http_response_code(200);
    exit();
}

$notification = json_decode(base64_decode($message['data']), true);
if (!is_array($notification)) {
    http_response_code(400);
    echo 'Invalid notification data';
    exit();
}

$package_name = $notification['packageName'] ?? '';
$event_label = 'unknown';
$processed = false;
$error_message = null;
$result_message = null;

try {
    if (isset($notification['testNotification'])) {
        $event_label = 'test';
        $processed = true;
        $result_message = 'Test notification';
    } elseif (!in_array($package_name, GooglePlayHelper::allowedPackageNames())) {
        $event_label = 'rejected';
        $result_message = 'Package not allowed: ' . $package_name;
    } elseif (isset($notification['subscriptionNotification'])) {
        $sub_notification = $notification['subscriptionNotification'];
        $notification_type = (int)($sub_notification['notificationType'] ?? 0);
        $purchase_token = $sub_notification['purchaseToken'] ?? '';
        $event_label = 'subscription.' . $notification_type;

        if (!$purchase_token) {
            $result_message = 'Notification carries no purchase token';
        } else {
            // The RTDN is only a poke — fetch authoritative state.
            $purchase = GooglePlayHelper::getSubscriptionPurchase($package_name, $purchase_token);
            $result = MobileBilling::applyPlayEvent($notification_type, $package_name, $purchase_token, $purchase);
            $processed = $result['processed'];
            $result_message = $result['message'];
        }
    } else {
        // voidedPurchaseNotification / oneTimeProductNotification — not
        // subscription billing; acknowledged and logged.
        $event_label = 'other';
        $processed = true;
        $result_message = 'Non-subscription notification';
    }
} catch (Exception $e) {
    $error_message = $e->getMessage();
    error_log('Play RTDN processing error for ' . $event_label . ': ' . $error_message);
}

if (!$processed && $error_message === null && $result_message !== null) {
    $error_message = $result_message;
}

WebhookLog::logEvent(
    'play_store',
    $event_label,
    $event_id,
    json_encode($notification),
    $processed,
    $processed ? null : $error_message
);

http_response_code(200);
?>
