<?php
	// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('password-reset-2_logic.php', 'logic'));

	$page_vars = password_reset_2_logic($_GET, $_POST);
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

	if($page_vars['message']){
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
									<div class="mb-4">
										<div class="text-success mb-3">
											<i class="icon-check-circle" style="font-size: 4rem;"></i>
										</div>
										<h3 class="mb-2">Password Successfully Reset</h3>
										<p class="text-muted">Continue to log in with your new password.</p>
									</div>
									<a href="/login" class="button button-3d button-black m-0">
										<i class="icon-chevron-left me-1"></i>Continue to Login
									</a>
								</div>
							</div>

						</div>
					</div>
				</div>

			</div>
		</section><!-- #content end -->
		<?php
	}
	else{
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
									<h3>Set New Password</h3>

									<?php
									$formwriter = $page->getFormWriter('form1');
									$validation_rules = array();
									$validation_rules['usr_password']['required']['value'] = 'true';
									$validation_rules['usr_password']['minlength']['value'] = 5;
									$validation_rules['usr_password_again']['required']['value'] = 'true';
									$validation_rules['usr_password_again']['required']['message'] = "'You must enter your password twice to confirm'";
									$validation_rules['usr_password_again']['equalTo']['value'] = "'#usr_password'";
									$validation_rules['usr_password_again']['equalTo']['message'] = "'Your password did not match the one you entered above'";
									echo $formwriter->set_validate($validation_rules);
									echo $formwriter->begin_form("", "post", "/password-reset-2", true, 'class="mb-0"');
									echo $formwriter->hiddeninput('act_code',$page_vars['act_code']);
									?>

									<div class="row">
										<div class="col-12 form-group">
											<label for="usr_password">New Password:</label>
											<input type="password"
												   name="usr_password"
												   id="usr_password"
												   class="form-control"
												   autocomplete="new-password" />
										</div>

										<div class="col-12 form-group">
											<label for="usr_password_again">Confirm Password:</label>
											<input type="password"
												   name="usr_password_again"
												   id="usr_password_again"
												   class="form-control"
												   autocomplete="new-password" />
										</div>

										<div class="col-12 form-group">
											<button type="submit" name="submit" class="button button-3d button-black m-0">
												Set Password
											</button>
										</div>
									</div>

									<?php echo $formwriter->end_form(); ?>
								</div>
							</div>

						</div>
					</div>
				</div>

			</div>
		</section><!-- #content end -->
		<?php
	}

	$page->public_footer($foptions=array('track'=>TRUE, 'header_only' => true));
?>