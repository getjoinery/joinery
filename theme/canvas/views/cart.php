<?php
	// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('cart_logic.php', 'logic'));

	$page_vars = cart_logic($_GET, $_POST);
	// Handle LogicResult return format
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;
	$cart = $page_vars['cart'];
	$currency_symbol = $page_vars['currency_symbol'];
	$page_vars['currency_code'] = $currency_code;
	$settings = Globalvars::get_instance();
	$require_login = $page_vars['require_login'];

	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Checkout'
	));

	echo PublicPage::BeginPage('Checkout');
?>

<!-- Canvas Checkout Section -->
<section id="content">
	<div class="content-wrap">
		<div class="container">
			<div class="row justify-content-center">
				<div class="col-xl-10">
					
					<!-- Page Header -->
					<div class="mb-5 text-center">
						<h1 class="h2 mb-2">Checkout</h1>
						<p class="text-muted">Review your order and complete your purchase</p>
					</div>

					<div class="row gx-5">
						
						<!-- Order Summary -->
						<div class="col-lg-7 order-lg-2 mb-5 mb-lg-0">
							<div class="card shadow-sm rounded-4 sticky-top">
								<div class="card-header bg-primary text-white rounded-top-4">
									<h3 class="mb-0 h5">Order Summary</h3>
								</div>
								<div class="card-body p-0">
									
									<!-- Cart Items -->
									<?php if (!empty($cart->items)): ?>
									<div class="list-group list-group-flush">
										<?php
										$total_discount = 0;
										$itemcount = 0;
										foreach($cart->items as $key => $cart_item):
											list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
											$coupon_discount_words = '';
											//HANDLE COUPONS
											if($discount):
												$coupon_discount_words = ' ('.$currency_symbol.number_format($discount, 2, '.', ','). ' discount)'; 
												$total_discount += $discount;
											endif;
											?>
											<div class="list-group-item p-4">
												<div class="d-flex justify-content-between align-items-start">
													<div class="flex-grow-1">
														<h6 class="mb-1">
															<?php echo htmlspecialchars($product->get('pro_name'), ENT_QUOTES, 'UTF-8').' '. htmlspecialchars($product_version->get('prv_version_name'), ENT_QUOTES, 'UTF-8'); ?>
														</h6>
														<small class="text-muted">
															<?php echo htmlspecialchars($data['full_name_first'], ENT_QUOTES, 'UTF-8'). ' ' .htmlspecialchars($data['full_name_last'], ENT_QUOTES, 'UTF-8'); ?>
														</small>
													</div>
													<div class="text-end">
														<div class="fw-bold text-primary">
															<?php echo $currency_symbol . number_format($price, 2, '.', ','). $coupon_discount_words; ?>
														</div>
														<a href="/cart?r=<?php echo $key; ?>" class="btn btn-outline-danger btn-sm mt-1">
															<i class="bi-trash me-1"></i>Remove
														</a>
													</div>
												</div>
											</div>
											<?php
											$itemcount++;
										endforeach;
										?>
									</div>
									<?php else: ?>
									<div class="p-4 text-center">
										<i class="bi-cart-x display-6 text-muted mb-3"></i>
										<p class="text-muted mb-3">Your cart is empty</p>
										<a href="/products" class="btn btn-primary">Shop Now</a>
									</div>
									<?php endif; ?>

									<!-- Totals -->
									<?php if (!empty($cart->items)): ?>
									<div class="card-footer bg-light rounded-bottom-4">
										<dl class="row mb-0">
											<dt class="col-6">Subtotal:</dt>
											<dd class="col-6 text-end"><?php echo $currency_symbol . number_format($cart->get_total() - $total_discount, 2, '.', ','); ?></dd>
											
											<?php if($total_discount): ?>
											<dt class="col-6 text-success">Discount:</dt>
											<dd class="col-6 text-end text-success">-<?php echo $currency_symbol . number_format($total_discount, 2, '.', ','); ?></dd>
											<?php endif; ?>
											
											<dt class="col-6 h5 border-top pt-3 mt-3">Total:</dt>
											<dd class="col-6 h5 text-end text-primary border-top pt-3 mt-3 mb-0">
												<?php echo $currency_symbol . number_format($cart->get_total() - $total_discount, 2, '.', ','); ?>
											</dd>
										</dl>
									</div>
									<?php endif; ?>
								</div>
							</div>
						</div>

						<!-- Billing & Payment -->
						<div class="col-lg-5 order-lg-1">
							
							<!-- Billing Information -->
							<?php if($cart->billing_user): ?>
							<div class="card shadow-sm rounded-4 mb-4">
								<div class="card-header bg-light">
									<h4 class="mb-0 h5">Billing Information</h4>
								</div>
								<div class="card-body">
									<div class="d-flex justify-content-between align-items-center">
										<div>
											<h6 class="mb-1"><?php echo htmlspecialchars($cart->billing_user['first_name'], ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($cart->billing_user['last_name'], ENT_QUOTES, 'UTF-8'); ?></h6>
											<small class="text-muted"><?php echo htmlspecialchars($cart->billing_user['email'], ENT_QUOTES, 'UTF-8'); ?></small>
										</div>
										<?php
										$formwriter = $page->getFormWriter('form_billing_user');
										echo $formwriter->new_button('Change', '/cart?newbilling=1', 'btn btn-outline-secondary btn-sm');
										?>
									</div>
								</div>
							</div>
							<?php else: ?>
							<!-- New Billing Form -->
							<div class="card shadow-sm rounded-4 mb-4">
								<div class="card-header bg-light">
									<h4 class="mb-0 h5">Billing Information</h4>
								</div>
								<div class="card-body">
									<?php
									$formwriter = $page->getFormWriter('form2');
									$validation_rules = array();
									$validation_rules['billing_email']['required']['value'] = 'true';
									$validation_rules['billing_first_name']['required']['value'] = 'true';
									$validation_rules['billing_last_name']['required']['value'] = 'true';		
									$validation_rules['password']['required']['value'] = 'true';
									echo $formwriter->set_validate($validation_rules);

									echo $formwriter->begin_form("", "post", "/cart");
									?>
									
									<div class="row g-3">
										<div class="col-md-6">
											<div class="form-group">
												<label class="form-label">First Name <span class="text-danger">*</span></label>
												<?php echo $formwriter->textinput("", "billing_first_name", 'form-control', 30, htmlspecialchars($cart->billing_user['first_name'], ENT_QUOTES, 'UTF-8'), "", 255, ""); ?>
											</div>
										</div>
										<div class="col-md-6">
											<div class="form-group">
												<label class="form-label">Last Name <span class="text-danger">*</span></label>
												<?php echo $formwriter->textinput("", "billing_last_name", 'form-control', 30, htmlspecialchars($cart->billing_user['last_name'], ENT_QUOTES, 'UTF-8'), "", 255, ""); ?>
											</div>
										</div>
										<div class="col-12">
											<div class="form-group">
												<label class="form-label">Email Address <span class="text-danger">*</span></label>
												<?php echo $formwriter->textinput("", "billing_email", 'form-control', 30, htmlspecialchars($cart->billing_user['email'], ENT_QUOTES, 'UTF-8'), "", 255, ""); ?>
											</div>
										</div>
										<div class="col-12">
											<div class="form-group">
												<label class="form-label">Create Password <span class="text-danger">*</span></label>
												<?php echo $formwriter->passwordinput("", "password", 'form-control', 20, "" , "", 255,""); ?>
											</div>
										</div>
										<div class="col-12">
											<div class="form-check">
												<?php echo $formwriter->checkboxinput("I consent to the terms of use and privacy policy.", "privacy", "form-check-input", "left", NULL, 1, ""); ?>
											</div>
										</div>
										<div class="col-12">
											<div class="d-grid">
												<?php echo $formwriter->new_form_button('Save Billing Information', 'btn btn-primary btn-lg'); ?>
											</div>
										</div>
									</div>

									<?php echo $formwriter->end_form(); ?>
								</div>
							</div>
							<?php endif; ?>

							<!-- Coupon Codes -->
							<?php if($settings->get_setting('coupons_active')): ?>
							<div class="card shadow-sm rounded-4 mb-4">
								<div class="card-header bg-light">
									<h4 class="mb-0 h5">Coupon Codes</h4>
								</div>
								<div class="card-body">
									
									<!-- Applied Coupons -->
									<?php if(!empty($cart->coupon_codes)): ?>
									<div class="mb-3">
										<h6>Applied Coupons:</h6>
										<?php foreach($cart->coupon_codes as $coupon_code): ?>
										<div class="badge bg-success me-2 mb-2">
											<?php echo htmlspecialchars($coupon_code); ?>
											<a href="/cart?clear_coupon_code=<?php echo $coupon_code; ?>" class="text-white ms-1">
												<i class="bi-x"></i>
											</a>
										</div>
										<?php endforeach; ?>
									</div>
									<?php endif; ?>

									<!-- Test Mode Coupons -->
									<?php if(StripeHelper::isTestMode()): ?>
									<div class="alert alert-info small mb-3">
										<strong>Test Mode:</strong> Available test coupons:
										<?php foreach($page_vars['all_coupons'] as $coupon): ?>
										<div class="d-inline-block me-2 mt-1">
											<a href="/cart?coupon_code=<?php echo $coupon->get('ccd_code'); ?>" class="btn btn-outline-primary btn-sm">
												<?php echo htmlspecialchars($coupon->get('ccd_code')); ?>
											</a>
										</div>
										<?php endforeach; ?>
									</div>
									<?php endif; ?>

									<!-- Add Coupon Form -->
									<?php
									$formwriter = $page->getFormWriter('form_coupon');
									echo $formwriter->begin_form("", "get", '/cart');
									?>
									
									<div class="input-group">
										<?php echo $formwriter->textinput('', 'coupon_code', 'form-control', 64, NULL, 'Enter coupon code', 255, ''); ?>
										<div class="input-group-append">
											<?php echo $formwriter->new_form_button('Apply', 'btn btn-outline-primary'); ?>
										</div>
									</div>
									
									<?php if($page_vars['coupon_error']): ?>
									<div class="text-danger small mt-2"><?php echo htmlspecialchars($page_vars['coupon_error'], ENT_QUOTES, 'UTF-8'); ?></div>
									<?php endif; ?>

									<?php echo $formwriter->end_form(); ?>
								</div>
							</div>
							<?php endif; ?>

							<!-- Payment Section -->
							<?php if(StripeHelper::isTestMode()): ?>
							<div class="alert alert-warning mb-4">
								<i class="bi-exclamation-triangle me-2"></i>
								<strong>Test Mode:</strong> Using checkout type: <?php echo $settings->get_setting('checkout_type'); ?>
							</div>
							<?php endif; ?>

							<?php if($require_login): ?>
							<div class="alert alert-warning mb-4">
								The email (<?php echo htmlspecialchars($cart->billing_user['billing_email'], ENT_QUOTES, 'UTF-8'); ?>) you entered already exists in our system. 
								<a href="/login" class="alert-link">Log in</a> to continue checkout or 
								<a href="/cart_clear" class="alert-link">clear the cart</a>.
							</div>
							<?php else: ?>
								<?php if($cart->get_total() > 0 && $cart->billing_user['billing_email']): ?>
								
								<!-- Stripe Payment -->
								<div class="card shadow-sm rounded-4 mb-4">
									<div class="card-header bg-success text-white">
										<h4 class="mb-0 h5"><i class="bi-credit-card me-2"></i>Payment with Stripe</h4>
									</div>
									<div class="card-body">
										<?php
										if($settings->get_setting('checkout_type') == 'stripe_checkout'):
											echo $page_vars['stripe_helper']->output_stripe_checkout_form($cart->get_hash());
										else:
											echo $page_vars['stripe_helper']->output_stripe_regular_form($formwriter, '');
										endif;
										?>
									</div>
								</div>

								<!-- PayPal Payment -->
								<?php if($settings->get_setting('use_paypal_checkout') && $page_vars['paypal_helper']): ?>
								<div class="card shadow-sm rounded-4">
									<div class="card-header bg-warning text-dark">
										<h4 class="mb-0 h5"><i class="bi-paypal me-2"></i>Payment with PayPal</h4>
									</div>
									<div class="card-body">
										<?php
										if($cart->get_num_recurring() == 1 && $cart->get_num_non_recurring() == 0):
											echo $page_vars['paypal_helper']->output_paypal_subscription_checkout_code($page_vars['plan_id']);
										elseif($cart->get_num_recurring() == 0):
											echo $page_vars['paypal_helper']->output_paypal_checkout_code($page_vars['paypal_item_list']);
										else:
											?>
											<div class="alert alert-info mb-0">
												<strong>Note:</strong> PayPal subscriptions must be purchased individually. Remove all other items from your cart to pay with PayPal.
											</div>
											<?php
										endif;
										?>
									</div>
								</div>
								<?php endif; ?>

								<?php elseif($cart->billing_user): ?>
								<!-- Free Checkout -->
								<div class="card shadow-sm rounded-4">
									<div class="card-header bg-light">
										<h4 class="mb-0 h5">Complete Order</h4>
									</div>
									<div class="card-body text-center">
										<p class="text-muted mb-4">Your order total is <?php echo $currency_symbol . number_format($cart->get_total() - $total_discount, 2, '.', ','); ?></p>
										<?php
										$formwriter = $page->getFormWriter('form4');
										echo $formwriter->begin_form("", "post", '/cart_charge');
										echo $formwriter->hiddeninput('novalue', '');
										?>
										<div class="d-grid">
											<?php echo $formwriter->new_form_button('Complete Order', 'btn btn-success btn-lg'); ?>
										</div>
										<?php echo $formwriter->end_form(); ?>
									</div>
								</div>
								<?php endif; ?>
							<?php endif; ?>

						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</section>

<style>
.sticky-top {
	top: 100px;
}

.card-header h3,
.card-header h4,
.card-header h5 {
	color: inherit;
}

.list-group-item {
	border-left: none;
	border-right: none;
}

.list-group-item:first-child {
	border-top: none;
}

.list-group-item:last-child {
	border-bottom: none;
}

@media (max-width: 991px) {
	.sticky-top {
		position: relative !important;
		top: auto !important;
	}
}
</style>

<script>
$(document).ready(function() {
	// Disable all submit buttons after first click to prevent duplicate submissions
	$('form').on('submit', function() {
		var $form = $(this);
		var $submitButtons = $form.find('button[type="submit"], input[type="submit"]');
		
		// Disable buttons and show loading state
		$submitButtons.prop('disabled', true);
		$submitButtons.each(function() {
			var $btn = $(this);
			$btn.data('original-text', $btn.html());
			$btn.html('<span class="spinner-border spinner-border-sm me-2"></span>Processing...');
		});
		
		// Re-enable after 10 seconds as failsafe (in case of network issues)
		setTimeout(function() {
			$submitButtons.prop('disabled', false);
			$submitButtons.each(function() {
				var $btn = $(this);
				if ($btn.data('original-text')) {
					$btn.html($btn.data('original-text'));
				}
			});
		}, 10000);
		
		return true; // Allow form submission to proceed
	});
});
</script>

<?php
echo PublicPage::EndPage();
$page->public_footer($foptions=array('track'=>TRUE));
?>