<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('cart_logic.php', 'logic', 'system', null, 'store', false));

    $page_vars    = process_logic(cart_logic(array_merge($_GET, $_POST, $params ?? [])));
    $cart         = $page_vars['cart'];
    $currency_symbol = $page_vars['currency_symbol'];
    $settings     = $page_vars['settings'];
    $session      = $page_vars['session'];

    $page = new PublicPage();
    $page->public_header([
        'is_valid_page' => $is_valid_page,
        'title'         => 'Your Cart',
        'noheader'      => true,
    ]);
?>
<div class="jy-ui">
<section class="jy-cart-section">
<div class="jy-container jy-cart-wrap">

<h1 class="jy-cart-title">Your Cart</h1>

<?php if (empty($cart->items)): ?>
    <div class="jy-cart-empty">
        <div class="jy-cart-empty-icon">&#128722;</div>
        <h2 class="jy-cart-empty-title">Your cart is empty</h2>
        <p class="jy-cart-empty-text">Add some items to get started.</p>
        <a href="/products" class="btn btn-primary">Browse Products</a>
    </div>
<?php else: ?>

<?php
$total_discount = 0;
foreach ($cart->items as $cart_item) {
    list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
    if ($discount) $total_discount += $discount;
}
?>

<div class="jy-cart-items">
<?php foreach ($cart->items as $key => $cart_item):
    list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
