<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('cart_logic.php', 'logic'));

    $page_vars = cart_logic($_GET, $_POST);
    if ($page_vars->redirect) {
        LibraryFunctions::redirect($page_vars->redirect);
        exit();
    }
    $page_vars = $page_vars->data;
    $cart            = $page_vars['cart'];
    $currency_symbol = $page_vars['currency_symbol'];
    $currency_code   = $page_vars['currency_code'];
    $settings        = Globalvars::get_instance();
    $require_login   = $page_vars['require_login'];

    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page,
        'title'         => 'Checkout',
    ]);
    echo PublicPage::BeginPage('Checkout');
?>

<div class="container" style="padding: 1rem;">
    <div class="row justify-content-center">
        <div class="col-xl-10">

            <div class="row gx-5">

                <!-- Order Summary -->
                <div class="col-lg-7 order-lg-2 mb-5 mb-lg-0">
                    <div class="card shadow-sm rounded-4 sticky-top">
                        <div class="card-header bg-primary text-white rounded-top-4">
                            <h3 class="mb-0 h5">Order Summary</h3>
                        </div>
                        <div class="card-body p-0">

                            <?php if (!empty($cart->items)): ?>
                            <div class="list-group list-group-flush">
                                <?php
                                $total_discount = 0;
                                foreach ($cart->items as $key => $cart_item):
                                    list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
                                    $coupon_discount_words = '';
                                    if ($discount) {
                                        $coupon_discount_words = ' (' . $currency_symbol . number_format($discount, 2, '.', ',') . ' discount)';
                                        $total_discount += $discount;
                                    }
                                ?>
                                <div class="list-group-item p-4">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1">
                                                <?php echo htmlspecialchars($product->get('pro_name'), ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($product_version->get('prv_version_name'), ENT_QUOTES, 'UTF-8'); ?>
                                            </h6>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($data['full_name_first'], ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($data['full_name_last'], ENT_QUOTES, 'UTF-8'); ?>
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-bold text-primary">
                                                <?php echo $currency_symbol . number_format($price, 2, '.', ',') . $coupon_discount_words; ?>
                                            </div>
                                            <a href="/cart?r=<?php echo $key; ?>" class="btn btn-outline-danger btn-sm mt-1">Remove</a>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="p-4 text-center">
                                <p class="text-muted mb-3">Your cart is empty</p>
                                <a href="/products" class="btn btn-primary">Shop Now</a>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($cart->items)): ?>
                            <div class="card-footer bg-light rounded-bottom-4">
                                <dl class="row mb-0">
                                    <dt class="col-6">Subtotal:</dt>
                                    <dd class="col-6 text-end"><?php echo $currency_symbol . number_format($cart->get_total() - $total_discount, 2, '.', ','); ?></dd>
                                    <?php if ($total_discount): ?>
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
                    <?php if ($cart->billing_user): ?>
                    <div class="card shadow-sm rounded-4 mb-4">
                        <div class="card-header bg-light">
                            <h4 class="mb-0 h5">Billing Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1"><?php echo htmlspecialchars($cart->billing_user['billing_first_name'], ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($cart->billing_user['billing_last_name'], ENT_QUOTES, 'UTF-8'); ?></h6>
                                    <small class="text-muted"><?php echo htmlspecialchars($cart->billing_user['billing_email'], ENT_QUOTES, 'UTF-8'); ?></small>
                                </div>
                                <?php
                                $formwriter = $page->getFormWriter('form_billing_user');
                                echo $formwriter->new_button('Change', '/cart?newbilling=1', 'btn btn-outline btn-sm');
                                ?>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="card shadow-sm rounded-4 mb-4">
                        <div class="card-header bg-light">
                            <h4 class="mb-0 h5">Billing Information</h4>
                        </div>
                        <div class="card-body">
                            <?php
                            $formwriter = $page->getFormWriter('form2', ['action' => '/cart']);
                            $validation_rules = array();
                            $validation_rules['billing_email']['required']['value'] = 'true';
                            $validation_rules['billing_first_name']['required']['value'] = 'true';
                            $validation_rules['billing_last_name']['required']['value'] = 'true';
                            $validation_rules['password']['required']['value'] = 'true';
                            echo $formwriter->set_validate($validation_rules);
                            $formwriter->begin_form();
                            ?>

                            <?php if ($page_vars['session']->is_logged_in()): ?>
                            <div class="alert alert-info d-flex justify-content-between align-items-center mb-3">
                                <span>You are currently logged in.</span>
                                <a href="/cart?use_current_user=1" class="btn btn-sm btn-primary">Use Current User</a>
                            </div>
                            <?php endif; ?>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">First Name <span class="text-danger">*</span></label>
                                        <?php echo $formwriter->textinput('', 'billing_first_name', 'form-control', 30, htmlspecialchars($cart->billing_user['billing_first_name'] ?? '', ENT_QUOTES, 'UTF-8'), '', 255, ''); ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                        <?php echo $formwriter->textinput('', 'billing_last_name', 'form-control', 30, htmlspecialchars($cart->billing_user['billing_last_name'] ?? '', ENT_QUOTES, 'UTF-8'), '', 255, ''); ?>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="form-group">
                                        <label class="form-label">Email Address <span class="text-danger">*</span></label>
                                        <?php echo $formwriter->textinput('', 'billing_email', 'form-control', 30, htmlspecialchars($cart->billing_user['billing_email'] ?? '', ENT_QUOTES, 'UTF-8'), '', 255, ''); ?>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="form-group">
                                        <label class="form-label">Create Password <span class="text-danger">*</span></label>
                                        <?php echo $formwriter->passwordinput('', 'password', 'form-control', 20, '', '', 255, ''); ?>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <?php echo $formwriter->checkboxinput('I consent to the terms of use and privacy policy.', 'privacy', 'form-check-input', 'left', null, 1, ''); ?>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="d-grid">
                                        <?php echo $formwriter->submitbutton('submit', 'Save Billing Information', ['class' => 'btn btn-primary']); ?>
                                    </div>
                                </div>
                            </div>

                            <?php echo $formwriter->end_form(); ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Coupon Codes -->
                    <?php if ($settings->get_setting('coupons_active')): ?>
                    <div class="card shadow-sm rounded-4 mb-4">
                        <div class="card-header bg-light">
                            <h4 class="mb-0 h5">Coupon Codes</h4>
                        </div>
                        <div class="card-body">

                            <?php if (!empty($cart->coupon_codes)): ?>
                            <div class="mb-3">
                                <h6>Applied Coupons:</h6>
                                <?php foreach ($cart->coupon_codes as $coupon_code): ?>
                                <span class="badge bg-success me-2 mb-2">
                                    <?php echo htmlspecialchars($coupon_code); ?>
                                    <a href="/cart?clear_coupon_code=<?php echo $coupon_code; ?>" class="text-white ms-1">&times;</a>
                                </span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <?php if (StripeHelper::isTestMode()): ?>
                            <div class="alert alert-info small mb-3">
                                <strong>Test Mode:</strong> Available test coupons:
                                <?php foreach ($page_vars['all_coupons'] as $coupon): ?>
                                <a href="/cart?coupon_code=<?php echo $coupon->get('ccd_code'); ?>" class="btn btn-outline btn-sm mt-1">
                                    <?php echo htmlspecialchars($coupon->get('ccd_code')); ?>
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <?php
                            $formwriter = $page->getFormWriter('form_coupon', ['action' => '/cart', 'method' => 'GET']);
                            $formwriter->begin_form();
                            ?>
                            <div class="input-group">
                                <?php echo $formwriter->textinput('', 'coupon_code', 'form-control', 64, null, 'Enter coupon code', 255, ''); ?>
                                <div class="input-group-append">
                                    <?php echo $formwriter->submitbutton('submit', 'Apply', ['class' => 'btn btn-primary']); ?>
                                </div>
                            </div>
                            <?php if ($page_vars['coupon_error']): ?>
                            <div class="text-danger small mt-2"><?php echo htmlspecialchars($page_vars['coupon_error'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endif; ?>
                            <?php echo $formwriter->end_form(); ?>

                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Payment Section -->
                    <?php if (StripeHelper::isTestMode()): ?>
                    <div class="alert alert-warning mb-4">
                        <strong>Test Mode:</strong> Using checkout type: <?php echo $settings->get_setting('checkout_type'); ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($require_login): ?>
                    <div class="alert alert-warning mb-4">
                        The email (<?php echo htmlspecialchars($cart->billing_user['billing_email'], ENT_QUOTES, 'UTF-8'); ?>) you entered already exists in our system.
                        <a href="/login">Log in</a> to continue checkout or
                        <a href="/cart_clear">clear the cart</a>.
                    </div>
                    <?php else: ?>

                        <?php if ($cart->get_total() > 0 && $cart->billing_user['billing_email']): ?>

                        <!-- Stripe Payment -->
                        <div class="card shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-success text-white">
                                <h4 class="mb-0 h5">Payment with Stripe</h4>
                            </div>
                            <div class="card-body">
                                <?php
                                $formwriter = $page->getFormWriter('form_stripe');
                                if ($settings->get_setting('checkout_type') == 'stripe_checkout') {
                                    echo $page_vars['stripe_helper']->output_stripe_checkout_form($cart->get_hash());
                                } else {
                                    echo $page_vars['stripe_helper']->output_stripe_regular_form($formwriter, '');
                                }
                                ?>
                            </div>
                        </div>

                        <!-- PayPal Payment -->
                        <?php if ($settings->get_setting('use_paypal_checkout') && $page_vars['paypal_helper']): ?>
                        <div class="card shadow-sm rounded-4">
                            <div class="card-header bg-warning text-dark">
                                <h4 class="mb-0 h5">Payment with PayPal</h4>
                            </div>
                            <div class="card-body">
                                <?php
                                if ($cart->get_num_recurring() == 1 && $cart->get_num_non_recurring() == 0) {
                                    echo $page_vars['paypal_helper']->output_paypal_subscription_checkout_code($page_vars['plan_id']);
                                } elseif ($cart->get_num_recurring() == 0) {
                                    echo $page_vars['paypal_helper']->output_paypal_checkout_code($page_vars['paypal_item_list']);
                                } else {
                                    ?>
                                    <div class="alert alert-info mb-0">
                                        <strong>Note:</strong> PayPal subscriptions must be purchased individually.
                                    </div>
                                    <?php
                                }
                                ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php elseif ($cart->billing_user): ?>
                        <!-- Free Checkout -->
                        <div class="card shadow-sm rounded-4">
                            <div class="card-header bg-light">
                                <h4 class="mb-0 h5">Complete Order</h4>
                            </div>
                            <div class="card-body text-center">
                                <p class="text-muted mb-4">Your order total is <?php echo $currency_symbol . number_format($cart->get_total() - $total_discount, 2, '.', ','); ?></p>
                                <?php
                                $formwriter = $page->getFormWriter('form4', ['action' => '/cart_charge']);
                                $formwriter->begin_form();
                                $formwriter->hiddeninput('novalue', '');
                                ?>
                                <div class="d-grid">
                                    <?php echo $formwriter->submitbutton('submit', 'Complete Order', ['class' => 'btn btn-primary']); ?>
                                </div>
                                <?php echo $formwriter->end_form(); ?>
                            </div>
                        </div>
                        <?php endif; ?>

                    <?php endif; ?>

                </div><!-- /col billing -->
            </div><!-- /row -->
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var forms = document.querySelectorAll('form');
    forms.forEach(function(form) {
        form.addEventListener('submit', function() {
            var buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
            buttons.forEach(function(btn) {
                btn.disabled = true;
                btn.dataset.originalText = btn.innerHTML;
                btn.innerHTML = 'Processing...';
            });
            setTimeout(function() {
                buttons.forEach(function(btn) {
                    btn.disabled = false;
                    if (btn.dataset.originalText) btn.innerHTML = btn.dataset.originalText;
                });
            }, 10000);
        });
    });
});
</script>

<?php
    echo PublicPage::EndPage();
    $page->public_footer(['track' => true]);
?>
