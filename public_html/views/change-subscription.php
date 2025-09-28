<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
require_once(PathHelper::getIncludePath('data/products_class.php'));
require_once(PathHelper::getThemeFilePath('change_subscription_logic.php', 'logic'));

$page_vars = process_logic(change_subscription_logic($_GET, $_POST));

$page = new PublicPage();
$hoptions = array(
    'title' => 'Change Your Subscription',
    'breadcrumbs' => array(
        'Change Subscription' => ''
    ),
);
$page->public_header($hoptions, NULL);

echo PublicPage::BeginPage('Choose Your Membership Tier', $hoptions);

// Get current user's tier
$session = SessionControl::get_instance();
$user_id = $session->get_user_id();
$current_tier = SubscriptionTier::GetUserTier($user_id);
$current_level = $current_tier ? $current_tier->get('sbt_tier_level') : 0;

// Get all active tiers
$all_tiers = MultiSubscriptionTier::GetAllActive();
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="text-center">
        <h2 class="text-3xl font-extrabold text-gray-900 sm:text-4xl">
            Choose Your Membership Level
        </h2>
        <?php if ($current_tier): ?>
            <p class="mt-3 text-xl text-gray-500">
                You currently have <strong><?php echo htmlspecialchars($current_tier->get('sbt_display_name')); ?></strong> membership
            </p>
        <?php else: ?>
            <p class="mt-3 text-xl text-gray-500">
                Join today and unlock premium features
            </p>
        <?php endif; ?>
    </div>

    <div class="mt-12 space-y-4 sm:mt-16 sm:space-y-0 sm:grid sm:grid-cols-<?php echo count($all_tiers); ?> sm:gap-6 lg:max-w-5xl lg:mx-auto">

        <?php foreach ($all_tiers as $tier): ?>
            <?php
            // Get products for this tier
            $tier_products = new MultiProduct([
                'pro_sbt_subscription_tier_id' => $tier->key,
                'pro_is_active' => true,
                'pro_delete_time' => 'IS NULL'
            ], ['pro_name' => 'ASC']);
            $tier_products->load();

            $is_current = $current_tier && $current_tier->key == $tier->key;
            $is_upgrade = $tier->get('sbt_tier_level') > $current_level;
            $is_downgrade = $tier->get('sbt_tier_level') < $current_level;
            ?>

            <div class="border border-gray-200 rounded-lg shadow-sm divide-y divide-gray-200
                        <?php echo $is_current ? 'ring-2 ring-indigo-500' : ''; ?>">
                <div class="p-6">
                    <h3 class="text-lg leading-6 font-medium text-gray-900">
                        <?php echo htmlspecialchars($tier->get('sbt_display_name')); ?>
                    </h3>

                    <?php if ($is_current): ?>
                        <p class="mt-2 text-sm text-indigo-600 font-semibold">Your Current Plan</p>
                    <?php elseif ($is_downgrade): ?>
                        <p class="mt-2 text-sm text-gray-500">(Downgrade)</p>
                    <?php endif; ?>

                    <p class="mt-4 text-sm text-gray-500">
                        <?php echo htmlspecialchars($tier->get('sbt_description')); ?>
                    </p>

                    <div class="mt-6">
                        <?php if (count($tier_products) > 0): ?>
                            <?php foreach ($tier_products as $product): ?>
                                <?php
                                // Get product versions for pricing
                                $versions = $product->get_product_versions(TRUE);
                                if ($versions && $versions->count() > 0):
                                    $version = $versions->get(0); // Get first active version
                                ?>
                                    <p class="text-3xl font-extrabold text-gray-900">
                                        $<?php echo number_format($version->get('prv_version_price'), 2); ?>
                                        <?php if ($version->is_subscription()): ?>
                                            <span class="text-base font-medium text-gray-500">
                                                /<?php echo $version->get('prv_price_type'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="mt-8">
                        <?php if ($is_current): ?>
                            <button class="block w-full py-2 px-4 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-gray-100 cursor-not-allowed" disabled>
                                Current Plan
                            </button>
                        <?php elseif ($is_downgrade): ?>
                            <button class="block w-full py-2 px-4 border border-gray-300 rounded-md text-sm font-medium text-gray-400 bg-gray-100 cursor-not-allowed" disabled>
                                Downgrades Coming Soon
                            </button>
                        <?php elseif (count($tier_products) > 0): ?>
                            <?php foreach ($tier_products as $product): ?>
                                <a href="/product/<?php echo $product->get('pro_link'); ?>"
                                   class="block w-full py-2 px-4 border border-transparent rounded-md text-center text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">
                                    <?php echo $is_upgrade ? 'Upgrade Now' : 'Select Plan'; ?>
                                </a>
                                <?php break; // Show only first product as button ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <button class="block w-full py-2 px-4 border border-gray-300 rounded-md text-sm font-medium text-gray-400 bg-gray-100 cursor-not-allowed" disabled>
                                Coming Soon
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

    </div>
</div>

<?php
echo PublicPage::EndPage($hoptions);
$page->public_footer($hoptions, NULL);
?>