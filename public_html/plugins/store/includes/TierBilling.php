<?php
/**
 * TierBilling — the billing-facing half of subscription tiers.
 *
 * SubscriptionTier (core) owns tier gating: who is in which tier, feature
 * resolution, and the group membership that enforces access. TierBilling owns
 * the money side: granting a tier when its product is purchased, revoking it
 * when a subscription lapses, and computing upgrade offers. It reaches into the
 * core tier primitives (GetUserTier, addUser, removeUserFromAllTiers) only
 * through those public methods — the only place grant/revoke crosses the
 * gating/billing boundary.
 *
 * Store-owned. Callers (cart_charge_logic, change_tier_logic, the Stripe/PayPal
 * webhooks) are all store-side.
 *
 * @version 1.0.0
 */
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

class TierBilling {

    /**
     * Grant the product's subscription tier to the buyer on purchase.
     * Returns true if a tier was granted, false otherwise.
     */
    public static function handleProductPurchase($user, $product, $order_item, $order) {
        // Check if product has a subscription tier
        if (!$product->get('pro_sbt_subscription_tier_id')) {
            return false;
        }

        try {
            $tier = new SubscriptionTier($product->get('pro_sbt_subscription_tier_id'), TRUE);

            // Add user to tier with purchase context
            $result = $tier->addUser(
                $user->key,
                'purchase',
                'order',
                $order->key,
                null  // No admin user for purchases
            );

            return true;

        } catch (Exception $e) {
            // Log error but don't break checkout
            return false;
        }
    }

    /**
     * Whether the user has at least one active subscription order item.
     */
    public static function userHasActiveSubscription($user_id) {
        require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
        $subscriptions = new MultiOrderItem(
            array('user_id' => $user_id, 'is_active_subscription' => true)
        );
        $subscriptions->load();

        if ($subscriptions->count() > 0) {
            foreach ($subscriptions as $subscription) {
                if ($subscription->check_subscription_status()) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Revoke a user's tier when their subscription expires/cancels, and notify.
     * Called from the Stripe/PayPal webhooks.
     */
    public static function handleSubscriptionExpired($user_id) {
        $current_tier = SubscriptionTier::GetUserTier($user_id);
        if ($current_tier) {
            $old_tier_level = $current_tier->get('sbt_tier_level');
            SubscriptionTier::removeUserFromAllTiers($user_id);

            ChangeTracking::logChange(
                'subscription_tier',
                null,
                $user_id,
                'tier_removed',
                $old_tier_level,
                null,
                'subscription_expired'
            );

            // Send expiration email
            try {
                require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
                require_once(PathHelper::getIncludePath('data/users_class.php'));
                $user = new User($user_id, TRUE);
                if ($user->key) {
                    EmailSender::sendTemplate('subscription_expired', $user->get('usr_email'), [
                        'recipient' => $user->export_as_array(),
                        'tier_name' => $current_tier->get('sbt_display_name'),
                    ]);
                }
            } catch (Exception $e) {
                error_log('Subscription expiration email failed: ' . $e->getMessage());
            }

            // In-app notification: subscription expired
            try {
                require_once(PathHelper::getIncludePath('data/notifications_class.php'));
                Notification::create_notification(
                    $user_id,
                    'subscription',
                    'Your ' . $current_tier->get('sbt_name') . ' subscription has expired',
                    'Your subscription tier access has been removed.',
                    '/pricing',
                    null
                );
            } catch (Exception $e) { /* notification system not available */ }

            // Admin alert: a subscription lapsed.
            require_once(PathHelper::getIncludePath('includes/SignalBus.php'));
            SignalBus::dispatch('subscription.expired', array(
                'user_id'   => $user_id,
                'tier_id'   => $current_tier->key,
                'tier_name' => $current_tier->get('sbt_name'),
            ));

            SubscriptionTier::clearUserCache($user_id);
        }
    }

    /**
     * The tiers above the user's current level that can be bought, each with the
     * products that grant them.
     */
    public static function getUpgradeOptions($user_id) {
        $current_tier = SubscriptionTier::GetUserTier($user_id);
        $current_level = $current_tier ? $current_tier->get('sbt_tier_level') : 0;

        $all_tiers = MultiSubscriptionTier::GetAllActive();
        $upgrade_options = [];

        foreach ($all_tiers as $tier) {
            if ($tier->get('sbt_tier_level') > $current_level) {
                // Find products that grant this tier using models. These are the
                // option keys MultiProduct::getMultiResults() recognizes — raw
                // column names (pro_is_active/pro_delete_time) are ignored, which
                // would silently offer inactive/deleted products as upgrades.
                $products_with_tier = new MultiProduct([
                    'pro_sbt_subscription_tier_id' => $tier->key,
                    'is_active' => true,
                    'deleted' => false
                ]);

                if ($products_with_tier->count_all() > 0) {
                    $products_with_tier->load();
                    $products = [];

                    foreach ($products_with_tier as $product) {
                        $products[] = [
                            'pro_product_id' => $product->key,
                            'pro_name' => $product->get('pro_name'),
                            'pro_url' => $product->get_url()
                        ];
                    }

                    if (count($products) > 0) {
                        $upgrade_options[] = [
                            'tier' => $tier,
                            'products' => $products
                        ];
                    }
                }
            }
        }

        return $upgrade_options;
    }
}
