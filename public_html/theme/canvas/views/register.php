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
	$formwriter = $page->getFormWriter('form1', [
		'action' => '/register',
		'class' => 'row mb-0'
	]);

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
							<h3>Register for an Account</h3>
							<?php
							$formwriter->begin_form();
							$formwriter->hiddeninput("prevformname", "register");
							?>
								<div class="col-md-6 form-group">
									<label for="usr_first_name">First Name:</label>
									<?php $formwriter->textinput("usr_first_name", "", [
										'value' => @$form_fields->usr_first_name,
										'maxlength' => 32,
										'class' => 'form-control'
									]); ?>
								</div>

								<div class="col-md-6 form-group">
									<label for="usr_last_name">Last Name:</label>
									<?php $formwriter->textinput("usr_last_name", "", [
										'value' => @$form_fields->usr_last_name,
										'maxlength' => 32,
										'class' => 'form-control'
									]); ?>
								</div>

								<?php if($nickname_display){ ?>
								<div class="col-12 form-group">
									<label for="usr_nickname"><?php echo htmlspecialchars($nickname_display); ?>:</label>
									<?php $formwriter->textinput("usr_nickname", "", [
										'value' => @$form_fields->usr_nickname,
										'maxlength' => 32,
										'class' => 'form-control'
									]); ?>
								</div>
								<?php } ?>

								<div class="col-12 form-group">
									<label for="usr_email">Email Address:</label>
									<?php $formwriter->textinput("usr_email", "", [
										'maxlength' => 64,
										'class' => 'form-control'
									]); ?>
								</div>

								<div class="col-12 form-group">
									<label for="password">Choose Password:</label>
									<?php $formwriter->passwordinput("password", "", [
										'maxlength' => 255,
										'class' => 'form-control'
									]); ?>
								</div>

								<div class="col-12 form-group">
									<label for="usr_timezone">Timezone:</label>
									<?php
									$optionvals = Address::get_timezone_drop_array();
									$default_timezone = $settings->get_setting('default_timezone');
									$formwriter->dropinput("usr_timezone", "", [
										'options' => $optionvals,
										'value' => $default_timezone,
										'class' => 'form-control'
									]);
									?>
								</div>

								<!-- Anti-spam Question -->
								<div class="col-12 form-group">
									<?php $formwriter->antispam_question_input(); ?>
								</div>

								<!-- Checkboxes -->
								<div class="col-12 form-group">
									<?php $formwriter->checkboxinput("privacy", "I have read and agree to the <a href='/privacy' target='_blank'>privacy policy</a>", [
										'value' => 'yes',
										'class' => 'form-check-input'
									]); ?>
								</div>
								<div class="col-12 form-group">
									<?php $formwriter->checkboxinput("newsletter", "Please add me to the mailing list", [
										'value' => 'yes',
										'class' => 'form-check-input'
									]); ?>
								</div>
								<div class="col-12 form-group">
									<?php $formwriter->checkboxinput("setcookie", "Keep me logged in", [
										'value' => 'yes',
										'checked' => true,
										'class' => 'form-check-input'
									]); ?>
								</div>

								<!-- Security fields -->
								<?php
								$formwriter->honeypot_hidden_input();
								$formwriter->captcha_hidden_input();
								?>

								<!-- Submit Button -->
								<div class="col-12 form-group">
									<?php $formwriter->submitbutton('submit', 'Register Now', ['class' => 'btn btn-primary']); ?>
								</div>

							<?php $formwriter->end_form(true); ?>

							<div class="w-100"></div>

							<div class="text-center w-100">
								<p style="line-height: 1.6">Already have an account? <a href="/login<?php echo $extra; ?>">Login to your Account</a></p>
							</div>
						</div>
					</div>

				</div>
			</div>
		</div>

	</div>
</section><!-- #content end -->

<?php
echo PublicPage::EndPage();
$page->public_footer($foptions=array('track'=>TRUE, 'fbconnect'=>TRUE));
?>
