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
    $page->public_header([
        'is_valid_page' => $is_valid_page,
        'title'         => $product->get('pro_name'),
    ]);

    if (!$product->get('pro_is_active')) {
        PublicPage::OutputGenericPublicPage('Product not available', 'Product not available', 'Sorry, this item is currently not available for purchase/registration.');
    }

    if (!$page_vars['display_empty_form']) {
?>

<!-- Page Title -->
<section class="page-title bg-transparent">
    <div class="container">
        <div class="page-title-row">
            <div class="page-title-content">
                <h1>Confirm Your Order</h1>
            </div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item"><a href="/products">Products</a></li>
                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($product->get('pro_name')); ?></li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div style="max-width: 640px; margin: 0 auto;">
            <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); overflow: hidden;">
                <div style="background: var(--color-primary); color: #fff; padding: 1rem 1.5rem;">
                    <h4 style="margin: 0; color: #fff;">Confirm Your Order</h4>
                </div>
                <div style="padding: 2rem;">
                    <p style="margin-bottom: 1.5rem;">Is everything correct?</p>

                    <?php
                    $formwriter = $page->getFormWriter('product_form', ['action' => '/product']);
                    $formwriter->begin_form();
                    echo $formwriter->hiddeninput('product_id', $product_id);
                    echo $formwriter->hiddeninput('product_key', $form_key);

                    foreach ($page_vars['display_data'] as $key => $value) {
                        echo '<div style="margin-bottom: 0.75rem;"><strong>' . htmlspecialchars($key) . ':</strong> ' . htmlspecialchars($value) . '</div>';
                    }
                    ?>
                    <div style="margin-top: 1.5rem;">
                        <?php echo $formwriter->submitbutton('btn_submit', 'Confirm Order', ['class' => 'btn btn-primary']); ?>
                    </div>
                    <?php echo $formwriter->end_form(); ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php
        $page->public_footer(['track' => true]);
        exit;
    }
?>

<!-- Page Title -->
<section class="page-title bg-transparent">
    <div class="container">
        <div class="page-title-row">
            <div class="page-title-content">
                <h1><?php echo htmlspecialchars($product->get('pro_name')); ?></h1>
            </div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item"><a href="/products">Products</a></li>
                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($product->get('pro_name')); ?></li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div class="grid-2" style="gap: 3rem; align-items: start;">

            <!-- Left: image + description -->
            <div>
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

                <?php if ($product->get('pro_description')): ?>
                <div>
                    <h5 style="margin-bottom: 0.75rem;">Description</h5>
                    <div style="color: var(--color-muted);"><?php echo $product->get('pro_description'); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right: purchase form -->
            <div>
                <?php if ($product->is_sold_out()): ?>
                    <div class="alert alert-warning" style="margin-bottom: 1.5rem;"><strong>Sold Out</strong></div>
                <?php elseif ($product->get_readable_price()): ?>
                    <div style="font-size: 1.75rem; font-weight: 700; color: var(--color-primary); margin-bottom: 1.25rem;">
                        <?php echo $product->get_readable_price(); ?>
                    </div>
                <?php endif; ?>

                <div style="background: var(--color-light, #f8f9fa); border-radius: 8px; padding: 1.75rem; margin-bottom: 1.5rem;">
                    <?php
                    if (!$product_version): ?>
                        <div class="alert alert-error">This product is not available for purchase. No product version found.</div>
                    <?php elseif (!$product->is_sold_out() && $cart->can_add_to_cart($product_version)):
                        $formwriter = $page->getFormWriter('product_form', ['action' => $product->get_url(), 'method' => 'POST']);
                        echo $formwriter->begin_form();
                        echo $formwriter->hiddeninput('product_id', $product_id);
                        if ($product->output_product_form($formwriter, $page_vars['user'], null, $product_version->key)) {
                            echo '<div style="margin-top: 1.25rem;">';
                            echo $formwriter->submitbutton('btn_submit', 'Add to Cart', ['class' => 'btn btn-primary']);
                            echo '</div>';
                        }
                        echo $formwriter->end_form();
                        $product->output_javascript($formwriter, []);
                    elseif ($product_version && !$cart->can_add_to_cart($product_version)): ?>
                        <div class="alert alert-warning">
                            <?php
                            if ($product_version->is_subscription()) {
                                echo $cart->get_num_recurring()
                                    ? 'You cannot add more than one subscription to the cart.'
                                    : 'You cannot add a subscription to a cart that contains other items. Please check out first or clear your cart.';
                            } else {
                                echo 'You cannot add an item to a cart containing a subscription. Please check out first or clear your cart.';
                            }
                            ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="padding-top: 1rem; border-top: 1px solid var(--color-border, #eee);">
                    <a href="/products" class="btn btn-outline">&#8592; Back to Products</a>
                </div>
            </div>

        </div>
    </div>
</section>

<?php
    $page->public_footer(['track' => true]);
?>
