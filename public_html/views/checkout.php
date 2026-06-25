<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('checkout_logic.php', 'logic'));

    $page_vars = process_logic(checkout_logic(array_merge($_GET, $_POST, $params ?? [])));
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
<div class="jy-checkout-testbar">
    <strong>Test Mode</strong> — Checkout type: <?php echo htmlspecialchars($settings->get_setting('checkout_type'), ENT_QUOTES, 'UTF-8'); ?>
</div>
<?php endif; ?>

<section class="jy-checkout-wrap">
    <div class="jy-container">

    <?php
    // Display session messages (payment errors, etc.)
    $checkout_messages = $session->get_messages('/checkout');
    if (!empty($checkout_messages)):
        foreach ($checkout_messages as $msg):
            $is_error_msg = ($msg->get_message_class() === 'error');
    ?>
    <div class="jy-checkout-msg <?php echo $is_error_msg ? 'is-error' : 'is-info'; ?>" role="alert">
        <span class="jy-checkout-msg-icon"><?php echo $is_error_msg ? '&#9888;' : '&#8505;'; ?></span>
        <div>
            <?php if ($msg->message_title): ?><strong><?php echo htmlspecialchars($msg->message_title, ENT_QUOTES, 'UTF-8'); ?>:</strong> <?php endif; ?>
            <?php echo htmlspecialchars($msg->message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    </div>
    <?php
        endforeach;
    endif;
    ?>

    <?php
    // Campaign coupon flash — shown once when a valid ?coupon= URL lands the visitor here
    $coupon_flash = $session->get_pending_coupon_flash();
    if ($coupon_flash):
    ?>
    <div class="jy-checkout-flash" role="status">
        <?php echo $coupon_flash; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($cart->items)): ?>
        <div class="jy-checkout-empty">
            <div class="jy-checkout-empty-icon">&#128722;</div>
            <h2 class="jy-checkout-empty-h">Your cart is empty</h2>
            <p class="jy-checkout-empty-p">Add some items to get started.</p>
            <a href="/products" class="btn btn-primary">Browse Products</a>
        </div>
    <?php else:
        // CHECKOUT_START conversion event — fires once per cart cycle when the visitor
        // reaches the checkout page with items in cart. Flag cleared on PURCHASE and on cart emptied.
        if (empty($_SESSION['checkout_started'])) {
            require_once(PathHelper::getIncludePath('data/visitor_events_class.php'));
            $session->save_visitor_event(VisitorEvent::TYPE_CHECKOUT_START);
            $_SESSION['checkout_started'] = true;
        }
    ?>

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
        <div class="jy-checkout-progress">
            <div class="jy-checkout-progress-head">
                <span class="jy-checkout-step-label" aria-current="step">Step <?php echo $active_number; ?> of <?php echo $total_sections; ?></span>
            </div>
            <div class="jy-checkout-track">
                <div id="progress-bar" class="jy-checkout-bar" style="--jy-checkout-progress: <?php echo $progress_pct; ?>%;"></div>
            </div>
        </div>

        <?php $total_discount = 0; ?>

        <!-- Mobile Order Summary (shown on desktop via media query) -->
        <div id="mobile-order-summary" class="jy-checkout-mobilesummary">
            <div class="jy-checkout-mobilecard" onclick="this.classList.toggle('is-open');">
                <div class="jy-checkout-mobilerow">
                    <span class="jy-fw-600">Order: <?php
                        $first_item = reset($cart->items);
                        echo htmlspecialchars($first_item[1]->get('pro_name'), ENT_QUOTES, 'UTF-8');
                    ?></span>
                    <span class="jy-checkout-mobile-price">
                        <strong class="jy-checkout-mobile-total"><?php echo $currency_symbol . number_format($cart->get_total() - $total_discount, 2, '.', ','); ?></strong>
                        <span class="jy-checkout-chevron">&#9660;</span>
                    </span>
                </div>
                <div class="jy-checkout-mobiledetail">
                    <?php foreach ($cart->items as $key => $cart_item):
                        list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
                    ?>
                    <div class="jy-checkout-mobile-itemrow">
                        <span><?php echo htmlspecialchars($product->get('pro_name'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <span><?php echo $currency_symbol . number_format($price, 2, '.', ','); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="jy-checkout-layout">

            <!-- Accordion (left) -->
            <div class="jy-checkout-accordion" id="checkout-accordion">
            <div aria-live="polite" id="checkout-status" class="jy-sr-only"></div>
            <?php foreach ($sections as $section_key => $section): ?>
                <fieldset class="checkout-section" data-section="<?php echo $section_key; ?>" data-state="<?php echo $section['state']; ?>">
                    <legend class="jy-sr-only"><?php echo htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8'); ?></legend>

                    <!-- Section Header -->
                    <div class="section-header" role="button" tabindex="0"
                        id="header-<?php echo $section_key; ?>"
                        aria-expanded="<?php echo ($section['state'] == 'active') ? 'true' : 'false'; ?>"
                        aria-controls="body-<?php echo $section_key; ?>"
                        <?php if ($section['state'] == 'completed'): ?>onclick="openSection('<?php echo $section_key; ?>')"<?php endif; ?>
                        onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();if(this.parentElement.dataset.state==='completed')openSection('<?php echo $section_key; ?>');}">
                        <div class="jy-checkout-headleft">
                            <span aria-hidden="true" class="section-badge">
                                <?php if ($section['state'] == 'completed'): ?>&#10003;<?php else: echo $section['number']; endif; ?>
                            </span>
                            <strong class="jy-checkout-sectitle"><?php echo htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>
                        <?php if ($section['state'] == 'completed' && $section['summary']): ?>
                        <div class="section-summary">
                            <span class="jy-checkout-sumtext"><?php echo $section['summary']; ?></span>
                            <span class="jy-checkout-sumedit">Edit</span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Section Body -->
                    <div class="section-body" id="body-<?php echo $section_key; ?>"
                         role="region" aria-labelledby="header-<?php echo $section_key; ?>">

                    <?php if ($section_key == 'billing'): ?>
                        <!-- BILLING USER SECTION -->
                        <?php
                        $is_logged_in = $session->is_logged_in();
                        if ($is_logged_in && !isset($user)) {
                            $user = new User($session->get_user_id(), TRUE);
                        }
                        // Logged-in users with a complete profile see read-only summary.
                        // Logged-in users with missing first/last names see editable name fields with email read-only.
                        $logged_in_complete = $is_logged_in
                            && trim((string)$user->get('usr_first_name')) !== ''
                            && trim((string)$user->get('usr_last_name')) !== '';
                        ?>
                        <?php if ($logged_in_complete):
                            $display_name = trim($user->get('usr_first_name') . ' ' . $user->get('usr_last_name'));
                        ?>
                            <div class="jy-checkout-userbox">
                                <div>
                                    <div class="jy-checkout-username"><?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="jy-checkout-useremail"><?php echo htmlspecialchars($user->get('usr_email'), ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <a href="/logout?redirect=/cart" class="jy-checkout-notyou">Not you?</a>
                            </div>
                            <input type="hidden" id="contact_email" value="<?php echo htmlspecialchars($user->get('usr_email'), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" id="billing_first_name" value="<?php echo htmlspecialchars($user->get('usr_first_name'), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" id="billing_last_name" value="<?php echo htmlspecialchars($user->get('usr_last_name'), ENT_QUOTES, 'UTF-8'); ?>">
                        <?php elseif ($is_logged_in): ?>
                            <div class="jy-checkout-signedin">
                                Signed in as <strong><?php echo htmlspecialchars($user->get('usr_email'), ENT_QUOTES, 'UTF-8'); ?></strong>. Please confirm your name to continue.
                            </div>
                            <input type="hidden" id="contact_email" value="<?php echo htmlspecialchars($user->get('usr_email'), ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="jy-checkout-namegrid">
                                <div>
                                    <label for="billing_first_name" class="jy-checkout-label">First Name <span class="jy-checkout-req">*</span></label>
                                    <input type="text" id="billing_first_name" name="billing_first_name"
                                           value="<?php echo htmlspecialchars($cart->billing_user['billing_first_name'] ?? $user->get('usr_first_name') ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                           class="jy-checkout-input"
                                           required autocomplete="given-name">
                                </div>
                                <div>
                                    <label for="billing_last_name" class="jy-checkout-label">Last Name <span class="jy-checkout-req">*</span></label>
                                    <input type="text" id="billing_last_name" name="billing_last_name"
                                           value="<?php echo htmlspecialchars($cart->billing_user['billing_last_name'] ?? $user->get('usr_last_name') ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                           class="jy-checkout-input"
                                           required autocomplete="family-name">
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Email -->
                            <div class="jy-checkout-field">
                                <label for="contact_email" class="jy-checkout-label">Email Address <span class="jy-checkout-req">*</span></label>
                                <input type="email" id="contact_email" name="billing_email"
                                       value="<?php echo htmlspecialchars($cart->billing_user['billing_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                       class="jy-checkout-input"
                                       required autocomplete="email" placeholder="your@email.com">
                                <div id="contact_email_exists" class="jy-checkout-emailexists" hidden>
                                    Welcome back! <a href="#" onclick="showLoginModal(); return false;">Log in</a> for faster checkout, or continue as guest.
                                </div>
                            </div>

                            <!-- Name -->
                            <div class="jy-checkout-namegrid">
                                <div>
                                    <label for="billing_first_name" class="jy-checkout-label">First Name <span class="jy-checkout-req">*</span></label>
                                    <input type="text" id="billing_first_name" name="billing_first_name"
                                           value="<?php echo htmlspecialchars($cart->billing_user['billing_first_name'] ?? $prefill_name['first'], ENT_QUOTES, 'UTF-8'); ?>"
                                           class="jy-checkout-input"
                                           required autocomplete="given-name">
                                </div>
                                <div>
                                    <label for="billing_last_name" class="jy-checkout-label">Last Name <span class="jy-checkout-req">*</span></label>
                                    <input type="text" id="billing_last_name" name="billing_last_name"
                                           value="<?php echo htmlspecialchars($cart->billing_user['billing_last_name'] ?? $prefill_name['last'], ENT_QUOTES, 'UTF-8'); ?>"
                                           class="jy-checkout-input"
                                           required autocomplete="family-name">
                                </div>
                            </div>
                        <?php endif; ?>

                        <div id="billing_errors" class="jy-checkout-errors" hidden></div>

                        <div class="jy-checkout-actions">
                            <?php if ($cart->get_total() <= 0): ?>
                            <button type="button" class="btn btn-primary jy-w-full" onclick="submitBillingAndComplete()">Complete Order</button>
                            <?php else: ?>
                            <button type="button" class="btn btn-primary jy-w-full" onclick="validateAndContinue('billing')">Continue</button>
                            <?php endif; ?>
                            <?php $consent_copy = LibraryFunctions::consent_copy('continuing'); if ($consent_copy): ?>
                            <p class="jy-checkout-consent">
                                <?php echo $consent_copy; ?>
                            </p>
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

                            <?php if (($settings->get_setting('checkout_type') == 'stripe_checkout' || $settings->get_setting('checkout_type') == 'stripe_regular') && !empty($page_vars['stripe_helper'])): ?>
                            <div class="jy-checkout-pay-stripe">
                                <?php
                                $formwriter = $page->getFormWriter('form_stripe');
                                if ($settings->get_setting('checkout_type') == 'stripe_checkout') {
                                    echo '<h5 class="jy-checkout-payhead">Review & Pay</h5>';
                                    echo $page_vars['stripe_helper']->output_stripe_checkout_form($cart->get_hash());
                                } else {
                                    echo $page_vars['stripe_helper']->output_stripe_regular_form($formwriter, '');
                                }
                                ?>
                            </div>
                            <?php endif; ?>

                            <?php if ($settings->get_setting('use_paypal_checkout') && !empty($page_vars['paypal_helper'])): ?>
                            <?php if ($cart->is_paypal_available()): ?>
                            <div class="jy-checkout-pay-paypal<?php if ($settings->get_setting('checkout_type')): ?> is-divided<?php endif; ?>">
                                <h5 class="jy-checkout-payhead">Pay with PayPal</h5>
                                <?php
                                if ($cart->get_num_recurring() == 1 && $cart->get_num_non_recurring() == 0) {
                                    echo $page_vars['paypal_helper']->output_paypal_subscription_checkout_code($page_vars['plan_id']);
                                } else {
                                    echo $page_vars['paypal_helper']->output_paypal_checkout_code($page_vars['paypal_item_list']);
                                }
                                ?>
                            </div>
                            <?php else: ?>
                            <div class="jy-checkout-paypal-unavail">
                                PayPal is not available for carts containing a mix of subscriptions and other items. You can pay with Stripe, or check out subscriptions separately.
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>

                            <div class="jy-checkout-secure">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="jy-checkout-secure-icon" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                Your order is protected by 256-bit SSL encryption
                            </div>
                        <?php endif; ?>

                    <?php endif; ?>

                    </div><!-- /section-body -->
                </fieldset><!-- /checkout-section -->
            <?php endforeach; ?>
            </div><!-- /accordion -->

            <!-- Order Summary (right) -->
            <div class="jy-checkout-summary-col" id="order-summary">
                <div class="jy-checkout-summary-card">
                    <div class="jy-checkout-summary-head">
                        <h3 class="jy-checkout-summary-title">Order Summary</h3>
                    </div>

                    <?php
                    $total_discount = 0;
                    foreach ($cart->items as $key => $cart_item):
                        list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
                        if ($discount) $total_discount += $discount;
                    ?>
                    <div class="jy-checkout-lineitem">
                        <div class="jy-checkout-lineitem-row">
                            <div class="jy-flex1min">
                                <h6 class="jy-checkout-lineitem-name">
                                    <?php echo htmlspecialchars($product->get('pro_name'), ENT_QUOTES, 'UTF-8'); ?>
                                    <small class="jy-muted"><?php echo htmlspecialchars($product_version->get('prv_version_name'), ENT_QUOTES, 'UTF-8'); ?></small>
                                </h6>
                                <?php if (!empty($data['full_name_first'])): ?>
                                <small class="jy-muted">
                                    <?php echo htmlspecialchars($data['full_name_first'] . ' ' . $data['full_name_last'], ENT_QUOTES, 'UTF-8'); ?>
                                </small>
                                <?php endif; ?>
                            </div>
                            <div class="jy-checkout-lineitem-pricewrap">
                                <div class="jy-checkout-price">
                                    <?php echo $currency_symbol . number_format($price, 2, '.', ','); ?>
                                    <?php if ($discount): ?>
                                    <div class="jy-checkout-discount">-<?php echo $currency_symbol . number_format($discount, 2, '.', ','); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <!-- Per-item actions -->
                        <div class="jy-checkout-itemactions">
                            <a href="<?php echo $product->get_url(); ?>?edit_item=<?php echo $key; ?>" class="jy-checkout-link">Edit</a>
                            <a href="/checkout?r=<?php echo $key; ?>" class="jy-checkout-link-danger">Remove</a>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <?php if ($settings->get_setting('coupons_active')): ?>
                    <div class="jy-checkout-coupon">
                        <?php if (!empty($cart->coupon_codes)): ?>
                        <div class="jy-checkout-coupon-chips">
                            <?php foreach ($cart->coupon_codes as $coupon_code): ?>
                            <span class="jy-checkout-chip">
                                <?php echo htmlspecialchars($coupon_code, ENT_QUOTES, 'UTF-8'); ?>
                                <a href="#" onclick="removeCoupon('<?php echo addslashes($coupon_code); ?>'); return false;" class="jy-checkout-chip-x">&times;</a>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php if (StripeHelper::isTestMode() && !empty($page_vars['all_coupons'])): ?>
                        <div class="jy-checkout-coupon-test">
                            Test:
                            <?php foreach ($page_vars['all_coupons'] as $coupon): ?>
                            <a href="#" onclick="applyCouponCode('<?php echo addslashes($coupon->get('ccd_code')); ?>'); return false;" class="jy-checkout-coupon-testlink">
                                <?php echo htmlspecialchars($coupon->get('ccd_code'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <div class="jy-checkout-coupon-row">
                            <input type="text" id="coupon_code_input" placeholder="Coupon code" class="jy-checkout-coupon-input">
                            <button type="button" class="btn btn-outline jy-checkout-coupon-apply" onclick="applyCoupon()">Apply</button>
                        </div>
                        <div id="coupon_error" class="jy-checkout-coupon-error" hidden></div>
                        <?php if (!empty($page_vars['coupon_error'])): ?>
                        <div class="jy-checkout-coupon-error">
                            <?php echo htmlspecialchars($page_vars['coupon_error'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="jy-checkout-totals">
                        <dl class="jy-checkout-totals-dl">
                            <?php if ($total_discount > 0): ?>
                            <dt class="jy-checkout-subtotal-dt">Subtotal:</dt>
                            <dd><?php echo $currency_symbol . number_format($cart->get_total() + $total_discount, 2, '.', ','); ?></dd>
                            <dt class="jy-checkout-discount-dt">Discount:</dt>
                            <dd class="jy-checkout-discount-dd">-<?php echo $currency_symbol . number_format($total_discount, 2, '.', ','); ?></dd>
                            <?php endif; ?>
                            <dt class="jy-checkout-total-dt<?php if ($total_discount > 0): ?> is-bordered<?php endif; ?>">Total:</dt>
                            <dd class="jy-checkout-total-dd<?php if ($total_discount > 0): ?> is-bordered<?php endif; ?>">
                                <?php echo $currency_symbol . number_format($cart->get_total(), 2, '.', ','); ?>
                            </dd>
                        </dl>
                    </div>

                    <div class="jy-checkout-addmore">
                        <a href="/products" class="jy-checkout-link-muted">+ Add another item</a>
                    </div>
                </div>
            </div><!-- /order summary -->

        </div>
    <?php endif; ?>

    </div>
</section>

<!-- Login Modal -->
<div id="login-modal" class="jy-checkout-modal" hidden>
    <div class="jy-checkout-modal-box">
        <button onclick="closeLoginModal()" class="jy-checkout-modal-close">&times;</button>
        <h3 class="jy-checkout-modal-title">Log In</h3>
        <div id="login-modal-error" class="jy-checkout-modal-error" hidden></div>
        <div class="jy-checkout-modal-field">
            <label for="login_modal_email" class="jy-checkout-modal-label">Email</label>
            <input type="email" id="login_modal_email" name="email" class="jy-checkout-input" autocomplete="email">
        </div>
        <div class="jy-checkout-modal-field is-last">
            <label for="login_modal_password" class="jy-checkout-modal-label">Password</label>
            <input type="password" id="login_modal_password" name="password" class="jy-checkout-input" autocomplete="current-password">
        </div>
        <button onclick="submitLogin()" class="btn btn-primary jy-w-full">Log In</button>
        <div class="jy-checkout-modal-foot">
            <a href="/forgot_password" class="jy-checkout-modal-forgot">Forgot password?</a>
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
                header.setAttribute('aria-expanded', 'false');
            }
        });
    }

    function markCompleted(sectionKey, summary) {
        var el = document.querySelector('[data-section="' + sectionKey + '"]');
        if (!el) return;
        el.dataset.state = 'completed';
        var header = el.querySelector('.section-header');
        header.onclick = function() { openSection(sectionKey); };

        // Update number badge to checkmark
        var badge = header.querySelector('.section-badge');
        if (badge) badge.innerHTML = '&#10003;';

        // Show summary
        var existing = header.querySelector('.section-summary');
        if (existing) existing.remove();
        if (summary) {
            var sumDiv = document.createElement('div');
            sumDiv.className = 'section-summary';
            sumDiv.innerHTML = '<span class="jy-checkout-sumtext">' + summary + '</span><span class="jy-checkout-sumedit">Edit</span>';
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
        if (sectionKey === 'billing') {
            submitBilling(false);
            return;
        }
    }

    function submitBilling(andComplete) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '/checkout';

        var email = document.getElementById('contact_email') ? document.getElementById('contact_email').value.trim() : '';
        var firstName = document.getElementById('billing_first_name') ? document.getElementById('billing_first_name').value.trim() : '';
        var lastName = document.getElementById('billing_last_name') ? document.getElementById('billing_last_name').value.trim() : '';

        var errors = [];
        if (!email || !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) errors.push('A valid email address is required.');
        if (!firstName) errors.push('First name is required.');
        if (!lastName) errors.push('Last name is required.');

        if (errors.length > 0) {
            var errDiv = document.getElementById('billing_errors');
            if (errDiv) { errDiv.innerHTML = errors.join('<br>'); errDiv.style.display = 'block'; }
            return;
        }

        var fields = {
            'billing_email': email,
            'billing_first_name': firstName,
            'billing_last_name': lastName
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
            'redirect': '/checkout'
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
