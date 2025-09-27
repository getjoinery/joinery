<?php
	require_once(PathHelper::getThemeFilePath('register_logic.php', 'logic'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	$page_vars = register_logic($_GET, $_POST);
	// Handle LogicResult return format
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;

	$page = new PublicPage();
	$hoptions=array(
		'is_valid_page' => $is_valid_page,
		'title'=>'Register',
	);
	$page->public_header($hoptions,NULL);

	$extra = '';
	if(isset($_GET['m'])){ 
		$extra = '?m='.htmlspecialchars($_GET['m']); 
	}
	$options['subtitle'] = '<a href="/login'.$extra.'">Already a member? Log in</a>';
	echo PublicPage::BeginPage('Register', $options);

	if(isset($_GET['msgtext'])){
		if (array_key_exists($_GET['msgtext'], $page_vars['LOGIN_MESSAGES'])) {
			echo PublicPage::alert('Login warning', htmlspecialchars($LOGIN_MESSAGES[$_GET['msgtext']]), 'warn');
		}
	}

	$settings = Globalvars::get_instance();
	$nickname_display = $settings->get_setting('nickname_display_as');
	$formwriter = $page->getFormWriter('form1');

	$validation_rules = array();
	$validation_rules['usr_first_name']['required']['value'] = 'true';
	$validation_rules['usr_first_name']['minlength']['value'] = 1;
	$validation_rules['usr_first_name']['maxlength']['value'] = 32;
	$validation_rules['usr_first_name']['required']['message'] = "'Please enter your first name.'";
	$validation_rules['usr_last_name']['required']['value'] = 'true';
	$validation_rules['usr_last_name']['minlength']['value'] = 2;
	$validation_rules['usr_last_name']['maxlength']['value'] = 32;
	if($nickname_display){
		$validation_rules['usr_nickname']['maxlength']['value'] = 32;
	}
	$validation_rules['usr_email']['required']['value'] = 'true';
	$validation_rules['usr_email']['email']['value'] = 'true';
	$validation_rules['usr_email']['maxlength']['value'] = 64;
	$validation_rules['usr_email']['remote']['value'] = "'/ajax/email_check_ajax'";	
	$validation_rules['usr_email']['remote']['message'] = "'This email already exists.'";
	$validation_rules['password']['required']['value'] = 'true';
	$validation_rules['password']['minlength']['value'] = 5;	
	$validation_rules['password']['minlength']['message'] = "'Password must be at least {0} characters'";
	$validation_rules['privacy']['required']['value'] = 'true';	
	if($nickname_display){
		$validation_rules['usr_nickname']['maxlength']['value'] = 32;
	}
	$validation_rules = $formwriter->antispam_question_validate($validation_rules);
	echo $formwriter->set_validate($validation_rules);
?>

<!-- Canvas Registration Section -->
<section id="content">
	<div class="content-wrap">
		<div class="container">
			<div class="row justify-content-center">
				<div class="col-lg-6 col-md-8">
					<div class="card shadow-sm rounded-4">
						<div class="card-header text-center bg-primary text-white rounded-top-4">
							<h3 class="mb-0">Create Your Account</h3>
							<p class="mb-0 mt-2 opacity-75">Join our community today</p>
						</div>
						<div class="card-body p-4">
							<?php
							echo $formwriter->begin_form("form1", "post", "/register", TRUE);
							echo $formwriter->hiddeninput("prevformname", "register");
							?>

							<div class="row g-3">
								<div class="col-md-6">
									<div class="form-group">
										<label for="usr_first_name" class="form-label">First Name <span class="text-danger">*</span></label>
										<?php echo $formwriter->textinput("", "usr_first_name", 'form-control', 20, @$form_fields->usr_first_name , "",32, ""); ?>
									</div>
								</div>
								<div class="col-md-6">
									<div class="form-group">
										<label for="usr_last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
										<?php echo $formwriter->textinput("", "usr_last_name", 'form-control', 20, @$form_fields->usr_last_name, "" , 32, ""); ?>
									</div>
								</div>
								
								<?php if($nickname_display){ ?>
								<div class="col-12">
									<div class="form-group">
										<label for="usr_nickname" class="form-label"><?php echo htmlspecialchars($nickname_display); ?></label>
										<?php echo $formwriter->textinput("", "usr_nickname", 'form-control', 20, @$form_fields->usr_nickname, "" , 32, ""); ?>
									</div>
								</div>
								<?php } ?>
								
								<div class="col-12">
									<div class="form-group">
										<label for="usr_email" class="form-label">Email Address <span class="text-danger">*</span></label>
										<?php echo $formwriter->textinput("", "usr_email", 'form-control', 20, '', "" , 64, ""); ?>
									</div>
								</div>

								<div class="col-12">
									<div class="form-group">
										<label for="password" class="form-label">Create Password <span class="text-danger">*</span></label>
										<?php echo $formwriter->passwordinput("", "password", 'form-control', 20, "" , "", 255,""); ?>
										<small class="form-text text-muted">Password must be at least 5 characters long</small>
									</div>
								</div>

								<div class="col-12">
									<div class="form-group">
										<label for="usr_timezone" class="form-label">Timezone</label>
										<?php 
										$optionvals = Address::get_timezone_drop_array();
										$default_timezone = $settings->get_setting('default_timezone');
										echo $formwriter->dropinput("", "usr_timezone", 'form-select', $optionvals, $default_timezone, '', FALSE);
										?>
									</div>
								</div>

								<!-- Anti-spam Question -->
								<div class="col-12">
									<?php echo $formwriter->antispam_question_input(); ?>
								</div>

								<!-- Checkboxes -->
								<div class="col-12">
									<div class="form-check mb-3">
										<?php echo $formwriter->checkboxinput("I have read and agree to the <a href='/privacy' target='_blank'>privacy policy</a>", "privacy", "form-check-input", "normal", NULL, "yes", ''); ?>
									</div>
									<div class="form-check mb-3">
										<?php echo $formwriter->checkboxinput("Please add me to the mailing list", "newsletter", "form-check-input", "normal", NULL, "yes", ''); ?>
									</div>
									<div class="form-check mb-3">
										<?php echo $formwriter->checkboxinput("Keep me logged in", "setcookie", "form-check-input", "normal", 'yes', "yes", ''); ?>
									</div>
								</div>

								<!-- Security fields -->
								<?php 
								echo $formwriter->honeypot_hidden_input();
								echo $formwriter->captcha_hidden_input();
								?>

								<!-- Submit Button -->
								<div class="col-12">
									<div class="d-grid">
										<?php echo $formwriter->new_form_button('Create Account', 'btn btn-primary btn-lg', 'full'); ?>
									</div>
								</div>
							</div>

							<?php echo $formwriter->end_form(true); ?>

							<div class="text-center mt-4">
								<p class="mb-0">Already have an account? <a href="/login<?php echo $extra; ?>" class="text-primary fw-semibold">Sign in here</a></p>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</section>

<?php
echo PublicPage::EndPage();
$page->public_footer($foptions=array('track'=>TRUE, 'fbconnect'=>TRUE));
?>