<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function change_subscription_logic($get, $post) {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('includes/StripeHelper.php'));
    require_once(PathHelper::getIncludePath('data/users_class.php'));
    require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
    require_once(PathHelper::getIncludePath('data/products_class.php'));
    require_once(PathHelper::getIncludePath('data/order_items_class.php'));
    require_once(PathHelper::getIncludePath('data/orders_class.php'));
    require_once(PathHelper::getIncludePath('data/change_tracking_class.php'));

    $page_vars = array();
    $settings = Globalvars::get_instance();
    $session = SessionControl::get_instance();

    // Check if user is logged in
    if (!$session->is_logged_in()) {
        // Redirect to login with return URL
        header('Location: /login?return=' . urlencode('/change-subscription'));
        exit;
    }

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

    $page_vars['current_subscription'] = $current_subscription;
    $page_vars['has_active_subscription'] = $has_active_subscription;
    $page_vars['has_cancelled_subscription'] = $has_cancelled_subscription;
    $page_vars['is_expired'] = $is_expired;

    // Handle POST actions
    if (isset($post['action']) && $current_subscription) {
        $stripe_helper = new StripeHelper();
        $result = null;
        $error = null;

        try {
            switch ($post['action']) {
                case 'upgrade':
                    // Validate product and tier
                    if (!isset($post['product_id'])) {
                        throw new Exception('No product selected');
                    }

                    $product = new Product($post['product_id'], TRUE);
                    if (!$product->get('pro_sbt_subscription_tier_id')) {
                        throw new Exception('Product does not grant a subscription tier');
                    }

                    $new_tier = new SubscriptionTier($product->get('pro_sbt_subscription_tier_id'), TRUE);
                    if ($new_tier->get('sbt_tier_level') <= $current_tier_level) {
                        throw new Exception('Selected tier is not an upgrade');
                    }

                    // Get the new price ID from product
                    $product_version = $product->get_default_version();
                    if (!$product_version || !$product_version->get('prv_stripe_price_id')) {
                        throw new Exception('Product does not have a valid Stripe price');
                    }

                    // Update subscription with Stripe
                    $subscription_id = $current_subscription->get('odi_subscription_id');
                    $item_id = $current_subscription->get('odi_subscription_item_id');
                    $new_price_id = $product_version->get('prv_stripe_price_id');

                    $result = $stripe_helper->change_subscription($subscription_id, $item_id, $new_price_id);

                    if ($result['success']) {
                        // Create new order for the upgrade
                        $order = new Order(NULL);
                        $order->set('ord_usr_user_id', $user_id);
                        $order->set('ord_status', Order::STATUS_PAID);
                        $order->set('ord_payment_method', 'stripe');
                        $order->set('ord_amount', $result['amount_charged'] / 100); // Convert from cents
                        $order->save();

                        // Mark old order item as cancelled
                        $current_subscription->set('odi_subscription_cancelled_time', 'now()');
                        $current_subscription->save();

                        // Create new order item
                        $new_order_item = new OrderItem(NULL);
                        $new_order_item->set('odi_ord_order_id', $order->key);
                        $new_order_item->set('odi_pro_product_id', $product->key);
                        $new_order_item->set('odi_prv_product_version_id', $product_version->key);
                        $new_order_item->set('odi_price', $product_version->get('prv_price'));
                        $new_order_item->set('odi_is_subscription', true);
                        $new_order_item->set('odi_subscription_id', $subscription_id);
                        $new_order_item->set('odi_subscription_item_id', $item_id);
                        $new_order_item->set('odi_subscription_status', 'active');
                        $new_order_item->set('odi_subscription_period_end', date('Y-m-d H:i:s', $result['current_period_end']));
                        $new_order_item->save();

                        // Update user's tier
                        SubscriptionTier::handleProductPurchase($user, $product, $new_order_item, $order);

                        // Success message
                        $page_vars['success_message'] = 'Successfully upgraded to ' . $new_tier->get('sbt_display_name');
                    } else {
                        throw new Exception($result['error'] ?? 'Failed to upgrade subscription');
                    }
                    break;

                case 'downgrade':
                    // Check if downgrades are enabled
                    if (!$page_vars['settings']['subscription_downgrades_enabled']) {
                        throw new Exception('Downgrades are not currently available');
                    }

                    // Validate product and tier
                    if (!isset($post['product_id'])) {
                        throw new Exception('No product selected');
                    }

                    $product = new Product($post['product_id'], TRUE);
                    if (!$product->get('pro_sbt_subscription_tier_id')) {
                        throw new Exception('Product does not grant a subscription tier');
                    }

                    $new_tier = new SubscriptionTier($product->get('pro_sbt_subscription_tier_id'), TRUE);
                    if ($new_tier->get('sbt_tier_level') >= $current_tier_level) {
                        throw new Exception('Selected tier is not a downgrade');
                    }

                    // Get the new price ID from product
                    $product_version = $product->get_default_version();
                    if (!$product_version || !$product_version->get('prv_stripe_price_id')) {
                        throw new Exception('Product does not have a valid Stripe price');
                    }

                    // Determine proration behavior based on timing setting
                    $proration_behavior = ($page_vars['settings']['subscription_downgrade_timing'] == 'immediate')
                        ? 'create_prorations'
                        : 'none';

                    // Update subscription with Stripe
                    $subscription_id = $current_subscription->get('odi_subscription_id');
                    $item_id = $current_subscription->get('odi_subscription_item_id');
                    $new_price_id = $product_version->get('prv_stripe_price_id');

                    $result = $stripe_helper->change_subscription(
                        $subscription_id,
                        $item_id,
                        $new_price_id,
                        $proration_behavior
                    );

                    if ($result['success']) {
                        // Handle based on timing
                        if ($page_vars['settings']['subscription_downgrade_timing'] == 'immediate') {
                            // Immediate downgrade - update tier now

                            // Create new order
                            $order = new Order(NULL);
                            $order->set('ord_usr_user_id', $user_id);
                            $order->set('ord_status', Order::STATUS_PAID);
                            $order->set('ord_payment_method', 'stripe');
                            $order->set('ord_amount', 0); // Credit applied, no charge
                            $order->save();

                            // Mark old order item as cancelled
                            $current_subscription->set('odi_subscription_cancelled_time', 'now()');
                            $current_subscription->save();

                            // Create new order item
                            $new_order_item = new OrderItem(NULL);
                            $new_order_item->set('odi_ord_order_id', $order->key);
                            $new_order_item->set('odi_pro_product_id', $product->key);
                            $new_order_item->set('odi_prv_product_version_id', $product_version->key);
                            $new_order_item->set('odi_price', $product_version->get('prv_price'));
                            $new_order_item->set('odi_is_subscription', true);
                            $new_order_item->set('odi_subscription_id', $subscription_id);
                            $new_order_item->set('odi_subscription_item_id', $item_id);
                            $new_order_item->set('odi_subscription_status', 'active');
                            $new_order_item->set('odi_subscription_period_end', date('Y-m-d H:i:s', $result['current_period_end']));
                            $new_order_item->save();

                            // Update user's tier immediately
                            SubscriptionTier::handleProductPurchase($user, $product, $new_order_item, $order);

                            $page_vars['success_message'] = 'Successfully downgraded to ' . $new_tier->get('sbt_display_name');
                        } else {
                            // End-of-period downgrade - tier changes at period end
                            $current_subscription->set('odi_pending_product_id', $product->key);
                            $current_subscription->save();

                            $page_vars['success_message'] = 'Downgrade to ' . $new_tier->get('sbt_display_name') .
                                ' scheduled for ' . date('F j, Y', strtotime($current_subscription->get('odi_subscription_period_end')));
                        }
                    } else {
                        throw new Exception($result['error'] ?? 'Failed to downgrade subscription');
                    }
                    break;

                case 'cancel':
                    // Check if cancellation is enabled
                    if (!$page_vars['settings']['subscription_cancellation_enabled']) {
                        throw new Exception('Cancellation is not currently available');
                    }

                    $subscription_id = $current_subscription->get('odi_subscription_id');

                    if ($page_vars['settings']['subscription_cancellation_timing'] == 'immediate') {
                        // Immediate cancellation
                        $result = $stripe_helper->cancel_subscription(
                            $subscription_id,
                            $page_vars['settings']['subscription_cancellation_prorate']
                        );

                        if ($result['success']) {
                            // Mark as cancelled immediately
                            $current_subscription->set('odi_subscription_cancelled_time', 'now()');
                            $current_subscription->set('odi_subscription_status', 'canceled');
                            $current_subscription->save();

                            // Remove user from tier immediately
                            SubscriptionTier::removeUserFromAllTiers($user_id);

                            // Log the change
                            ChangeTracking::logChange(
                                'subscription_tier',
                                null,
                                $user_id,
                                'tier_removed',
                                $current_tier_level,
                                null,
                                'subscription_cancelled',
                                'order_item',
                                $current_subscription->key,
                                $user_id
                            );

                            $page_vars['success_message'] = 'Subscription cancelled successfully';
                            if ($page_vars['settings']['subscription_cancellation_prorate']) {
                                $page_vars['success_message'] .= '. A prorated refund will be processed.';
                            }
                        } else {
                            throw new Exception($result['error'] ?? 'Failed to cancel subscription');
                        }
                    } else {
                        // End-of-period cancellation
                        $result = $stripe_helper->cancel_subscription_at_period_end($subscription_id);

                        if ($result['success']) {
                            // Set future cancellation date
                            $current_subscription->set('odi_subscription_cancel_at_period_end', true);
                            $current_subscription->save();

                            $page_vars['success_message'] = 'Subscription will be cancelled on ' .
                                date('F j, Y', strtotime($current_subscription->get('odi_subscription_period_end')));
                        } else {
                            throw new Exception($result['error'] ?? 'Failed to schedule cancellation');
                        }
                    }
                    break;

                case 'reactivate':
                    // Check if reactivation is enabled
                    if (!$page_vars['settings']['subscription_reactivation_enabled']) {
                        throw new Exception('Reactivation is not currently available');
                    }

                    // Can only reactivate if not expired
                    if ($is_expired) {
                        throw new Exception('Subscription has expired. Please purchase a new subscription.');
                    }

                    $subscription_id = $current_subscription->get('odi_subscription_id');
                    $result = $stripe_helper->reactivate_subscription($subscription_id);

                    if ($result['success']) {
                        // Clear cancellation flags
                        $current_subscription->set('odi_subscription_cancel_at_period_end', false);
                        $current_subscription->save();

                        $page_vars['success_message'] = 'Subscription reactivated successfully';
                    } else {
                        throw new Exception($result['error'] ?? 'Failed to reactivate subscription');
                    }
                    break;

                default:
                    throw new Exception('Invalid action');
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
            $product_version = $product->get_default_version();
            if ($product_version && $product_version->get('prv_stripe_price_id')) {
                $tier_data['products'][] = array(
                    'id' => $product->key,
                    'name' => $product->get('pro_name'),
                    'price' => $product_version->get('prv_price'),
                    'period' => $product_version->is_subscription()
                );
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
                $tier_data['button_text'] = ($page_vars['settings']['subscription_downgrade_timing'] == 'immediate')
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
        $page_vars['cancel_button_text'] = ($page_vars['settings']['subscription_cancellation_timing'] == 'immediate')
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
?>