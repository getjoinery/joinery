<?php
/**
 * Logic for admin_subscription_tier_edit.php
 * Handles subscription tier creation and editing
 */

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
require_once(PathHelper::getIncludePath('data/change_tracking_class.php'));

function admin_subscription_tier_edit_logic($get, $post) {
    // Permission check
    $session = SessionControl::get_instance();
    $session->check_permission(5);

    // Load or create tier
    if (isset($get['id']) || isset($post['edit_primary_key_value'])) {
        $tier_id = isset($post['edit_primary_key_value']) ? $post['edit_primary_key_value'] : $get['id'];
        try {
            $tier = new SubscriptionTier($tier_id, TRUE);
            if (!$tier || $tier->get('sbt_delete_time')) {
                // Tier doesn't exist or is deleted
                return LogicResult::redirect('/admin/admin_subscription_tiers?error=tier_not_found');
            }
        } catch (Exception $e) {
            // Tier doesn't exist
            return LogicResult::redirect('/admin/admin_subscription_tiers?error=tier_not_found');
        }
    } else {
        $tier = new SubscriptionTier(NULL);
    }

    // Process POST actions
    if($post){
        try {
            // Process features
            $features = array();
            $available_features = SubscriptionTier::getAllAvailableFeatures();
            if (isset($post['features']) && is_array($post['features'])) {
                foreach ($post['features'] as $key => $value) {
                    // Get feature definition to check type
                    $definition = isset($available_features[$key]) ? $available_features[$key] : null;

                    // Convert value based on feature definition type
                    if ($definition && $definition['type'] === 'integer') {
                        $features[$key] = intval($value);
                    } elseif ($definition && $definition['type'] === 'boolean') {
                        $features[$key] = ($value === '1' || $value === 'true' || $value === true);
                    } elseif (is_numeric($value)) {
                        $features[$key] = intval($value);
                    } else {
                        $features[$key] = $value;
                    }
                }
            }

            if ($tier && $tier->key) {
                // Update existing tier
                $tier->set('sbt_name', $post['sbt_name']);
                $tier->set('sbt_display_name', $post['sbt_display_name']);
                $tier->set('sbt_tier_level', $post['sbt_tier_level']);
                $tier->set('sbt_description', $post['sbt_description']);
                $tier->set('sbt_is_active', isset($post['sbt_is_active']) ? true : false);
                $tier->setFeatures($features);
                $tier->save();

                ChangeTracking::logChange(
                    'subscription_tier',
                    $tier->key,
                    null,
                    'updated',
                    null,
                    $tier->get('sbt_name'),
                    'admin_update',
                    'admin_action',
                    null,
                    $session->get_user_id()
                );
            } else {
                // Create new tier
                $tier = new SubscriptionTier(NULL);
                $tier->set('sbt_name', $post['sbt_name']);
                $tier->set('sbt_display_name', $post['sbt_display_name']);
                $tier->set('sbt_tier_level', $post['sbt_tier_level']);
                $tier->set('sbt_description', $post['sbt_description']);
                $tier->set('sbt_is_active', isset($post['sbt_is_active']) ? true : false);
                $tier->setFeatures($features);
                $tier->save();

                ChangeTracking::logChange(
                    'subscription_tier',
                    $tier->key,
                    null,
                    'created',
                    null,
                    $tier->get('sbt_name'),
                    'admin_create',
                    'admin_action',
                    null,
                    $session->get_user_id()
                );
            }

            return LogicResult::redirect('/admin/admin_subscription_tiers?success=1');
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    }

    // Return data for view
    return LogicResult::render([
        'tier' => $tier,
        'error_message' => $error_message ?? null,
        'session' => $session
    ]);
}
