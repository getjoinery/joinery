<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('profile_logic.php', 'logic'));

	$page_vars = process_logic(profile_logic($_GET, $_POST));

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'My Profile',
		'breadcrumbs' => array(
			'My Profile' => '',
		),
	);
	$page->public_header($hoptions, NULL);
?>

<!-- Page Title -->
<section class="page-title bg-transparent">
    <div class="container">
        <div class="page-title-row">
            <div class="page-title-content">
                <h1>My Profile</h1>
            </div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item active">My Profile</li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">

        <?php
        foreach($page_vars['display_messages'] AS $display_message) {
            if($display_message->identifier == 'profilebox') {
                echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
            }
        }
        ?>

        <div style="display: flex; gap: 2rem; align-items: flex-start; flex-wrap: wrap;">

            <!-- Main content -->
            <div style="flex: 2; min-width: 0;">

                <!-- Events & Courses -->
                <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden; margin-bottom: 1.5rem;">
                    <div style="background: var(--color-primary); color: #fff; padding: 1rem 1.5rem; display: flex; justify-content: space-between; align-items: center;">
                        <h5 style="margin: 0; color: #fff;">Events &amp; Courses</h5>
                    </div>
                    <div style="padding: 1.5rem;">
                        <?php if(!$page_vars['num_events']): ?>
                            <p style="color: var(--color-muted); margin: 0;">You have no event registrations.</p>
                        <?php else: ?>
                            <?php foreach($page_vars['event_registrations'] as $event): ?>
                            <div style="border-bottom: 1px solid var(--color-border, #eee); padding: 1rem 0; display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem;">
                                <div style="flex: 1;">
                                    <h6 style="margin: 0 0 0.25rem;">
                                        <a href="<?php echo htmlspecialchars($event['event_link']); ?>"><?php echo htmlspecialchars($event['event_name']); ?></a>
                                    </h6>
                                    <?php if($event['event_time']): ?>
                                    <p style="margin: 0; font-size: 0.875rem; color: var(--color-muted);"><?php echo htmlspecialchars($event['event_time']); ?></p>
                                    <?php endif; ?>
                                </div>
                                <div style="flex-shrink: 0;">
                                    <?php
                                    if($event['event_status'] == 'Active'){
                                        if($event['event_expires']){
                                            echo '<span style="background: #d4edda; color: #155724; font-size: 0.8125rem; padding: 0.2rem 0.625rem; border-radius: 4px;">Expires ' . htmlspecialchars($event['event_expires']) . '</span>';
                                        } else {
                                            echo '<span style="background: #d4edda; color: #155724; font-size: 0.8125rem; padding: 0.2rem 0.625rem; border-radius: 4px;">Active</span>';
                                        }
                                    } else if($event['event_status'] == 'Expired'){
                                        echo '<span style="background: #f8f9fa; color: #6c757d; font-size: 0.8125rem; padding: 0.2rem 0.625rem; border-radius: 4px;">Expired</span>';
                                    } else if($event['event_status'] == 'Canceled'){
                                        echo '<span style="background: #f8f9fa; color: #6c757d; font-size: 0.8125rem; padding: 0.2rem 0.625rem; border-radius: 4px;">Canceled</span>';
                                    } else if($event['event_status'] == 'Completed'){
                                        echo '<span style="background: #f8f9fa; color: #6c757d; font-size: 0.8125rem; padding: 0.2rem 0.625rem; border-radius: 4px;">Completed</span>';
                                    }
                                    ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Subscriptions -->
                <?php if($page_vars['subscriptions']): ?>
                <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden; margin-bottom: 1.5rem;">
                    <div style="background: var(--color-primary); color: #fff; padding: 1rem 1.5rem;">
                        <h5 style="margin: 0; color: #fff;">Subscriptions</h5>
                    </div>
                    <div style="padding: 1.5rem;">
                        <?php foreach($page_vars['subscriptions'] as $subscription): ?>
                        <?php
                        if($subscription->get('odi_subscription_cancelled_time')){
                            $status = 'Canceled on ' . LibraryFunctions::convert_time($subscription->get('odi_subscription_cancelled_time'), 'UTC', $page_vars['session']->get_timezone());
                            $action = '';
                        } else {
                            $status = $subscription->get('odi_subscription_status') ?: 'Active';
                            $action = '<a href="/profile/orders_recurring_action?order_item_id=' . $subscription->key . '" style="font-size: 0.875rem; color: #dc3545;">Cancel</a>';
                        }
                        ?>
                        <div style="border-bottom: 1px solid var(--color-border, #eee); padding: 0.875rem 0; display: flex; justify-content: space-between; align-items: center; gap: 1rem;">
                            <div>
                                <p style="margin: 0; font-weight: 600;">$<?php echo htmlspecialchars($subscription->get('odi_price')); ?>/month</p>
                                <p style="margin: 0; font-size: 0.875rem; color: var(--color-muted);"><?php echo htmlspecialchars($status); ?></p>
                            </div>
                            <?php if($action): ?>
                            <div><?php echo $action; ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Orders -->
                <?php if($page_vars['settings']->get_setting('products_active')): ?>
                <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden; margin-bottom: 1.5rem;">
                    <div style="background: var(--color-primary); color: #fff; padding: 1rem 1.5rem;">
                        <h5 style="margin: 0; color: #fff;">Orders</h5>
                    </div>
                    <div style="padding: 1.5rem;">
                        <?php if(empty($page_vars['orders'])): ?>
                            <p style="color: var(--color-muted); margin: 0;">No orders found.</p>
                        <?php else: ?>
                            <?php foreach($page_vars['orders'] as $order): ?>
                            <div style="border-bottom: 1px solid var(--color-border, #eee); padding: 0.875rem 0; display: flex; justify-content: space-between; align-items: center; gap: 1rem;">
                                <p style="margin: 0; font-weight: 600;">Order #<?php echo htmlspecialchars($order->key); ?> &mdash; $<?php echo htmlspecialchars($order->get('ord_total_cost')); ?></p>
                                <p style="margin: 0; font-size: 0.875rem; color: var(--color-muted);"><?php echo LibraryFunctions::convert_time($order->get('ord_timestamp'), 'UTC', $page_vars['session']->get_timezone(), 'M d, Y'); ?></p>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>

            <!-- Sidebar -->
            <div style="flex: 1; min-width: 260px; max-width: 320px;">

                <!-- User info card -->
                <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 1.5rem; margin-bottom: 1.5rem; text-align: center;">
                    <div style="width: 72px; height: 72px; border-radius: 50%; background: var(--color-primary); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 2rem; margin: 0 auto 1rem; overflow: hidden;">
                        <?php
                        $pic = $page_vars['user']->get_picture_link('avatar');
                        if($pic):
                        ?>
                        <img src="<?php echo htmlspecialchars($pic); ?>" alt="" style="width: 100%; height: 100%; object-fit: cover;">
                        <?php else: ?>
                        &#128100;
                        <?php endif; ?>
                    </div>
                    <h5 style="margin: 0 0 0.25rem;"><?php echo htmlspecialchars($page_vars['user']->display_name()); ?></h5>
                    <p style="margin: 0 0 0.25rem; font-size: 0.875rem; color: var(--color-muted);"><?php echo htmlspecialchars($page_vars['user']->get('usr_email')); ?></p>
                    <?php if($page_vars['address']->get_address_string(', ')): ?>
                    <p style="margin: 0 0 1rem; font-size: 0.875rem; color: var(--color-muted);"><?php echo htmlspecialchars($page_vars['address']->get_address_string(', ')); ?></p>
                    <?php else: ?>
                    <div style="margin-bottom: 1rem;"></div>
                    <?php endif; ?>
                    <a href="/profile/account_edit" class="btn btn-primary" style="width: 100%;">Edit Account</a>
                </div>

                <!-- Mailing lists -->
                <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 1.25rem; font-size: 0.875rem;">
                    <h6 style="margin: 0 0 0.75rem; font-size: 0.9375rem;">Mailing Lists</h6>
                    <?php if(empty($page_vars['user_subscribed_list'])): ?>
                        <p style="margin: 0; color: var(--color-muted);">Not subscribed to any lists.</p>
                    <?php else: ?>
                        <p style="margin: 0; color: var(--color-muted);"><?php echo htmlspecialchars(implode(', ', $page_vars['user_subscribed_list'])); ?></p>
                    <?php endif; ?>
                </div>

            </div>

        </div>
    </div>
</section>

<?php
$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
