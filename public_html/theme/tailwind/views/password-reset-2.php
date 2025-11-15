<?php

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
		<main class="min-h-screen" id="top">
		  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

			<div class="flex items-center justify-center min-h-screen py-6">
			  <div class="w-full max-w-md">
				<a class="flex items-center justify-center mb-4" href="/">
				<?php
		  		if($settings->get_setting('logo_link')){
					echo '<img class="mr-2" src="'.htmlspecialchars($settings->get_setting('logo_link')).'" alt="" width="40" />';
				}
				?>
				<span class="text-blue-600 font-bold text-2xl"><?php echo htmlspecialchars($settings->get_setting('site_name')); ?></span>
				</a>
				<div class="bg-white rounded-lg shadow-md overflow-hidden">
				  <div class="p-6 sm:p-8">
					<div class="text-center">
					  <h4 class="text-2xl font-semibold text-gray-900 mb-2">Password successfully reset.</h4>
					  <p class="text-gray-600 mb-4">Continue to <span class="whitespace-nowrap">log in.</span>
					  </p>
					  <a class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded transition text-sm" href="/login">
					    <span class="fas fa-chevron-left mr-2" data-fa-transform="shrink-4 down-1"></span>Continue to login
					  </a>
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
		<main class="min-h-screen" id="top">
		  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
			<div class="flex items-center justify-center min-h-screen py-6">
			  <div class="w-full max-w-md">
				<a class="flex items-center justify-center mb-4" href="/">
				<?php
		  		if($settings->get_setting('logo_link')){
					echo '<img class="mr-2" src="'.htmlspecialchars($settings->get_setting('logo_link')).'" alt="" width="40" />';
				}
				?>
				<span class="text-blue-600 font-bold text-2xl"><?php echo htmlspecialchars($settings->get_setting('site_name')); ?></span>
				</a>
				<div class="bg-white rounded-lg shadow-md overflow-hidden">
				  <div class="p-6 sm:p-8">
					<?php
					$formwriter = $page->getFormWriter('form1');
					$validation_rules = array();
					$validation_rules['usr_password']['required']['value'] = 'true';
					$validation_rules['usr_password']['minlength']['value'] = 5;
					$validation_rules['usr_password_again']['required']['value'] = 'true';
					$validation_rules['usr_password_again']['required']['message'] = "'You must enter your password twice to confirm'";
					$validation_rules['usr_password_again']['equalTo']['value'] = "'#usr_password'";
					$validation_rules['usr_password_again']['equalTo']['message'] = "'Your password did not match the one you entered above'";
					echo $formwriter->begin_form("", "post", "/password-reset-2", true);
					echo $formwriter->hiddeninput('act_code',$page_vars['act_code']);
					?>
					<h5 class="text-center text-xl font-semibold text-gray-900 mb-4">Set new password</h5>
					<div class="mt-3">
					  <div class="mb-4">
						<label for="usr_password" class="block text-sm font-medium text-gray-700 mb-2">New Password</label>
						<input class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" type="password" name="usr_password" id="usr_password" />
					  </div>
					  <div class="mb-4">
						<label for="usr_password_again" class="block text-sm font-medium text-gray-700 mb-2">Confirm Password</label>
						<input class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" type="password" name="usr_password_again" id="usr_password_again" />
					  </div>
					  <button class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded transition" type="submit" name="submit">Set password</button>
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

	$page->public_footer($foptions=array('track'=>TRUE, 'header_only' => true));

?>
