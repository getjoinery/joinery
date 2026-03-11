<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('password-reset-1_logic.php', 'logic'));

	$page_vars = process_logic(password_reset_1_logic($_GET, $_POST));
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
					  <h4 class="text-2xl font-semibold text-gray-900 mb-2">Please check your email!</h4>
					  <p class="text-gray-600 mb-4">An email has been sent to you. Please click on the included link to reset <span class="whitespace-nowrap">your password.</span>
					  </p>
					  <a class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded transition text-sm" href="/login">
					    <span class="fas fa-chevron-left mr-2" data-fa-transform="shrink-4 down-1"></span>Return to login
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
		$formwriter = $page->getFormWriter('form1', ['action' => '/password-reset-1', 'method' => 'post']);

		?>
		<main class="min-h-screen" id="top">
		  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
			<div class="flex items-center justify-center min-h-screen py-6 text-center">
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
					<h5 class="text-xl font-semibold text-gray-900 mb-1">Forgot your password?</h5>
					<small class="text-gray-600">Enter your email and we'll send you a reset link.</small>
					<div class="mt-4">
						<?php
						echo $formwriter->begin_form();

						?>
					  <label for="email" class="block text-sm font-medium text-gray-700 mb-2 text-left">Email address</label>
					  <input class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" type="email" name="email" id="email" />
					  <div class="mb-3"></div>
					  <button class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded transition" type="submit" name="submit">Send reset link</button>
					  <?php echo $formwriter->end_form(); ?>
					</div>
					<a class="text-sm text-blue-600 hover:text-blue-700 mt-3 inline-block" href="/login">Back to login</a>
				  </div>
				</div>
			  </div>
			</div>
		  </div>
		</main>
		<?php

	}

	$page->public_footer($foptions=array('track'=>TRUE, 'header_only' => true));

?>
