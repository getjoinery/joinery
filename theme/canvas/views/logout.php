<?php
	// PathHelper is always available - never require it
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	$settings = Globalvars::get_instance();
	$session = SessionControl::get_instance();
	$session->logout();

	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Log Out',
		'header_only' => true,
	), NULL);
?>

	<!-- Content
	============================================= -->
	<section id="content">
		<div class="content-wrap py-0">

			<div class="section dark p-0 m-0 h-100 position-absolute"></div>

			<div class="section bg-transparent min-vh-100 p-0 m-0 d-flex">
				<div class="vertical-middle">
					<div class="container py-5">

						<div class="text-center">
							<a href="/">
								<?php if($settings->get_setting('logo_link')){ ?>
									<img src="<?php echo $settings->get_setting('logo_link'); ?>" alt="<?php echo $settings->get_setting('site_name'); ?>" style="height: 100px;">
								<?php } else { ?>
									<h2><?php echo $settings->get_setting('site_name'); ?></h2>
								<?php } ?>
							</a>
						</div>

						<div class="card mx-auto rounded-0 border-0" style="max-width: 400px;">
							<div class="card-body text-center" style="padding: 40px;">
								<i class="bi-check-circle" style="font-size: 48px; color: #28a745;"></i>
								<h3 class="mt-3">Logged Out</h3>
								<p class="text-muted mb-4">You have been successfully signed out.</p>
								<a class="button button-3d button-black m-0" href="/login">
									<i class="bi-chevron-left me-1"></i> Return to Login
								</a>
							</div>
						</div>

						<div class="text-center text-muted mt-3">
							<small>Copyrights &copy; All Rights Reserved by <?php echo $settings->get_setting('site_name'); ?>.</small>
						</div>

					</div>
				</div>
			</div>

		</div>
	</section><!-- #content end -->

<?php
	$page->public_footer($foptions=array('track'=>TRUE, 'no_wrapper_close'=>true));
?>