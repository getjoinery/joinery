<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('MemberPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('subscriptions_logic.php', 'logic'));

	$page_vars = process_logic(subscriptions_logic($_GET, $_POST));

	$page = new MemberPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'My Subscriptions',
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			'Subscriptions' => '',
		),
	);
	$page->member_header($hoptions, NULL);
?>
<div class="jy-ui">

<!-- Page Title -->
<section class="page-title bg-transparent">
    <div class="container">
        <div class="page-title-row">
            <div class="page-title-content">
                <h1>My Subscriptions</h1>
                <span><?php echo htmlspecialchars($page_vars['user']->display_name()); ?></span>
            </div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item"><a href="/profile/profile">My Profile</a></li>
                    <li class="breadcrumb-item active">Subscriptions</li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div style="max-width: 860px; margin: 0 auto;">

            <!-- User summary -->
            <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 1.5rem; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;">
                <div>
                    <h5 style="margin: 0 0 0.25rem;"><?php echo htmlspecialchars($page_vars['user']->display_name()); ?></h5>
                    <p style="margin: 0; font-size: 0.875rem; color: var(--jy-color-text-muted);"><?php echo htmlspecialchars($page_vars['user']->get('usr_email')); ?></p>
                    <?php if($page_vars['user']->get('usr_timezone')): ?>
                    <p style="margin: 0; font-size: 0.875rem; color: var(--jy-color-text-muted);"><?php echo htmlspecialchars($page_vars['user']->get('usr_timezone')); ?></p>
                    <?php endif; ?>
                </div>
                <a href="/profile/account_edit" class="btn btn-outline">Edit Profile</a>
            </div>

            <!-- Active Subscriptions -->
            <?php if($page_vars['settings']->get_setting('products_active') && $page_vars['settings']->get_setting('subscriptions_active')): ?>
            <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden; margin-bottom: 1.5rem;">
                <div style="background: var(--jy-color-primary); color: #fff; padding: 1rem 1.5rem;">
                    <h5 style="margin: 0; color: #fff;">Your Subscriptions</h5>
                </div>
                <div style="padding: 1.5rem;">
                    <?php if(empty($page_vars['active_subscriptions'])): ?>
                        <p style="color: var(--jy-color-text-muted); margin: 0;">No active subscriptions.</p>
                    <?php else: ?>
                        <?php foreach($page_vars['active_subscriptions'] as $subscription): ?>
                        <?php
                        if($subscription->get('odi_subscription_cancelled_time')){
                            $status = 'Canceled on ' . LibraryFunctions::convert_time($subscription->get('odi_subscription_cancelled_time'), 'UTC', $page_vars['session']->get_timezone());
                            $action = '';
                        } else {
                            $status = $subscription->get('odi_subscription_status') ?: 'Active';
                            $action = '<a href="/profile/orders_recurring_action?order_item_id=' . $subscription->key . '" style="font-size: 0.875rem; color: #dc3545; margin-left: 0.75rem;">Cancel</a>';
                        }
                        ?>
                        <div style="border-bottom: 1px solid var(--jy-color-border); padding: 1rem 0; display: flex; justify-content: space-between; align-items: center; gap: 1rem;">
                            <div>
                                <p style="margin: 0; font-weight: 600;">$<?php echo htmlspecialchars($subscription->get('odi_price')); ?>/month</p>
                                <p style="margin: 0; font-size: 0.875rem; color: var(--jy-color-text-muted);"><?php echo htmlspecialchars($status); ?></p>
                            </div>
                            <?php if($action): ?>
                            <div><?php echo $action; ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if(!isset($active) || !$active): ?>
                    <div style="margin-top: 1.25rem;">
                        <a href="/product/recurring-donation" class="btn btn-primary">Start a New Subscription</a>
                    </div>
                    <?php endif; ?>

                    <div style="margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--jy-color-border); display: flex; gap: 1.5rem; flex-wrap: wrap;">
                        <a href="/profile/change-tier" style="font-size: 0.875rem;">Change Subscription Plan</a>
                        <a href="/profile/billing" style="font-size: 0.875rem;">Manage Payment Method</a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Order History -->
            <?php if($page_vars['settings']->get_setting('products_active')): ?>
            <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden;">
                <div style="background: var(--jy-color-primary); color: #fff; padding: 1rem 1.5rem;">
                    <h5 style="margin: 0; color: #fff;">Your Orders</h5>
                </div>
                <div style="padding: 1.5rem;">
                    <?php if(empty($page_vars['orders'])): ?>
                        <p style="color: var(--jy-color-text-muted); margin: 0;">No orders found.</p>
                    <?php else: ?>
                        <?php foreach($page_vars['orders'] as $order): ?>
                        <div style="border-bottom: 1px solid var(--jy-color-border); padding: 0.875rem 0; display: flex; justify-content: space-between; align-items: center; gap: 1rem;">
                            <p style="margin: 0; font-weight: 600;">Order #<?php echo htmlspecialchars($order->key); ?> &mdash; $<?php echo htmlspecialchars($order->get('ord_total_cost')); ?></p>
                            <p style="margin: 0; font-size: 0.875rem; color: var(--jy-color-text-muted);"><?php echo LibraryFunctions::convert_time($order->get('ord_timestamp'), 'UTC', $page_vars['session']->get_timezone(), 'M d, Y'); ?></p>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</section>

</div>
<?php
$page->member_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
