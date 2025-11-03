<?php
	// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('password-set_logic.php', 'logic'));

	$page_vars = password_set_logic($_GET, $_POST);
	// Handle LogicResult return format
if ($page_vars->redirect) {
    LibraryFunctions::redirect($page_vars->redirect);
    exit();
}
$page_vars = $page_vars->data;

	$page = new PublicPage();
	$hoptions=array(
		'is_valid_page' => $is_valid_page,
		'title'=>'Password Set', 
	);
	$page->public_header($hoptions,NULL);

	echo PublicPage::BeginPage('Set a Password');
?>

<!-- Canvas Password Set Section -->
<section id="content">
	<div class="content-wrap">
		<div class="container">
			<div class="row justify-content-center">
				<div class="col-lg-6 col-xl-5">
					
					<!-- Page Header -->
					<div class="text-center mb-5">
						<h1 class="h2 mb-2">Set a Password</h1>
						<p class="text-muted">Create a secure password for your account</p>
					</div>

					<?php if($message): ?>
						<!-- Alert Message -->
						<div class="alert alert-<?php echo $page_vars['message_type'] == 'error' ? 'danger' : ($page_vars['message_type'] == 'success' ? 'success' : 'info'); ?> rounded-4 shadow-sm" role="alert">
							<?php if($page_vars['message_title']): ?>
								<h5 class="alert-heading mb-2"><?php echo $page_vars['message_title']; ?></h5>
							<?php endif; ?>
							<?php echo $page_vars['message']; ?>
						</div>
					<?php else: ?>
						
						<!-- Password Set Form -->
						<div class="card shadow-sm rounded-4 border-0">
							<div class="card-body p-4 p-lg-5">
								<?php
								$settings = Globalvars::get_instance();
								$formwriter = $page->getFormWriter('form1', [
									'action' => '/password-set'
								]);

								$validation_rules = array();
								$validation_rules['usr_password']['required']['value'] = 'true';
								$validation_rules['usr_password']['minlength']['value'] = 5;
								$validation_rules['usr_password_again']['required']['value'] = 'true';
								$validation_rules['usr_password_again']['required']['message'] = "'You must enter your password twice to confirm'";
								$validation_rules['usr_password_again']['equalTo']['value'] = "'#usr_password'";
								$validation_rules['usr_password_again']['equalTo']['message'] = "'Your password did not match the one you entered above'";

								$formwriter->begin_form();
								?>
								
								<div class="mb-4">
									<label for="usr_password" class="form-label fw-semibold">New Password</label>
									<input type="password" 
										   name="usr_password" 
										   id="usr_password" 
										   class="form-control form-control-lg rounded-pill" 
										   placeholder="Enter new password"
										   autocomplete="new-password" />
									<div class="form-text">Must be at least 5 characters.</div>
								</div>

								<div class="mb-4">
									<label for="usr_password_again" class="form-label fw-semibold">Retype New Password</label>
									<input type="password" 
										   name="usr_password_again" 
										   id="usr_password_again" 
										   class="form-control form-control-lg rounded-pill" 
										   placeholder="Confirm new password"
										   autocomplete="new-password" />
								</div>

								<div class="d-grid">
									<button type="submit" class="btn btn-primary btn-lg rounded-pill">
										<i class="icon-check me-2"></i>Set Password
									</button>
								</div>

								<?php echo $formwriter->end_form(); ?>
							</div>
						</div>

					<?php endif; ?>

				</div>
			</div>
		</div>
	</div>
</section>

<?php
	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE, 'formvalidate'=>TRUE));
?>