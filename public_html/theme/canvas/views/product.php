<?php
	// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('product_logic.php', 'logic'));

	$page_vars = product_logic($_GET, $_POST, $product);
	// Handle LogicResult return format
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;
	$product = $page_vars['product'];
	$product_version = $page_vars['product_version'];
	$cart = $page_vars['cart'];
	$settings = Globalvars::get_instance();

	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => $product->get('pro_name')
	));

	if(!$product->get('pro_is_active')){
		PublicPage::OutputGenericPublicPage('Product not available', 'Product not available', '<p>Sorry, this item is currently not available for purchase/registration.</p>');	
	}
	
	echo PublicPage::BeginPage('Product Details');
	
	if (!$page_vars['display_empty_form']) {
		echo '<div class="container mt-5">';
		echo '<div class="row justify-content-center">';
		echo '<div class="col-lg-8">';
		echo '<div class="card shadow-sm rounded-4">';
		echo '<div class="card-header bg-primary text-white rounded-top-4">';
		echo '<h4 class="mb-0">Confirm Your Order</h4>';
		echo '</div>';
		echo '<div class="card-body p-4">';
		echo '<p class="mb-4">Is everything correct?</p>';

		$formwriter = $page->getFormWriter('product_form', [
			'action' => '/product'
		]);
		$formwriter->begin_form(); 

		echo $formwriter->hiddeninput('product_id', $product_id);
		echo $formwriter->hiddeninput('product_key', $form_key);

		foreach($page_vars['display_data'] as $key => $value) {
			echo '<div class="mb-3">';
			echo '<strong>' . htmlspecialchars($key) . ':</strong> ' . htmlspecialchars($value);
			echo '</div>';
		}

		echo '<div class="d-grid">';
		echo $formwriter->submitbutton('submit', 'Confirm Order', ['class' => 'btn btn-primary']);
		echo '</div>';
		echo $formwriter->end_form();
		
		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo PublicPage::EndPage();
		$page->public_footer($foptions=array('track'=>TRUE));
		exit;
	} 
?>

<!-- Canvas Single Product Section -->
<section id="content">
	<div class="content-wrap">
		<div class="container">
			
			<!-- Single Product -->
			<div class="single-product">
				<div class="product">
					<div class="row gx-5">
						
						<!-- Left Column: Product Image and Description -->
						<div class="col-lg-6">
							<div class="product-image mb-4">
								<!-- Product image placeholder -->
								<div class="text-center position-relative">
									<img src="https://via.placeholder.com/500x500/f8f9fa/6c757d?text=Product+Image" class="img-fluid rounded-4 shadow-sm" alt="<?php echo htmlspecialchars($product->get('pro_name')); ?>" style="max-height: 500px;">
									
									<!-- Sale Badge -->
									<?php if($product->get('pro_on_sale')): ?>
										<div class="position-absolute top-0 start-0 m-3">
											<span class="badge bg-danger fs-6 px-3 py-2">Sale!</span>
										</div>
									<?php endif; ?>
								</div>
							</div>
							
							<!-- Product Description -->
							<?php if($product->get('pro_description')): ?>
							<div class="product-description">
								<h5 class="mb-3">Description</h5>
								<div class="text-muted">
									<?php echo $product->get('pro_description'); ?>
								</div>
							</div>
							<?php endif; ?>
						</div>

						<!-- Right Column: Product Details and Form -->
						<div class="col-lg-6">
							<div class="product-info">
								
								<!-- Product Title & Price -->
								<div class="mb-4">
									<h1 class="product-title h2 mb-3"><?php echo htmlspecialchars($product->get('pro_name')); ?></h1>
									<?php
									if($product->is_sold_out()):
										?>
										<div class="alert alert-warning mb-3">
											<strong>Sold Out</strong>
										</div>
										<?php
									elseif($product->get_readable_price()): 
										?>
										<div class="product-price h3 text-primary mb-0">
											<?php echo $product->get_readable_price(); ?>
										</div>
										<?php
									endif; 
									?>
								</div>
								
								<!-- Product Form -->
								<div class="product-form mb-4">
									<h5 class="mb-3">Product Details</h5>
									<div class="card border-0 bg-light rounded-4 p-4">
										<?php
										// Check product availability and cart compatibility
										if(!$product_version):
											?>
											<div class="alert alert-danger mb-0">
												<i class="bi-exclamation-triangle me-2"></i>
												This product is not available for purchase. No product version found.
											</div>
											<?php
										elseif(!$product->is_sold_out() && $cart->can_add_to_cart($product_version)):
											// Product can be added to cart
											$formwriter = $page->getFormWriter('product_form');
											echo $formwriter->begin_form("product-quantity", "POST", $product->get_url(), true); 
											echo $formwriter->hiddeninput('product_id', $product_id);
											
											if ($product->output_product_form($formwriter, $page_vars['user'], null, $product_version->key)) {
												echo '<div class="d-grid gap-2 mt-3">';
												echo $formwriter->submitbutton('submit', 'Add to Cart', ['class' => 'btn btn-primary']);
												echo '</div>';
											} else {
												echo '<div class="alert alert-info mb-0">';
												echo '<i class="bi-info-circle me-2"></i>Product configuration options will appear here.';
												echo '</div>';
											}
											
											echo $formwriter->end_form(true);
											$product->output_javascript($formwriter, array());
											
										elseif($product_version && !$cart->can_add_to_cart($product_version)):
											// Product cannot be added due to cart restrictions
											?>
											<div class="alert alert-warning mb-0">
												<i class="bi-exclamation-triangle me-2"></i>
												<?php
												if($product_version->is_subscription()):
													if($cart->get_num_recurring()):
														echo 'You cannot add more than one subscription to the cart. Please check out first or clear your cart.';
													else:
														echo 'You cannot add a subscription to a cart that contains other items. Please check out first or clear your cart.';
													endif;
												else:
													echo 'You cannot add an item to a cart containing a subscription. Please check out first or clear your cart.';
												endif;
												?>
											</div>
											<?php
										endif;
										?>
									</div>
								</div>

								<!-- Back to Products -->
								<div class="mt-4 pt-4 border-top">
									<a href="/products" class="btn btn-outline-secondary">
										<i class="bi-arrow-left me-2"></i>Back to Products
									</a>
								</div>

							</div>
						</div>
					</div>
				</div>
			</div>


		</div>
	</div>
</section>

<style>
.product-image {
	position: relative;
}

.product-image img {
	transition: transform 0.3s ease;
}

.product-image:hover img {
	transform: scale(1.05);
}

.product-price {
	font-weight: 600;
}

.product-form .form-control,
.product-form .form-select {
	border-radius: 0.5rem;
}

.product-form .btn {
	border-radius: 0.5rem;
}
</style>

<?php
echo PublicPage::EndPage();
$page->public_footer($foptions=array('track'=>TRUE));
?>