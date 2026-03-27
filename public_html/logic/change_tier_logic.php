<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function change_tier_logic($get, $post) {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('includes/StripeHelper.php'));
    require_once(PathHelper::getIncludePath('data/users_class.php'));
    require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
    require_once(PathHelper::getIncludePath('data/products_class.php'));
    require_once(PathHelper::getIncludePath('data/order_items_class.php'));
    require_once(PathHelper::getIncludePath('data/orders_class.php'));
    require_once(PathHelper::getIncludePath('data/change_tracking_class.php'));
    require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

    $page_vars = array();
    $settings = Globalvars::get_instance();
    $session = SessionControl::get_instance();

    // Check if user is logged in
    if (!$session->is_logged_in()) {
        return LogicResult::redirect('/login?return=' . urlencode('/profile/change-tier'));
    }

    if (!$settings->get_setting('products_active') || !$settings->get_setting('subscriptions_active')) {
        return LogicResult::redirect('/profile');
    }

    // Get period filter from GET parameter (default to 'month')
    $period_filter = isset($get['period']) ? $get['period'] : 'month';
    $page_vars['period_filter'] = $period_filter;

    $user_id = $session->get_user_id();
    $user = new User($user_id, TRUE);
    $page_vars['user'] = $user;

    // Get subscription management settings
    $page_vars['settings'] = array(
        'subscription_downgrades_enabled' => $settings->get_setting('subscription_downgrades_enabled') == '1',
        'subscription_downgrade_timing' => $settings->get_setting('subscription_downgrade_timing') ?? 'end_of_period',
        'subscription_cancellation_enabled' => $settings->get_setting('subscription_cancellation_enabled') == '1',
        'subscription_cancellation_timing' => $settings->get_setting('subscription_cancellation_timing') ?? 'end_of_period',
        'subscription_reactivation_enabled' => $settings->get_setting('subscription_reactivation_enabled') == '1',
        'subscription_cancellation_prorate' => $settings->get_setting('subscription_cancellation_prorate') == '1'
    );

    // Get user's current tier
    $current_tier = SubscriptionTier::GetUserTier($user_id);
    $page_vars['current_tier'] = $current_tier;
    $current_tier_level = $current_tier ? $current_tier->get('sbt_tier_level') : 0;

    // Get user's current subscription
    $current_subscription = null;
    $has_active_subscription = false;
    $has_cancelled_subscription = false;
    $is_expired = false;

    // Find active subscription order items
    $subscriptions = new MultiOrderItem(
        array('user_id' => $user_id, 'is_active_subscription' => true),
        array('order_item_id' => 'DESC')
    );
    $subscriptions->load();

    if ($subscriptions->count() > 0) {
        $current_subscription = $subscriptions->get(0);

        // Check subscription status
        if ($current_subscription->check_subscription_status()) {
            $has_active_subscription = true;
        } else {
            $is_expired = true;
        }

        if ($current_subscription->get('odi_subscription_cancelled_time')) {
            $has_cancelled_subscription = true;
            $cancelled_time = strtotime($current_subscription->get('odi_subscription_cancelled_time'));
            if ($cancelled_time < time()) {
                $is_expired = true;
            }
        }
    }

    // Detect PayPal provider
    $is_paypal = false;
    if ($current_subscription) {
        if ($current_subscription->get('odi_paypal_subscription_id')) {
            $is_paypal = true;
        } else {
            // Also check the order's payment method
            $sub_order_id = $current_subscription->get('odi_ord_order_id');
            if ($sub_order_id) {
                $sub_order = new Order($sub_order_id, TRUE);
                if ($sub_order->key) {
                    $payment_method = $sub_order->get('ord_payment_method');
                    if ($payment_method === 'paypal' || $payment_method === 'venmo') {
                        $is_paypal = true;
                    }
                }
            }
        }
    }

    $page_vars['current_subscription'] = $current_subscription;
    $page_vars['has_active_subscription'] = $has_active_subscription;
    $page_vars['has_cancelled_subscription'] = $has_cancelled_subscription;
    $page_vars['is_expired'] = $is_expired;
    $page_vars['is_paypal'] = $is_paypal;

    // Handle POST actions
    if (isset($post['action'])) {

        // Check if user has an active subscription
        if (!$current_subscription) {
            $page_vars['error_message'] = 'No active subscription found. Please purchase a subscription first.';
            return LogicResult::render($page_vars);
        }

        $stripe_helper = new StripeHelper();
        $result = null;
        $error = null;

        try {
            switch ($post['action']) {
                case 'upgrade':
                    // Block upgrade for PayPal subscribers
                    if ($is_paypal) {
                        throw new Exception('PayPal subscriptions cannot be upgraded directly. Please cancel your current subscription and subscribe to a new plan.');
                    }

                    // Validate product selection
                    if (!isset($post['product_id'])) {
                        throw new Exception('Please select a plan to upgrade to.');
                    }

                    // Validate product exists and loads correctly
                    $product = new Product($post['product_id'], TRUE);
                    if (!$product->key) {
                        throw new Exception('The selected plan could not be found. Please try again or contact support.');
                    }

                    if (!$product->get('pro_sbt_subscription_tier_id')) {
                        throw new Exception('The selected product is not associated with a subscription tier. Please contact support.');
                    }

                    // Validate tier exists
                    $new_tier = new SubscriptionTier($product->get('pro_sbt_subscription_tier_id'), TRUE);
                    if (!$new_tier->key) {
                        throw new Exception('The subscription tier for this product could not be found. Please contact support.');
                    }

                    // Verify it's actually an upgrade
                    $current_tier_name = $current_tier ? $current_tier->get('sbt_display_name') : 'Free';
                    $new_tier_name = $new_tier->get('sbt_display_name');

                    if ($new_tier->get('sbt_tier_level') <= $current_tier_level) {
                        throw new Exception("Cannot upgrade from {$current_tier_name} to {$new_tier_name}. The selected plan is not a higher tier than your current plan.");
                    }

                    // Get the new price ID from product
                    $versions = $product->get_product_versions();
                    $product_version = $versions->count() > 0 ? $versions->get(0) : null;

                    if (!$product_version) {
                        throw new Exception('The selected plan does not have any active pricing versions. Please contact support.');
                    }

                    // Get appropriate price ID based on test mode
                    $price_id_field = $stripe_helper->test_mode ? 'prv_stripe_price_id_test' : 'prv_stripe_price_id';
                    $new_price_id = $product_version->get($price_id_field);

                    if (!$new_price_id) {
                        $mode = $stripe_helper->test_mode ? 'test' : 'live';
                        throw new Exception("The selected plan is not configured for {$mode} mode payments. Please contact support.");
                    }

                    // Validate subscription ID exists
                    $subscription_id = $current_subscription->get('odi_stripe_subscription_id');
                    if (!$subscription_id) {
                        throw new Exception('Your current subscription is not linked to a payment processor. Please contact support.');
                    }

                    // Get subscription from Stripe to get the item ID
                    try {
                        $subscription = $stripe_helper->get_stripe_client()->subscriptions->retrieve($subscription_id);
                    } catch (Exception $e) {
                        throw new Exception('Unable to retrieve your subscription from the payment processor: ' . $e->getMessage());
                    }

                    if (empty($subscription->items->data)) {
                        throw new Exception('Your subscription has no active items. Please contact support.');
                    }

                    $item_id = $subscription->items->data[0]->id;


                    // Update subscription with Stripe
                    try {
                        $updated_subscription = $stripe_helper->change_subscription($subscription_id, $item_id, $new_price_id);
                    } catch (Exception $e) {
                        throw new Exception('Failed to update your subscription with the payment processor: ' . $e->getMessage());
                    }

                    // Subscription updated successfully

                    // Create new order for the upgrade
                    $order = new Order(NULL);
                    $order->set('ord_usr_user_id', $user_id);
                    $order->set('ord_status', Order::STATUS_PAID);
                    $order->set('ord_total_cost', $product_version->get('prv_version_price'));
                    $order->save();

                    // Mark old order item as cancelled
                    $current_subscription->set('odi_subscription_cancelled_time', 'now()');
                    $current_subscription->save();

                    // Create new order item
                    $new_order_item = new OrderItem(NULL);
                    $new_order_item->set('odi_ord_order_id', $order->key);
                    $new_order_item->set('odi_usr_user_id', $user_id);
                    $new_order_item->set('odi_pro_product_id', $product->key);
                    $new_order_item->set('odi_prv_product_version_id', $product_version->key);
                    $new_order_item->set('odi_price', $product_version->get('prv_version_price'));
                    $new_order_item->set('odi_status', OrderItem::STATUS_PAID);
                    $new_order_item->set('odi_is_subscription', true);
                    $new_order_item->set('odi_stripe_subscription_id', $subscription_id);
                    $new_order_item->set('odi_subscription_status', 'active');
                    $new_order_item->set('odi_subscription_period_end', date('Y-m-d H:i:s', $updated_subscription->current_period_end));
                    $new_order_item->save();

                    // Update user's tier
                    $tier_result = SubscriptionTier::handleProductPurchase($user, $product, $new_order_item, $order);

                    // Send upgrade email
                    try {
                        $current_tier_display = $current_tier ? $current_tier->get('sbt_display_name') : 'Free';
                        EmailSender::sendTemplate('subscription_upgraded', $user->get('usr_email'), [
                            'recipient' => $user->export_as_array(),
                            'tier_name' => $new_tier->get('sbt_display_name'),
                            'previous_tier_name' => $current_tier_display,
                            'next_billing_date' => date('F j, Y', $updated_subscription->current_period_end),
                        ]);
                    } catch (Exception $e) {
                        error_log('Subscription upgrade email failed: ' . $e->getMessage());
                    }

                    // Success message
                    $page_vars['success_message'] = 'Successfully upgraded to ' . $new_tier->get('sbt_display_name');
                    break;

                case 'downgrade':
                    // Block downgrade for PayPal subscribers
                    if ($is_paypal) {
                        throw new Exception('PayPal subscriptions cannot be downgraded directly. Please cancel your current subscription and subscribe to a new plan.');
                    }

                    // Check if downgrades are enabled
                    if (!$page_vars['settings']['subscription_downgrades_enabled']) {
                        throw new Exception('Plan downgrades are not currently available. Please contact support for assistance.');
                    }

                    // Validate product selection
                    if (!isset($post['product_id'])) {
                        throw new Exception('Please select a plan to downgrade to.');
                    }

                    // Validate product exists and loads correctly
                    $product = new Product($post['product_id'], TRUE);
                    if (!$product->key) {
                        throw new Exception('The selected plan could not be found. Please try again or contact support.');
                    }

                    if (!$product->get('pro_sbt_subscription_tier_id')) {
                        throw new Exception('The selected product is not associated with a subscription tier. Please contact support.');
                    }

                    // Validate tier exists
                    $new_tier = new SubscriptionTier($product->get('pro_sbt_subscription_tier_id'), TRUE);
                    if (!$new_tier->key) {
                        throw new Exception('The subscription tier for this product could not be found. Please contact support.');
                    }

                    // Verify it's actually a downgrade
                    $current_tier_name = $current_tier ? $current_tier->get('sbt_display_name') : 'Free';
                    $new_tier_name = $new_tier->get('sbt_display_name');

                    if ($new_tier->get('sbt_tier_level') >= $current_tier_level) {
                        throw new Exception("Cannot downgrade from {$current_tier_name} to {$new_tier_name}. The selected plan is not a lower tier than your current plan.");
                    }

                    // Get the new price ID from product
                    $versions = $product->get_product_versions();
                    $product_version = $versions->count() > 0 ? $versions->get(0) : null;

                    if (!$product_version) {
                        throw new Exception('The selected plan does not have any active pricing versions. Please contact support.');
                    }

                    // Get appropriate price ID based on test mode
                    $price_id_field = $stripe_helper->test_mode ? 'prv_stripe_price_id_test' : 'prv_stripe_price_id';
                    $new_price_id = $product_version->get($price_id_field);

                    if (!$new_price_id) {
                        $mode = $stripe_helper->test_mode ? 'test' : 'live';
                        throw new Exception("The selected plan is not configured for {$mode} mode payments. Please contact support.");
                    }

                    // Validate subscription ID exists
                    $subscription_id = $current_subscription->get('odi_stripe_subscription_id');
                    if (!$subscription_id) {
                        throw new Exception('Your current subscription is not linked to a payment processor. Please contact support.');
                    }

                    // Get subscription from Stripe to get the item ID
                    try {
                        $subscription = $stripe_helper->get_stripe_client()->subscriptions->retrieve($subscription_id);
                    } catch (Exception $e) {
                        throw new Exception('Unable to retrieve your subscription from the payment processor: ' . $e->getMessage());
                    }

                    if (empty($subscription->items->data)) {
                        throw new Exception('Your subscription has no active items. Please contact support.');
                    }

                    $item_id = $subscription->items->data[0]->id;


                    // Update subscription with Stripe
                    try {
                        $updated_subscription = $stripe_helper->change_subscription($subscription_id, $item_id, $new_price_id);
                    } catch (Exception $e) {
                        throw new Exception('Failed to update your subscription with the payment processor: ' . $e->getMessage());
                    }

                    // Downgrade successful

                    // Create new order
                    $order = new Order(NULL);
                    $order->set('ord_usr_user_id', $user_id);
                    $order->set('ord_status', Order::STATUS_PAID);
                    $order->set('ord_total_cost', 0); // Credit applied, no charge
                    $order->save();

                    // Mark old order item as cancelled
                    $current_subscription->set('odi_subscription_cancelled_time', 'now()');
                    $current_subscription->save();

                    // Create new order item
                    $new_order_item = new OrderItem(NULL);
                    $new_order_item->set('odi_ord_order_id', $order->key);
                    $new_order_item->set('odi_usr_user_id', $user_id);
                    $new_order_item->set('odi_pro_product_id', $product->key);
                    $new_order_item->set('odi_prv_product_version_id', $product_version->key);
                    $new_order_item->set('odi_price', $product_version->get('prv_version_price'));
                    $new_order_item->set('odi_status', OrderItem::STATUS_PAID);
                    $new_order_item->set('odi_is_subscription', true);
                    $new_order_item->set('odi_stripe_subscription_id', $subscription_id);
                    $new_order_item->set('odi_subscription_status', 'active');
                    $new_order_item->set('odi_subscription_period_end', date('Y-m-d H:i:s', $updated_subscription->current_period_end));
                    $new_order_item->save();

                    // Update user's tier (use 'subscription_change' reason to allow downgrades)
                    $tier_result = $new_tier->addUser($user_id, 'subscription_change', 'order', $order->key, null);

                    // Send downgrade email
                    try {
                        $current_tier_display = $current_tier ? $current_tier->get('sbt_display_name') : 'Free';
                        EmailSender::sendTemplate('subscription_downgraded', $user->get('usr_email'), [
                            'recipient' => $user->export_as_array(),
                            'tier_name' => $new_tier->get('sbt_display_name'),
                            'previous_tier_name' => $current_tier_display,
                            'effective_date' => 'Immediately',
                        ]);
                    } catch (Exception $e) {
                        error_log('Subscription downgrade email failed: ' . $e->getMessage());
                    }

                    // Success message
                    $page_vars['success_message'] = 'Successfully downgraded to ' . $new_tier->get('sbt_display_name');
                    break;

                case 'cancel':
                    // Check if cancellation is enabled
                    if (!$page_vars['settings']['subscription_cancellation_enabled']) {
                        throw new Exception('Subscription cancellation is not currently available. Please contact support for assistance.');
                    }

                    // Determine payment system and validate subscription ID
                    $paypal_sub_id = $current_subscription->get('odi_paypal_subscription_id');
                    $stripe_sub_id = $current_subscription->get('odi_stripe_subscription_id');

                    if (!$paypal_sub_id && !$stripe_sub_id) {
                        throw new Exception('Your subscription is not linked to a payment processor. Please contact support.');
                    }

                    if ($paypal_sub_id) {
                        // PayPal cancellation — always immediate (PayPal doesn't support end-of-period via API)
                        require_once(PathHelper::getIncludePath('includes/PaypalHelper.php'));
                        $paypal = new PaypalHelper();
                        $result = $paypal->cancel_subscription($paypal_sub_id);

                        if ($result) {
                            $current_subscription->set('odi_subscription_cancelled_time', 'now()');
                            $current_subscription->set('odi_subscription_status', 'canceled');
                            $current_subscription->save();

                            SubscriptionTier::removeUserFromAllTiers($user_id);

                            ChangeTracking::logChange(
                                'subscription_tier', null, $user_id, 'tier_removed',
                                $current_tier_level, null, 'subscription_cancelled',
                                'order_item', $current_subscription->key, $user_id
                            );

                            // Send cancellation email
                            try {
                                $tier_display = $current_tier ? $current_tier->get('sbt_display_name') : 'your plan';
                                EmailSender::sendTemplate('subscription_cancelled', $user->get('usr_email'), [
                                    'recipient' => $user->export_as_array(),
                                    'tier_name' => $tier_display,
                                    'access_end_date' => 'Immediately',
                                ]);
                            } catch (Exception $e) {
                                error_log('Subscription cancel email failed: ' . $e->getMessage());
                            }

                            $page_vars['success_message'] = 'Your subscription has been cancelled successfully.';
                        } else {
                            throw new Exception('Unable to cancel your PayPal subscription. Please try again or cancel directly at paypal.com.');
                        }
                    } else if (strtolower($page_vars['settings']['subscription_cancellation_timing']) == 'immediate') {
                        // Stripe immediate cancellation
                        try {
                            $result = $stripe_helper->cancel_subscription($stripe_sub_id, 'immediate');
                        } catch (Exception $e) {
                            throw new Exception('Failed to cancel your subscription with the payment processor: ' . $e->getMessage());
                        }

                        if ($result) {
                            $current_subscription->set('odi_subscription_cancelled_time', 'now()');
                            $current_subscription->set('odi_subscription_status', 'canceled');
                            $current_subscription->save();

                            SubscriptionTier::removeUserFromAllTiers($user_id);

                            ChangeTracking::logChange(
                                'subscription_tier', null, $user_id, 'tier_removed',
                                $current_tier_level, null, 'subscription_cancelled',
                                'order_item', $current_subscription->key, $user_id
                            );

                            // Send cancellation email
                            try {
                                $tier_display = $current_tier ? $current_tier->get('sbt_display_name') : 'your plan';
                                EmailSender::sendTemplate('subscription_cancelled', $user->get('usr_email'), [
                                    'recipient' => $user->export_as_array(),
                                    'tier_name' => $tier_display,
                                    'access_end_date' => 'Immediately',
                                ]);
                            } catch (Exception $e) {
                                error_log('Subscription cancel email failed: ' . $e->getMessage());
                            }

                            $page_vars['success_message'] = 'Your subscription has been cancelled successfully.';
                            if ($page_vars['settings']['subscription_cancellation_prorate']) {
                                $page_vars['success_message'] .= ' A prorated refund will be processed to your payment method.';
                            }
                        } else {
                            throw new Exception('Unable to cancel your subscription. Please try again or contact support.');
                        }
                    } else {
                        // Stripe end-of-period cancellation
                        try {
                            $result = $stripe_helper->cancel_subscription($stripe_sub_id, 'period_end');
                        } catch (Exception $e) {
                            throw new Exception('Failed to schedule subscription cancellation with the payment processor: ' . $e->getMessage());
                        }

                        if ($result) {
                            $current_subscription->set('odi_subscription_cancel_at_period_end', true);
                            $current_subscription->set('odi_subscription_cancelled_time', $current_subscription->get('odi_subscription_period_end'));
                            $current_subscription->save();

                            $cancellation_date = date('F j, Y', strtotime($current_subscription->get('odi_subscription_period_end')));

                            // Send cancellation email
                            try {
                                $tier_display = $current_tier ? $current_tier->get('sbt_display_name') : 'your plan';
                                EmailSender::sendTemplate('subscription_cancelled', $user->get('usr_email'), [
                                    'recipient' => $user->export_as_array(),
                                    'tier_name' => $tier_display,
                                    'access_end_date' => $cancellation_date,
                                ]);
                            } catch (Exception $e) {
                                error_log('Subscription cancel email failed: ' . $e->getMessage());
                            }

                            $page_vars['success_message'] = "Your subscription will be cancelled on {$cancellation_date}. You will retain access until then.";
                        } else {
                            throw new Exception('Unable to schedule your subscription cancellation. Please try again or contact support.');
                        }
                    }
                    break;

                case 'reactivate':
                    // Check if reactivation is enabled
                    if (!$page_vars['settings']['subscription_reactivation_enabled']) {
                        throw new Exception('Subscription reactivation is not currently available. Please contact support for assistance.');
                    }

                    // Can only reactivate if not expired
                    if ($is_expired) {
                        throw new Exception('Your subscription has expired and cannot be reactivated. Please purchase a new subscription.');
                    }

                    // Determine payment system
                    $paypal_sub_id = $current_subscription->get('odi_paypal_subscription_id');
                    $stripe_sub_id = $current_subscription->get('odi_stripe_subscription_id');

                    if (!$paypal_sub_id && !$stripe_sub_id) {
                        throw new Exception('Your subscription is not linked to a payment processor. Please contact support.');
                    }

                    if ($paypal_sub_id) {
                        require_once(PathHelper::getIncludePath('includes/PaypalHelper.php'));
                        $paypal = new PaypalHelper();
                        $result = $paypal->activate_subscription($paypal_sub_id);

                        if ($result) {
                            $current_subscription->set('odi_subscription_cancel_at_period_end', false);
                            $current_subscription->set('odi_subscription_cancelled_time', null);
                            $current_subscription->set('odi_subscription_status', 'active');
                            $current_subscription->save();

                            // Send reactivation email
                            try {
                                $tier_display = $current_tier ? $current_tier->get('sbt_display_name') : 'your plan';
                                $period_end = $current_subscription->get('odi_subscription_period_end');
                                EmailSender::sendTemplate('subscription_reactivated', $user->get('usr_email'), [
                                    'recipient' => $user->export_as_array(),
                                    'tier_name' => $tier_display,
                                    'next_billing_date' => $period_end ? date('F j, Y', strtotime($period_end)) : '',
                                ]);
                            } catch (Exception $e) {
                                error_log('Subscription reactivate email failed: ' . $e->getMessage());
                            }

                            $page_vars['success_message'] = 'Your subscription has been reactivated successfully.';
                        } else {
                            throw new Exception('Unable to reactivate your PayPal subscription. Please try again or contact support.');
                        }
                    } else {
                        try {
                            $subscription = $stripe_helper->reactivate_subscription($stripe_sub_id);
                        } catch (Exception $e) {
                            throw new Exception('Failed to reactivate your subscription with the payment processor: ' . $e->getMessage());
                        }

                        if ($subscription) {
                            $current_subscription->set('odi_subscription_cancel_at_period_end', false);
                            $current_subscription->save();

                            // Send reactivation email
                            try {
                                $tier_display = $current_tier ? $current_tier->get('sbt_display_name') : 'your plan';
                                $period_end = $current_subscription->get('odi_subscription_period_end');
                                EmailSender::sendTemplate('subscription_reactivated', $user->get('usr_email'), [
                                    'recipient' => $user->export_as_array(),
                                    'tier_name' => $tier_display,
                                    'next_billing_date' => $period_end ? date('F j, Y', strtotime($period_end)) : '',
                                ]);
                            } catch (Exception $e) {
                                error_log('Subscription reactivate email failed: ' . $e->getMessage());
                            }

                            $page_vars['success_message'] = 'Your subscription has been reactivated successfully. Billing will continue as normal.';
                        } else {
                            throw new Exception('Unable to reactivate your subscription. Please try again or contact support.');
                        }
                    }
                    break;

                default:
                    throw new Exception('Invalid subscription action requested. Please try again or contact support.');
            }
        } catch (Exception $e) {
            $page_vars['error_message'] = $e->getMessage();
            error_log('Subscription change error: ' . $e->getMessage());
        }

        // Reload data after changes
        $current_tier = SubscriptionTier::GetUserTier($user_id);
        $page_vars['current_tier'] = $current_tier;
        $current_tier_level = $current_tier ? $current_tier->get('sbt_tier_level') : 0;
    }

    // Get all available tiers
    $all_tiers = MultiSubscriptionTier::GetAllActive();
    $page_vars['available_tiers'] = $all_tiers;

    // Prepare tier display data for the view
    $page_vars['tier_display_data'] = array();

    foreach ($all_tiers as $tier) {
        $tier_data = array(
            'tier' => $tier,
            'products' => array(),
            'is_current' => false,
            'action_type' => null,
            'button_text' => '',
            'button_enabled' => false,
            'message' => ''
        );

        // Get products for this tier
        $tier_products = new MultiProduct([
            'pro_sbt_subscription_tier_id' => $tier->key,
            'pro_is_active' => true,
            'pro_delete_time' => 'IS NULL'
        ]);
        $tier_products->load();

        foreach ($tier_products as $product) {
            $versions = $product->get_product_versions();

            // Filter product versions by period
            foreach ($versions as $product_version) {
                $version_period = $product_version->is_subscription();

                // Only include products matching the selected period filter
                if ($version_period == $period_filter) {
                    $tier_data['products'][] = array(
                        'id' => $product->key,
                        'name' => $product->get('pro_name'),
                        'price' => $product_version->get('prv_version_price'),
                        'period' => $version_period
                    );
                    break; // Only add one matching version per product
                }
            }
        }

        $tier_level = $tier->get('sbt_tier_level');

        if ($current_tier && $tier->key == $current_tier->key) {
            // Current tier
            $tier_data['is_current'] = true;
            $tier_data['action_type'] = 'current';
            $tier_data['button_text'] = 'Current Plan';
        } elseif ($tier_level > $current_tier_level) {
            // Upgrade - always allowed
            $tier_data['action_type'] = 'upgrade';
            $tier_data['button_text'] = 'Upgrade Now';
            $tier_data['button_enabled'] = true;
        } elseif ($tier_level < $current_tier_level) {
            // Downgrade - check settings
            if (!$page_vars['settings']['subscription_downgrades_enabled']) {
                $tier_data['action_type'] = 'downgrade_disabled';
                $tier_data['button_text'] = 'Contact Support';
                $tier_data['message'] = 'Downgrades require support assistance';
            } else {
                $tier_data['action_type'] = 'downgrade';
                $tier_data['button_text'] = (strtolower($page_vars['settings']['subscription_downgrade_timing']) == 'immediate')
                    ? 'Downgrade Now'
                    : 'Downgrade at Period End';
                $tier_data['button_enabled'] = true;
            }
        }

        $page_vars['tier_display_data'][] = $tier_data;
    }

    // Prepare cancellation button data
    $page_vars['show_cancel_button'] = false;
    $page_vars['cancel_button_text'] = '';

    if ($has_active_subscription && !$has_cancelled_subscription) {
        $page_vars['show_cancel_button'] = $page_vars['settings']['subscription_cancellation_enabled'];
        $page_vars['cancel_button_text'] = (strtolower($page_vars['settings']['subscription_cancellation_timing']) == 'immediate')
            ? 'Cancel Immediately'
            : 'Cancel at Period End';
    }

    // Prepare reactivation button data
    $page_vars['show_reactivate_button'] = false;

    if ($has_cancelled_subscription && !$is_expired) {
        $page_vars['show_reactivate_button'] = $page_vars['settings']['subscription_reactivation_enabled'];
    }

    return LogicResult::render($page_vars);
}

function change_tier_logic_api() {
    return [
        'requires_session' => true,
        'description' => 'Change subscription tier',
    ];
}
?>