<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('cart_logic.php', 'logic'));

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
<section style="padding: 2rem 0;">
<div class="jy-container" style="max-width: 820px;">

<h1 style="font-size: 1.75rem; font-weight: 700; margin-bottom: 1.5rem;">Your Cart</h1>

<?php if (empty($cart->items)): ?>
    <div style="text-align: center; padding: 4rem 2rem;">
        <div style="font-size: 4rem; color: var(--jy-color-text-muted); margin-bottom: 1rem;">&#128722;</div>
        <h2 style="margin-bottom: 0.5rem;">Your cart is empty</h2>
        <p style="color: var(--jy-color-text-muted); margin-bottom: 1.5rem;">Add some items to get started.</p>
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

<div style="display: flex; flex-direction: column; gap: 1rem; margin-bottom: 2rem;">
<?php foreach ($cart->items as $key => $cart_item):
    list($quantity, $product, $data, $price, $discount, $product_version) = $cart_item;
?>
<div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 1.5rem;">

    <!-- Item header: name + price -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1rem; padding-bottom: 0.875rem; border-bottom: 1px solid var(--jy-color-border);">
        <div>
            <h3 style="margin: 0 0 0.125rem; font-size: 1.0625rem; font-weight: 700;">
                <?php echo htmlspecialchars($product->get('pro_name'), ENT_QUOTES, 'UTF-8'); ?>
            </h3>
            <?php if ($product_version->get('prv_version_name')): ?>
            <div style="font-size: 0.875rem; color: var(--jy-color-text-muted);">
                <?php echo htmlspecialchars($product_version->get('prv_version_name'), ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php endif; ?>
        </div>
        <div style="text-align: right; flex-shrink: 0;">
            <div style="font-weight: 700; font-size: 1.0625rem; color: var(--jy-color-primary);">
                <?php echo $currency_symbol . number_format($price, 2, '.', ','); ?>
            </div>
            <?php if ($discount): ?>
            <div style="font-size: 0.8125rem; color: #198754;">
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
    <dl style="margin: 0 0 1rem; display: grid; grid-template-columns: max-content 1fr; gap: 0.375rem 1.25rem; font-size: 0.9375rem;">
        <?php foreach ($meta_rows as $label => $value): ?>
        <dt style="font-weight: 600; color: var(--jy-color-text-muted); white-space: nowrap;"><?php echo $label; ?></dt>
        <dd style="margin: 0;"><?php echo $value; ?></dd>
        <?php endforeach; ?>
    </dl>
    <?php endif; ?>

    <!-- Item actions -->
    <div style="display: flex; gap: 1.25rem; font-size: 0.875rem;">
        <a href="<?php echo $product->get_url(); ?>?edit_item=<?php echo $key; ?>"
           style="color: var(--jy-color-primary); text-decoration: none; font-weight: 600;">Edit</a>
        <a href="/cart?r=<?php echo $key; ?>"
           style="color: var(--jy-color-danger); text-decoration: none; font-weight: 600;">Remove</a>
    </div>

</div>
<?php endforeach; ?>
</div>

<!-- Coupon section -->
<?php if ($settings->get_setting('coupons_active')): ?>
<div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 1.25rem 1.5rem; margin-bottom: 1.5rem;">
    <h4 style="margin: 0 0 0.875rem; font-size: 1rem; font-weight: 600;">Coupon Code</h4>

    <?php if (!empty($cart->coupon_codes)): ?>
    <div style="margin-bottom: 0.75rem; display: flex; flex-wrap: wrap; gap: 0.375rem;">
        <?php foreach ($cart->coupon_codes as $coupon_code): ?>
        <span style="display: inline-flex; align-items: center; gap: 0.375rem; background: #198754; color: #fff; font-size: 0.875rem; font-weight: 600; padding: 0.3125rem 0.75rem; border-radius: 4px;">
            <?php echo htmlspecialchars($coupon_code, ENT_QUOTES, 'UTF-8'); ?>
            <a href="/cart?rc=<?php echo urlencode($coupon_code); ?>"
               style="color: rgba(255,255,255,0.8); text-decoration: none; font-weight: 700; line-height: 1; font-size: 1rem;"
               aria-label="Remove coupon <?php echo htmlspecialchars($coupon_code, ENT_QUOTES, 'UTF-8'); ?>">&times;</a>
        </span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($page_vars['coupon_error'])): ?>
    <div style="color: var(--jy-color-danger); font-size: 0.875rem; margin-bottom: 0.5rem;">
        <?php echo htmlspecialchars($page_vars['coupon_error'], ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php endif; ?>

    <?php if (StripeHelper::isTestMode() && !empty($page_vars['all_coupons'])): ?>
    <div style="font-size: 0.8125rem; color: var(--jy-color-text-muted); margin-bottom: 0.625rem;">
        Test coupons:
        <?php foreach ($page_vars['all_coupons'] as $coupon): ?>
        <a href="/cart?coupon_code=<?php echo urlencode($coupon->get('ccd_code')); ?>"
           style="color: var(--jy-color-primary); margin-left: 0.375rem;">
            <?php echo htmlspecialchars($coupon->get('ccd_code'), ENT_QUOTES, 'UTF-8'); ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="GET" action="/cart" style="display: flex; gap: 0.5rem; align-items: flex-start;">
        <input type="text" name="coupon_code" placeholder="Enter coupon code"
               style="flex: 1; padding: 0.5625rem 0.875rem; border: 1px solid var(--jy-color-border); border-radius: 6px; font-size: 0.9375rem; min-width: 0;">
        <button type="submit" class="btn btn-outline" style="white-space: nowrap;">Apply</button>
    </form>
</div>
<?php endif; ?>

<!-- Order total + proceed button -->
<div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 1.5rem;">
    <dl style="margin: 0 0 1.25rem; display: grid; grid-template-columns: 1fr auto; gap: 0.5rem 1rem; font-size: 1rem;">
        <?php if ($total_discount > 0): ?>
        <dt>Subtotal</dt>
        <dd style="margin: 0; text-align: right;"><?php echo $currency_symbol . number_format($cart->get_total() + $total_discount, 2, '.', ','); ?></dd>
        <dt style="color: #198754;">Discount</dt>
        <dd style="margin: 0; text-align: right; color: #198754;">&minus;<?php echo $currency_symbol . number_format($total_discount, 2, '.', ','); ?></dd>
        <?php endif; ?>
        <dt style="font-weight: 700; font-size: 1.125rem; <?php if ($total_discount > 0): ?>padding-top: 0.75rem; border-top: 2px solid var(--jy-color-border);<?php endif; ?>">Total</dt>
        <dd style="margin: 0; text-align: right; font-weight: 700; font-size: 1.125rem; color: var(--jy-color-primary); <?php if ($total_discount > 0): ?>padding-top: 0.75rem; border-top: 2px solid var(--jy-color-border);<?php endif; ?>">
            <?php echo $currency_symbol . number_format($cart->get_total(), 2, '.', ','); ?>
        </dd>
    </dl>

    <a href="/checkout" class="btn btn-primary" style="display: block; width: 100%; text-align: center; padding: 0.875rem; font-size: 1.0625rem; font-weight: 700;">
        Proceed to Checkout &rarr;
    </a>

    <div style="margin-top: 1rem; text-align: center;">
        <a href="/products" style="font-size: 0.875rem; color: var(--jy-color-text-muted); text-decoration: none;">+ Add another item</a>
    </div>
</div>

<?php endif; ?>

</div>
</section>
</div>
<?php
    $page->public_footer(['track' => true]);
