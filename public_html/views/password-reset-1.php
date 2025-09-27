<?php
	
	PathHelper::requireOnce('includes/LibraryFunctions.php');
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
	//echo PublicPage::BeginPage('Reset Password - Step 1 of 2');
	//echo PublicPage::BeginPanel();

	if($page_vars['message']){
		?>
		<main class="main" id="top">
		  <div class="container" data-layout="container">

			<div class="row flex-center min-vh-100 py-6">
			  <div class="col-sm-10 col-md-8 col-lg-6 col-xl-5 col-xxl-4">
				<a class="d-flex flex-center mb-4" href="/">
				<?php
		  		if($settings->get_setting('logo_link')){
					echo '<img class="me-2" src="'.$settings->get_setting('logo_link').'" alt="" width="40" />';
				}
				?>
				<span class="font-sans-serif text-primary fw-bolder fs-4 d-inline-block"><?php echo $settings->get_setting('site_name'); ?></span>
				</a>
				<div class="card">
				  <div class="card-body p-4 p-sm-5">
					<div class="text-center"><!--<img class="d-block mx-auto mb-4" src="../../../assets/img/icons/spot-illustrations/16.png" alt="Email" width="100" />-->
					  <h4 class="mb-2">Please check your email!</h4>
					  <p>An email has been sent to you. Please click on the included link to reset <span class="white-space-nowrap">your password.</span>
					  </p><a class="btn btn-primary btn-sm mt-3" href="/login"><span class="fas fa-chevron-left me-1" data-fa-transform="shrink-4 down-1"></span>Return to login</a>
					</div>
				  </div>
				</div>
			  </div>
			</div>
		  </div>
		</main>		
		<?php
	}
	else{
		$formwriter = $page->getFormWriter('form1');
		
		?>
		<main class="main" id="top">
		  <div class="container" data-layout="container">
			<div class="row flex-center min-vh-100 py-6 text-center">
			  <div class="col-sm-10 col-md-8 col-lg-6 col-xl-5 col-xxl-4">
				<a class="d-flex flex-center mb-4" href="/">
				<?php
		  		if($settings->get_setting('logo_link')){
					echo '<img class="me-2" src="'.$settings->get_setting('logo_link').'" alt="" width="40" />';
				}
				?>
				<span class="font-sans-serif text-primary fw-bolder fs-4 d-inline-block"><?php echo $settings->get_setting('site_name'); ?></span>
				</a>
				<div class="card">
				  <div class="card-body p-4 p-sm-5">
					<h5 class="mb-0">Forgot your password?</h5><small>Enter your email and we'll send you a reset link.</small>
					<div class="mt-4">
						<?php 
						$validation_rules = array();
						$validation_rules['email']['required']['value'] = 'true';
						$validation_rules['password']['required']['value'] = 'true';
						echo $formwriter->set_validate($validation_rules);	
						echo $formwriter->begin_form("", "post", "/password-reset-1", true);

						?>
					  <input class="form-control" type="email" name="email" id="email" placeholder="Email address" />
					  <div class="mb-3"></div>
					  <button class="btn btn-primary d-block w-100 mt-3" type="submit" name="submit">Send reset link</button>
					  <?php echo $formwriter->end_form(); ?>
					</div><a class="fs-10 text-600" href="/login">Back to login</a>
				  </div>
				</div>
			  </div>
			</div>
		  </div>
		</main>		
		<?php
		
	}

	//echo PublicPage::EndPanel();
	//echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'header_only' => true));

?>
