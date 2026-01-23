<?php
	// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('change-password-required_logic.php', 'logic'));

	$page_vars = change_password_required_logic($_GET, $_POST);
	// Handle LogicResult return format
	if ($page_vars->redirect) {
		LibraryFunctions::redirect($page_vars->redirect);
		exit();
	}
	$page_vars = $page_vars->data;
	$settings = Globalvars::get_instance();

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => true,
		'title' => 'Change Password Required',
		'header_only' => true,
	);
	$page->public_header($hoptions, NULL);
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
						<div class="card-body" style="padding: 40px;">
							<div class="alert alert-warning mb-4">
								<i class="fas fa-exclamation-triangle me-2"></i>
								<strong>Password Change Required</strong>
								<p class="mb-0 mt-2">For security reasons, you must change your password before continuing. The default password should not be used in production.</p>
							</div>

							<h3>Set New Password</h3>

							<?php
							$formwriter = $page->getFormWriter('form1');

							$formwriter->begin_form([
								'id' => 'change-password-form',
								'method' => 'POST',
								'action' => '/change-password-required',
								'ajax' => true,
								'attributes' => 'class="mb-0"'
							]);
							?>

							<div class="row">
								<div class="col-12 form-group">
									<label for="new_password">New Password:</label>
									<?php
									$formwriter->passwordinput('new_password', '', [
										'class' => 'form-control',
										'required' => true,
										'minlength' => 8,
										'autocomplete' => 'new-password',
										'placeholder' => 'Minimum 8 characters'
									]);
									?>
								</div>

								<div class="col-12 form-group">
									<label for="confirm_password">Confirm Password:</label>
									<?php
									$formwriter->passwordinput('confirm_password', '', [
										'class' => 'form-control',
										'required' => true,
										'data-msg-required' => 'Please confirm your password',
										'data-rule-equalTo' => '#new_password',
										'data-msg-equalTo' => 'Passwords do not match',
										'autocomplete' => 'new-password'
									]);
									?>
								</div>

								<div class="col-12 form-group">
									<?php
									$formwriter->submitbutton('submit', 'Change Password', [
										'class' => 'button button-3d button-black m-0'
									]);
									?>
								</div>
							</div>

							<?php $formwriter->end_form(); ?>

							<div class="mt-3 text-center">
								<a href="/logout" class="text-muted small">Log out</a>
							</div>
						</div>
					</div>

				</div>
			</div>
		</div>

	</div>
</section><!-- #content end -->
<?php
	$page->public_footer($foptions = array('track' => TRUE, 'header_only' => true));
?>
