<?php
	// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	ThemeHelper::includeThemeFile('includes/PublicPage.php');
	ThemeHelper::includeThemeFile('logic/password-reset-1_logic.php');

	$page_vars = password_reset_1_logic($_GET, $_POST);
	$settings = Globalvars::get_instance();

	$page = new PublicPage();
	$hoptions=array(
		'is_valid_page' => $is_valid_page,
		'title'=>'Password Reset', 
		'header_only' => true,
	);	
	$page->public_header($hoptions,NULL);

	if($page_vars['message']):
?>
<!-- Canvas Password Reset Success -->
<section id="content">
	<div class="content-wrap">
		<div class="container">
			<div class="row justify-content-center min-vh-75 align-items-center">
				<div class="col-lg-5 col-md-7">
					<div class="card shadow-sm rounded-4 text-center">
						<div class="card-body p-5">
							<!-- Site Logo/Brand -->
							<div class="d-flex justify-content-center align-items-center mb-4">
								<?php if($settings->get_setting('logo_link')): ?>
									<img src="<?php echo $settings->get_setting('logo_link'); ?>" alt="Logo" height="40" class="me-3">
								<?php endif; ?>
								<span class="h4 text-primary fw-bold mb-0"><?php echo htmlspecialchars($settings->get_setting('site_name')); ?></span>
							</div>

							<!-- Success Icon -->
							<div class="mb-4">
								<i class="bi-envelope-check text-success" style="font-size: 4rem;"></i>
							</div>

							<!-- Success Message -->
							<h2 class="h4 mb-3">Check Your Email!</h2>
							<p class="text-muted mb-4">
								An email has been sent to you. Please click on the included link to reset your password.
							</p>
							
							<a href="/login" class="btn btn-primary btn-lg">
								<i class="bi-arrow-left me-2"></i>Return to Login
							</a>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</section>

<?php else: ?>

<!-- Canvas Password Reset Form -->
<section id="content">
	<div class="content-wrap">
		<div class="container">
			<div class="row justify-content-center min-vh-75 align-items-center">
				<div class="col-lg-5 col-md-7">
					<div class="card shadow-sm rounded-4">
						<div class="card-header text-center bg-primary text-white rounded-top-4">
							<!-- Site Logo/Brand -->
							<div class="d-flex justify-content-center align-items-center mb-2">
								<?php if($settings->get_setting('logo_link')): ?>
									<img src="<?php echo $settings->get_setting('logo_link'); ?>" alt="Logo" height="30" class="me-2">
								<?php endif; ?>
								<span class="h5 mb-0"><?php echo htmlspecialchars($settings->get_setting('site_name')); ?></span>
							</div>
							<h3 class="mb-0">Reset Password</h3>
						</div>
						<div class="card-body p-4">
							<p class="text-muted mb-4">Enter your email address and we'll send you a link to reset your password.</p>
							
							<?php
							$formwriter = $page->getFormWriter('form1');
							$validation_rules = array();
							$validation_rules['usr_email']['required']['value'] = 'true';
							$validation_rules['usr_email']['email']['value'] = 'true';
							echo $formwriter->set_validate($validation_rules);

							echo $formwriter->begin_form("", "post", "/password-reset-1");
							?>

							<div class="form-group mb-4">
								<label for="usr_email" class="form-label">Email Address</label>
								<?php echo $formwriter->textinput("", "usr_email", 'form-control form-control-lg', 50, '', 'Enter your email address', 64, ""); ?>
							</div>

							<div class="d-grid">
								<?php echo $formwriter->new_form_button('Send Reset Link', 'btn btn-primary btn-lg'); ?>
							</div>

							<?php echo $formwriter->end_form(); ?>

							<div class="text-center mt-4">
								<p class="mb-0">Remember your password? <a href="/login" class="text-primary fw-semibold">Sign in here</a></p>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</section>

<?php endif; ?>

<style>
.min-vh-75 {
	min-height: 75vh;
}
</style>

<?php
$page->public_footer(array('track'=>TRUE, 'header_only' => true));
?>