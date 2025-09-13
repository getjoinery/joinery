<?php
	// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('password-reset-2_logic.php', 'logic'));

	$page_vars = password_reset_2_logic($_GET, $_POST);
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
		<!-- Canvas Success Section -->
		<section id="content">
			<div class="content-wrap">
				<div class="container">
					<div class="row justify-content-center">
						<div class="col-sm-10 col-md-8 col-lg-6 col-xl-5 col-xxl-4">
							
							<!-- Logo -->
							<div class="text-center mb-4">
								<a href="/" class="d-flex align-items-center justify-content-center text-decoration-none">
									<?php if($settings->get_setting('logo_link')): ?>
										<img src="<?php echo $settings->get_setting('logo_link'); ?>" alt="" width="40" class="me-2" />
									<?php endif; ?>
									<span class="h4 mb-0 text-primary fw-bold"><?php echo $settings->get_setting('site_name'); ?></span>
								</a>
							</div>

							<!-- Success Card -->
							<div class="card shadow-sm rounded-4 border-0">
								<div class="card-body p-4 p-sm-5 text-center">
									<div class="mb-4">
										<div class="text-success mb-3">
											<i class="icon-check-circle display-4"></i>
										</div>
										<h4 class="mb-2">Password successfully reset.</h4>
										<p class="text-muted">Continue to log in.</p>
									</div>
									<a href="/login" class="btn btn-primary btn-lg rounded-pill">
										<i class="icon-chevron-left me-1"></i>Continue to login
									</a>
								</div>
							</div>

						</div>
					</div>
				</div>
			</div>
		</section>
		<?php
	}
	else{
		?>
		<!-- Canvas Password Reset Form Section -->
		<section id="content">
			<div class="content-wrap">
				<div class="container">
					<div class="row justify-content-center">
						<div class="col-sm-10 col-md-8 col-lg-6 col-xl-5 col-xxl-4">
							
							<!-- Logo -->
							<div class="text-center mb-4">
								<a href="/" class="d-flex align-items-center justify-content-center text-decoration-none">
									<?php if($settings->get_setting('logo_link')): ?>
										<img src="<?php echo $settings->get_setting('logo_link'); ?>" alt="" width="40" class="me-2" />
									<?php endif; ?>
									<span class="h4 mb-0 text-primary fw-bold"><?php echo $settings->get_setting('site_name'); ?></span>
								</a>
							</div>

							<!-- Form Card -->
							<div class="card shadow-sm rounded-4 border-0">
								<div class="card-body p-4 p-sm-5">
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
									echo $formwriter->begin_form("", "post", "/password-reset-2", true);
									echo $formwriter->hiddeninput('act_code',$page_vars['act_code']);
									?>
									
									<div class="text-center mb-4">
										<h5 class="mb-0">Set New Password</h5>
									</div>

									<div class="mb-3">
										<label for="usr_password" class="form-label">New Password</label>
										<input type="password" 
											   name="usr_password" 
											   id="usr_password" 
											   class="form-control form-control-lg rounded-pill" 
											   placeholder="Enter new password" 
											   autocomplete="new-password" />
									</div>

									<div class="mb-3">
										<label for="usr_password_again" class="form-label">Confirm Password</label>
										<input type="password" 
											   name="usr_password_again" 
											   id="usr_password_again" 
											   class="form-control form-control-lg rounded-pill" 
											   placeholder="Confirm password" 
											   autocomplete="new-password" />
									</div>

									<button type="submit" name="submit" class="btn btn-primary btn-lg w-100 rounded-pill mt-3">
										Set Password
									</button>
									
									<?php echo $formwriter->end_form(); ?>
								</div>
							</div>

						</div>
					</div>
				</div>
			</div>
		</section>
		<?php
	}

	$page->public_footer($foptions=array('track'=>TRUE, 'header_only' => true));
?>