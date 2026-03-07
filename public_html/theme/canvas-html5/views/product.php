<?php
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
    require_once(PathHelper::getThemeFilePath('product_logic.php', 'logic'));

    $page_vars = product_logic($_GET, $_POST, $product);
    if ($page_vars->redirect) {
        LibraryFunctions::redirect($page_vars->redirect);
        exit();
    }
    $page_vars = $page_vars->data;
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

    echo PublicPage::BeginPage('Product Details');

    if (!$page_vars['display_empty_form']) {
        ?>
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="card shadow-sm rounded-4">
                        <div class="card-header bg-primary text-white">
                            <h4 class="mb-0">Confirm Your Order</h4>
                        </div>
                        <div class="card-body p-4">
                            <p class="mb-4">Is everything correct?</p>

                            <?php
                            $formwriter = $page->getFormWriter('product_form', ['action' => '/product']);
                            $formwriter->begin_form();
                            echo $formwriter->hiddeninput('product_id', $product_id);
                            echo $formwriter->hiddeninput('product_key', $form_key);

                            foreach ($page_vars['display_data'] as $key => $value) {
                                echo '<div class="mb-3"><strong>' . htmlspecialchars($key) . ':</strong> ' . htmlspecialchars($value) . '</div>';
                            }
                            ?>
                            <div class="d-grid">
                                <?php echo $formwriter->submitbutton('submit', 'Confirm Order', ['class' => 'btn btn-primary']); ?>
                            </div>
                            <?php echo $formwriter->end_form(); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        echo PublicPage::EndPage();
        $page->public_footer(['track' => true]);
        exit;
    }
?>

<div class="container" style="padding: 2rem 1rem;">
    <div class="row gx-5">

        <div class="col-lg-6">
            <div class="mb-4 text-center">
                <img src="https://via.placeholder.com/500x500/f8f9fa/6c757d?text=Product+Image"
                     style="max-height: 400px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);"
                     class="mw-100"
                     alt="<?php echo htmlspecialchars($product->get('pro_name')); ?>">
                <?php if ($product->get('pro_on_sale')): ?>
                    <div class="mt-2"><span class="badge bg-danger">Sale!</span></div>
                <?php endif; ?>
            </div>

            <?php if ($product->get('pro_description')): ?>
            <div class="mb-4">
                <h5>Description</h5>
                <div class="text-muted"><?php echo $product->get('pro_description'); ?></div>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-6">
            <h1 class="mb-3"><?php echo htmlspecialchars($product->get('pro_name')); ?></h1>

            <?php if ($product->is_sold_out()): ?>
                <div class="alert alert-warning mb-3"><strong>Sold Out</strong></div>
            <?php elseif ($product->get_readable_price()): ?>
                <div class="mb-3" style="font-size: 1.75rem; font-weight: 700; color: var(--color-primary);">
                    <?php echo $product->get_readable_price(); ?>
                </div>
            <?php endif; ?>

            <div class="card border-0 bg-light rounded-4 p-4 mb-4">
                <?php
                if (!$product_version): ?>
                    <div class="alert alert-danger mb-0">This product is not available for purchase. No product version found.</div>
                <?php elseif (!$product->is_sold_out() && $cart->can_add_to_cart($product_version)):
                    $formwriter = $page->getFormWriter('product_form');
                    echo $formwriter->begin_form('product-quantity', 'POST', $product->get_url(), true);
                    echo $formwriter->hiddeninput('product_id', $product_id);
                    if ($product->output_product_form($formwriter, $page_vars['user'], null, $product_version->key)) {
                        echo '<div class="d-grid mt-3">';
                        echo $formwriter->submitbutton('submit', 'Add to Cart', ['class' => 'btn btn-primary']);
                        echo '</div>';
                    }
                    echo $formwriter->end_form(true);
                    $product->output_javascript($formwriter, []);
                elseif ($product_version && !$cart->can_add_to_cart($product_version)): ?>
                    <div class="alert alert-warning mb-0">
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

            <div class="mt-4 pt-4 border-top">
                <a href="/products" class="btn btn-outline">&larr; Back to Products</a>
            </div>
        </div>

    </div>
</div>

<?php
    echo PublicPage::EndPage();
    $page->public_footer(['track' => true]);
?>
