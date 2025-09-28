<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('data/groups_class.php'));
require_once(PathHelper::getIncludePath('data/group_members_class.php'));
require_once(PathHelper::getIncludePath('data/change_tracking_class.php'));

class SubscriptionTierException extends SystemBaseException {}

class SubscriptionTier extends SystemBase {
    public static $prefix = 'sbt';
    public static $tablename = 'sbt_subscription_tiers';
    public static $pkey_column = 'sbt_subscription_tier_id';

    public static $field_specifications = array(
        'sbt_subscription_tier_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'sbt_grp_group_id' => array('type'=>'int4', 'required'=>true, 'unique'=>true),
        'sbt_tier_level' => array('type'=>'int4', 'required'=>true),
        'sbt_name' => array('type'=>'varchar(100)', 'required'=>true),
        'sbt_display_name' => array('type'=>'varchar(100)', 'required'=>true),
        'sbt_description' => array('type'=>'text'),
        'sbt_is_active' => array('type'=>'bool', 'default'=>true),
        'sbt_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'sbt_update_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'sbt_delete_time' => array('type'=>'timestamp(6)')
    );

    /**
     * Create the associated group when creating a new tier
     */
    public function save($debug = false) {
        // If new tier, create the group first
        if (!$this->key && !$this->get('sbt_grp_group_id')) {
            // Check if a group with this name already exists in the subscription_tier category
            $existing_groups = new MultiGroup([
                'grp_name' => $this->get('sbt_name'),
                'grp_category' => 'subscription_tier'
            ]);

            if ($existing_groups->count_all() > 0) {
                throw new Exception('A subscription tier with the name "' . $this->get('sbt_name') . '" already exists');
            }

            $group = new Group(NULL);
            $group->set('grp_name', $this->get('sbt_name'));
            $group->set('grp_category', 'subscription_tier');
            $group->save();
            $this->set('sbt_grp_group_id', $group->key);
        } elseif ($this->key && $this->is_dirty('sbt_name')) {
            // If updating tier name, also update the group name
            $group = new Group($this->get('sbt_grp_group_id'), TRUE);
            $group->set('grp_name', $this->get('sbt_name'));
            $group->save();
        }

        return parent::save($debug);
    }

    /**
     * Add user to this subscription tier
     */
    public function addUser($user_id, $reason = 'manual', $reference_type = null,
                            $reference_id = null, $changed_by_user_id = null) {
        // Get current tier before change
        $current_tier = self::GetUserTier($user_id);
        $old_tier_level = $current_tier ? $current_tier->get('sbt_tier_level') : null;

        // For purchases, only allow upgrades (not downgrades)
        if ($reason === 'purchase' && $current_tier) {
            if ($this->get('sbt_tier_level') <= $current_tier->get('sbt_tier_level')) {
                // User already has this tier or higher - skip the change
                return false;
            }
        }

        // Get all subscription tier groups
        $tier_groups = new MultiGroup(['grp_category' => 'subscription_tier']);
        $tier_groups->load();

        // Remove user from all subscription tier groups
        foreach ($tier_groups as $group) {
            $existing_members = new MultiGroupMember([
                'grm_grp_group_id' => $group->key,
                'grm_foreign_key_id' => $user_id
            ]);
            $existing_members->load();

            foreach ($existing_members as $member) {
                $member->remove();  // Uses the remove() method from GroupMember class
            }
        }

        // Add user to this tier's group
        $group_member = new GroupMember(NULL);
        $group_member->set('grm_grp_group_id', $this->get('sbt_grp_group_id'));
        $group_member->set('grm_foreign_key_id', $user_id);
        $group_member->save();

        // Log the change
        ChangeTracking::logChange(
            'subscription_tier',
            $this->key,
            $user_id,
            'tier_level',
            $old_tier_level,
            $this->get('sbt_tier_level'),
            $reason,
            $reference_type,
            $reference_id,
            $changed_by_user_id
        );

        return true;
    }

    /**
     * Get current subscription tier for a user
     */
    public static function GetUserTier($user_id) {
        // Get all subscription tier groups the user belongs to
        $user_groups = new MultiGroupMember(['grm_foreign_key_id' => $user_id]);
        $user_groups->load();

        foreach ($user_groups as $group_member) {
            // Get the group
            $group = new Group($group_member->get('grm_grp_group_id'), TRUE);

            // Check if it's a subscription tier group
            if ($group->get('grp_category') === 'subscription_tier' && !$group->get('grp_delete_time')) {
                // Find the tier for this group
                $tier = self::GetByColumn('sbt_grp_group_id', $group->key);
                if ($tier && !$tier->get('sbt_delete_time')) {
                    return $tier;
                }
            }
        }

        return null;
    }

