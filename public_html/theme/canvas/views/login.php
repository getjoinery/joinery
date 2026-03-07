<?php
	// PathHelper is always available - never require it
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('login_logic.php', 'logic'));
	
	$page_vars = login_logic($_GET, $_POST);
	// Handle LogicResult return format
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;
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
								
								<h3>Login to your Account</h3>

								<?php
								foreach($page_vars['display_messages'] AS $display_message) {
									if($display_message->identifier == 'loginbox') {
										echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
									}
								}

								$formwriter = $page->getFormWriter('form1', ['action' => '/login', 'method' => 'POST']);

								$formwriter->begin_form();
								?>

								<div class="row">
									<div class="col-12 form-group">
										<?php
										$formwriter->textinput('email', 'Username:', [
											'class' => 'form-control',
											'type' => 'email',
											'required' => true
										]);
										?>
									</div>

									<div class="col-12 form-group">
										<?php
										$formwriter->passwordinput('password', 'Password:', [
											'class' => 'form-control',
											'required' => true
										]);
										?>
									</div>

									<div class="col-12 form-group">
										<div class="d-flex justify-content-between">
											<?php
											$formwriter->submitbutton('login-form-submit', 'Login', [
												'class' => 'button button-3d button-black m-0',
												'value' => 'login'
											]);
											?>
											<a href="<?php echo $forgot_link; ?>">Forgot Password?</a>
										</div>
									</div>
								</div>

								<?php $formwriter->end_form(); ?>

								<div class="w-100"></div>

								<div class="text-center w-100">
									<p style="line-height: 1.6">Don't have an account yet? <a href="/register<?php if(isset($_GET['m'])){ echo '?m='.htmlspecialchars($_GET['m']); } ?>">Register for an Account</a></p>
								</div>
							</div>
						</div>

					</div>
				</div>
			</div>

		</div>
	</section><!-- #content end -->

<?php
	$page->public_footer($foptions=array('no_wrapper_close'=>true));
?>