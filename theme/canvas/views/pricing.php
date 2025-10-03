<?php
	// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('pricing_logic.php', 'logic'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	$page_vars = process_logic(pricing_logic($_GET, $_POST));
	
	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Pricing'
	));
	echo PublicPage::BeginPage('Pricing');
?>

<!-- Canvas Pricing Section -->
<section id="content">
	<div class="content-wrap">
		<div class="container">

			<!-- Page Header -->
			<div class="mb-5 text-center">
				<h1 class="h2 mb-3">Our Pricing Plans</h1>
				<p class="lead text-muted">Choose the perfect plan for your needs</p>
			</div>

			<!-- Pricing Cards -->
			<div class="row justify-content-center gx-4 gy-4">
				<?php
				$cardIndex = 0;
				foreach ($page_vars['tier_display_data'] as $item):
					$tier = $item['tier'];
					$product = $item['product'];
					$version = $item['version'];
					$cardIndex++;
					$isPopular = ($cardIndex == 2); // Make middle plan popular
				?>
				<div class="col-lg-4 col-md-6">
					<div class="card pricing-card shadow-sm rounded-4 h-100 <?php echo $isPopular ? 'border-primary' : ''; ?> position-relative">
						
						<?php if($isPopular): ?>
						<!-- Popular Badge -->
						<div class="position-absolute top-0 start-50 translate-middle">
							<span class="badge bg-primary px-3 py-2 rounded-pill">Most Popular</span>
						</div>
						<?php endif; ?>

						<div class="card-header text-center bg-transparent border-0 pt-5 <?php echo $isPopular ? 'pt-4' : ''; ?>">

							<!-- Icon -->
							<div class="mb-4">
								<div class="bg-primary bg-opacity-10 rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
									<i class="bi-star-fill text-primary fs-3"></i>
								</div>
							</div>

							<!-- Plan Name -->
							<h3 class="pricing-title h4 mb-3"><?php echo htmlspecialchars($product->get('pro_name')); ?></h3>
							
							<!-- Price -->
							<div class="pricing-price mb-3">
								<div class="h2 text-primary mb-0">
									<?php echo $product->get_readable_price($version->key); ?>
								</div>
								<p class="text-muted small"><?php echo htmlspecialchars($tier->get('sbt_display_name')); ?></p>
							</div>
						</div>

						<div class="card-body d-flex flex-column">
							
							<!-- Description -->
							<?php if($product->get('pro_description')): ?>
							<div class="pricing-description text-center mb-4">
								<p class="text-muted small">
									<?php echo $product->get('pro_description'); ?>
								</p>
							</div>
							<?php endif; ?>

							<!-- Features List -->
							<div class="pricing-features flex-grow-1">
								<ul class="list-unstyled">
									<?php
									// Sample features - you may want to add custom fields or logic
									$features = [
										'Full access to platform',
										'Priority customer support',
										'Advanced analytics',
										'Custom integrations',
										'Monthly updates'
									];
									
									// Limit features based on plan level
									$featureCount = ($cardIndex == 1) ? 3 : (($cardIndex == 2) ? 5 : 4);
									for($i = 0; $i < $featureCount; $i++):
										if(isset($features[$i])):
									?>
									<li class="mb-2 d-flex align-items-center">
										<i class="bi-check-circle-fill text-success me-2"></i>
										<span><?php echo $features[$i]; ?></span>
									</li>
									<?php 
										endif;
									endfor; 
									?>
								</ul>
							</div>

							<!-- Action Button -->
							<div class="pricing-action mt-4">
								<a href="<?php echo $product->get_url() . '?product_version_id=' . $version->key; ?>"
								   class="btn <?php echo $isPopular ? 'btn-primary' : 'btn-outline-primary'; ?> btn-lg w-100 rounded-pill">
									Choose This Plan
									<i class="bi-arrow-right ms-1"></i>
								</a>
							</div>
						</div>
					</div>
				</div>
				<?php endforeach; ?>
			</div>

			<!-- FAQ or Additional Information -->
			<div class="row mt-5">
				<div class="col-lg-8 mx-auto text-center">
					<div class="card border-0 bg-light rounded-4">
						<div class="card-body p-5">
							<h4 class="mb-3">Need Help Choosing?</h4>
							<p class="text-muted mb-4">Not sure which plan is right for you? Our team is here to help you find the perfect solution for your needs.</p>
							<div class="d-flex flex-wrap justify-content-center gap-3">
								<a href="/contact" class="btn btn-primary rounded-pill">
									<i class="bi-chat-dots me-2"></i>Contact Us
								</a>
								<a href="/products" class="btn btn-outline-secondary rounded-pill">
									<i class="bi-grid-3x3-gap me-2"></i>View All Products
								</a>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Features Comparison -->
			<div class="row mt-5">
				<div class="col-12">
					<div class="text-center mb-4">
						<h3>Compare Plans</h3>
						<p class="text-muted">See what's included in each plan</p>
					</div>
					
					<div class="table-responsive">
						<table class="table table-hover align-middle">
							<thead class="table-light">
								<tr>
									<th scope="col" class="border-0 py-3">Features</th>
									<?php foreach ($page_vars['tier_display_data'] as $item): ?>
									<th scope="col" class="border-0 py-3 text-center"><?php echo htmlspecialchars($item['product']->get('pro_name')); ?></th>
									<?php endforeach; ?>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td class="fw-semibold">Basic Access</td>
									<?php foreach ($page_vars['tier_display_data'] as $item): ?>
									<td class="text-center"><i class="bi-check-lg text-success fs-5"></i></td>
									<?php endforeach; ?>
								</tr>
								<tr>
									<td class="fw-semibold">Premium Support</td>
									<?php 
									$supportIndex = 0;
									foreach ($page_vars['tier_display_data'] as $item): 
										$supportIndex++;
									?>
									<td class="text-center">
										<?php if($supportIndex >= 2): ?>
											<i class="bi-check-lg text-success fs-5"></i>
										<?php else: ?>
											<i class="bi-dash text-muted fs-5"></i>
										<?php endif; ?>
									</td>
									<?php endforeach; ?>
								</tr>
								<tr>
									<td class="fw-semibold">Advanced Analytics</td>
									<?php 
									$analyticsIndex = 0;
									foreach ($page_vars['tier_display_data'] as $item): 
										$analyticsIndex++;
									?>
									<td class="text-center">
										<?php if($analyticsIndex >= 3): ?>
											<i class="bi-check-lg text-success fs-5"></i>
										<?php else: ?>
											<i class="bi-dash text-muted fs-5"></i>
										<?php endif; ?>
									</td>
									<?php endforeach; ?>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>

		</div>
	</div>
</section>

<style>
.pricing-card {
	transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.pricing-card:hover {
	transform: translateY(-5px);
	box-shadow: 0 20px 40px rgba(0,0,0,0.1) !important;
}

.pricing-card.border-primary {
	border-width: 2px !important;
}

.pricing-price {
	position: relative;
}

.pricing-features ul li {
	font-size: 0.95rem;
}

.pricing-action .btn {
	font-weight: 600;
	padding: 12px 24px;
}

@media (max-width: 768px) {
	.pricing-card {
		margin-bottom: 2rem;
	}
	
	.table-responsive {
		font-size: 0.9rem;
	}
}
</style>

<?php
echo PublicPage::EndPage();
$page->public_footer($foptions=array('track'=>TRUE));
?>