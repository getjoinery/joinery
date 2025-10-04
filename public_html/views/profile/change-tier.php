<?php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('change_tier_logic.php', 'logic'));

// Process logic - all data preparation happens in the logic file
$page_vars = process_logic(change_tier_logic($_GET, $_POST));

$page = new PublicPage();
$hoptions = array(
    'title' => 'Change Tier',
    'breadcrumbs' => array(
        'Change Tier' => ''
    ),
);
$page->public_header($hoptions, NULL);

$formwriter = $page->getFormWriter('tier_form');

echo PublicPage::BeginPage('Change Tier', $hoptions);

// Display messages
if (isset($page_vars['success_message'])): ?>
    <div class="alert alert-success mb-4">
        <?php echo htmlspecialchars($page_vars['success_message']); ?>
    </div>
<?php endif; ?>

<?php if (isset($page_vars['error_message'])): ?>
    <div class="alert alert-danger mb-4">
        <?php echo htmlspecialchars($page_vars['error_message']); ?>
    </div>
<?php endif; ?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="text-center">
        <h2 class="text-3xl font-extrabold text-gray-900 sm:text-4xl">
            Choose Your Membership Level
        </h2>
        <?php if ($page_vars['current_tier']): ?>
            <p class="mt-3 text-xl text-gray-500">
                Your current tier: <strong><?php echo htmlspecialchars($page_vars['current_tier']->get('sbt_display_name')); ?></strong>
            </p>
        <?php else: ?>
            <p class="mt-3 text-xl text-gray-500">
                Join today and unlock premium features
            </p>
        <?php endif; ?>

        <?php if ($page_vars['has_cancelled_subscription'] && !$page_vars['is_expired']): ?>
            <p class="mt-2 text-sm text-orange-600">
                Your subscription is scheduled to cancel at the end of your billing period
            </p>
        <?php elseif ($page_vars['is_expired']): ?>
            <p class="mt-2 text-sm text-red-600">
                Your subscription has expired
            </p>
        <?php endif; ?>
    </div>

    <?php if ($page_vars['show_reactivate_button']): ?>
        <div class="mt-6 text-center">
            <?php echo $formwriter->form_start('reactivate_form', '', 'POST'); ?>
            <input type="hidden" name="action" value="reactivate">
            <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                Reactivate Subscription
            </button>
            <?php echo $formwriter->form_end(); ?>
        </div>
    <?php endif; ?>

    <div class="mt-12 space-y-4 sm:mt-16 sm:space-y-0 sm:grid sm:grid-cols-<?php echo count($page_vars['tier_display_data']); ?> sm:gap-6 lg:max-w-6xl lg:mx-auto">

        <?php foreach ($page_vars['tier_display_data'] as $tier_data): ?>
            <?php
            $tier = $tier_data['tier'];
            $is_current = $tier_data['is_current'];
            ?>

            <div class="border border-gray-200 rounded-lg shadow-sm divide-y divide-gray-200
                        <?php echo $is_current ? 'ring-2 ring-indigo-500' : ''; ?>">
                <div class="p-6">
                    <h3 class="text-lg leading-6 font-medium text-gray-900">
                        <?php echo htmlspecialchars($tier->get('sbt_display_name')); ?>
                    </h3>

                    <?php if ($is_current): ?>
                        <p class="mt-2 text-sm text-indigo-600 font-semibold">Your Current Plan</p>
                    <?php elseif ($tier_data['action_type'] == 'downgrade' || $tier_data['action_type'] == 'downgrade_disabled'): ?>
                        <p class="mt-2 text-sm text-gray-500">(Downgrade)</p>
                    <?php endif; ?>

                    <p class="mt-4 text-sm text-gray-500">
                        <?php echo htmlspecialchars($tier->get('sbt_description')); ?>
                    </p>

                    <?php
                    // Display features if available
                    $features = $tier->getAllFeatures();
                    if (!empty($features)):
                    ?>
                        <div class="mt-4">
                            <p class="text-sm font-medium text-gray-900 mb-2">Features:</p>
                            <ul class="text-sm text-gray-500 space-y-1">
                                <?php
                                $all_available = SubscriptionTier::getAllAvailableFeatures();
                                foreach ($features as $key => $value):
                                    if (isset($all_available[$key])):
                                        $label = $all_available[$key]['label'];
                                        if ($all_available[$key]['type'] == 'boolean'):
                                            if ($value):
                                ?>
                                                <li>✓ <?php echo htmlspecialchars($label); ?></li>
                                <?php
                                            endif;
                                        else:
                                ?>
                                            <li>• <?php echo htmlspecialchars($label); ?>: <?php echo htmlspecialchars($value); ?></li>
                                <?php
                                        endif;
                                    endif;
                                endforeach;
                                ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <div class="mt-6">
                        <?php if (!empty($tier_data['products'])): ?>
                            <div class="space-y-3">
                                <?php foreach ($tier_data['products'] as $product): ?>
                                    <div class="border-t pt-3">
                                        <p class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($product['name']); ?>
                                        </p>
                                        <?php if ($product['price'] > 0): ?>
                                            <p class="text-2xl font-bold text-gray-900">
                                                $<?php echo number_format($product['price'], 2); ?>
                                                <?php if ($product['period']): ?>
                                                    <span class="text-sm font-medium text-gray-500">
                                                        /<?php echo htmlspecialchars($product['period']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="mt-8">
                        <?php if ($tier_data['message']): ?>
                            <p class="text-sm text-gray-500 mb-2">
                                <?php echo htmlspecialchars($tier_data['message']); ?>
                            </p>
                        <?php endif; ?>

                        <?php if ($tier_data['action_type'] == 'current'): ?>
                            <button class="block w-full py-2 px-4 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-gray-100 cursor-not-allowed" disabled>
                                <?php echo htmlspecialchars($tier_data['button_text']); ?>
                            </button>
                        <?php elseif ($tier_data['button_enabled'] && !empty($tier_data['products'])): ?>
                            <?php if (count($tier_data['products']) == 1): ?>
                                <?php // Single product - direct action ?>
                                <?php echo $formwriter->form_start('tier_action_' . $tier->key, '', 'POST'); ?>
                                <input type="hidden" name="action" value="<?php echo htmlspecialchars($tier_data['action_type']); ?>">
                                <input type="hidden" name="product_id" value="<?php echo $tier_data['products'][0]['id']; ?>">
                                <button type="submit" class="block w-full py-2 px-4 border border-transparent rounded-md text-center text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">
                                    <?php echo htmlspecialchars($tier_data['button_text']); ?>
                                </button>
                                <?php echo $formwriter->form_end(); ?>
                            <?php else: ?>
                                <?php // Multiple products - show dropdown ?>
                                <?php echo $formwriter->form_start('tier_action_' . $tier->key, '', 'POST'); ?>
                                <input type="hidden" name="action" value="<?php echo htmlspecialchars($tier_data['action_type']); ?>">
                                <div class="mb-2">
                                    <select name="product_id" class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm">
                                        <option value="">Select a product</option>
                                        <?php foreach ($tier_data['products'] as $product): ?>
                                            <option value="<?php echo $product['id']; ?>">
                                                <?php echo htmlspecialchars($product['name']); ?> -
                                                $<?php echo number_format($product['price'], 2); ?>
                                                <?php if ($product['period']): ?>
                                                    /<?php echo htmlspecialchars($product['period']); ?>
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="block w-full py-2 px-4 border border-transparent rounded-md text-center text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">
                                    <?php echo htmlspecialchars($tier_data['button_text']); ?>
                                </button>
                                <?php echo $formwriter->form_end(); ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <button class="block w-full py-2 px-4 border border-gray-300 rounded-md text-sm font-medium text-gray-400 bg-gray-100 cursor-not-allowed" disabled>
                                <?php echo htmlspecialchars($tier_data['button_text']); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

    </div>

    <?php if ($page_vars['show_cancel_button']): ?>
        <div class="mt-8 text-center">
            <?php echo $formwriter->form_start('cancel_form', '', 'POST'); ?>
            <input type="hidden" name="action" value="cancel">
            <button type="submit" class="inline-flex justify-center py-2 px-4 border border-red-300 shadow-sm text-sm font-medium rounded-md text-red-700 bg-white hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                    onclick="return confirm('Are you sure you want to cancel your subscription?');">
                <?php echo htmlspecialchars($page_vars['cancel_button_text']); ?>
            </button>
            <?php echo $formwriter->form_end(); ?>
        </div>
    <?php endif; ?>
</div>

<?php
echo PublicPage::EndPage($hoptions);
$page->public_footer($hoptions, NULL);
?>