<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function billing_logic($get, $post) {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('includes/StripeHelper.php'));
    require_once(PathHelper::getIncludePath('data/users_class.php'));
    require_once(PathHelper::getIncludePath('data/products_class.php'));
    require_once(PathHelper::getIncludePath('data/product_versions_class.php'));
    require_once(PathHelper::getIncludePath('data/order_items_class.php'));
    require_once(PathHelper::getIncludePath('data/orders_class.php'));

    $page_vars = array();
    $settings = Globalvars::get_instance();
    $session = SessionControl::get_instance();

    if (!$session->is_logged_in()) {
        $session->set_return('/profile/billing');
        return LogicResult::redirect('/login');
    }

    $user_id = $session->get_user_id();
    $user = new User($user_id, TRUE);
    $page_vars['user'] = $user;
    $page_vars['settings'] = $settings;

    // Find active subscription
    $current_subscription = null;
    $subscriptions = new MultiOrderItem(
        array('user_id' => $user_id, 'is_active_subscription' => true),
        array('order_item_id' => 'DESC')
    );
    $subscriptions->load();

    if ($subscriptions->count() > 0) {
        $current_subscription = $subscriptions->get(0);
    }
    $page_vars['current_subscription'] = $current_subscription;

    // Determine payment system
    $payment_system = null;
    if ($current_subscription) {
        if ($current_subscription->get('odi_stripe_subscription_id')) {
            $payment_system = 'stripe';
        } elseif ($current_subscription->get('odi_paypal_subscription_id')) {
            $payment_system = 'paypal';
        }
    }
    $page_vars['payment_system'] = $payment_system;

    // Get Stripe customer ID
    $stripe_customer_id = null;
    if ($payment_system === 'stripe' || $user->get('usr_stripe_customer_id') || $user->get('usr_stripe_customer_id_test')) {
        try {
            $stripe_helper = new StripeHelper();
            $stripe_customer_id = $stripe_helper->get_stripe_customer_id($user);
        } catch (Exception $e) {
            // Stripe not configured or customer doesn't exist
        }
    }
    $page_vars['stripe_customer_id'] = $stripe_customer_id;

    // Load payment method info (Stripe only)
    $page_vars['payment_method'] = null;
    if ($stripe_customer_id && $payment_system === 'stripe') {
        try {
            $methods = $stripe_helper->get_payment_methods($stripe_customer_id);
            if ($methods && count($methods->data) > 0) {
                $pm = $methods->data[0];
                $page_vars['payment_method'] = array(
                    'brand' => ucfirst($pm->card->brand),
                    'last4' => $pm->card->last4,
                    'exp_month' => $pm->card->exp_month,
                    'exp_year' => $pm->card->exp_year,
                );
            }
        } catch (Exception $e) {
            // Payment method lookup failed
        }
    }

    // Load current product and alternative billing cycles (Stripe only)
    $page_vars['current_product'] = null;
    $page_vars['current_version'] = null;
    $page_vars['alternative_versions'] = array();
    $page_vars['show_cycle_switcher'] = false;

    if ($current_subscription && $payment_system === 'stripe') {
        $product = new Product($current_subscription->get('odi_pro_product_id'), TRUE);
        $page_vars['current_product'] = $product;

        $current_version = new ProductVersion($current_subscription->get('odi_prv_product_version_id'), TRUE);
        $page_vars['current_version'] = $current_version;

        $current_price_type = $current_version->get('prv_price_type');

        // Find alternative versions with different billing cycles
        if ($current_price_type && $current_price_type !== 'single') {
            $all_versions = $product->get_product_versions(true);
            if ($all_versions) {
                foreach ($all_versions as $version) {
                    $vpt = $version->get('prv_price_type');
                    if ($vpt && $vpt !== 'single' && $vpt !== $current_price_type) {
                        $page_vars['alternative_versions'][] = $version;
                    }
                }
            }
            $page_vars['show_cycle_switcher'] = !empty($page_vars['alternative_versions'])
                && !$current_subscription->get('odi_subscription_cancelled_time');
        }
    }

    // Load invoices
    $page_vars['invoices'] = array();
    if ($stripe_customer_id) {
        try {
            $stripe_invoices = $stripe_helper->get_customer_invoices($stripe_customer_id, 10);
            foreach ($stripe_invoices as $inv) {
                $page_vars['invoices'][] = array(
                    'date' => date('M j, Y', $inv->created),
                    'description' => $inv->lines->data[0]->description ?? 'Subscription payment',
                    'amount' => number_format($inv->amount_paid / 100, 2),
                    'status' => $inv->status,
                    'pdf_url' => $inv->invoice_pdf,
                );
            }
        } catch (Exception $e) {
            // Invoice lookup failed — fall back to local orders
        }
    }

    // Try PayPal transaction history if no Stripe invoices
    if (empty($page_vars['invoices']) && $current_subscription && $current_subscription->get('odi_paypal_subscription_id')) {
        try {
            require_once(PathHelper::getIncludePath('includes/PaypalHelper.php'));
            $paypal = new PaypalHelper();
            $transactions = $paypal->get_subscription_transactions(
                $current_subscription->get('odi_paypal_subscription_id'),
                date('Y-m-d\TH:i:s\Z', strtotime('-1 year')),
                date('Y-m-d\TH:i:s\Z')
            );
            if (!empty($transactions['transactions'])) {
                foreach ($transactions['transactions'] as $txn) {
                    $page_vars['invoices'][] = array(
                        'date' => date('M j, Y', strtotime($txn['time'] ?? $txn['create_time'] ?? 'now')),
                        'description' => $txn['payer_name']['given_name'] ?? 'Subscription payment',
                        'amount' => number_format(floatval($txn['amount_with_breakdown']['gross_amount']['value'] ?? 0), 2),
                        'status' => strtolower($txn['status'] ?? 'completed'),
                        'pdf_url' => null,
                    );
                }
            }
        } catch (Exception $e) {
            // PayPal transaction lookup failed
        }
    }

    // Fall back to local order history if no Stripe or PayPal invoices
    if (empty($page_vars['invoices'])) {
        $orders = new MultiOrder(
            array('user_id' => $user_id),
            array('ord_order_id' => 'DESC'),
            10
        );
        $orders->load();
        foreach ($orders as $order) {
            if ($order->get('ord_status') == Order::STATUS_PAID) {
                $page_vars['invoices'][] = array(
                    'date' => LibraryFunctions::convert_time($order->get('ord_timestamp'), 'UTC', $session->get_timezone(), 'M j, Y'),
                    'description' => 'Order #' . $order->key,
                    'amount' => number_format($order->get('ord_total_cost'), 2),
                    'status' => 'paid',
                    'pdf_url' => null,
                );
            }
        }
    }

    // Handle POST actions
    if (isset($post['action'])) {
        try {
            switch ($post['action']) {
                case 'update_payment_method':
                    if (!$stripe_customer_id) {
                        throw new Exception('No Stripe account found.');
                    }
                    $return_url = LibraryFunctions::get_absolute_url('/profile/billing?payment_updated=1');
                    $portal_session = $stripe_helper->create_billing_portal_session($stripe_customer_id, $return_url);
                    return LogicResult::redirect($portal_session->url);

                case 'change_billing_cycle':
                    if (!$current_subscription || $payment_system !== 'stripe') {
                        throw new Exception('Billing cycle changes are only available for Stripe subscriptions.');
                    }
                    $new_version_id = intval($post['new_version_id']);
                    $new_version = new ProductVersion($new_version_id, TRUE);

                    if (!$new_version->key || $new_version->get('prv_pro_product_id') != $current_subscription->get('odi_pro_product_id')) {
                        throw new Exception('Invalid billing option selected.');
                    }

                    // Get the Stripe price ID for the new version
                    $new_price_id = StripeHelper::isTestMode()
                        ? $new_version->get('prv_stripe_price_id_test')
                        : $new_version->get('prv_stripe_price_id');

                    if (!$new_price_id) {
                        $stripe_helper->get_or_create_price($new_version);
                        $new_price_id = StripeHelper::isTestMode()
                            ? $new_version->get('prv_stripe_price_id_test')
                            : $new_version->get('prv_stripe_price_id');
                    }

                    // Get the subscription item ID from Stripe
                    $stripe_sub = $stripe_helper->get_subscription($current_subscription->get('odi_stripe_subscription_id'));
                    $item_id = $stripe_sub->items->data[0]->id;

                    $stripe_helper->change_subscription(
                        $current_subscription->get('odi_stripe_subscription_id'),
                        $item_id,
                        $new_price_id
                    );

                    // Update local record
                    $current_subscription->set('odi_prv_product_version_id', $new_version->key);
                    $current_subscription->set('odi_amount', $new_version->get('prv_version_price'));
                    $current_subscription->save();

                    $session->save_message(new DisplayMessage(
                        'Your billing cycle has been updated to ' . $new_version->get('prv_version_name') . '.',
                        'Billing Updated',
                        null,
                        DisplayMessage::MESSAGE_SUCCESS
                    ));
                    return LogicResult::redirect('/profile/billing');
            }
        } catch (Exception $e) {
            error_log("Billing page error: " . $e->getMessage());
            $page_vars['error_message'] = $e->getMessage();
        }
    }

    // Success messages from redirects
    if (isset($get['payment_updated'])) {
        $page_vars['success_message'] = 'Your payment method has been updated.';
    }

    $page_vars['display_messages'] = $session->get_messages('/profile/billing');

    return LogicResult::render($page_vars);
}
?>
