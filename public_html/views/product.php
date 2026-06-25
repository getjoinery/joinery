<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('product_logic.php', 'logic'));

    $page_vars = process_logic(product_logic(array_merge($_GET, $_POST, $params ?? [])));
    $product         = $page_vars['product'];
    $product_version = $page_vars['product_version'];
    $cart            = $page_vars['cart'];
    $settings        = Globalvars::get_instance();

    $page = new PublicPage();
    $product_header_options = [
        'is_valid_page'    => $is_valid_page,
        'title'            => $product->get('pro_name'),
        'og_type'          => 'product',
        'entity_type'      => 'product',
        'entity_body_html' => $product->get('pro_description'),
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
        <div class="jy-product-layout">

            <!-- Left: Product Info -->
            <div class="jy-product-left">
                <!-- Image -->
                <div class="jy-product-imgwrap">
                    <div class="jy-product-img">
                        &#128722;
                    </div>
                    <?php if ($product->get('pro_on_sale')): ?>
                    <div class="jy-product-sale-wrap">
                        <span class="jy-product-sale">SALE</span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Price -->
                <?php if ($product->is_sold_out()): ?>
                    <div class="alert alert-warning jy-product-block"><strong>Sold Out</strong></div>
                <?php elseif ($product->get_readable_price()): ?>
                    <div class="jy-product-price">
                        <?php echo $product->get_readable_price(); ?>
                    </div>
                <?php endif; ?>

                <!-- Description -->
                <?php if ($product->get('pro_description')): ?>
                <div class="jy-product-block">
                    <div class="jy-product-desc"><?php echo $product->get('pro_description'); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right: Purchase Form -->
            <div class="jy-product-right">
                <?php if ($edit_item_index !== null): ?>
                <div class="alert alert-info jy-product-alert">
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
                        echo '<div class="jy-product-submit">';
                        echo $formwriter->submitbutton('btn_submit', $submit_label, ['class' => 'btn btn-primary jy-product-addbtn']);
                        echo '</div>';
                    }
                    echo $formwriter->end_form();
                    $product->output_javascript($formwriter, []);
                endif; ?>

                <div class="jy-product-backwrap">
                    <?php $products_list_on = $settings->get_setting('products_list_items_active') || $settings->get_setting('products_list_events_active'); ?>
                    <a href="<?= $products_list_on ? '/products' : '/' ?>" class="jy-product-back">&#8592; <?= $products_list_on ? 'Back to Products' : 'Back to Home' ?></a>
                </div>
            </div>

        </div>
    </div>
</section>

</div>
<?php
    $page->public_footer(['track' => true]);
?>
