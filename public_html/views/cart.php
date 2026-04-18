<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('cart_logic.php', 'logic'));

    $page_vars = process_logic(cart_logic($_GET, $_POST));
    $cart            = $page_vars['cart'];
    $currency_symbol = $page_vars['currency_symbol'];
    $currency_code   = $page_vars['currency_code'];
    $settings        = Globalvars::get_instance();
    $session         = $page_vars['session'];
    $require_login   = $page_vars['require_login'];
    $sections        = $page_vars['sections'];
    $prefill_name    = $page_vars['prefill_name'];
    $has_name_from_cart = $page_vars['has_name_from_cart'];

    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page,
        'title'         => 'Checkout',
        'noheader'      => true,
    ]);
?>
<div class="jy-ui">

<?php if (StripeHelper::isTestMode()): ?>
<div style="background: #fff3cd; color: #856404; padding: 0.5rem 1rem; text-align: center; font-size: 0.875rem; border-bottom: 1px solid #ffc107;">
    <strong>Test Mode</strong> — Checkout type: <?php echo htmlspecialchars($settings->get_setting('checkout_type'), ENT_QUOTES, 'UTF-8'); ?>
</div>
<?php endif; ?>

<section style="padding: 2rem 0;">
    <div class="jy-container">

    <?php
    // Display session messages (payment errors, etc.)
    $checkout_messages = $session->get_messages('/cart');
    if (!empty($checkout_messages)):
        foreach ($checkout_messages as $msg):
            $alert_class = ($msg->get_message_class() === 'error') ? 'alert-danger' : 'alert-' . $msg->get_message_class();
    ?>
    <div style="background: <?php echo ($msg->get_message_class() === 'error') ? '#f8d7da' : '#d1ecf1'; ?>; color: <?php echo ($msg->get_message_class() === 'error') ? '#721c24' : '#0c5460'; ?>; padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; display: flex; align-items: flex-start; gap: 0.75rem;" role="alert">
        <span style="font-size: 1.25rem; line-height: 1; flex-shrink: 0;"><?php echo ($msg->get_message_class() === 'error') ? '&#9888;' : '&#8505;'; ?></span>
        <div>
            <?php if ($msg->message_title): ?><strong><?php echo htmlspecialchars($msg->message_title, ENT_QUOTES, 'UTF-8'); ?>:</strong> <?php endif; ?>
            <?php echo htmlspecialchars($msg->message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    </div>
    <?php
        endforeach;
    endif;
    ?>

    <?php if (empty($cart->items)): ?>
        <div style="max-width: 500px; margin: 3rem auto; text-align: center;">
            <div style="font-size: 4rem; color: var(--jy-color-text-muted); margin-bottom: 1rem;">&#128722;</div>
            <h2 style="margin-bottom: 0.5rem;">Your cart is empty</h2>
            <p style="color: var(--jy-color-text-muted); margin-bottom: 1.5rem;">Add some items to get started.</p>
            <a href="/products" class="btn btn-primary">Browse Products</a>
        </div>
    <?php else: ?>

        <!-- Progress Indicator -->
        <?php
        $total_sections = count($sections);
        $completed_count = 0;
        $active_number = 1;
        foreach ($sections as $sk => $sv) {
            if ($sv['state'] == 'completed') $completed_count++;
            if ($sv['state'] == 'active') $active_number = $sv['number'];
        }
        $progress_pct = ($total_sections > 0) ? round(($completed_count / $total_sections) * 100) : 0;
        ?>
        <div style="margin-bottom: 1.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.875rem; color: var(--jy-color-text-muted);" aria-current="step">Step <?php echo $active_number; ?> of <?php echo $total_sections; ?></span>
            </div>
            <div style="height: 4px; background: #e9ecef; border-radius: 2px; overflow: hidden;">
                <div id="progress-bar" style="height: 100%; background: var(--jy-color-primary); border-radius: 2px; transition: width 0.3s; width: <?php echo $progress_pct; ?>%;"></div>
            </div>
        </div>

        <!-- Mobile Order Summary (hidden on desktop) -->
        <div id="mobile-order-summary" style="display: none; margin-bottom: 1rem;">
            <div onclick="this.querySelector('.mobile-summary-detail').style.display = this.querySelector('.mobile-summary-detail').style.display === 'none' ? 'block' : 'none'; this.querySelector('.chevron').classList.toggle('expanded');"
                 style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 0.875rem 1.25rem; cursor: pointer;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-weight: 600;">Order: <?php
                        $first_item = reset($cart->items);
                        echo htmlspecialchars($first_item[1]->get('pro_name'), ENT_QUOTES, 'UTF-8');
                    ?></span>
                    <span style="display: flex; align-items: center; gap: 0.5rem;">
                        <strong style="color: var(--jy-color-primary);"><?php echo $currency_symbol . number_format($cart->get_total() - $total_discount, 2, '.', ','); ?></strong>
                        <span class="chevron" style="display: inline-block; transition: transform 0.2s; font-size: 0.75rem;">&#9660;</span>
                    </span>
                </div>
                <div class="mobile-summary-detail" style="display: none; margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid var(--jy-color-border);">
                    <?php foreach ($cart->items as $key => $cart_item):
                        list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
                    ?>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.9375rem;">
                        <span><?php echo htmlspecialchars($product->get('pro_name'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <span><?php echo $currency_symbol . number_format($price, 2, '.', ','); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <style>
            @media (max-width: 768px) {
                #mobile-order-summary { display: block !important; }
                #order-summary { display: none !important; }
                .chevron.expanded { transform: rotate(180deg); }
            }
            .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
            .checkout-section:focus-within .section-header { outline: 2px solid var(--jy-color-primary); outline-offset: -2px; }
        </style>

        <div style="display: flex; gap: 2rem; align-items: flex-start; flex-wrap: wrap;">

            <!-- Accordion (left) -->
            <div style="flex: 1; min-width: 320px;" id="checkout-accordion">
            <div aria-live="polite" id="checkout-status" class="sr-only"></div>
            <?php foreach ($sections as $section_key => $section): ?>
                <fieldset class="checkout-section" data-section="<?php echo $section_key; ?>" data-state="<?php echo $section['state']; ?>"
                     style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); margin-bottom: 1rem; overflow: hidden; border: none; padding: 0;">
                    <legend class="sr-only"><?php echo htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8'); ?></legend>

                    <!-- Section Header -->
                    <div class="section-header" role="button" tabindex="0"
                        id="header-<?php echo $section_key; ?>"
                        aria-expanded="<?php echo ($section['state'] == 'active') ? 'true' : 'false'; ?>"
                        aria-controls="body-<?php echo $section_key; ?>"
                        style="padding: 1rem 1.5rem; display: flex; justify-content: space-between; align-items: center; cursor: pointer;
                        <?php if ($section['state'] == 'active'): ?>background: var(--jy-color-primary); color: #fff;
                        <?php elseif ($section['state'] == 'completed'): ?>background: var(--jy-color-surface); border-bottom: 1px solid var(--jy-color-border);
                        <?php else: ?>background: #f5f5f5; color: #aaa; cursor: default;<?php endif; ?>"
                        <?php if ($section['state'] == 'completed'): ?>onclick="openSection('<?php echo $section_key; ?>')"<?php endif; ?>
                        onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();if(this.parentElement.dataset.state==='completed')openSection('<?php echo $section_key; ?>');}">
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <span aria-hidden="true" style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; font-weight: 700; font-size: 0.875rem;
                                <?php if ($section['state'] == 'active'): ?>background: rgba(255,255,255,0.2); color: #fff;
                                <?php elseif ($section['state'] == 'completed'): ?>background: #198754; color: #fff;
                                <?php else: ?>background: #ddd; color: #999;<?php endif; ?>">
                                <?php if ($section['state'] == 'completed'): ?>&#10003;<?php else: echo $section['number']; endif; ?>
                            </span>
                            <strong style="font-size: 1.0625rem;"><?php echo htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>
                        <?php if ($section['state'] == 'completed' && $section['summary']): ?>
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <span style="font-size: 0.875rem; color: var(--jy-color-text-muted);"><?php echo $section['summary']; ?></span>
                            <span style="font-size: 0.8125rem; color: var(--jy-color-primary); font-weight: 600;">Edit</span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Section Body -->
                    <div class="section-body" id="body-<?php echo $section_key; ?>"
                         role="region" aria-labelledby="header-<?php echo $section_key; ?>"
                         style="padding: 1.5rem; <?php if ($section['state'] != 'active') echo 'display: none;'; ?>">

                    <?php if ($section_key == 'contact'): ?>
                        <!-- CONTACT SECTION -->
                        <?php if ($session->is_logged_in()):
                            $user = new User($session->get_user_id(), TRUE);
                        ?>
                            <div class="alert alert-info" style="margin-bottom: 1rem;">
                                Logged in as <strong><?php echo htmlspecialchars($user->get('usr_fname') . ' ' . $user->get('usr_lname'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                (<?php echo htmlspecialchars($user->get('usr_email'), ENT_QUOTES, 'UTF-8'); ?>)
                            </div>
                            <input type="hidden" id="contact_email" value="<?php echo htmlspecialchars($user->get('usr_email'), ENT_QUOTES, 'UTF-8'); ?>">
                        <?php else: ?>
                            <?php
                            $contact_email = '';
                            if (!empty($cart->billing_user['billing_email'])) {
                                $contact_email = $cart->billing_user['billing_email'];
                            }
                            ?>
                            <label for="contact_email" style="display: block; font-weight: 600; margin-bottom: 0.5rem;">Email Address <span style="color: var(--jy-color-danger);">*</span></label>
                            <input type="email" id="contact_email" name="email" value="<?php echo htmlspecialchars($contact_email, ENT_QUOTES, 'UTF-8'); ?>"
                                   style="width: 100%; padding: 0.625rem 0.875rem; border: 1px solid var(--jy-color-border); border-radius: 6px; font-size: 1rem;"
                                   required aria-required="true" autocomplete="email" placeholder="your@email.com"
                                   aria-describedby="contact_email_error">
                            <div id="contact_email_error" role="alert" style="color: var(--jy-color-danger); font-size: 0.875rem; margin-top: 0.25rem; display: none;"></div>
                            <div id="contact_email_exists" style="background: #e8f4fd; padding: 0.75rem 1rem; border-radius: 6px; margin-top: 0.75rem; font-size: 0.9375rem; display: none;">
                                Welcome back! <a href="#" onclick="showLoginModal(); return false;">Log in</a> for faster checkout, or continue as guest.
                            </div>
                        <?php endif; ?>
                        <div style="margin-top: 1.25rem;">
                            <button type="button" class="btn btn-primary" onclick="validateAndContinue('contact')" style="width: 100%;">Continue</button>
                        </div>

                    <?php elseif ($section_key == 'coupon'): ?>
                        <!-- COUPON SECTION -->
                        <?php if (!empty($cart->coupon_codes)): ?>
                        <div style="margin-bottom: 1rem;">
                            <strong style="font-size: 0.875rem;">Applied:</strong>
                            <?php foreach ($cart->coupon_codes as $coupon_code): ?>
                            <span class="applied-coupon" style="display: inline-flex; align-items: center; gap: 0.25rem; background: #198754; color: #fff; font-size: 0.8125rem; padding: 0.25rem 0.625rem; border-radius: 4px; margin: 0.25rem 0.25rem 0 0;">
                                <?php echo htmlspecialchars($coupon_code, ENT_QUOTES, 'UTF-8'); ?>
                                <a href="#" onclick="removeCoupon('<?php echo addslashes($coupon_code); ?>'); return false;" style="color: #fff; text-decoration: none; font-weight: 700;">&times;</a>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (StripeHelper::isTestMode() && !empty($page_vars['all_coupons'])): ?>
                        <div class="alert alert-info" style="font-size: 0.875rem; margin-bottom: 1rem;">
                            <strong>Test coupons:</strong>
                            <?php foreach ($page_vars['all_coupons'] as $coupon): ?>
                            <a href="#" onclick="applyCouponCode('<?php echo addslashes($coupon->get('ccd_code')); ?>'); return false;" class="btn btn-outline" style="font-size: 0.75rem; padding: 0.2rem 0.6rem; margin: 0.25rem 0 0 0.25rem;">
                                <?php echo htmlspecialchars($coupon->get('ccd_code'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <div style="display: flex; gap: 0.5rem;">
                            <input type="text" id="coupon_code_input" placeholder="Enter coupon code"
                                   style="flex: 1; padding: 0.625rem 0.875rem; border: 1px solid var(--jy-color-border); border-radius: 6px; font-size: 1rem;">
                            <button type="button" class="btn btn-outline" onclick="applyCoupon()">Apply</button>
                        </div>
                        <div id="coupon_error" style="color: var(--jy-color-danger); font-size: 0.875rem; margin-top: 0.5rem; display: none;"></div>
                        <?php if (!empty($page_vars['coupon_error'])): ?>
                        <div style="color: var(--jy-color-danger); font-size: 0.875rem; margin-top: 0.5rem;">
                            <?php echo htmlspecialchars($page_vars['coupon_error'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <?php endif; ?>
                        <div style="margin-top: 1.25rem;">
                            <button type="button" class="btn btn-primary" onclick="validateAndContinue('coupon')" style="width: 100%;">Continue</button>
                        </div>

                    <?php elseif ($section_key == 'billing'): ?>
                        <!-- BILLING SECTION -->
                        <?php if ($session->is_logged_in()):
                            if (!isset($user)) $user = new User($session->get_user_id(), TRUE);
                        ?>
                            <div style="margin-bottom: 1rem; padding: 0.75rem 1rem; background: var(--jy-color-surface); border-radius: 6px;">
                                <strong><?php echo htmlspecialchars($user->get('usr_fname') . ' ' . $user->get('usr_lname'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                <br><span style="color: var(--jy-color-text-muted); font-size: 0.9375rem;"><?php echo htmlspecialchars($user->get('usr_email'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        <?php else: ?>
                            <!-- Name (read-only from cart or editable) -->
                            <?php if ($has_name_from_cart): ?>
                            <div id="billing_name_display" style="margin-bottom: 1rem; padding: 0.75rem 1rem; background: var(--jy-color-surface); border-radius: 6px; display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <strong id="billing_name_text"><?php echo htmlspecialchars($prefill_name['first'] . ' ' . $prefill_name['last'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <br><span style="color: var(--jy-color-text-muted); font-size: 0.9375rem;" id="billing_email_text"><?php echo htmlspecialchars($cart->billing_user['billing_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <a href="#" onclick="document.getElementById('billing_name_fields').style.display='block'; this.parentElement.style.display='none'; return false;" style="font-size: 0.8125rem; color: var(--jy-color-primary);">Change</a>
                            </div>
                            <div id="billing_name_fields" style="display: none; margin-bottom: 1rem;">
                            <?php else: ?>
                            <div id="billing_name_fields" style="margin-bottom: 1rem;">
                            <?php endif; ?>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                                    <div>
                                        <label for="billing_first_name" style="display: block; font-weight: 600; margin-bottom: 0.25rem; font-size: 0.9375rem;">First Name <span style="color: var(--jy-color-danger);">*</span></label>
                                        <input type="text" id="billing_first_name" name="billing_first_name"
                                               value="<?php echo htmlspecialchars($cart->billing_user['billing_first_name'] ?? $prefill_name['first'], ENT_QUOTES, 'UTF-8'); ?>"
                                               style="width: 100%; padding: 0.625rem 0.875rem; border: 1px solid var(--jy-color-border); border-radius: 6px; font-size: 1rem;"
                                               required autocomplete="given-name">
                                    </div>
                                    <div>
                                        <label for="billing_last_name" style="display: block; font-weight: 600; margin-bottom: 0.25rem; font-size: 0.9375rem;">Last Name <span style="color: var(--jy-color-danger);">*</span></label>
                                        <input type="text" id="billing_last_name" name="billing_last_name"
                                               value="<?php echo htmlspecialchars($cart->billing_user['billing_last_name'] ?? $prefill_name['last'], ENT_QUOTES, 'UTF-8'); ?>"
                                               style="width: 100%; padding: 0.625rem 0.875rem; border: 1px solid var(--jy-color-border); border-radius: 6px; font-size: 1rem;"
                                               required autocomplete="family-name">
                                    </div>
                                </div>
                            </div>

                            <!-- Password -->
                            <div style="margin-bottom: 1rem;">
                                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.9375rem;">
                                    <input type="checkbox" id="create_account_toggle" onchange="document.getElementById('password_field').style.display = this.checked ? 'block' : 'none';">
                                    Create an account for faster checkout next time
                                </label>
                                <div id="password_field" style="display: none; margin-top: 0.75rem;">
                                    <label for="billing_password" style="display: block; font-weight: 600; margin-bottom: 0.25rem; font-size: 0.9375rem;">Password <span style="color: var(--jy-color-danger);">*</span></label>
                                    <input type="password" id="billing_password" name="password"
                                           style="width: 100%; padding: 0.625rem 0.875rem; border: 1px solid var(--jy-color-border); border-radius: 6px; font-size: 1rem;"
                                           autocomplete="new-password">
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Terms -->
                        <div style="margin-bottom: 1rem;">
                            <label style="display: flex; align-items: flex-start; gap: 0.5rem; cursor: pointer; font-size: 0.9375rem;">
                                <input type="checkbox" id="billing_privacy" name="privacy" required style="margin-top: 0.2rem;">
                                <span>I agree to the <a href="/terms" target="_blank">Terms of Use</a> and <a href="/privacy" target="_blank">Privacy Policy</a>. <span style="color: var(--jy-color-danger);">*</span></span>
                            </label>
                            <div id="billing_privacy_error" style="color: var(--jy-color-danger); font-size: 0.875rem; margin-top: 0.25rem; display: none;"></div>
                        </div>

                        <div id="billing_errors" style="color: var(--jy-color-danger); font-size: 0.875rem; margin-bottom: 0.75rem; display: none;"></div>

                        <div style="margin-top: 1.25rem;">
                            <?php if ($cart->get_total() <= 0): ?>
                            <button type="button" class="btn btn-primary" onclick="submitBillingAndComplete()" style="width: 100%;">Complete Order</button>
                            <?php else: ?>
                            <button type="button" class="btn btn-primary" onclick="validateAndContinue('billing')" style="width: 100%;">Continue</button>
                            <?php endif; ?>
                        </div>

                    <?php elseif ($section_key == 'payment'): ?>
                        <!-- PAYMENT SECTION -->
                        <?php if ($require_login): ?>
                        <div class="alert alert-warning">
                            The email <strong><?php echo htmlspecialchars($cart->billing_user['billing_email'], ENT_QUOTES, 'UTF-8'); ?></strong> already exists in our system.
                            <a href="/login">Log in</a> to continue checkout.
                        </div>
                        <?php else: ?>

                            <?php if ($settings->get_setting('checkout_type') == 'stripe_checkout' || $settings->get_setting('checkout_type') == 'stripe_regular'): ?>
                            <div style="margin-bottom: 1.5rem;">
                                <?php
                                $formwriter = $page->getFormWriter('form_stripe');
                                if ($settings->get_setting('checkout_type') == 'stripe_checkout') {
                                    echo '<h5 style="margin-bottom: 1rem;">Review & Pay</h5>';
                                    echo $page_vars['stripe_helper']->output_stripe_checkout_form($cart->get_hash());
                                } else {
                                    echo $page_vars['stripe_helper']->output_stripe_regular_form($formwriter, '');
                                }
                                ?>
                            </div>
                            <?php endif; ?>

                            <?php if ($settings->get_setting('use_paypal_checkout') && !empty($page_vars['paypal_helper'])): ?>
                            <?php if ($cart->is_paypal_available()): ?>
                            <div style="<?php if ($settings->get_setting('checkout_type')): ?>border-top: 1px solid var(--jy-color-border); padding-top: 1.5rem; margin-top: 1.5rem;<?php endif; ?>">
                                <h5 style="margin-bottom: 1rem;">Pay with PayPal</h5>
                                <?php
                                if ($cart->get_num_recurring() == 1 && $cart->get_num_non_recurring() == 0) {
                                    echo $page_vars['paypal_helper']->output_paypal_subscription_checkout_code($page_vars['plan_id']);
                                } else {
                                    echo $page_vars['paypal_helper']->output_paypal_checkout_code($page_vars['paypal_item_list']);
                                }
                                ?>
                            </div>
                            <?php else: ?>
                            <div style="border-top: 1px solid var(--jy-color-border); padding-top: 1.5rem; margin-top: 1.5rem; color: var(--jy-color-text-muted); font-size: 0.875rem;">
                                PayPal is not available for carts containing a mix of subscriptions and other items. You can pay with Stripe, or check out subscriptions separately.
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>

                            <div style="margin-top: 1.5rem; text-align: center; color: var(--jy-color-text-muted); font-size: 0.8125rem;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: -2px;" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                Your order is protected by 256-bit SSL encryption
                            </div>
                        <?php endif; ?>

                    <?php endif; ?>

                    </div><!-- /section-body -->
                </fieldset><!-- /checkout-section -->
            <?php endforeach; ?>
            </div><!-- /accordion -->

            <!-- Order Summary (right) -->
            <div style="flex: 0 0 340px; min-width: 260px; position: sticky; top: 2rem;" id="order-summary">
                <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden;">
                    <div style="background: var(--jy-color-primary); color: #fff; padding: 1rem 1.5rem;">
                        <h3 style="margin: 0; color: #fff; font-size: 1.0625rem;">Order Summary</h3>
                    </div>

                    <?php
                    $total_discount = 0;
                    foreach ($cart->items as $key => $cart_item):
                        list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
                        if ($discount) $total_discount += $discount;
                    ?>
                    <div style="padding: 1rem 1.5rem; border-bottom: 1px solid var(--jy-color-border);">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem;">
                            <div style="flex: 1; min-width: 0;">
                                <h6 style="margin: 0 0 0.25rem; font-size: 0.9375rem;">
                                    <?php echo htmlspecialchars($product->get('pro_name'), ENT_QUOTES, 'UTF-8'); ?>
                                    <small style="color: var(--jy-color-text-muted);"><?php echo htmlspecialchars($product_version->get('prv_version_name'), ENT_QUOTES, 'UTF-8'); ?></small>
                                </h6>
                                <?php if (!empty($data['full_name_first'])): ?>
                                <small style="color: var(--jy-color-text-muted);">
                                    <?php echo htmlspecialchars($data['full_name_first'] . ' ' . $data['full_name_last'], ENT_QUOTES, 'UTF-8'); ?>
                                </small>
                                <?php endif; ?>
                            </div>
                            <div style="text-align: right; flex-shrink: 0;">
                                <div style="font-weight: 700; color: var(--jy-color-primary);">
                                    <?php echo $currency_symbol . number_format($price, 2, '.', ','); ?>
                                    <?php if ($discount): ?>
                                    <div style="font-size: 0.8125rem; color: #198754;">-<?php echo $currency_symbol . number_format($discount, 2, '.', ','); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <!-- Per-item actions -->
                        <div style="margin-top: 0.5rem; display: flex; gap: 1rem; font-size: 0.8125rem;">
                            <a href="<?php echo $product->get_url(); ?>?edit_item=<?php echo $key; ?>" style="color: var(--jy-color-primary); text-decoration: none;">Edit</a>
                            <a href="/cart?r=<?php echo $key; ?>" style="color: var(--jy-color-danger); text-decoration: none;">Remove</a>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div style="background: var(--jy-color-surface); padding: 1rem 1.5rem;">
                        <dl style="margin: 0; display: grid; grid-template-columns: 1fr auto; gap: 0.375rem 1rem;">
                            <?php if ($total_discount > 0): ?>
                            <dt style="font-weight: 400;">Subtotal:</dt>
                            <dd style="margin: 0; text-align: right;"><?php echo $currency_symbol . number_format($cart->get_total(), 2, '.', ','); ?></dd>
                            <dt style="color: #198754;">Discount:</dt>
                            <dd style="margin: 0; text-align: right; color: #198754;">-<?php echo $currency_symbol . number_format($total_discount, 2, '.', ','); ?></dd>
                            <?php endif; ?>
                            <dt style="font-weight: 700; font-size: 1.0625rem; <?php if ($total_discount > 0): ?>padding-top: 0.75rem; border-top: 1px solid var(--jy-color-border);<?php endif; ?>">Total:</dt>
                            <dd style="margin: 0; text-align: right; font-weight: 700; font-size: 1.0625rem; color: var(--jy-color-primary); <?php if ($total_discount > 0): ?>padding-top: 0.75rem; border-top: 1px solid var(--jy-color-border);<?php endif; ?>">
                                <?php echo $currency_symbol . number_format($cart->get_total() - $total_discount, 2, '.', ','); ?>
                            </dd>
                        </dl>
                    </div>

                    <div style="padding: 1rem 1.5rem; text-align: center;">
                        <a href="/products" style="font-size: 0.875rem; color: var(--jy-color-text-muted); text-decoration: none;">+ Add another item</a>
                    </div>
                </div>
            </div><!-- /order summary -->

        </div>
    <?php endif; ?>

    </div>
</section>

<!-- Login Modal -->
<div id="login-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: #fff; border-radius: 12px; padding: 2rem; max-width: 400px; width: 90%; position: relative; box-shadow: 0 8px 32px rgba(0,0,0,0.2);">
        <button onclick="closeLoginModal()" style="position: absolute; top: 0.75rem; right: 1rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--jy-color-text-muted);">&times;</button>
        <h3 style="margin: 0 0 1.5rem;">Log In</h3>
        <div id="login-modal-error" style="color: var(--jy-color-danger); font-size: 0.875rem; margin-bottom: 1rem; display: none;"></div>
        <div style="margin-bottom: 1rem;">
            <label for="login_modal_email" style="display: block; font-weight: 600; margin-bottom: 0.25rem;">Email</label>
            <input type="email" id="login_modal_email" name="email" style="width: 100%; padding: 0.625rem 0.875rem; border: 1px solid var(--jy-color-border); border-radius: 6px; font-size: 1rem;" autocomplete="email">
        </div>
        <div style="margin-bottom: 1.5rem;">
            <label for="login_modal_password" style="display: block; font-weight: 600; margin-bottom: 0.25rem;">Password</label>
            <input type="password" id="login_modal_password" name="password" style="width: 100%; padding: 0.625rem 0.875rem; border: 1px solid var(--jy-color-border); border-radius: 6px; font-size: 1rem;" autocomplete="current-password">
        </div>
        <button onclick="submitLogin()" class="btn btn-primary" style="width: 100%;">Log In</button>
        <div style="margin-top: 1rem; text-align: center;">
            <a href="/forgot_password" style="font-size: 0.875rem; color: var(--jy-color-text-muted);">Forgot password?</a>
        </div>
    </div>
</div>

<script>
(function() {
    function openSection(sectionKey) {
        var sections = document.querySelectorAll('.checkout-section');
        sections.forEach(function(el) {
            var body = el.querySelector('.section-body');
            var header = el.querySelector('.section-header');
            if (el.dataset.section === sectionKey) {
                el.dataset.state = 'active';
                body.style.display = 'block';
                header.style.background = 'var(--jy-color-primary)';
                header.style.color = '#fff';
                header.style.cursor = 'pointer';
                header.setAttribute('aria-expanded', 'true');
                body.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                // Focus first input in the section
                var firstInput = body.querySelector('input, select, textarea, button');
                if (firstInput) setTimeout(function() { firstInput.focus(); }, 300);
                // Announce to screen readers
                var statusEl = document.getElementById('checkout-status');
                if (statusEl) statusEl.textContent = header.querySelector('strong').textContent + ' section is now active';
            } else if (el.dataset.state === 'active') {
                el.dataset.state = 'pending';
                body.style.display = 'none';
                header.style.background = '#f5f5f5';
                header.style.color = '#aaa';
                header.style.cursor = 'default';
                header.setAttribute('aria-expanded', 'false');
            }
        });
    }

    function markCompleted(sectionKey, summary) {
        var el = document.querySelector('[data-section="' + sectionKey + '"]');
        if (!el) return;
        el.dataset.state = 'completed';
        var header = el.querySelector('.section-header');
        var body = el.querySelector('.section-body');
        body.style.display = 'none';
        header.style.background = 'var(--jy-color-surface)';
        header.style.color = '';
        header.style.cursor = 'pointer';
        header.onclick = function() { openSection(sectionKey); };

        // Update number badge to checkmark
        var badge = header.querySelector('span');
        if (badge) {
            badge.innerHTML = '&#10003;';
            badge.style.background = '#198754';
            badge.style.color = '#fff';
        }

        // Show summary
        var existing = header.querySelector('.section-summary');
        if (existing) existing.remove();
        if (summary) {
            var sumDiv = document.createElement('div');
            sumDiv.className = 'section-summary';
            sumDiv.style.cssText = 'display:flex;align-items:center;gap:1rem;';
            sumDiv.innerHTML = '<span style="font-size:0.875rem;color:var(--jy-color-text-muted);">' + summary + '</span><span style="font-size:0.8125rem;color:var(--jy-color-primary);font-weight:600;">Edit</span>';
            header.appendChild(sumDiv);
        }
        updateProgress();
    }

    function getNextSection(currentKey) {
        var keys = [];
        document.querySelectorAll('.checkout-section').forEach(function(el) {
            keys.push(el.dataset.section);
        });
        var idx = keys.indexOf(currentKey);
        return (idx >= 0 && idx < keys.length - 1) ? keys[idx + 1] : null;
    }

    function validateAndContinue(sectionKey) {
        if (sectionKey === 'contact') {
            var email = document.getElementById('contact_email').value.trim();
            var errorEl = document.getElementById('contact_email_error');
            if (!email || !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                if (errorEl) { errorEl.textContent = 'Please enter a valid email address.'; errorEl.style.display = 'block'; }
                return;
            }
            if (errorEl) errorEl.style.display = 'none';

            // Save email to billing via form POST
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = '/cart';
            var fields = {
                'billing_email': email,
                'billing_first_name': '<?php echo addslashes($prefill_name['first']); ?>',
                'billing_last_name': '<?php echo addslashes($prefill_name['last']); ?>',
                'privacy': '<?php echo $session->is_logged_in() ? "1" : ""; ?>',
                'password': '<?php echo $session->is_logged_in() ? "x" : ""; ?>'
            };
            <?php if ($session->is_logged_in()): ?>
            // For logged-in users, just advance the section
            markCompleted('contact', email);
            openSection(getNextSection('contact'));
            return;
            <?php endif; ?>
            for (var key in fields) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = fields[key];
                form.appendChild(input);
            }
            document.body.appendChild(form);
            form.submit();
            return;
        }

        if (sectionKey === 'coupon') {
            // Coupons are optional, just advance
            var summary = '';
            var applied = document.querySelectorAll('[data-section="coupon"] .section-body span[style*="background: #198754"]');
            if (applied.length > 0) {
                var codes = [];
                applied.forEach(function(el) { codes.push(el.textContent.replace('×', '').trim()); });
                summary = codes.join(', ') + ' applied';
            } else {
                summary = 'No coupon';
            }
            markCompleted('coupon', summary);
            openSection(getNextSection('coupon'));
            return;
        }

        if (sectionKey === 'billing') {
            submitBilling(false);
            return;
        }
    }

    function submitBilling(andComplete) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '/cart';

        var email = document.getElementById('contact_email') ? document.getElementById('contact_email').value.trim() : '<?php echo addslashes($cart->billing_user['billing_email'] ?? ''); ?>';
        var firstName = document.getElementById('billing_first_name') ? document.getElementById('billing_first_name').value.trim() : '<?php echo addslashes($prefill_name['first']); ?>';
        var lastName = document.getElementById('billing_last_name') ? document.getElementById('billing_last_name').value.trim() : '<?php echo addslashes($prefill_name['last']); ?>';
        var privacy = document.getElementById('billing_privacy') ? (document.getElementById('billing_privacy').checked ? '1' : '') : '';
        var password = document.getElementById('billing_password') ? document.getElementById('billing_password').value : '';

        // Client-side validation
        var errors = [];
        if (!firstName) errors.push('First name is required.');
        if (!lastName) errors.push('Last name is required.');
        if (!privacy) errors.push('You must agree to the terms.');
        <?php if (!$session->is_logged_in()): ?>
        var createAccount = document.getElementById('create_account_toggle');
        if (createAccount && createAccount.checked && !password) {
            errors.push('Password is required to create an account.');
        }
        if (!createAccount || !createAccount.checked) {
            // Still need a password for guest checkout account creation
            password = password || Math.random().toString(36).slice(-12);
        }
        <?php endif; ?>

        if (errors.length > 0) {
            var errDiv = document.getElementById('billing_errors');
            if (errDiv) { errDiv.innerHTML = errors.join('<br>'); errDiv.style.display = 'block'; }
            if (!privacy) {
                var privErr = document.getElementById('billing_privacy_error');
                if (privErr) { privErr.textContent = 'Required'; privErr.style.display = 'block'; }
            }
            return;
        }

        var fields = {
            'billing_email': email,
            'billing_first_name': firstName,
            'billing_last_name': lastName,
            'privacy': privacy,
            'password': password
        };
        if (andComplete) {
            fields['complete_order'] = '1';
        }
        for (var key in fields) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = fields[key];
            form.appendChild(input);
        }
        document.body.appendChild(form);
        form.submit();
    }

    function submitBillingAndComplete() {
        submitBilling(true);
    }

    function applyCoupon() {
        var code = document.getElementById('coupon_code_input').value.trim();
        if (!code) return;
        applyCouponCode(code);
    }

    function applyCouponCode(code) {
        var errorEl = document.getElementById('coupon_error');
        if (errorEl) errorEl.style.display = 'none';

        var formData = new FormData();
        formData.append('action', 'apply_coupon');
        formData.append('coupon_code', code);

        fetch('/ajax/checkout_ajax', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    // Reload to reflect updated prices in order summary
                    window.location.reload();
                } else {
                    if (errorEl) { errorEl.textContent = data.error; errorEl.style.display = 'block'; }
                }
            })
            .catch(function() {
                if (errorEl) { errorEl.textContent = 'An error occurred. Please try again.'; errorEl.style.display = 'block'; }
            });
    }

    function removeCoupon(code) {
        var formData = new FormData();
        formData.append('action', 'remove_coupon');
        formData.append('coupon_code', code);

        fetch('/ajax/checkout_ajax', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    window.location.reload();
                }
            });
    }

    // Email check on blur
    var contactEmail = document.getElementById('contact_email');
    if (contactEmail && contactEmail.type === 'email') {
        contactEmail.addEventListener('blur', function() {
            var email = this.value.trim();
            var existsEl = document.getElementById('contact_email_exists');
            if (!email || !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                if (existsEl) existsEl.style.display = 'none';
                return;
            }
            fetch('/ajax/checkout_ajax?action=check_email&email=' + encodeURIComponent(email))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.exists && existsEl) {
                        existsEl.style.display = 'block';
                    } else if (existsEl) {
                        existsEl.style.display = 'none';
                    }
                });
        });
    }

    // Login modal
    function showLoginModal() {
        var modal = document.getElementById('login-modal');
        modal.style.display = 'flex';
        var emailInput = document.getElementById('login_modal_email');
        var contactVal = document.getElementById('contact_email');
        if (emailInput && contactVal) emailInput.value = contactVal.value;
        document.getElementById('login_modal_password').focus();
    }

    function closeLoginModal() {
        document.getElementById('login-modal').style.display = 'none';
    }

    function submitLogin() {
        var email = document.getElementById('login_modal_email').value.trim();
        var password = document.getElementById('login_modal_password').value;
        var errorEl = document.getElementById('login-modal-error');

        if (!email || !password) {
            if (errorEl) { errorEl.textContent = 'Please enter email and password.'; errorEl.style.display = 'block'; }
            return;
        }

        // Submit as a real form POST to /login with redirect back to /cart
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '/login';

        var fields = {
            'email': email,
            'password': password,
            'redirect': '/cart'
        };
        for (var key in fields) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = fields[key];
            form.appendChild(input);
        }
        document.body.appendChild(form);
        form.submit();
    }

    // Progress bar update
    function updateProgress() {
        var sections = document.querySelectorAll('.checkout-section');
        var total = sections.length;
        var completed = 0;
        sections.forEach(function(el) {
            if (el.dataset.state === 'completed') completed++;
        });
        var bar = document.getElementById('progress-bar');
        if (bar) bar.style.width = Math.round((completed / total) * 100) + '%';
    }

    // Expose to global scope for onclick handlers
    window.openSection = openSection;
    window.validateAndContinue = validateAndContinue;
    window.submitBillingAndComplete = submitBillingAndComplete;
    window.applyCoupon = applyCoupon;
    window.applyCouponCode = applyCouponCode;
    window.removeCoupon = removeCoupon;
    window.showLoginModal = showLoginModal;
    window.closeLoginModal = closeLoginModal;
    window.submitLogin = submitLogin;

    // Disable submit buttons on form submission
    document.addEventListener('submit', function(e) {
        var form = e.target;
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

    // Back button: restore accordion state from URL hash
    function saveState() {
        var active = document.querySelector('.checkout-section[data-state="active"]');
        if (active) {
            history.replaceState({ section: active.dataset.section }, '', '#' + active.dataset.section);
        }
    }

    window.addEventListener('popstate', function(e) {
        if (e.state && e.state.section) {
            openSection(e.state.section);
        }
    });

    // On page load, check hash for section to open
    if (window.location.hash) {
        var hashSection = window.location.hash.substring(1);
        var el = document.querySelector('[data-section="' + hashSection + '"]');
        if (el && (el.dataset.state === 'completed' || el.dataset.state === 'active')) {
            openSection(hashSection);
        }
    }

    // Save state when sections change
    var origOpen = openSection;
    openSection = function(key) {
        origOpen(key);
        history.pushState({ section: key }, '', '#' + key);
    };
    // Re-expose
    window.openSection = openSection;
})();
</script>

</div>
<?php
    $page->public_footer(['track' => true]);
?>
