<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('adm/logic/admin_order_logic.php'));

	$page_vars = process_logic(admin_order_logic($_GET, $_POST));
	extract($page_vars);

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'orders-list',
		'page_title' => 'Order',
		'readable_title' => 'Order #'.$order->key,
		'breadcrumbs' => array(
			'Orders'=>'/admin/admin_orders',
			'Order #'.$order->key => '',
		),
		'session' => $session,
		'no_page_card' => true,
		'header_action' => $dropdown_button,
	)
	);
	?>

	<!-- Two Column Layout -->
	<div class="row g-3 mb-3">
		<!-- LEFT COLUMN: Order & Items Information -->
		<div class="col-xxl-6">

			<!-- Order Information Card -->
			<div class="card">
				<div class="card-header bg-body-tertiary">
					<h6 class="mb-0"><span class="fas fa-shopping-cart me-2"></span>Order Information</h6>
				</div>
				<div class="card-body">
					<table class="table table-borderless fs-9 fw-medium mb-0">
						<tbody>
							<tr>
								<td class="p-1" style="width: 35%;">Order ID:</td>
								<td class="p-1 text-600"><strong><?php echo $order->key; ?></strong></td>
							</tr>
							<tr>
								<td class="p-1" style="width: 35%;">Order Total:</td>
								<td class="p-1 text-600"><strong><?php echo $currency_symbol.number_format($order->get('ord_total_cost'), 2); ?></strong></td>
							</tr>
							<?php if($order->get('ord_refund_amount')): ?>
							<tr>
								<td class="p-1" style="width: 35%;">Refund Amount:</td>
								<td class="p-1">
									<span class="badge badge-subtle-danger"><?php echo $currency_symbol.number_format($order->get('ord_refund_amount'), 2); ?> Refunded</span>
								</td>
							</tr>
							<tr>
								<td class="p-1" style="width: 35%;">Last Refund:</td>
								<td class="p-1 text-600"><?php echo LibraryFunctions::convert_time($order->get('ord_refund_time'), "UTC", $session->get_timezone()); ?></td>
							</tr>
							<?php if($order->get('ord_refund_note')): ?>
							<tr>
								<td class="p-1" style="width: 35%;">Refund Note:</td>
								<td class="p-1 text-600"><?php echo htmlspecialchars($order->get('ord_refund_note')); ?></td>
							</tr>
							<?php endif; ?>
							<?php endif; ?>
							<tr>
								<td class="p-1" style="width: 35%;">Order Time:</td>
								<td class="p-1 text-600"><?php echo LibraryFunctions::convert_time($order->get('ord_timestamp'), "UTC", $session->get_timezone(), 'M j, Y g:i A T'); ?></td>
							</tr>
							<tr>
								<td class="p-1" style="width: 35%;">Customer:</td>
								<td class="p-1">
									<a href="/admin/admin_user?usr_user_id=<?php echo $order_user->key; ?>"><?php echo htmlspecialchars($order_user->display_name()); ?> (User #<?php echo $order_user->key; ?>)</a>
								</td>
							</tr>
							<?php if($_SESSION['permission'] == 10): ?>
							<tr>
								<td class="p-1" style="width: 35%;">Status:</td>
								<td class="p-1">
									<?php if($order->get('ord_status') == 2): ?>
										<span class="badge rounded-pill badge-subtle-success">
											<span>Complete</span><span class="fas fa-check ms-1" data-fa-transform="shrink-4"></span>
										</span>
									<?php elseif($order->get('ord_status') == 1): ?>
										<span class="badge badge-subtle-warning">Incomplete</span>
									<?php else: ?>
										<span class="badge badge-subtle-secondary">Unknown</span>
									<?php endif; ?>
								</td>
							</tr>
							<?php endif; ?>
							<?php if($billing_address): ?>
							<tr>
								<td class="p-1" style="width: 35%;">Billing Address:</td>
								<td class="p-1 text-600">
									<?php echo $billing_address; ?>
								</td>
							</tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Order Items Card -->
			<div class="card mt-3">
				<div class="card-header bg-body-tertiary">
					<h6 class="mb-0"><span class="fas fa-box me-2"></span>Order Items</h6>
				</div>
				<div class="card-body">
					<?php
					if(count($order_items) > 0):
						foreach($order_items as $order_item):
							$product_data = $order_item->get_data();

							if($order_item->get('odi_usr_user_id')){
								$order_item_user = new User($order_item->get('odi_usr_user_id'), TRUE);
							}

							if (array_key_exists($order_item->get('odi_pro_product_id'), $PRODUCT_ID_TO_NAME_CACHE)) {
								$title = $PRODUCT_ID_TO_NAME_CACHE[$order_item->get('odi_pro_product_id')];
							} else {
								$product = new Product($order_item->get('odi_pro_product_id'), TRUE);
								$title = $product->get('pro_name');
								$PRODUCT_ID_TO_NAME_CACHE[$product->key] = $title;
							}
					?>
					<!-- Order Item -->
					<div class="mb-3 pb-3 border-bottom">
						<div class="d-flex justify-content-between align-items-start">
							<div class="flex-grow-1">
								<div class="fs-9 fw-semi-bold mb-1">
									<?php echo htmlspecialchars($title); ?>
								</div>
								<?php if($order_item->get('odi_usr_user_id')): ?>
								<div class="fs-10 text-600 mb-1">
									For: <a href="/admin/admin_user?usr_user_id=<?php echo $order_item_user->key; ?>"><?php echo htmlspecialchars($order_item_user->display_name()); ?></a>
								</div>
								<?php endif; ?>
								<div class="fs-10 text-600 mb-1">
									Price: <strong><?php echo $currency_symbol.number_format($order_item->get('odi_price'), 2); ?></strong>
								</div>

								<?php if($order_item->get('odi_refund_amount')): ?>
								<div class="fs-11 text-600">
									<span class="badge badge-subtle-danger me-2">REFUNDED <?php echo $currency_symbol.number_format($order_item->get('odi_refund_amount'), 2); ?></span>
								</div>
								<div class="fs-11 text-600 mt-1">
									Refund time: <?php echo LibraryFunctions::convert_time($order_item->get('odi_refund_time'), 'UTC', $session->get_timezone()); ?>
								</div>
								<?php if($order_item->get('odi_refund_note')): ?>
								<div class="fs-11 text-600 mt-1">
									Comment: <?php echo htmlspecialchars($order_item->get('odi_refund_note')); ?>
								</div>
								<?php endif; ?>
								<?php elseif($order_item->get('odi_subscription_status')): ?>
								<div class="fs-11 text-600">
									<?php if($order_item->get('odi_subscription_cancelled_time')): ?>
										<span class="badge badge-subtle-warning me-2">Cancelled Subscription</span>
										Status: <?php echo htmlspecialchars($order_item->get('odi_subscription_status')); ?>
									<?php else: ?>
										<span class="badge badge-subtle-success me-2">Active Subscription</span>
										Status: <?php echo htmlspecialchars($order_item->get('odi_subscription_status')); ?>
									<?php endif; ?>
								</div>
								<?php if($order_item->get('odi_subscription_period_end')): ?>
								<div class="fs-11 text-600 mt-1">
									Period ends: <?php echo LibraryFunctions::convert_time($order_item->get('odi_subscription_period_end'), 'UTC', $session->get_timezone()); ?>
								</div>
								<?php endif; ?>
								<?php if($order_item->get('odi_subscription_cancelled_time')): ?>
								<div class="fs-11 text-600 mt-1">
									Cancelled at: <?php echo LibraryFunctions::convert_time($order_item->get('odi_subscription_cancelled_time'), 'UTC', $session->get_timezone()); ?>
								</div>
								<?php endif; ?>
								<?php endif; ?>

								<div class="fs-11 mt-2">
									<?php if($_SESSION['permission'] >= 8 && ($order->get('ord_stripe_payment_intent_id') || $order->get('ord_stripe_charge_id')) && ($order->get('ord_refund_amount') < $order->get('ord_total_cost'))): ?>
									<a href="/admin/admin_order_refund?oi=<?php echo $order_item->key; ?>" class="me-3">[refund]</a>
									<?php endif; ?>
									<a href="/admin/admin_order_item_edit?odi_order_item_id=<?php echo $order_item->key; ?>">[edit]</a>
								</div>
								<?php if($order_item->get('odi_stripe_subscription_id')): ?>
								<div class="fs-11 mt-1">
									<a href="https://dashboard.stripe.com/subscriptions/<?php echo htmlspecialchars($order_item->get('odi_stripe_subscription_id')); ?>" target="_blank" class="text-decoration-none">
										<span class="fas fa-external-link-alt me-1"></span>View <?php echo htmlspecialchars($order_item->get('odi_stripe_subscription_id')); ?> on Stripe
									</a>
								</div>
								<?php endif; ?>
							</div>
						</div>
						<!-- Additional Order Item Data -->
						<?php
						$order_data = $order_item->get_all_data();
						if(count($order_data) > 0):
						?>
						<div class="ms-3 mt-2 fs-11 text-600">
							<?php foreach($order_data as $data): ?>
							<div><?php echo htmlspecialchars($data->get('oir_label')); ?>: <strong><?php echo htmlspecialchars($data->get('oir_answer')); ?></strong></div>
							<?php endforeach; ?>
						</div>
						<?php endif; ?>
					</div>
					<?php
						endforeach;
					else:
					?>
						<p class="text-600 mb-0">No items in this order.</p>
					<?php endif; ?>

					<!-- Add New Item Link -->
					<div class="mt-3 pt-3 border-top">
						<a href="/admin/admin_order_item_edit?ord_order_id=<?php echo $order->key; ?>" class="btn btn-sm btn-soft-default">
							<span class="fas fa-plus me-1"></span>Add Order Item
						</a>
					</div>
				</div>
			</div>

		</div>

		<!-- RIGHT COLUMN: Payment Details -->
		<div class="col-xxl-6">

			<!-- Payment Details Card -->
			<div class="card">
				<div class="card-header bg-body-tertiary">
					<h6 class="mb-0"><span class="fas fa-credit-card me-2"></span>Payment Details</h6>
				</div>
				<div class="card-body">
					<?php
					$has_payment_info = $order->get('ord_stripe_payment_intent_id') ||
					                    $order->get('ord_stripe_charge_id') ||
					                    $order->get('ord_stripe_session_id') ||
					                    $order->get('ord_stripe_invoice_id') ||
					                    $order->get('ord_paypal_order_id') ||
					                    $order->get('ord_payment_method') ||
					                    $order->get('ord_stripe_mode') == 'test';

					if($has_payment_info):
					?>
					<table class="table table-borderless fs-9 fw-medium mb-0">
						<tbody>
							<?php if($order->get('ord_payment_method')):
								$method_labels = array(
									'paypal' => 'PayPal',
									'venmo' => 'Venmo',
									'stripe' => 'Stripe',
									'stripe_checkout' => 'Stripe',
									'card' => 'PayPal (Card)',
									'free' => 'Free',
								);
								$pm = $order->get('ord_payment_method');
								$pm_label = isset($method_labels[$pm]) ? $method_labels[$pm] : ucfirst($pm);
							?>
							<tr>
								<td class="p-1" style="width: 40%;">Payment Method:</td>
								<td class="p-1 text-600"><strong><?php echo htmlspecialchars($pm_label); ?></strong></td>
							</tr>
							<?php endif; ?>
							<?php if($order->get('ord_paypal_order_id')): ?>
							<tr>
								<td class="p-1" style="width: 40%;">PayPal Order ID:</td>
								<td class="p-1 text-600"><?php echo htmlspecialchars($order->get('ord_paypal_order_id')); ?></td>
							</tr>
							<?php endif; ?>
							<?php if($order->get('ord_stripe_payment_intent_id')): ?>
							<tr>
								<td class="p-1" style="width: 40%;">Payment Intent ID:</td>
								<td class="p-1">
									<a href="https://dashboard.stripe.com/payments/<?php echo htmlspecialchars($order->get('ord_stripe_payment_intent_id')); ?>" target="_blank" class="text-600 text-decoration-none">
										<?php echo htmlspecialchars($order->get('ord_stripe_payment_intent_id')); ?>
										<span class="fas fa-external-link-alt ms-1 fs-11"></span>
									</a>
								</td>
							</tr>
							<?php endif; ?>
							<?php if($order->get('ord_stripe_charge_id')): ?>
							<tr>
								<td class="p-1" style="width: 40%;">Charge ID:</td>
								<td class="p-1">
									<a href="https://dashboard.stripe.com/charges/<?php echo htmlspecialchars($order->get('ord_stripe_charge_id')); ?>" target="_blank" class="text-600 text-decoration-none">
										<?php echo htmlspecialchars($order->get('ord_stripe_charge_id')); ?>
										<span class="fas fa-external-link-alt ms-1 fs-11"></span>
									</a>
								</td>
							</tr>
							<?php endif; ?>
							<?php if($order->get('ord_stripe_session_id')): ?>
							<tr>
								<td class="p-1" style="width: 40%;">Session ID:</td>
								<td class="p-1">
									<a href="https://dashboard.stripe.com/checkout/sessions/<?php echo htmlspecialchars($order->get('ord_stripe_session_id')); ?>" target="_blank" class="text-600 text-decoration-none">
										<?php echo htmlspecialchars($order->get('ord_stripe_session_id')); ?>
										<span class="fas fa-external-link-alt ms-1 fs-11"></span>
									</a>
								</td>
							</tr>
							<?php endif; ?>
							<?php if($order->get('ord_stripe_invoice_id')): ?>
							<tr>
								<td class="p-1" style="width: 40%;">Invoice ID:</td>
								<td class="p-1">
									<a href="https://dashboard.stripe.com/invoices/<?php echo htmlspecialchars($order->get('ord_stripe_invoice_id')); ?>" target="_blank" class="text-600 text-decoration-none">
										<?php echo htmlspecialchars($order->get('ord_stripe_invoice_id')); ?>
										<span class="fas fa-external-link-alt ms-1 fs-11"></span>
									</a>
								</td>
							</tr>
							<?php endif; ?>
							<?php if($order->get('ord_stripe_mode') == 'test'): ?>
							<tr>
								<td class="p-1" style="width: 40%;">Test Mode:</td>
								<td class="p-1">
									<span class="badge badge-subtle-warning">
										<span class="fas fa-flask me-1" data-fa-transform="shrink-2"></span>Test Mode
									</span>
								</td>
							</tr>
							<?php endif; ?>
						</tbody>
					</table>
					<?php else: ?>
					<div class="alert alert-info mb-0" style="font-size: 0.875rem;">
						<i class="fas fa-info-circle me-2"></i>
						No payment information available for this order.
					</div>
					<?php endif; ?>
				</div>
			</div>

			<?php if($_SESSION['permission'] == 10 && $order->get('ord_raw_cart')): ?>
			<!-- Raw Cart Data Card (Superadmin Only) -->
			<div class="card mt-3">
				<div class="card-header bg-body-tertiary">
					<h6 class="mb-0"><span class="fas fa-code me-2"></span>Raw Cart Data</h6>
				</div>
				<div class="card-body">
					<pre class="fs-11 mb-0 text-600" style="max-height: 300px; overflow-y: auto;"><code><?php echo htmlspecialchars($order->get('ord_raw_cart')); ?></code></pre>
				</div>
			</div>
			<?php endif; ?>

		</div>
	</div>

	<?php
	$page->admin_footer();

?>

