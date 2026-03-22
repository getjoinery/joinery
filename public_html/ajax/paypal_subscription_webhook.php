<?php
/**
 * PayPal Subscription Webhook Handler
 *
 * Receives BILLING.SUBSCRIPTION.* events from PayPal.
 * Responds HTTP 200 immediately, then processes the event.
 */
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/PaypalHelper.php'));
require_once(PathHelper::getIncludePath('data/order_items_class.php'));

// Read payload before responding
$body = file_get_contents('php://input');
$event = json_decode($body, true);

// Respond 200 immediately to avoid PayPal's 30-second timeout
http_response_code(200);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// Log for debugging
error_log("PayPal webhook received: " . ($event['event_type'] ?? 'unknown'));

if (!$event || !isset($event['event_type'])) {
    error_log("PayPal webhook: invalid payload");
    exit;
}

// Verify webhook signature
try {
    $paypal = new PaypalHelper();
    $headers = array(
        'PAYPAL-AUTH-ALGO' => $_SERVER['HTTP_PAYPAL_AUTH_ALGO'] ?? '',
        'PAYPAL-CERT-URL' => $_SERVER['HTTP_PAYPAL_CERT_URL'] ?? '',
        'PAYPAL-TRANSMISSION-ID' => $_SERVER['HTTP_PAYPAL_TRANSMISSION_ID'] ?? '',
        'PAYPAL-TRANSMISSION-SIG' => $_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG'] ?? '',
        'PAYPAL-TRANSMISSION-TIME' => $_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME'] ?? '',
    );

    $settings = Globalvars::get_instance();
    $webhook_id = StripeHelper::isTestMode()
        ? $settings->get_setting('paypal_webhook_id_test')
        : $settings->get_setting('paypal_webhook_id');

    // Only verify if webhook ID is configured (skip during initial setup)
    if ($webhook_id && !$paypal->verify_webhook_signature($headers, $body)) {
        error_log("PayPal webhook: signature verification failed");
        exit;
    }
} catch (Exception $e) {
    error_log("PayPal webhook: verification error: " . $e->getMessage());
    exit;
}

// Idempotency: check if we've already processed this event
$event_id = $event['id'] ?? null;
if ($event_id) {
    $dbconnector = DbConnector::get_instance();
    $dblink = $dbconnector->get_db_link();

    // Create tracking table if it doesn't exist (one-time)
    $dblink->exec("CREATE TABLE IF NOT EXISTS paypal_webhook_events (
        event_id VARCHAR(64) PRIMARY KEY,
        processed_time TIMESTAMP DEFAULT NOW()
    )");

    $q = $dblink->prepare("SELECT 1 FROM paypal_webhook_events WHERE event_id = ?");
    $q->execute([$event_id]);
    if ($q->fetch()) {
        error_log("PayPal webhook: duplicate event $event_id, skipping");
        exit;
    }

    // Mark as processed
    $q = $dblink->prepare("INSERT INTO paypal_webhook_events (event_id) VALUES (?)");
    $q->execute([$event_id]);
}

// Extract subscription ID from resource
$subscription_id = $event['resource']['id'] ?? null;
if (!$subscription_id) {
    error_log("PayPal webhook: no resource.id in event");
    exit;
}

// Find the order item with this PayPal subscription ID
$order_items = new MultiOrderItem(
    array('odi_paypal_subscription_id' => $subscription_id),
    array('order_item_id' => 'DESC'),
    1
);

// Check if the filter key exists in MultiOrderItem
try {
    $order_items->load();
} catch (Exception $e) {
    // Fallback: direct query if the Multi class doesn't support this filter
    $dbconnector = DbConnector::get_instance();
    $dblink = $dbconnector->get_db_link();
    $q = $dblink->prepare("SELECT odi_order_item_id FROM odi_order_items WHERE odi_paypal_subscription_id = ? LIMIT 1");
    $q->execute([$subscription_id]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        error_log("PayPal webhook: no order item found for subscription $subscription_id");
        exit;
    }
    $order_item = new OrderItem($row['odi_order_item_id'], TRUE);
    $order_items = null; // Skip the iterator below
}

if ($order_items !== null) {
    if ($order_items->count() === 0) {
        error_log("PayPal webhook: no order item found for subscription $subscription_id");
        exit;
    }
    $order_item = $order_items->get(0);
}

// Handle the event
$event_type = $event['event_type'];
$now = gmdate('Y-m-d H:i:s');

switch ($event_type) {
    case 'BILLING.SUBSCRIPTION.ACTIVATED':
        $order_item->set('odi_subscription_status', 'active');
        $order_item->save();
        error_log("PayPal webhook: subscription $subscription_id activated");
        break;

    case 'BILLING.SUBSCRIPTION.CANCELLED':
        $order_item->set('odi_subscription_status', 'cancelled');
        $order_item->set('odi_subscription_cancelled_time', $now);
        $order_item->set('odi_subscription_cancel_at_period_end', true);
        $order_item->save();
        error_log("PayPal webhook: subscription $subscription_id cancelled");
        break;

    case 'BILLING.SUBSCRIPTION.SUSPENDED':
        $order_item->set('odi_subscription_status', 'suspended');
        $order_item->save();
        error_log("PayPal webhook: subscription $subscription_id suspended");
        break;

    case 'BILLING.SUBSCRIPTION.EXPIRED':
        $order_item->set('odi_subscription_status', 'expired');
        $order_item->save();
        error_log("PayPal webhook: subscription $subscription_id expired");
        break;

    case 'BILLING.SUBSCRIPTION.RE-ACTIVATED':
        $order_item->set('odi_subscription_status', 'active');
        $order_item->set('odi_subscription_cancelled_time', null);
        $order_item->set('odi_subscription_cancel_at_period_end', false);
        $order_item->save();
        error_log("PayPal webhook: subscription $subscription_id reactivated");
        break;

    case 'BILLING.SUBSCRIPTION.RENEWED':
        $order_item->set('odi_subscription_status', 'active');
        // Update period end if available in the resource
        if (isset($event['resource']['billing_info']['next_billing_time'])) {
            $next_billing = $event['resource']['billing_info']['next_billing_time'];
            $order_item->set('odi_subscription_period_end', gmdate('Y-m-d H:i:s', strtotime($next_billing)));
        }
        $order_item->save();
        error_log("PayPal webhook: subscription $subscription_id renewed");
        break;

    case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
        $order_item->set('odi_subscription_status', 'past_due');
        $order_item->save();
        error_log("PayPal webhook: subscription $subscription_id payment failed");

        // Notify admin
        $settings = Globalvars::get_instance();
        if ($settings->get_setting('subscription_notification_emails')) {
            require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
            $notify_emails = explode(',', $settings->get_setting('subscription_notification_emails'));
            foreach ($notify_emails as $email) {
                $email = trim($email);
                if ($email) {
                    EmailSender::sendPlain(
                        $email,
                        'PayPal Subscription Payment Failed',
                        "PayPal subscription $subscription_id has a failed payment.\nOrder Item ID: {$order_item->key}\n"
                    );
                }
            }
        }
        break;

    default:
        error_log("PayPal webhook: unhandled event type $event_type for subscription $subscription_id");
        break;
}
