<?php
	// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	$page = new PublicPage();
	$is_valid_page = false; // This is a 404 page, so page is not valid
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Page not found', 
		'is_404' => 1,
		'header_only' => true,
	);
	$page->public_header($hoptions);
?>

<!-- Canvas 404 Content -->
<section id="content">
	<div class="content-wrap">
		<div class="container">
			<div class="row align-items-center justify-content-center min-vh-75">
				
				<!-- 404 Error Visual -->
				<div class="col-lg-5 col-md-6 text-center mb-5 mb-lg-0">
					<div class="error404-visual">
						<div class="error404-number">404</div>
						<div class="error404-icon mt-4">
							<i class="bi-exclamation-triangle-fill text-warning" style="font-size: 4rem;"></i>
						</div>
					</div>
				</div>

				<!-- 404 Content -->
				<div class="col-lg-6 col-md-8">
					<div class="error404-content text-center text-lg-start">
						
						<!-- Site Logo/Brand -->
						<div class="d-flex justify-content-center justify-content-lg-start align-items-center mb-4">
							<?php if($settings->get_setting('logo_link')): ?>
								<img src="<?php echo $settings->get_setting('logo_link'); ?>" alt="Logo" height="40" class="me-3">
							<?php endif; ?>
							<span class="h4 text-primary fw-bold mb-0"><?php echo htmlspecialchars($settings->get_setting('site_name')); ?></span>
						</div>

						<!-- Error Message -->
						<div class="heading-block border-0 mb-4">
							<h1 class="h3 mb-3">Oops! Page Not Found</h1>
							<p class="lead text-muted mb-4">
								The page you're looking for couldn't be found. It might have been moved, deleted, or the URL might be incorrect.
							</p>
						</div>

						<!-- Search Form -->
						<div class="error404-search mb-5">
							<form action="/search" method="get" class="mb-4">
								<div class="input-group input-group-lg">
									<input type="text" name="q" class="form-control" placeholder="Search our site..." aria-label="Search">
									<button class="btn btn-primary" type="submit">
										<i class="bi-search me-1"></i>Search
									</button>
								</div>
							</form>
						</div>

						<!-- Action Buttons -->
						<div class="error404-actions d-flex flex-column flex-sm-row gap-3 justify-content-center justify-content-lg-start">
							<a href="/" class="btn btn-primary btn-lg">
								<i class="bi-house-fill me-2"></i>Go Home
							</a>
							<a href="/contact" class="btn btn-outline-primary btn-lg">
								<i class="bi-envelope me-2"></i>Contact Support
							</a>
						</div>

						<!-- Helpful Links -->
						<div class="error404-links mt-5">
							<h5 class="mb-3">You might be looking for:</h5>
							<div class="row g-3">
								<div class="col-sm-6">
									<ul class="list-unstyled">
										<li class="mb-2"><a href="/blog" class="text-decoration-none"><i class="bi-arrow-right me-2 text-primary"></i>Blog</a></li>
										<li class="mb-2"><a href="/products" class="text-decoration-none"><i class="bi-arrow-right me-2 text-primary"></i>Products</a></li>
										<li class="mb-2"><a href="/pricing" class="text-decoration-none"><i class="bi-arrow-right me-2 text-primary"></i>Pricing</a></li>
									</ul>
								</div>
								<div class="col-sm-6">
									<ul class="list-unstyled">
										<li class="mb-2"><a href="/contact" class="text-decoration-none"><i class="bi-arrow-right me-2 text-primary"></i>Contact</a></li>
										<li class="mb-2"><a href="/login" class="text-decoration-none"><i class="bi-arrow-right me-2 text-primary"></i>Login</a></li>
										<li class="mb-2"><a href="/register" class="text-decoration-none"><i class="bi-arrow-right me-2 text-primary"></i>Register</a></li>
									</ul>
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
.error404-number {
	font-size: 8rem;
	font-weight: 900;
	color: var(--bs-primary);
	opacity: 0.1;
	line-height: 1;
	text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
}

.error404-visual {
	position: relative;
}

.error404-icon {
	position: relative;
	z-index: 2;
}

.min-vh-75 {
	min-height: 75vh;
}

.error404-content .heading-block h1 {
	color: var(--bs-dark);
}

.error404-links ul li a:hover {
	color: var(--bs-primary) !important;
	transform: translateX(5px);
	transition: all 0.3s ease;
}

.error404-actions .btn {
	min-width: 150px;
}

.input-group-lg .form-control {
	border-radius: 0.5rem 0 0 0.5rem;
}

.input-group-lg .btn {
	border-radius: 0 0.5rem 0.5rem 0;
}

@media (max-width: 768px) {
	.error404-number {
		font-size: 6rem;
	}
	
	.error404-content {
		text-align: center !important;
	}
	
	.error404-actions {
		justify-content: center !important;
	}
}
</style>

<?php
$page->public_footer(array('track'=>TRUE, 'header_only' => true, 'is_404'=> 1));
?>