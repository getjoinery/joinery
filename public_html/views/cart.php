<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('cart_logic.php', 'logic'));

    $page_vars = process_logic(cart_logic($_GET, $_POST));
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
?>

<!-- Page Title -->
<section class="page-title bg-transparent">
    <div class="container">
        <div class="page-title-row">
            <div class="page-title-content">
                <h1>Checkout</h1>
            </div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item active">Checkout</li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div style="display: flex; gap: 2rem; align-items: flex-start; flex-wrap: wrap;">

            <!-- Billing & Payment (left) -->
            <div style="flex: 1; min-width: 280px;">

                <!-- Billing Information -->
                <?php if ($cart->billing_user): ?>
                <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); margin-bottom: 1.5rem; overflow: hidden;">
                    <div style="background: var(--color-light, #f8f9fa); padding: 1rem 1.5rem; border-bottom: 1px solid var(--color-border, #eee);">
                        <h4 style="margin: 0; font-size: 1.0625rem;">Billing Information</h4>
                    </div>
                    <div style="padding: 1.25rem 1.5rem; display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <h6 style="margin: 0 0 0.25rem; font-size: 0.9375rem;"><?php echo htmlspecialchars($cart->billing_user['billing_first_name'], ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($cart->billing_user['billing_last_name'], ENT_QUOTES, 'UTF-8'); ?></h6>
                            <small style="color: var(--color-muted);"><?php echo htmlspecialchars($cart->billing_user['billing_email'], ENT_QUOTES, 'UTF-8'); ?></small>
                        </div>
                        <a href="/cart?newbilling=1" class="btn btn-outline" style="font-size: 0.8125rem; padding: 0.375rem 0.875rem;">Change</a>
                    </div>
                </div>
                <?php else: ?>
                <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); margin-bottom: 1.5rem; overflow: hidden;">
                    <div style="background: var(--color-light, #f8f9fa); padding: 1rem 1.5rem; border-bottom: 1px solid var(--color-border, #eee);">
                        <h4 style="margin: 0; font-size: 1.0625rem;">Billing Information</h4>
                    </div>
                    <div style="padding: 1.5rem;">
                        <?php
                        $formwriter = $page->getFormWriter('form2', ['action' => '/cart']);
                        $formwriter->begin_form();
                        ?>

                        <?php if ($page_vars['session']->is_logged_in()): ?>
                        <div class="alert alert-info" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <span>You are currently logged in.</span>
                            <a href="/cart?use_current_user=1" class="btn btn-primary" style="font-size: 0.8125rem; padding: 0.375rem 0.875rem; margin-left: 1rem;">Use Current User</a>
                        </div>
                        <?php endif; ?>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div><?php echo $formwriter->textinput('billing_first_name', 'First Name', ['value' => htmlspecialchars($cart->billing_user['billing_first_name'] ?? '', ENT_QUOTES, 'UTF-8'), 'maxlength' => 255, 'required' => true]); ?></div>
                            <div><?php echo $formwriter->textinput('billing_last_name', 'Last Name', ['value' => htmlspecialchars($cart->billing_user['billing_last_name'] ?? '', ENT_QUOTES, 'UTF-8'), 'maxlength' => 255, 'required' => true]); ?></div>
                            <div style="grid-column: 1/-1;"><?php echo $formwriter->textinput('billing_email', 'Email Address', ['value' => htmlspecialchars($cart->billing_user['billing_email'] ?? '', ENT_QUOTES, 'UTF-8'), 'maxlength' => 255, 'required' => true, 'type' => 'email']); ?></div>
                            <div style="grid-column: 1/-1;"><?php echo $formwriter->passwordinput('password', 'Create Password', ['required' => true]); ?></div>
                            <div style="grid-column: 1/-1;"><?php echo $formwriter->checkboxinput('privacy', 'I consent to the terms of use and privacy policy.', ['required' => true]); ?></div>
                            <div style="grid-column: 1/-1;"><?php echo $formwriter->submitbutton('btn_submit', 'Save Billing Information', ['class' => 'btn btn-primary']); ?></div>
                        </div>

                        <?php echo $formwriter->end_form(); ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Coupon Codes -->
                <?php if ($settings->get_setting('coupons_active')): ?>
                <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); margin-bottom: 1.5rem; overflow: hidden;">
                    <div style="background: var(--color-light, #f8f9fa); padding: 1rem 1.5rem; border-bottom: 1px solid var(--color-border, #eee);">
                        <h4 style="margin: 0; font-size: 1.0625rem;">Coupon Codes</h4>
                    </div>
                    <div style="padding: 1.5rem;">

                        <?php if (!empty($cart->coupon_codes)): ?>
                        <div style="margin-bottom: 1rem;">
                            <strong style="font-size: 0.875rem;">Applied Coupons:</strong>
                            <?php foreach ($cart->coupon_codes as $coupon_code): ?>
                            <span style="display: inline-flex; align-items: center; gap: 0.25rem; background: #198754; color: #fff; font-size: 0.8125rem; padding: 0.25rem 0.625rem; border-radius: 4px; margin: 0.25rem 0.25rem 0 0;">
                                <?php echo htmlspecialchars($coupon_code); ?>
                                <a href="/cart?clear_coupon_code=<?php echo $coupon_code; ?>" style="color: #fff; text-decoration: none; font-weight: 700;">&times;</a>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (StripeHelper::isTestMode()): ?>
                        <div class="alert alert-info" style="font-size: 0.875rem; margin-bottom: 1rem;">
                            <strong>Test Mode:</strong> Available test coupons:
                            <?php foreach ($page_vars['all_coupons'] as $coupon): ?>
                            <a href="/cart?coupon_code=<?php echo $coupon->get('ccd_code'); ?>" class="btn btn-outline" style="font-size: 0.75rem; padding: 0.2rem 0.6rem; margin: 0.25rem 0 0 0.25rem;">
                                <?php echo htmlspecialchars($coupon->get('ccd_code')); ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php
                        $formwriter = $page->getFormWriter('form_coupon', ['action' => '/cart', 'method' => 'GET']);
                        $formwriter->begin_form();
                        ?>
                        <div style="display: flex; gap: 0.5rem;">
                            <div style="flex: 1;"><?php echo $formwriter->textinput('coupon_code', '', ['placeholder' => 'Enter coupon code', 'maxlength' => 255]); ?></div>
                            <?php echo $formwriter->submitbutton('btn_submit', 'Apply', ['class' => 'btn btn-primary']); ?>
                        </div>
                        <?php if ($page_vars['coupon_error']): ?>
                        <div style="color: var(--color-danger, #dc3545); font-size: 0.875rem; margin-top: 0.5rem;"><?php echo htmlspecialchars($page_vars['coupon_error'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                        <?php echo $formwriter->end_form(); ?>

                    </div>
                </div>
                <?php endif; ?>

                <!-- Payment Section -->
                <?php if (StripeHelper::isTestMode()): ?>
                <div class="alert alert-warning" style="margin-bottom: 1.5rem;">
                    <strong>Test Mode:</strong> Using checkout type: <?php echo $settings->get_setting('checkout_type'); ?>
                </div>
                <?php endif; ?>

                <?php if ($require_login): ?>
                <div class="alert alert-warning" style="margin-bottom: 1.5rem;">
                    The email (<?php echo htmlspecialchars($cart->billing_user['billing_email'], ENT_QUOTES, 'UTF-8'); ?>) you entered already exists in our system.
                    <a href="/login">Log in</a> to continue checkout or
                    <a href="/cart_clear">clear the cart</a>.
                </div>
                <?php else: ?>

                    <?php if ($cart->get_total() > 0 && $cart->billing_user['billing_email']): ?>

                    <!-- Stripe Payment -->
                    <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); margin-bottom: 1.5rem; overflow: hidden;">
                        <div style="background: #198754; color: #fff; padding: 1rem 1.5rem;">
                            <h4 style="margin: 0; color: #fff; font-size: 1.0625rem;">Payment with Stripe</h4>
                        </div>
                        <div style="padding: 1.5rem;">
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
                    <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); margin-bottom: 1.5rem; overflow: hidden;">
                        <div style="background: #f6c23e; color: #333; padding: 1rem 1.5rem;">
                            <h4 style="margin: 0; font-size: 1.0625rem;">Payment with PayPal</h4>
                        </div>
                        <div style="padding: 1.5rem;">
                            <?php
                            if ($cart->get_num_recurring() == 1 && $cart->get_num_non_recurring() == 0) {
                                echo $page_vars['paypal_helper']->output_paypal_subscription_checkout_code($page_vars['plan_id']);
                            } elseif ($cart->get_num_recurring() == 0) {
                                echo $page_vars['paypal_helper']->output_paypal_checkout_code($page_vars['paypal_item_list']);
                            } else {
                                ?>
                                <div class="alert alert-info">
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
                    <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden;">
                        <div style="background: var(--color-light, #f8f9fa); padding: 1rem 1.5rem; border-bottom: 1px solid var(--color-border, #eee);">
                            <h4 style="margin: 0; font-size: 1.0625rem;">Complete Order</h4>
                        </div>
                        <div style="padding: 1.5rem; text-align: center;">
                            <p style="color: var(--color-muted); margin-bottom: 1.25rem;">Your order total is <?php echo $currency_symbol . number_format($cart->get_total() - $total_discount, 2, '.', ','); ?></p>
                            <?php
                            $formwriter = $page->getFormWriter('form4', ['action' => '/cart_charge']);
                            $formwriter->begin_form();
                            $formwriter->hiddeninput('novalue', '');
                            echo $formwriter->submitbutton('btn_submit', 'Complete Order', ['class' => 'btn btn-primary']);
                            echo $formwriter->end_form();
                            ?>
                        </div>
                    </div>
                    <?php endif; ?>

                <?php endif; ?>

            </div><!-- /billing column -->

            <!-- Order Summary (right) -->
            <div style="flex: 0 0 340px; min-width: 260px; position: sticky; top: 2rem;">
                <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden;">
                    <div style="background: var(--color-primary, #1abc9c); color: #fff; padding: 1rem 1.5rem;">
                        <h3 style="margin: 0; color: #fff; font-size: 1.0625rem;">Order Summary</h3>
                    </div>

                    <?php if (!empty($cart->items)): ?>
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
                    <div style="padding: 1rem 1.5rem; border-bottom: 1px solid var(--color-border, #eee); display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem;">
                        <div style="flex: 1; min-width: 0;">
                            <h6 style="margin: 0 0 0.25rem; font-size: 0.9375rem;">
                                <?php echo htmlspecialchars($product->get('pro_name'), ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($product_version->get('prv_version_name'), ENT_QUOTES, 'UTF-8'); ?>
                            </h6>
                            <small style="color: var(--color-muted);">
                                <?php echo htmlspecialchars($data['full_name_first'], ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($data['full_name_last'], ENT_QUOTES, 'UTF-8'); ?>
                            </small>
                        </div>
                        <div style="text-align: right; flex-shrink: 0;">
                            <div style="font-weight: 700; color: var(--color-primary);">
                                <?php echo $currency_symbol . number_format($price, 2, '.', ',') . $coupon_discount_words; ?>
                            </div>
                            <a href="/cart?r=<?php echo $key; ?>" style="font-size: 0.8125rem; color: var(--color-danger, #dc3545); text-decoration: none;">Remove</a>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div style="background: var(--color-light, #f8f9fa); padding: 1rem 1.5rem;">
                        <dl style="margin: 0; display: grid; grid-template-columns: 1fr auto; gap: 0.375rem 1rem;">
                            <dt style="font-weight: 400;">Subtotal:</dt>
                            <dd style="margin: 0; text-align: right;"><?php echo $currency_symbol . number_format($cart->get_total() - $total_discount, 2, '.', ','); ?></dd>
                            <?php if ($total_discount): ?>
                            <dt style="color: #198754;">Discount:</dt>
                            <dd style="margin: 0; text-align: right; color: #198754;">-<?php echo $currency_symbol . number_format($total_discount, 2, '.', ','); ?></dd>
                            <?php endif; ?>
                            <dt style="font-weight: 700; font-size: 1.0625rem; padding-top: 0.75rem; border-top: 1px solid var(--color-border, #eee);">Total:</dt>
                            <dd style="margin: 0; text-align: right; font-weight: 700; font-size: 1.0625rem; color: var(--color-primary); padding-top: 0.75rem; border-top: 1px solid var(--color-border, #eee);">
                                <?php echo $currency_symbol . number_format($cart->get_total() - $total_discount, 2, '.', ','); ?>
                            </dd>
                        </dl>
                    </div>

                    <?php else: ?>
                    <div style="padding: 2rem; text-align: center;">
                        <p style="color: var(--color-muted); margin-bottom: 1.25rem;">Your cart is empty</p>
                        <a href="/products" class="btn btn-primary">Shop Now</a>
                    </div>
                    <?php endif; ?>

                </div>
            </div><!-- /order summary -->

        </div>
    </div>
</section>

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
    $page->public_footer(['track' => true]);
?>
