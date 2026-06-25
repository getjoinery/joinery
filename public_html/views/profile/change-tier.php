<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('change_tier_logic.php', 'logic'));

$page_vars = process_logic(change_tier_logic(array_merge($_GET, $_POST, $params ?? [])));

$page = new PublicPage();
$page->public_header([
    'title' => 'Change Tier',
]);

$formwriter = $page->getFormWriter('tier_form');
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">

        <div class="jy-page-header">
            <div class="jy-page-header-bar">
                <div>
                    <h1>Choose Your Membership Level</h1>
                    <?php if ($page_vars['current_tier']): ?>
                    <span class="muted">Current tier: <strong><?php echo htmlspecialchars($page_vars['current_tier']->get('sbt_display_name')); ?></strong></span>
                    <?php endif; ?>
                </div>
                <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                    <ol>
                        <li><a href="/">Home</a></li>
                        <li><a href="/profile">My Profile</a></li>
                        <li class="active">Change Tier</li>
                    </ol>
                </nav>
            </div>
        </div>

        <?php if (isset($page_vars['success_message'])): ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($page_vars['success_message']); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($page_vars['error_message'])): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($page_vars['error_message']); ?>
        </div>
        <?php endif; ?>

        <?php if ($page_vars['has_cancelled_subscription'] && !$page_vars['is_expired']): ?>
        <div class="alert alert-warn">
            Your subscription is scheduled to cancel at the end of your billing period.
        </div>
        <?php elseif ($page_vars['is_expired']): ?>
        <div class="alert alert-danger">
            Your subscription has expired.
        </div>
        <?php endif; ?>

        <?php if (!empty($page_vars['is_paypal']) && $page_vars['has_active_subscription']): ?>
        <div class="alert alert-info">
            Your subscription is managed through PayPal. To change your plan, cancel your current subscription and subscribe to a new tier.
        </div>
        <?php endif; ?>

        <?php if ($page_vars['show_reactivate_button']): ?>
        <div class="jy-tier-reactivate">
            <?php $formwriter->begin_form(); ?>
            <input type="hidden" name="action" value="reactivate">
            <button type="submit" class="btn btn-primary">Reactivate Subscription</button>
            <?php $formwriter->end_form(); ?>
        </div>
        <?php endif; ?>

        <!-- Tier cards -->
        <div class="jy-tier-cards">

            <?php foreach ($page_vars['tier_display_data'] as $tier_data): ?>
            <?php
            $tier = $tier_data['tier'];
            $is_current = $tier_data['is_current'];
            ?>
            <div class="card jy-tier-card<?php echo $is_current ? ' is-current' : ''; ?>">
                <div class="card-header">
                    <h4 class="jy-tier-name"><?php echo htmlspecialchars($tier->get('sbt_display_name')); ?></h4>
                    <?php if ($is_current): ?>
                    <p class="jy-tier-current-label">Your Current Plan</p>
                    <?php elseif ($tier_data['action_type'] == 'downgrade' || $tier_data['action_type'] == 'downgrade_disabled'): ?>
                    <p class="muted text-sm jy-tier-sub">Downgrade</p>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($tier->get('sbt_description')): ?>
                    <div class="muted text-sm jy-tier-section">
                        <?php echo $tier->get('sbt_description'); ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($tier_data['products'])): ?>
                    <div class="jy-tier-section">
                        <?php foreach ($tier_data['products'] as $product): ?>
                        <div class="jy-tier-product">
                            <p class="jy-tier-product-name"><?php echo htmlspecialchars($product['name']); ?></p>
                            <?php if ($product['price'] > 0): ?>
                            <p class="jy-tier-price">
                                $<?php echo number_format($product['price'], 2); ?>
                                <?php if ($product['period']): ?>
                                <span class="muted text-sm jy-tier-period">/<?php echo htmlspecialchars($product['period']); ?></span>
                                <?php endif; ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($tier_data['message']): ?>
                    <p class="muted text-sm jy-tier-msg">
                        <?php echo htmlspecialchars($tier_data['message']); ?>
                    </p>
                    <?php endif; ?>

                    <?php if ($tier_data['action_type'] == 'current'): ?>
                        <button class="btn btn-block" disabled><?php echo htmlspecialchars($tier_data['button_text']); ?></button>
                    <?php elseif (!empty($page_vars['is_paypal']) && ($tier_data['action_type'] == 'upgrade' || $tier_data['action_type'] == 'downgrade')): ?>
                        <button class="btn btn-block" disabled><?php echo htmlspecialchars($tier_data['button_text']); ?></button>
                    <?php elseif ($tier_data['button_enabled'] && !empty($tier_data['products'])): ?>
                        <?php if (count($tier_data['products']) == 1): ?>
                            <?php $formwriter->begin_form(); ?>
                            <input type="hidden" name="action" value="<?php echo htmlspecialchars($tier_data['action_type']); ?>">
                            <input type="hidden" name="product_id" value="<?php echo $tier_data['products'][0]['id']; ?>">
                            <button type="submit" class="btn btn-primary btn-block">
                                <?php echo htmlspecialchars($tier_data['button_text']); ?>
                            </button>
                            <?php $formwriter->end_form(); ?>
                        <?php else: ?>
                            <?php $formwriter->begin_form(); ?>
                            <input type="hidden" name="action" value="<?php echo htmlspecialchars($tier_data['action_type']); ?>">
                            <div class="form-group">
                                <select name="product_id" class="form-control">
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
                            <button type="submit" class="btn btn-primary btn-block">
                                <?php echo htmlspecialchars($tier_data['button_text']); ?>
                            </button>
                            <?php $formwriter->end_form(); ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <button class="btn btn-block" disabled><?php echo htmlspecialchars($tier_data['button_text']); ?></button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

        </div>

        <?php if ($page_vars['show_cancel_button']): ?>
        <div class="text-center">
            <?php $formwriter->begin_form(); ?>
            <input type="hidden" name="action" value="cancel">
            <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to cancel your subscription?');">
                <?php echo htmlspecialchars($page_vars['cancel_button_text']); ?>
            </button>
            <?php $formwriter->end_form(); ?>
        </div>
        <?php endif; ?>

    </div>
</section>
</div>
<?php
$page->public_footer();
?>
