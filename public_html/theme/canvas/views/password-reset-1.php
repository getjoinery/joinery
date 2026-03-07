<?php
	// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('password-reset-1_logic.php', 'logic'));

	$page_vars = password_reset_1_logic($_GET, $_POST);
	// Handle LogicResult return format
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;
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
<!-- Content
============================================= -->
<section id="content">
	<div class="content-wrap py-0">

		<div class="section p-0 m-0 h-100 position-absolute" style="background: url('/theme/canvas/assets/images/hero/hero-login.jpg') center center no-repeat; background-size: cover;"></div>

		<div class="section bg-transparent min-vh-100 p-0 m-0">
			<div class="vertical-middle">
				<div class="container-fluid py-5 mx-auto" style="max-width: 40rem;">

					<div class="center mb-4">
						<a href="/">
							<img src="/theme/canvas/assets/images/logo-dark.png" alt="Logo" style="max-height: 50px;">
						</a>
					</div>

					<div class="card mb-0">
						<div class="card-body text-center" style="padding: 40px;">
							<!-- Success Icon -->
							<div class="mb-4">
								<i class="bi-envelope-check text-success" style="font-size: 4rem;"></i>
							</div>

							<!-- Success Message -->
							<h3>Check Your Email!</h3>
							<p class="text-muted mb-4">
								An email has been sent to you. Please click on the included link to reset your password.
							</p>

							<a href="/login" class="button button-3d button-black m-0">
								<i class="bi-arrow-left me-2"></i>Return to Login
							</a>
						</div>
					</div>

				</div>
			</div>
		</div>

	</div>
</section><!-- #content end -->

<?php else: ?>

<!-- Content
============================================= -->
<section id="content">
	<div class="content-wrap py-0">

		<div class="section p-0 m-0 h-100 position-absolute" style="background: url('/theme/canvas/assets/images/hero/hero-login.jpg') center center no-repeat; background-size: cover;"></div>

		<div class="section bg-transparent min-vh-100 p-0 m-0">
			<div class="vertical-middle">
				<div class="container-fluid py-5 mx-auto" style="max-width: 40rem;">

					<div class="center mb-4">
						<a href="/">
							<img src="/theme/canvas/assets/images/logo-dark.png" alt="Logo" style="max-height: 50px;">
						</a>
					</div>

					<div class="card mb-0">
						<div class="card-body" style="padding: 40px;">
							<h3>Reset Password</h3>
							<p class="text-muted mb-4">Enter your email address and we'll send you a link to reset your password.</p>

							<?php
							$formwriter = $page->getFormWriter('form1', ['action' => '/password-reset-1', 'method' => 'POST']);

							$formwriter->begin_form();
							?>

							<div class="row">
								<div class="col-12 form-group">
									<?php
									$formwriter->textinput('usr_email', 'Email Address:', [
										'class' => 'form-control',
										'type' => 'email',
										'required' => true,
										'maxlength' => 64
									]);
									?>
								</div>

								<div class="col-12 form-group">
									<?php
									$formwriter->submitbutton('btn_submit', 'Send Reset Link', [
										'class' => 'button button-3d button-black m-0'
									]);
									?>
								</div>
							</div>

							<?php $formwriter->end_form(); ?>

							<div class="w-100"></div>

							<div class="text-center w-100">
								<p style="line-height: 1.6">Remember your password? <a href="/login">Login to your Account</a></p>
							</div>
						</div>
					</div>

				</div>
			</div>
		</div>

	</div>
</section><!-- #content end -->

<?php endif; ?>

<?php
$page->public_footer(array('track'=>TRUE, 'header_only' => true));
?>