?>
<div class="jy-cart-card">

    <!-- Item header: name + price -->
    <div class="jy-cart-item-head">
        <div>
            <h3 class="jy-cart-item-name">
                <?php echo htmlspecialchars($product->get('pro_name'), ENT_QUOTES, 'UTF-8'); ?>
            </h3>
            <?php if ($product_version->get('prv_version_name')): ?>
            <div class="jy-cart-item-ver">
                <?php echo htmlspecialchars($product_version->get('prv_version_name'), ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="jy-cart-item-pricecol">
            <div class="jy-cart-item-price">
                <?php echo $currency_symbol . number_format($price, 2, '.', ','); ?>
            </div>
            <?php if ($discount): ?>
            <div class="jy-cart-item-coupon">
                &minus;<?php echo $currency_symbol . number_format($discount, 2, '.', ','); ?> coupon
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Item metadata -->
    <?php
    $meta_rows = array();

    if (!empty($data['email'])) {
        $meta_rows['Email'] = htmlspecialchars($data['email'], ENT_QUOTES, 'UTF-8');
    }
    if (!empty($data['full_name_first']) || !empty($data['full_name_last'])) {
        $name = trim(($data['full_name_first'] ?? '') . ' ' . ($data['full_name_last'] ?? ''));
        $meta_rows['Name'] = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    }
    if (!empty($data['phone'])) {
        $meta_rows['Phone'] = htmlspecialchars($data['phone'], ENT_QUOTES, 'UTF-8');
    }
    if (!empty($data['dob'])) {
        $meta_rows['Date of Birth'] = htmlspecialchars($data['dob'], ENT_QUOTES, 'UTF-8');
    }
    if (!empty($data['user_price'])) {
        $meta_rows['Donation Amount'] = $currency_symbol . htmlspecialchars($data['user_price'], ENT_QUOTES, 'UTF-8');
    }
    if (!empty($data['address']) && is_object($data['address'])) {
        $meta_rows['Address'] = htmlspecialchars($data['address']->get_address_string(', '), ENT_QUOTES, 'UTF-8');
    }
    // Question answers (stored as arrays with 'question' and 'answer' keys)
    foreach ($data as $field_key => $field_val) {
        if (strpos($field_key, 'question_') === 0 && is_array($field_val)) {
            $q_label = isset($field_val['question']) ? $field_val['question'] : $field_key;
            $q_answer = isset($field_val['answer']) ? $field_val['answer'] : '';
            if ($q_answer !== '') {
                $meta_rows[htmlspecialchars($q_label, ENT_QUOTES, 'UTF-8')] = htmlspecialchars($q_answer, ENT_QUOTES, 'UTF-8');
            }
        }
    }
    ?>

    <?php if (!empty($meta_rows)): ?>
    <dl class="jy-cart-meta">
        <?php foreach ($meta_rows as $label => $value): ?>
        <dt class="jy-cart-meta-dt"><?php echo $label; ?></dt>
        <dd class="jy-tight"><?php echo $value; ?></dd>
        <?php endforeach; ?>
    </dl>
    <?php endif; ?>

    <!-- Item actions -->
    <div class="jy-cart-item-actions">
        <a href="<?php echo $product->get_url(); ?>?edit_item=<?php echo $key; ?>"
           class="jy-cart-action-edit">Edit</a>
        <a href="/cart?r=<?php echo $key; ?>"
           class="jy-cart-action-remove">Remove</a>
    </div>

</div>
<?php endforeach; ?>
</div>

<!-- Coupon section -->
<?php if ($settings->get_setting('coupons_active')): ?>
<div class="jy-cart-coupon">
    <h4 class="jy-cart-coupon-title">Coupon Code</h4>

    <?php if (!empty($cart->coupon_codes)): ?>
    <div class="jy-cart-coupon-tags">
        <?php foreach ($cart->coupon_codes as $coupon_code): ?>
        <span class="jy-cart-coupon-tag">
            <?php echo htmlspecialchars($coupon_code, ENT_QUOTES, 'UTF-8'); ?>
            <a href="/cart?rc=<?php echo urlencode($coupon_code); ?>"
               class="jy-cart-coupon-remove"
               aria-label="Remove coupon <?php echo htmlspecialchars($coupon_code, ENT_QUOTES, 'UTF-8'); ?>">&times;</a>
        </span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($page_vars['coupon_error'])): ?>
    <div class="jy-cart-coupon-error">
        <?php echo htmlspecialchars($page_vars['coupon_error'], ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php endif; ?>

    <?php if (StripeHelper::isTestMode() && !empty($page_vars['all_coupons'])): ?>
    <div class="jy-cart-test-coupons">
        Test coupons:
        <?php foreach ($page_vars['all_coupons'] as $coupon): ?>
        <a href="/cart?coupon_code=<?php echo urlencode($coupon->get('ccd_code')); ?>"
           class="jy-cart-test-coupon-link">
            <?php echo htmlspecialchars($coupon->get('ccd_code'), ENT_QUOTES, 'UTF-8'); ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="GET" action="/cart" class="jy-cart-coupon-form">
        <input type="text" name="coupon_code" placeholder="Enter coupon code"
               class="jy-cart-coupon-input">
        <button type="submit" class="btn btn-outline jy-nowrap">Apply</button>
    </form>
</div>
<?php endif; ?>

<!-- Order total + proceed button -->
<div class="jy-cart-total">
    <dl class="jy-cart-totals">
        <?php if ($total_discount > 0): ?>
        <dt>Subtotal</dt>
        <dd class="jy-cart-amt"><?php echo $currency_symbol . number_format($cart->get_total() + $total_discount, 2, '.', ','); ?></dd>
        <dt class="jy-cart-discount-label">Discount</dt>
        <dd class="jy-cart-discount-amt">&minus;<?php echo $currency_symbol . number_format($total_discount, 2, '.', ','); ?></dd>
        <?php endif; ?>
        <dt class="jy-cart-total-label<?php echo $total_discount > 0 ? ' is-bordered' : ''; ?>">Total</dt>
        <dd class="jy-cart-total-amt<?php echo $total_discount > 0 ? ' is-bordered' : ''; ?>">
            <?php echo $currency_symbol . number_format($cart->get_total(), 2, '.', ','); ?>
        </dd>
    </dl>

    <a href="/checkout" class="btn btn-primary jy-cart-checkout-btn">
        Proceed to Checkout &rarr;
    </a>

    <div class="jy-cart-addmore">
        <a href="/products" class="jy-cart-addmore-link">+ Add another item</a>
    </div>
</div>

<?php endif; ?>

</div>
</section>
</div>
<?php
    $page->public_footer(['track' => true]);
