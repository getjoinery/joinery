<?php
	// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	ThemeHelper::includeThemeFile('logic/products_logic.php');
	ThemeHelper::includeThemeFile('includes/PublicPage.php');

	$page_vars = products_logic($_GET, $_POST);
	
	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Products'
	));
	echo PublicPage::BeginPage('Products');
?>

<!-- Canvas Shop Section -->
<section id="content">
	<div class="content-wrap">
		<div class="container">

			<!-- Page Header -->
			<div class="mb-5 text-center">
				<h1 class="h2 mb-3">Our Products</h1>
				<p class="lead text-muted">Discover our amazing collection of products</p>
			</div>

			<!-- Products Grid -->
			<div id="shop" class="shop row gutter-30">
				
				<?php foreach ($page_vars['products'] as $product): ?>
				<div class="product col-md-6 col-lg-4 col-12 mb-4">
					<div class="grid-inner">
						<div class="card shadow-sm rounded-4 h-100">
							
							<!-- Product Image -->
							<div class="product-image position-relative">
								<a href="<?php echo $product->get_url(); ?>" class="d-block">
									<!-- Product image placeholder -->
									<img src="https://via.placeholder.com/400x250/f8f9fa/6c757d?text=Product" class="card-img-top rounded-top-4" alt="<?php echo htmlspecialchars($product->get('pro_name')); ?>" style="height: 250px; object-fit: cover;">
								</a>
								
								<!-- Product Actions Overlay -->
								<div class="product-overlay">
									<div class="position-absolute top-0 end-0 p-3">
										<a href="<?php echo $product->get_url(); ?>" class="btn btn-primary btn-sm rounded-circle" data-bs-toggle="tooltip" title="View Details">
											<i class="bi-eye"></i>
										</a>
									</div>
								</div>
							</div>

							<!-- Product Info -->
							<div class="card-body p-4 d-flex flex-column">
								<div class="product-desc flex-grow-1">
									<div class="product-title mb-2">
										<h3 class="h5 mb-1">
											<a href="<?php echo $product->get_url(); ?>" class="text-decoration-none text-dark">
												<?php echo htmlspecialchars($product->get('pro_name')); ?>
											</a>
										</h3>
									</div>
									
									<?php if($product->get('pro_description')): ?>
									<div class="product-desc mb-3">
										<p class="text-muted small mb-0">
											<?php 
											$description = strip_tags($product->get('pro_description'));
											echo strlen($description) > 120 ? substr($description, 0, 120) . '...' : $description;
											?>
										</p>
									</div>
									<?php endif; ?>
								</div>

								<!-- Product Actions -->
								<div class="product-actions mt-auto">
									<div class="d-flex justify-content-end">
										<a href="<?php echo $product->get_url(); ?>" class="btn btn-primary btn-sm">
											View Details
										</a>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
				<?php endforeach; ?>

			</div><!-- Products Grid End -->

			<!-- Pagination -->
			<?php if($page_vars['pager']->is_valid_page('-1') || $page_vars['pager']->is_valid_page('+1')): ?>
			<nav aria-label="Products Pagination" class="mt-5">
				<div class="d-flex justify-content-between align-items-center bg-light p-4 rounded-4">
					<div class="d-none d-sm-block">
						<p class="mb-0 text-muted">
							Showing
							<span class="fw-semibold text-dark"><?php echo $page_vars['offsetdisp']; ?></span>
							to
							<span class="fw-semibold text-dark"><?php echo $page_vars['numperpage'] + $page_vars['offset']; ?></span>
							of
							<span class="fw-semibold text-dark"><?php echo $page_vars['numrecords']; ?></span>
							results
						</p>
					</div>
					<div class="d-flex gap-2">
						<?php if($page_vars['pager']->is_valid_page('-1')): ?>
							<a class="btn btn-outline-primary" href="<?php echo $page_vars['pager']->get_url('-1', ''); ?>">
								<i class="bi-arrow-left me-1"></i>Previous
							</a>
						<?php endif; ?>
						<?php if($page_vars['pager']->is_valid_page('+1')): ?>
							<a class="btn btn-outline-primary" href="<?php echo $page_vars['pager']->get_url('+1', ''); ?>">
								Next<i class="bi-arrow-right ms-1"></i>
							</a>
						<?php endif; ?>
					</div>
				</div>
			</nav>
			<?php endif; ?>

		</div>
	</div>
</section>

<style>
.product:hover .card {
	transform: translateY(-5px);
	transition: transform 0.3s ease;
}

.product-overlay {
	opacity: 0;
	transition: opacity 0.3s ease;
}

.product:hover .product-overlay {
	opacity: 1;
}

.product-image img {
	transition: transform 0.3s ease;
}

.product:hover .product-image img {
	transform: scale(1.05);
}
</style>

<?php
echo PublicPage::EndPage();
$page->public_footer($foptions=array('track'=>TRUE));
?>