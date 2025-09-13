<?php
	// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	require_once(PathHelper::getThemeFilePath('booking_logic.php', 'logic'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	
	$page_vars = booking_logic($_GET, $_POST);
	$booking_type = $page_vars['booking_type'];
	$client_user = $page_vars['client_user'];

	$page = new PublicPage();
	$hoptions = array(
		'title' => 'Book an appointment',
		'description' => 'Book an appointment',
		'banner' => 'Book',
		'submenu' => 'Book',
	);
	$page->public_header($hoptions);

	echo PublicPage::BeginPage('Book an appointment');
?>

<!-- Canvas Booking Section -->
<section id="content">
	<div class="content-wrap">
		<div class="container">
			<div class="row justify-content-center">
				<div class="col-lg-8 col-xl-7">
					
					<!-- Page Header -->
					<div class="text-center mb-5">
						<h1 class="h2 mb-2">Book an Appointment</h1>
						<p class="text-muted">Schedule your appointment with our convenient booking system</p>
					</div>

					<!-- Booking Status -->
					<div class="card shadow-sm rounded-4 border-0">
						<div class="card-body p-4 p-lg-5 text-center">
							<div class="mb-4">
								<div class="text-warning mb-3">
									<i class="icon-calendar display-4"></i>
								</div>
								<h4 class="mb-3">Booking Temporarily Unavailable</h4>
								<div class="alert alert-info rounded-4 mb-4" role="alert">
									<i class="icon-info-circle me-2"></i>
									Booking functionality is temporarily disabled while we review our calendar integration.
								</div>
								<p class="text-muted">We apologize for any inconvenience. Please check back soon or contact us directly for scheduling assistance.</p>
							</div>
							
							<div class="row g-3 justify-content-center">
								<div class="col-auto">
									<a href="/contact" class="btn btn-primary rounded-pill">
										<i class="icon-envelope me-2"></i>Contact Us
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

					<?php 
					/*
					// Future implementation when booking is re-enabled
					echo '<!-- Calendly inline widget begin -->
					<div class="calendly-inline-widget" data-url="'.$booking_type->get('bkt_schedule_link').'?primary_color=69be00&name='.str_replace(' ', '%20', $client_user->display_name()).'&email='.$client_user->get('usr_email').'&salesforce_uuid='.$booking_type->key.'" style="min-width:320px;height:630px;"></div>
					<script type="text/javascript" src="https://assets.calendly.com/assets/external/widget.js" async></script>
					<!-- Calendly inline widget end -->';
					*/
					?>

				</div>
			</div>
		</div>
	</div>
</section>

<?php
	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE));
?>