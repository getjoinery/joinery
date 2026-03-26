<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/StripeHelper.php'));
require_once(PathHelper::getIncludePath('data/events_class.php'));
require_once(PathHelper::getIncludePath('data/orders_class.php'));
require_once(PathHelper::getIncludePath('data/order_items_class.php'));
require_once(PathHelper::getIncludePath('data/webhook_logs_class.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

try {
    // StripeHelper handles ALL Stripe setup internally
    $stripe_helper = new StripeHelper();
    $event = $stripe_helper->process_webhook();

} catch(StripeHelperException $e) {
    // Stripe configuration errors
    error_log("Stripe webhook configuration error: " . $e->getMessage());
    http_response_code(500);
    echo 'Stripe configuration error';
    exit();
} catch(\UnexpectedValueException $e) {
    // Invalid payload
    http_response_code(400);
    echo 'Invalid payload';
    exit();
} catch(\Stripe\Exception\SignatureVerificationException $e) {
    // Invalid signature
    http_response_code(400);
    echo 'Invalid signature';
    exit();
} catch(\Exception $e) {
    // Any other unexpected errors
    error_log("Stripe webhook unexpected error: " . $e->getMessage());
    http_response_code(500);
    echo 'Webhook processing error';
    exit();
}

// Idempotency check
$event_id = $event->id ?? null;
if ($event_id && WebhookLog::isDuplicate($event_id)) {
    error_log("Stripe webhook: duplicate event $event_id, skipping");
    http_response_code(200);
    exit();
}

$event_type = $event->type;
$error_message = null;

try {
    switch ($event_type) {
        case 'checkout.session.completed':
            $sessionobject = $event->data->object;

            $order = new Order(NULL);
            $order->set('ord_total_cost', $sessionobject->amount_total / 100);
            if ($sessionobject->client_reference_id) {
                $order->set('ord_usr_user_id', $sessionobject->client_reference_id);
            }
            $order->set('ord_stripe_session_id', $sessionobject->id);
            $order->set('ord_raw_response', $sessionobject);
            $order->set('ord_stripe_payment_intent_id', $sessionobject->payment_intent);
            $order->set('ord_stripe_subscription_id_temp', $sessionobject->subscription);
            $order->set('ord_status', Order::STATUS_PAID);

            $order->prepare();
            $order->save();
            break;

        case 'customer.subscription.updated':
            $subscription = $event->data->object;
            $subscription_id = $subscription->id;

            $order_items = new MultiOrderItem(['odi_stripe_subscription_id' => $subscription_id]);
            $order_items->load();

            if ($order_items->count() > 0) {
                $order_item = $order_items->get(0);

                // Sync subscription status
                $status = $subscription->status; // active, past_due, canceled, unpaid, etc.
                $order_item->set('odi_subscription_status', $status);

                if ($subscription->current_period_end) {
                    $order_item->set('odi_subscription_period_end', date('Y-m-d H:i:s', $subscription->current_period_end));
                }

                if ($subscription->cancel_at_period_end) {
                    $order_item->set('odi_subscription_cancel_at_period_end', true);
                } else {
                    $order_item->set('odi_subscription_cancel_at_period_end', false);
                }

                $order_item->save();

                // Trigger tier validation
                $user_id = $order_item->get('odi_usr_user_id');
                if ($user_id) {
                    SubscriptionTier::GetUserTier($user_id);
                }

                error_log("Stripe webhook: subscription $subscription_id updated, status: $status");
            } else {
                error_log("Stripe webhook: no order item found for subscription $subscription_id");
            }
            break;

        case 'customer.subscription.deleted':
            $subscription = $event->data->object;
            $subscription_id = $subscription->id;

            $order_items = new MultiOrderItem(['odi_stripe_subscription_id' => $subscription_id]);
            $order_items->load();

            if ($order_items->count() > 0) {
                $order_item = $order_items->get(0);
                $order_item->set('odi_subscription_status', 'canceled');
                $order_item->set('odi_subscription_cancelled_time', gmdate('Y-m-d H:i:s'));
                $order_item->save();

                // Handle tier expiration
                $user_id = $order_item->get('odi_usr_user_id');
                if ($user_id) {
                    SubscriptionTier::handleSubscriptionExpired($user_id);
                }

                error_log("Stripe webhook: subscription $subscription_id deleted");
            } else {
                error_log("Stripe webhook: no order item found for subscription $subscription_id");
            }
            break;

        case 'invoice.payment_succeeded':
            $invoice = $event->data->object;
            $subscription_id = $invoice->subscription;

            if ($subscription_id) {
                $order_items = new MultiOrderItem(['odi_stripe_subscription_id' => $subscription_id]);
                $order_items->load();

                if ($order_items->count() > 0) {
                    $order_item = $order_items->get(0);
                    $order_item->set('odi_subscription_status', 'active');

                    if ($invoice->lines && $invoice->lines->data) {
                        foreach ($invoice->lines->data as $line) {
                            if ($line->period && $line->period->end) {
                                $order_item->set('odi_subscription_period_end', date('Y-m-d H:i:s', $line->period->end));
                                break;
                            }
                        }
                    }

                    $order_item->save();
                    error_log("Stripe webhook: payment succeeded for subscription $subscription_id");
                }
            }
            break;

        case 'invoice.payment_failed':
            $invoice = $event->data->object;
            $subscription_id = $invoice->subscription;

            if ($subscription_id) {
                $order_items = new MultiOrderItem(['odi_stripe_subscription_id' => $subscription_id]);
                $order_items->load();

                if ($order_items->count() > 0) {
                    $order_item = $order_items->get(0);
                    $order_item->set('odi_subscription_status', 'past_due');
                    $order_item->save();

                    // Send payment failure email (with dedup)
                    $user_id = $order_item->get('odi_usr_user_id');
                    if ($user_id) {
                        _stripe_webhook_send_payment_failure_email($user_id, $order_item);
                    }

                    error_log("Stripe webhook: payment failed for subscription $subscription_id");
                }
            }
            break;

        default:
            error_log("Stripe webhook: unhandled event type $event_type");
            break;
    }
} catch (Exception $e) {
    $error_message = $e->getMessage();
    error_log("Stripe webhook processing error for $event_type: " . $error_message);
}

// Log the event
WebhookLog::logEvent(
    'stripe',
    $event_type,
    $event_id,
    json_encode($event->data->object ?? null),
    ($error_message === null),
    $error_message
);

http_response_code(200);

/**
 * Send payment failure email to user with dedup check
 */
function _stripe_webhook_send_payment_failure_email($user_id, $order_item) {
    // Check for recent payment failure email
    if (WebhookLog::hasRecentPaymentFailure('stripe', 'invoice.payment_failed')) {
        error_log("Stripe webhook: skipping payment failure email, recent one exists");
        return;
    }

    try {
        require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
        require_once(PathHelper::getIncludePath('data/users_class.php'));

        $user = new User($user_id, TRUE);
        if (!$user->key) return;

        $tier = SubscriptionTier::GetUserTier($user_id);
        $tier_name = $tier ? $tier->get('sbt_display_name') : 'your current plan';

        $product = null;
        if ($order_item->get('odi_pro_product_id')) {
            $product = new Product($order_item->get('odi_pro_product_id'), TRUE);
        }
        $billing_amount = $product ? $product->get('pro_price') : '';
        if ($order_item->get('odi_price')) {
            $billing_amount = $order_item->get('odi_price');
        }

        EmailSender::sendTemplate('subscription_payment_failed', $user->get('usr_email'), [
            'recipient' => $user->export_as_array(),
            'tier_name' => $tier_name,
            'billing_amount' => number_format((float)$billing_amount, 2),
        ]);
    } catch (Exception $e) {
        error_log("Stripe webhook: failed to send payment failure email: " . $e->getMessage());
    }
}
?>
