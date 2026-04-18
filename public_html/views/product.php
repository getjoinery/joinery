<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('product_logic.php', 'logic'));

    $page_vars = process_logic(product_logic($_GET, $_POST, $product));
    $product         = $page_vars['product'];
    $product_version = $page_vars['product_version'];
    $cart            = $page_vars['cart'];
    $settings        = Globalvars::get_instance();

    $page = new PublicPage();
    $product_header_options = [
        'is_valid_page' => $is_valid_page,
        'title'         => $product->get('pro_name'),
        'og_type'       => 'product',
    ];
    if ($product->get('pro_short_description')) {
        $product_header_options['meta_description'] = $product->get('pro_short_description');
    }
    if (method_exists($product, 'get_picture_link') && $product->get_picture_link('og_image')) {
        $product_header_options['preview_image_url'] = $product->get_picture_link('og_image');
    }
    $page->public_header($product_header_options);

    if (!$product->get('pro_is_active')) {
        PublicPage::OutputGenericPublicPage('Product not available', 'Product not available', 'Sorry, this item is currently not available for purchase/registration.');
    }

    $edit_item_index = isset($page_vars['edit_item_index']) ? $page_vars['edit_item_index'] : null;
    $prefill_data = isset($page_vars['prefill_data']) ? $page_vars['prefill_data'] : null;
?>
<div class="jy-ui">

<!-- Breadcrumb -->
<section class="page-title bg-transparent">
    <div class="jy-container">
        <div class="page-title-row">
            <div class="page-title-content">
                <h1><?php echo htmlspecialchars($product->get('pro_name'), ENT_QUOTES, 'UTF-8'); ?></h1>
            </div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item"><a href="/products">Products</a></li>
                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($product->get('pro_name'), ENT_QUOTES, 'UTF-8'); ?></li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<section class="jy-content-section">
    <div class="jy-container">
        <div style="display: flex; gap: 3rem; align-items: flex-start; flex-wrap: wrap;">

            <!-- Left: Product Info -->
            <div style="flex: 1; min-width: 280px;">
                <!-- Image -->
                <div style="margin-bottom: 1.5rem; text-align: center;">
                    <div style="width: 100%; max-width: 400px; height: 300px; background: linear-gradient(135deg, #f0f4f8 0%, #dde3ea 100%); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 5rem; color: #b0bac4; margin: 0 auto; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                        &#128722;
                    </div>
                    <?php if ($product->get('pro_on_sale')): ?>
                    <div style="margin-top: 0.75rem;">
                        <span style="display: inline-block; background: #dc3545; color: #fff; font-size: 0.8125rem; font-weight: 700; padding: 0.25rem 0.75rem; border-radius: 4px; letter-spacing: 0.05em;">SALE</span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Price -->
                <?php if ($product->is_sold_out()): ?>
                    <div class="alert alert-warning" style="margin-bottom: 1.5rem;"><strong>Sold Out</strong></div>
                <?php elseif ($product->get_readable_price()): ?>
                    <div style="font-size: 1.75rem; font-weight: 700; color: var(--jy-color-primary); margin-bottom: 1.25rem;">
                        <?php echo $product->get_readable_price(); ?>
                    </div>
                <?php endif; ?>

                <!-- Description -->
                <?php if ($product->get('pro_description')): ?>
                <div style="margin-bottom: 1.5rem;">
                    <div style="color: var(--jy-color-text-muted); line-height: 1.6;"><?php echo $product->get('pro_description'); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right: Purchase Form -->
            <div style="flex: 1; min-width: 320px;">
                <?php if ($edit_item_index !== null): ?>
                <div class="alert alert-info" style="margin-bottom: 1rem;">
                    Editing item in your cart. <a href="/cart">Cancel and return to checkout</a>
                </div>
                <?php endif; ?>

                <?php
                if (!$product_version): ?>
                    <div class="alert alert-error">This product is not available for purchase. No product version found.</div>
                <?php elseif (!$product->is_sold_out() && ($edit_item_index !== null || $cart->can_add_to_cart($product_version))):
                    $formwriter = $page->getFormWriter('product_form', ['action' => $product->get_url(), 'method' => 'POST']);
                    echo $formwriter->begin_form();
                    echo $formwriter->hiddeninput('product_id', $product->key);
                    if ($edit_item_index !== null) {
                        echo $formwriter->hiddeninput('edit_item_index', $edit_item_index);
                    }
                    if ($product->output_product_form($formwriter, $page_vars['user'], null, $product_version->key, $prefill_data)) {
                        $submit_label = ($edit_item_index !== null) ? 'Update Cart' : 'Add to Cart';
                        echo '<div style="margin-top: 1.5rem;">';
                        echo $formwriter->submitbutton('btn_submit', $submit_label, ['class' => 'btn btn-primary', 'style' => 'width: 100%; padding: 0.75rem; font-size: 1.0625rem;']);
                        echo '</div>';
                    }
                    echo $formwriter->end_form();
                    $product->output_javascript($formwriter, []);
                endif; ?>

                <div style="padding-top: 1rem; margin-top: 1rem; border-top: 1px solid var(--jy-color-border);">
                    <a href="/products" style="color: var(--jy-color-text-muted); text-decoration: none; font-size: 0.9375rem;">&#8592; Back to Products</a>
                </div>
            </div>

        </div>
    </div>
</section>

</div>
<?php
    $page->public_footer(['track' => true]);
?>
