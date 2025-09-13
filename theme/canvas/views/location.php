<?php
	// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('location_logic.php', 'logic'));

	$page_vars = location_logic($_GET, $_POST, $location, $params);
	$location = $page_vars['location'];

	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => $location->get('pag_title')
	));
	echo PublicPage::BeginPage($location->get('loc_name'));
?>

<!-- Canvas Location Section -->
<section id="content">
	<div class="content-wrap">
		<div class="container">
			<div class="row justify-content-center">
				<div class="col-lg-10 col-xl-9">
					
					<!-- Page Header -->
					<div class="text-center mb-5">
						<h1 class="h2 mb-2"><?php echo $location->get('loc_name'); ?></h1>
					</div>

					<!-- Location Details -->
					<div class="card shadow-sm rounded-4 border-0">
						<div class="card-body p-4 p-lg-5">
							<div class="row align-items-center">
								<div class="col-md-2 text-center text-md-start mb-3 mb-md-0">
									<div class="text-primary">
										<i class="icon-map-marker display-4"></i>
									</div>
								</div>
								<div class="col-md-10">
									<div class="prose-content">
										<?php echo $location->get('loc_description'); ?>
									</div>
								</div>
							</div>
						</div>
					</div>

					<?php if($location->get('loc_address') || $location->get('loc_phone') || $location->get('loc_email')): ?>
					<!-- Contact Information -->
					<div class="card shadow-sm rounded-4 border-0 mt-4">
						<div class="card-header bg-primary text-white rounded-top-4">
							<h5 class="mb-0"><i class="icon-info-circle me-2"></i>Contact Information</h5>
						</div>
						<div class="card-body p-4">
							<div class="row g-4">
								<?php if($location->get('loc_address')): ?>
								<div class="col-md-4">
									<div class="d-flex align-items-start">
										<div class="text-primary me-3 mt-1">
											<i class="icon-location"></i>
										</div>
										<div>
											<h6 class="mb-1">Address</h6>
											<p class="text-muted mb-0"><?php echo nl2br($location->get('loc_address')); ?></p>
										</div>
									</div>
								</div>
								<?php endif; ?>
								
								<?php if($location->get('loc_phone')): ?>
								<div class="col-md-4">
									<div class="d-flex align-items-start">
										<div class="text-primary me-3 mt-1">
											<i class="icon-phone"></i>
										</div>
										<div>
											<h6 class="mb-1">Phone</h6>
											<p class="text-muted mb-0">
												<a href="tel:<?php echo $location->get('loc_phone'); ?>" class="text-decoration-none">
													<?php echo $location->get('loc_phone'); ?>
												</a>
											</p>
										</div>
									</div>
								</div>
								<?php endif; ?>
								
								<?php if($location->get('loc_email')): ?>
								<div class="col-md-4">
									<div class="d-flex align-items-start">
										<div class="text-primary me-3 mt-1">
											<i class="icon-envelope"></i>
										</div>
										<div>
											<h6 class="mb-1">Email</h6>
											<p class="text-muted mb-0">
												<a href="mailto:<?php echo $location->get('loc_email'); ?>" class="text-decoration-none">
													<?php echo $location->get('loc_email'); ?>
												</a>
											</p>
										</div>
									</div>
								</div>
								<?php endif; ?>
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