    /**
     * Check if user meets minimum tier level
     */
    public static function UserHasMinimumTier($user_id, $minimum_tier_level) {
        $user_tier = self::GetUserTier($user_id);
        if (!$user_tier) return false;
        return $user_tier->get('sbt_tier_level') >= $minimum_tier_level;
    }

    /**
     * Handle subscription tier assignment when a product is purchased
     * Called from cart_charge_logic.php
     */
    public static function handleProductPurchase($user, $product, $order_item, $order) {
        // Check if product has a subscription tier
        if (!$product->get('pro_sbt_subscription_tier_id')) {
            return false;
        }

        try {
            $tier = new SubscriptionTier($product->get('pro_sbt_subscription_tier_id'), TRUE);

            // Add user to tier with purchase context
            $tier->addUser(
                $user->key,
                'purchase',
                'order',
                $order->key,
                null  // No admin user for purchases
            );

            return true;

        } catch (Exception $e) {
            // Log error but don't break checkout
            error_log('Subscription tier assignment failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if user has minimum tier level and redirect if not
     */
    public static function requireMinimumTier($user_id, $minimum_tier_level, $redirect_url = '/change-subscription') {
        if (!self::UserHasMinimumTier($user_id, $minimum_tier_level)) {
            header('Location: ' . $redirect_url);
            exit;
        }
    }

    /**
     * Get tier display name for user
     */
    public static function getUserTierDisplay($user_id) {
        $tier = self::GetUserTier($user_id);

        if (!$tier) {
            return 'Free';
        }

        return htmlspecialchars($tier->get('sbt_display_name'));
    }

    /**
     * Get available upgrade options for a user
     */
    public static function getUpgradeOptions($user_id) {
        $current_tier = self::GetUserTier($user_id);
        $current_level = $current_tier ? $current_tier->get('sbt_tier_level') : 0;

        $all_tiers = MultiSubscriptionTier::GetAllActive();
        $upgrade_options = [];

        foreach ($all_tiers as $tier) {
            if ($tier->get('sbt_tier_level') > $current_level) {
                // Find products that grant this tier using models
                $products_with_tier = new MultiProduct([
                    'pro_sbt_subscription_tier_id' => $tier->key,
                    'pro_is_active' => true,
                    'pro_delete_time' => 'IS NULL'
                ]);

                if ($products_with_tier->count_all() > 0) {
                    $products_with_tier->load();
                    $products = [];

                    foreach ($products_with_tier as $product) {
                        $products[] = [
                            'pro_product_id' => $product->key,
                            'pro_name' => $product->get('pro_name')
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

class MultiSubscriptionTier extends SystemMultiBase {
    protected static $model_class = 'SubscriptionTier';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['sbt_grp_group_id'])) {
            $filters['sbt_grp_group_id'] = [$this->options['sbt_grp_group_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['sbt_tier_level'])) {
            $filters['sbt_tier_level'] = [$this->options['sbt_tier_level'], PDO::PARAM_INT];
        }

        if (isset($this->options['sbt_name'])) {
            $filters['sbt_name'] = [$this->options['sbt_name'], PDO::PARAM_STR];
        }

        if (isset($this->options['sbt_is_active'])) {
            if ($this->options['sbt_is_active'] === true) {
                $filters['sbt_is_active'] = '= TRUE';
            } elseif ($this->options['sbt_is_active'] === false) {
                $filters['sbt_is_active'] = '= FALSE';
            }
        }

        if (isset($this->options['sbt_delete_time'])) {
            if ($this->options['sbt_delete_time'] === 'IS NULL') {
                $filters['sbt_delete_time'] = 'IS NULL';
            } elseif ($this->options['sbt_delete_time'] === 'IS NOT NULL') {
                $filters['sbt_delete_time'] = 'IS NOT NULL';
            }
        }

        // Handle any standard filters from parent class
        $sorts = [];
        if (!empty($this->sorts)) {
            $sorts = $this->sorts;
        }

        return $this->_get_resultsv2('sbt_subscription_tiers', $filters, $sorts, $only_count, $debug);
    }

    /**
     * Get all active tiers ordered by level
     */
    public static function GetAllActive() {
        $tiers = new MultiSubscriptionTier(
            ['sbt_is_active' => true, 'sbt_delete_time' => 'IS NULL'],
            ['sbt_tier_level' => 'ASC']
        );
        $tiers->load();
        return $tiers;
    }
}