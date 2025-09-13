<?php
	// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
	PathHelper::requireOnce('includes/ShoppingCart.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	$session = SessionControl::get_instance();
	$session_id = $_GET['session_id']; 

	$settings = Globalvars::get_instance();

	$cart = $session->get_shopping_cart();
	$receipts = $cart->last_receipt;
	
	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => "Checkout confirmation"
	));
	echo PublicPage::BeginPage('Checkout confirmation');
?>

<!-- Canvas Checkout Confirmation Section -->
<section id="content">
	<div class="content-wrap">
		<div class="container">
			<div class="row justify-content-center">
				<div class="col-lg-8 col-xl-7">
					
					<?php if($receipts): ?>
						<!-- Success Confirmation -->
						<div class="text-center mb-5">
							<div class="text-success mb-3">
								<i class="icon-check-circle display-3"></i>
							</div>
							<h1 class="h2 mb-2">Purchase Confirmed!</h1>
							<p class="text-muted">Thank you for your purchase. An email has been sent to the email address of all registrants with your purchase confirmation and a link to provide any further info that we need.</p>
						</div>

						<!-- Order Summary -->
						<div class="card shadow-sm rounded-4 border-0 mb-4">
							<div class="card-header bg-primary text-white rounded-top-4">
								<h5 class="mb-0"><i class="icon-receipt me-2"></i>Order Summary</h5>
							</div>
							<div class="card-body p-0">
								<div class="table-responsive">
									<table class="table table-borderless mb-0">
										<thead class="bg-light">
											<tr>
												<th class="py-3 ps-4">Item</th>
												<th class="py-3 pe-4 text-end">Price</th>
											</tr>
										</thead>
										<tbody>
											<?php 
											$total = 0;
											foreach($receipts as $rkey => $receipt): 
												$total += $receipt['price'];
											?>
											<tr>
												<td class="py-3 ps-4">
													<div>
														<h6 class="mb-0"><?php echo $receipt['pname']; ?></h6>
														<small class="text-muted"><?php echo $receipt['name']; ?></small>
													</div>
												</td>
												<td class="py-3 pe-4 text-end fw-semibold">
													$<?php echo number_format($receipt['price'], 2, '.', ','); ?>
												</td>
											</tr>
											<?php endforeach; ?>
										</tbody>
										<tfoot class="border-top">
											<tr class="bg-light">
												<td class="py-3 ps-4 fw-bold">Total</td>
												<td class="py-3 pe-4 text-end fw-bold h5 mb-0 text-primary">
													$<?php echo number_format($total, 2, '.', ','); ?>
												</td>
											</tr>
										</tfoot>
									</table>
								</div>
							</div>
						</div>

						<!-- Next Steps -->
						<div class="card shadow-sm rounded-4 border-0">
							<div class="card-body p-4 text-center">
								<h5 class="mb-3">What's Next?</h5>
								<p class="text-muted mb-4">All of your purchases can be found in the My Profile section of the website.</p>
								<div class="row g-3 justify-content-center">
									<div class="col-auto">
										<a href="/profile" class="btn btn-primary rounded-pill">
											<i class="icon-user me-2"></i>View All Purchases
										</a>
									</div>
									<div class="col-auto">
										<a href="/" class="btn btn-outline-secondary rounded-pill">
											<i class="icon-home me-2"></i>Back to Home
										</a>
									</div>
								</div>
							</div>
						</div>

					<?php else: ?>
						<!-- Error State -->
						<div class="text-center mb-5">
							<div class="text-warning mb-3">
								<i class="icon-exclamation-triangle display-3"></i>
							</div>
							<h1 class="h2 mb-2">Purchase Not Found</h1>
						</div>

						<div class="card shadow-sm rounded-4 border-0">
							<div class="card-body p-4 p-lg-5 text-center">
								<p class="text-muted mb-4">Your recent purchase is not available. It could be that it didn't go through, or perhaps it's been too much time since it was processed.</p>
								
								<?php 
								$defaultemail = $settings->get_setting('defaultemail');
								if($defaultemail): 
								?>
								<div class="alert alert-info rounded-4 mb-4" role="alert">
									<h6 class="alert-heading mb-2">Need Help?</h6>
									If you think something is wrong, please contact us at 
									<a href="mailto:<?php echo $defaultemail; ?>" class="text-decoration-none fw-semibold">
										<?php echo $defaultemail; ?>
									</a>
								</div>
								<?php endif; ?>

								<div class="row g-3 justify-content-center">
									<div class="col-auto">
										<a href="/cart" class="btn btn-primary rounded-pill">
											<i class="icon-shopping-cart me-2"></i>Return to Cart
										</a>
									</div>
									<div class="col-auto">
										<a href="/" class="btn btn-outline-secondary rounded-pill">
											<i class="icon-home me-2"></i>Back to Home
										</a>
									</div>
								</div>
							</div>
						</div>
					<?php endif; ?>

				</div>
			</div>
		</div>
	</div>
</section>

<?php
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>