<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('change_tier_logic.php', 'logic'));

	$page_vars = process_logic(change_tier_logic($_GET, $_POST));

	$tab_menus = array(
		'My Profile' => '/profile',
		'Edit Account' => '/profile/account_edit',
		'Change Password' => '/profile/password_edit',
		'Edit Address' => '/profile/address_edit',
		'Edit Phone Number' => '/profile/phone_numbers_edit',
		'Change Contact Preferences' => '/profile/contact_preferences',
		'Change Tier' => '/profile/change-tier',
	);

	$page = new PublicPage();
	$hoptions=array(
		'title'=>'Change Tier',
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			'Change Tier' => '',
		),
	);
	$page->public_header($hoptions);

	echo PublicPage::BeginPage('Change Tier', $hoptions);

	echo PublicPage::tab_menu($tab_menus, 'Change Tier');

	// Display messages
	if (isset($page_vars['success_message'])) {
		echo PublicPage::alert('Success', $page_vars['success_message'], 'success');
	}

	if (isset($page_vars['error_message'])) {
		echo PublicPage::alert('Error', $page_vars['error_message'], 'danger');
	}

	?>

<!--==============================
Price Area
==============================-->
    <section class="space">
        <div class="container">
            <div class="title-area text-center">
                <h2 class="sec-title">Plans</h2>
				<?php if ($page_vars['current_tier']): ?>
					<p class="mt-3">Your current tier: <strong><?php echo htmlspecialchars($page_vars['current_tier']->get('sbt_display_name')); ?></strong></p>
				<?php endif; ?>

				<?php if ($page_vars['has_cancelled_subscription'] && !$page_vars['is_expired']): ?>
					<p class="mt-2 text-warning">Your subscription is scheduled to cancel at the end of your billing period</p>
				<?php elseif ($page_vars['is_expired']): ?>
					<p class="mt-2 text-danger">Your subscription has expired</p>
				<?php endif; ?>
            </div>

			<?php if ($page_vars['show_reactivate_button']): ?>
				<div class="text-center mb-4">
					<?php
					$formwriter = $page->getFormWriter();
					echo $formwriter->begin_form("reactivate_form", "POST", "/profile/change-tier");
					?>
					<input type="hidden" name="action" value="reactivate">
					<button type="submit" class="th-btn" style="background-color: #28a745;">Reactivate Subscription</button>
					<?php echo $formwriter->end_form(); ?>
				</div>
			<?php endif; ?>

            <div id="monthly" class="wrapper-full">
                <div class="row justify-content-center">
					<?php foreach ($page_vars['tier_display_data'] as $tier_data):
						$tier = $tier_data['tier'];
						$is_current = $tier_data['is_current'];
						$active = $is_current ? 'active' : '';
					?>

                    <div class="col-xl-4 col-md-6">
                        <div class="price-box th-ani <?php echo $active; ?>">
                            <div class="price-title-wrap">
                                <h3 class="box-title"><?php echo htmlspecialchars($tier->get('sbt_display_name')); ?></h3>
								<?php if ($is_current): ?>
									<p class="subtitle" style="color: #28a745; font-weight: bold;">CURRENT PLAN</p>
								<?php elseif ($tier_data['action_type'] == 'downgrade' || $tier_data['action_type'] == 'downgrade_disabled'): ?>
									<p class="subtitle">(Downgrade)</p>
								<?php endif; ?>
                            </div>

							<p class="box-text">&nbsp;</p>
							<?php if (!empty($tier_data['products'])): ?>
								<?php foreach ($tier_data['products'] as $product): ?>
									<h4 class="box-price">
										<?php if ($product['price'] > 0): ?>
											$<?php echo number_format($product['price'], 2); ?>
											<?php if ($product['period']): ?>
												<span class="duration">/<?php echo htmlspecialchars($product['period']); ?></span>
											<?php endif; ?>
										<?php else: ?>
											Free
										<?php endif; ?>
									</h4>
								<?php endforeach; ?>
							<?php endif; ?>

                            <div class="box-content">

								<?php echo $tier->get('sbt_description'); ?>

								<?php if ($tier_data['message']): ?>
									<p class="box-text2"><?php echo htmlspecialchars($tier_data['message']); ?></p>
								<?php endif; ?>

								<?php if ($tier_data['action_type'] == 'current'): ?>
									<a class="th-btn btn-fw style-radius" style="cursor: not-allowed; opacity: 0.6;"><?php echo htmlspecialchars($tier_data['button_text']); ?></a>
								<?php elseif ($tier_data['button_enabled'] && !empty($tier_data['products'])): ?>
									<?php if (count($tier_data['products']) == 1): ?>
										<?php // Single product - direct action ?>
										<?php
										$formwriter = $page->getFormWriter();
										echo $formwriter->begin_form("tier_action_".$tier->key, "POST", "/profile/change-tier");
										?>
										<input type="hidden" name="action" value="<?php echo htmlspecialchars($tier_data['action_type']); ?>">
										<input type="hidden" name="product_id" value="<?php echo $tier_data['products'][0]['id']; ?>">
										<button type="submit" class="th-btn btn-fw style-radius"><?php echo htmlspecialchars($tier_data['button_text']); ?></button>
										<?php echo $formwriter->end_form(); ?>
									<?php else: ?>
										<?php // Multiple products - show dropdown ?>
										<?php
										$formwriter = $page->getFormWriter();
										echo $formwriter->begin_form("tier_action_".$tier->key, "POST", "/profile/change-tier");
										?>
										<input type="hidden" name="action" value="<?php echo htmlspecialchars($tier_data['action_type']); ?>">
										<div class="mb-2">
											<select name="product_id" class="form-control" required>
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
										<button type="submit" class="th-btn btn-fw style-radius"><?php echo htmlspecialchars($tier_data['button_text']); ?></button>
										<?php echo $formwriter->end_form(); ?>
									<?php endif; ?>
								<?php else: ?>
									<a class="th-btn btn-fw style-radius" style="cursor: not-allowed; opacity: 0.6;"><?php echo htmlspecialchars($tier_data['button_text']); ?></a>
								<?php endif; ?>
                            </div>
                        </div>
                    </div>
					<?php endforeach; ?>

                </div>
            </div>

        </div>
    </section>

	<!--==============================
Cancel Subscription Area
==============================-->
	<?php if ($page_vars['show_cancel_button']): ?>
        <div class="container">
            <div class="row gy-5 align-items-center">
                    <div class="consultation-area">
                        <div class="consultation-form">
                            <h4 class="title mb-30 mt-n2 text-center">Cancel Subscription</h4>
							<p class="text-center">Your account will remain active until the last day of your subscription.</p>
                            <div class="row">
                                <div class="col-12 form-group mb-0 text-center">
									<?php
									$formwriter = $page->getFormWriter();
									echo $formwriter->begin_form("cancel_form", "POST", "/profile/change-tier");
									?>
									<input type="hidden" name="action" value="cancel">
									<button type="submit" class="th-btn style-radius" style="background-color: #dc3545;"
											onclick="return confirm('Are you sure you want to cancel your subscription?');">
										<?php echo htmlspecialchars($page_vars['cancel_button_text']); ?>
									</button>
									<?php echo $formwriter->end_form(); ?>
                                </div>
                            </div>
                            <p class="form-messages mb-0 mt-3"></p>
                        </div>
                    </div>
            </div>
        </div>
	<?php endif; ?>

	<?php

		echo PublicPage::EndPage();
		$page->public_footer($foptions=array('track'=>TRUE));
?>
