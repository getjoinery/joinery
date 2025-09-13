<?php
	// PathHelper is always available - never require it
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	ThemeHelper::includeThemeFile('logic/login_logic.php');
	
	$page_vars = login_logic($_GET, $_POST);
	$settings = $page_vars['settings'];
	if ($email) {
		$forgot_link = '/password-reset-1?e=' . rawurlencode(htmlspecialchars($email));
	} else {
		$forgot_link = '/password-reset-1';
	}

	$page = new PublicPage();
	$hoptions=array(
		'is_valid_page' => $is_valid_page,
		'title'=>'Log In',
		'header_only' => true,
	);
	$page->public_header($hoptions,NULL);
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
							<div class="card-body" style="padding: 40px;">
								
								<?php 
								foreach($page_vars['display_messages'] AS $display_message) {
									if($display_message->identifier == 'loginbox') {	
										echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
									}
								}   		

								$formwriter = $page->getFormWriter('form1');

								$validation_rules = array();
								$validation_rules['email']['required']['value'] = 'true';
								$validation_rules['password']['required']['value'] = 'true';
								echo $formwriter->set_validate($validation_rules);	
								echo $formwriter->begin_form('form1', 'POST', '/login');
								?>
								
								<h3>Login to your Account</h3>

								<div class="row">
									<div class="col-12 form-group">
										<label for="email">Email Address:</label>
										<input type="email" id="email" name="email" value="" class="form-control not-dark" placeholder="Email address">
									</div>

									<div class="col-12 form-group">
										<label for="password">Password:</label>
										<input type="password" id="password" name="password" value="" class="form-control not-dark" placeholder="Password">
									</div>

									<div class="col-12 form-group">
										<div class="form-check">
											<input class="form-check-input" type="checkbox" id="setcookie" name="setcookie" checked="checked" value="yes">
											<label class="form-check-label" for="setcookie">Remember me</label>
										</div>
									</div>

									<div class="col-12 form-group mb-0">
										<div class="d-flex justify-content-between">
											<button class="button button-3d button-black m-0" type="submit" name="submit">Login</button>
											<a href="<?php echo $forgot_link; ?>">Forgot Password?</a>
										</div>
									</div>
								</div>
								
								<?php echo $formwriter->end_form(); ?>

								<div class="line line-sm"></div>

								<div class="text-center">
									<p class="mb-0">Don't have an account? <a href="/register<?php if(isset($_GET['m'])){ echo '?m='.htmlspecialchars($_GET['m']); } ?>">Create one</a></p>
								</div>
							</div>
						</div>

						<div class="text-center text-muted mt-3"><small>Copyrights &copy; All Rights Reserved by <?php echo $settings->get_setting('site_name'); ?>.</small></div>

					</div>
				</div>
			</div>

		</div>
	</section><!-- #content end -->

<?php
	$page->public_footer($foptions=array('no_wrapper_close'=>true));
?>