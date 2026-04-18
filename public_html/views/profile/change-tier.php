<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('MemberPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('change_tier_logic.php', 'logic'));

$page_vars = process_logic(change_tier_logic($_GET, $_POST));

$page = new MemberPage();
$hoptions = array(
    'title' => 'Change Tier',
    'breadcrumbs' => array(
        'My Profile' => '/profile/profile',
        'Change Tier' => '',
    ),
);
$page->member_header($hoptions, NULL);

$formwriter = $page->getFormWriter('tier_form');
?>
<div class="jy-ui">

<!-- Page Title -->
<section class="page-title bg-transparent">
    <div class="container">
        <div class="page-title-row">
            <div class="page-title-content">
                <h1>Choose Your Membership Level</h1>
                <?php if ($page_vars['current_tier']): ?>
                <span>Current tier: <strong><?php echo htmlspecialchars($page_vars['current_tier']->get('sbt_display_name')); ?></strong></span>
                <?php endif; ?>
            </div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item"><a href="/profile/profile">My Profile</a></li>
                    <li class="breadcrumb-item active">Change Tier</li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">

        <?php if (isset($page_vars['success_message'])): ?>
        <div class="alert alert-success" style="margin-bottom: 1.5rem;">
            <?php echo htmlspecialchars($page_vars['success_message']); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($page_vars['error_message'])): ?>
        <div class="alert alert-error" style="margin-bottom: 1.5rem;">
            <?php echo htmlspecialchars($page_vars['error_message']); ?>
        </div>
        <?php endif; ?>

        <?php if ($page_vars['has_cancelled_subscription'] && !$page_vars['is_expired']): ?>
        <div class="alert" style="background: #fff3cd; border-left: 4px solid #f6c23e; padding: 1rem 1.25rem; border-radius: 4px; margin-bottom: 1.5rem;">
            Your subscription is scheduled to cancel at the end of your billing period.
        </div>
        <?php elseif ($page_vars['is_expired']): ?>
        <div class="alert alert-error" style="margin-bottom: 1.5rem;">
            Your subscription has expired.
        </div>
        <?php endif; ?>

        <?php if (!empty($page_vars['is_paypal']) && $page_vars['has_active_subscription']): ?>
        <div class="alert" style="background: #e2e3e5; border-left: 4px solid #6c757d; padding: 1rem 1.25rem; border-radius: 4px; margin-bottom: 1.5rem;">
            Your subscription is managed through PayPal. To change your plan, cancel your current subscription and subscribe to a new tier.
        </div>
        <?php endif; ?>

        <?php if ($page_vars['show_reactivate_button']): ?>
        <div style="margin-bottom: 1.5rem; text-align: center;">
            <?php $formwriter->begin_form(); ?>
            <input type="hidden" name="action" value="reactivate">
            <button type="submit" class="btn btn-primary">Reactivate Subscription</button>
            <?php $formwriter->end_form(); ?>
        </div>
        <?php endif; ?>

        <!-- Tier cards -->
        <div style="display: flex; gap: 1.5rem; flex-wrap: wrap; justify-content: center; margin-bottom: 2rem;">

            <?php foreach ($page_vars['tier_display_data'] as $tier_data): ?>
            <?php
            $tier = $tier_data['tier'];
            $is_current = $tier_data['is_current'];
            ?>
            <div style="flex: 1; min-width: 240px; max-width: 340px; background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden;<?php if($is_current): ?> box-shadow: 0 0 0 3px var(--jy-color-primary), 0 2px 8px rgba(0,0,0,0.15);<?php endif; ?>">
                <div style="background: <?php echo $is_current ? 'var(--jy-color-primary)' : 'var(--jy-color-surface)'; ?>; color: <?php echo $is_current ? '#fff' : 'inherit'; ?>; padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--jy-color-border);">
                    <h4 style="margin: 0; color: <?php echo $is_current ? '#fff' : 'inherit'; ?>;"><?php echo htmlspecialchars($tier->get('sbt_display_name')); ?></h4>
                    <?php if ($is_current): ?>
                    <p style="margin: 0.25rem 0 0; font-size: 0.875rem; color: rgba(255,255,255,0.85);">Your Current Plan</p>
                    <?php elseif ($tier_data['action_type'] == 'downgrade' || $tier_data['action_type'] == 'downgrade_disabled'): ?>
                    <p style="margin: 0.25rem 0 0; font-size: 0.875rem; color: var(--jy-color-text-muted);">Downgrade</p>
                    <?php endif; ?>
                </div>
                <div style="padding: 1.5rem;">
                    <?php if ($tier->get('sbt_description')): ?>
                    <div style="font-size: 0.9rem; color: var(--jy-color-text-muted); margin-bottom: 1.25rem;">
                        <?php echo $tier->get('sbt_description'); ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($tier_data['products'])): ?>
                    <div style="margin-bottom: 1.25rem;">
                        <?php foreach ($tier_data['products'] as $product): ?>
                        <div style="border-top: 1px solid var(--jy-color-border); padding-top: 0.875rem; margin-top: 0.875rem;">
                            <p style="margin: 0 0 0.25rem; font-weight: 600;"><?php echo htmlspecialchars($product['name']); ?></p>
                            <?php if ($product['price'] > 0): ?>
                            <p style="margin: 0; font-size: 1.5rem; font-weight: 700;">
                                $<?php echo number_format($product['price'], 2); ?>
                                <?php if ($product['period']): ?>
                                <span style="font-size: 0.875rem; font-weight: 400; color: var(--jy-color-text-muted);">/<?php echo htmlspecialchars($product['period']); ?></span>
                                <?php endif; ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($tier_data['message']): ?>
                    <p style="font-size: 0.875rem; color: var(--jy-color-text-muted); margin-bottom: 0.75rem;">
                        <?php echo htmlspecialchars($tier_data['message']); ?>
                    </p>
                    <?php endif; ?>

                    <?php if ($tier_data['action_type'] == 'current'): ?>
                        <button class="btn" style="width: 100%; background: #e9ecef; color: #6c757d; cursor: not-allowed;" disabled>
                            <?php echo htmlspecialchars($tier_data['button_text']); ?>
                        </button>
                    <?php elseif (!empty($page_vars['is_paypal']) && ($tier_data['action_type'] == 'upgrade' || $tier_data['action_type'] == 'downgrade')): ?>
                        <button class="btn" style="width: 100%; background: #e9ecef; color: #adb5bd; cursor: not-allowed;" disabled>
                            <?php echo htmlspecialchars($tier_data['button_text']); ?>
                        </button>
                    <?php elseif ($tier_data['button_enabled'] && !empty($tier_data['products'])): ?>
                        <?php if (count($tier_data['products']) == 1): ?>
                            <?php $formwriter->begin_form(); ?>
                            <input type="hidden" name="action" value="<?php echo htmlspecialchars($tier_data['action_type']); ?>">
                            <input type="hidden" name="product_id" value="<?php echo $tier_data['products'][0]['id']; ?>">
                            <button type="submit" class="btn btn-primary" style="width: 100%;">
                                <?php echo htmlspecialchars($tier_data['button_text']); ?>
                            </button>
                            <?php $formwriter->end_form(); ?>
                        <?php else: ?>
                            <?php $formwriter->begin_form(); ?>
                            <input type="hidden" name="action" value="<?php echo htmlspecialchars($tier_data['action_type']); ?>">
                            <div style="margin-bottom: 0.75rem;">
                                <select name="product_id" style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid var(--jy-color-border); border-radius: 4px; font-size: 0.9375rem;">
                                    <option value="">Select a product</option>
                                    <?php foreach ($tier_data['products'] as $product): ?>
                                    <option value="<?php echo $product['id']; ?>">
                                        <?php echo htmlspecialchars($product['name']); ?> —
                                        $<?php echo number_format($product['price'], 2); ?>
                                        <?php if ($product['period']): ?>/<?php echo htmlspecialchars($product['period']); ?><?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary" style="width: 100%;">
                                <?php echo htmlspecialchars($tier_data['button_text']); ?>
                            </button>
                            <?php $formwriter->end_form(); ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <button class="btn" style="width: 100%; background: #e9ecef; color: #adb5bd; cursor: not-allowed;" disabled>
                            <?php echo htmlspecialchars($tier_data['button_text']); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

        </div>

        <?php if ($page_vars['show_cancel_button']): ?>
        <div style="text-align: center;">
            <?php $formwriter->begin_form(); ?>
            <input type="hidden" name="action" value="cancel">
            <button type="submit" class="btn btn-outline" style="color: #dc3545; border-color: #dc3545;"
                    onclick="return confirm('Are you sure you want to cancel your subscription?');">
                <?php echo htmlspecialchars($page_vars['cancel_button_text']); ?>
            </button>
            <?php $formwriter->end_form(); ?>
        </div>
        <?php endif; ?>

    </div>
</section>

</div>
<?php
$page->member_footer($hoptions, NULL);
?>
