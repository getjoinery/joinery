<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	ThemeHelper::includeThemeFile('logic/password-reset-2_logic.php');

	$page_vars = password_reset_2_logic($_GET, $_POST);
	$settings = Globalvars::get_instance();
	
	$page = new PublicPage();
	$hoptions=array(
		'is_valid_page' => $is_valid_page,
		'title'=>'Password Reset', 
		'header_only' => true,
	);
	$page->public_header($hoptions,NULL);
	//echo PublicPage::BeginPage('Password Reset');
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
					  <h4 class="mb-2">Password successfully reset.</h4>
					  <p>Continue to <span class="white-space-nowrap">log in.</span>
					  </p><a class="btn btn-primary btn-sm mt-3" href="/login"><span class="fas fa-chevron-left me-1" data-fa-transform="shrink-4 down-1"></span>Continue to login</a>
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
					<h5 class="text-center">Set new password</h5>
					<div class="mt-3">
					  <div class="mb-3">
						<label class="form-label"></label>
						<input class="form-control" type="password" name="usr_password" id="usr_password" placeholder="New Password" />
					  </div>
					  <div class="mb-3">
						<input class="form-control" type="password" name="usr_password_again" id="usr_password_again" placeholder="Confirm Password" />
					  </div>
					  <button class="btn btn-primary d-block w-100 mt-3" type="submit" name="submit">Set password</button>
					</div>
				  </div>
				  <?php echo $formwriter->end_form(); ?>
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
