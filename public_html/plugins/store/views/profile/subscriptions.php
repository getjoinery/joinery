<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('subscriptions_logic.php', 'logic', 'system', null, 'store', false));

	$page_vars = process_logic(subscriptions_logic(array_merge($_GET, $_POST, $params ?? [])));

	$page = new PublicPage();
	$page->public_header([
		'is_valid_page' => $is_valid_page ?? false,
		'title' => 'My Subscriptions',
	]);
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">
        <div class="jy-narrow-lg">

            <div class="jy-page-header">
                <div class="jy-page-header-bar">
                    <div>
                        <h1>My Subscriptions</h1>
                        <span class="muted"><?php echo htmlspecialchars($page_vars['user']->display_name()); ?></span>
                    </div>
                    <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                        <ol>
                            <li><a href="/">Home</a></li>
                            <li><a href="/profile">My Profile</a></li>
                            <li class="active">Subscriptions</li>
                        </ol>
                    </nav>
                </div>
            </div>

            <!-- User summary -->
            <div class="jy-panel jy-subs-summary">
                <div>
                    <h5 class="jy-subs-name"><?php echo htmlspecialchars($page_vars['user']->display_name()); ?></h5>
                    <p class="muted text-sm jy-tight"><?php echo htmlspecialchars($page_vars['user']->get('usr_email')); ?></p>
                    <?php if($page_vars['user']->get('usr_timezone')): ?>
                    <p class="muted text-sm jy-tight"><?php echo htmlspecialchars($page_vars['user']->get('usr_timezone')); ?></p>
                    <?php endif; ?>
                </div>
                <a href="/profile/account_edit" class="btn btn-outline">Edit Profile</a>
            </div>

            <!-- Active Subscriptions -->
            <?php if($page_vars['settings']->get_setting('products_active') && $page_vars['settings']->get_setting('subscriptions_active')): ?>
            <div class="card">
                <div class="card-header">
                    <h5 class="jy-tight">Your Subscriptions</h5>
                </div>
                <div class="card-body">
                    <?php if(empty($page_vars['active_subscriptions'])): ?>
                        <p class="muted jy-tight">No active subscriptions.</p>
                    <?php else: ?>
                        <?php foreach($page_vars['active_subscriptions'] as $subscription): ?>
                        <?php
                        if($subscription->get('odi_subscription_cancelled_time')){
                            $status = 'Canceled on ' . LibraryFunctions::convert_time($subscription->get('odi_subscription_cancelled_time'), 'UTC', $page_vars['session']->get_timezone());
                            $action = '';
                        } else {
                            $status = $subscription->get('odi_subscription_status') ?: 'Active';
                            $action = '<a href="/profile/orders_recurring_action?order_item_id=' . $subscription->key . '" class="btn btn-ghost btn-sm jy-subs-cancel">Cancel</a>';
                        }
                        ?>
                        <div class="jy-subs-row">
                            <div>
                                <p class="jy-subs-amt">$<?php echo htmlspecialchars($subscription->get('odi_price')); ?>/month</p>
                                <p class="muted text-sm jy-tight"><?php echo htmlspecialchars($status); ?></p>
                            </div>
                            <?php if($action): ?>
                            <div><?php echo $action; ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if(!isset($active) || !$active): ?>
                    <div class="jy-mt-5">
                        <a href="/product/recurring-donation" class="btn btn-primary">Start a New Subscription</a>
                    </div>
                    <?php endif; ?>

                    <div class="jy-subs-links">
                        <a href="/profile/change-tier" class="text-sm">Change Subscription Plan</a>
                        <a href="/profile/billing" class="text-sm">Manage Payment Method</a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Order History -->
            <?php if($page_vars['settings']->get_setting('products_active')): ?>
            <div class="card">
                <div class="card-header">
                    <h5 class="jy-tight">Your Orders</h5>
                </div>
                <div class="card-body">
                    <?php if(empty($page_vars['orders'])): ?>
                        <p class="muted jy-tight">No orders found.</p>
                    <?php else: ?>
                        <?php foreach($page_vars['orders'] as $order): ?>
                        <div class="jy-subs-orderrow">
                            <p class="jy-subs-amt">Order #<?php echo htmlspecialchars($order->key); ?> &mdash; $<?php echo htmlspecialchars($order->get('ord_total_cost')); ?></p>
                            <p class="muted text-sm jy-tight"><?php echo LibraryFunctions::convert_time($order->get('ord_timestamp'), 'UTC', $page_vars['session']->get_timezone(), 'M d, Y'); ?></p>
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
$page->public_footer(['track' => TRUE, 'show_survey' => TRUE]);
?>
