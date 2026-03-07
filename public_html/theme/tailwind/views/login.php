<?php

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
	$email = $page_vars['email'] ?? null;
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

					foreach($page_vars['display_messages'] AS $display_message) {
						if($display_message->identifier == 'loginbox') {
							echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
						}
					}

					$formwriter = $page->getFormWriter('form1', ['action' => '/login', 'method' => 'POST']);

					echo $formwriter->begin_form();
				?>

                <div class="flex items-center justify-between mb-4">
                  <div>
                    <h5 class="text-xl font-semibold text-gray-900">Log in</h5>
                  </div>
                  <div class="text-sm text-gray-600">
                    <span class="mb-0">or</span>
                    <span><a href="/register<?php if(isset($_GET['m'])){ echo '?m='.htmlspecialchars($_GET['m']); } ?>" class="text-blue-600 hover:text-blue-700">Create an account</a></span>
                  </div>
                </div>

                  <div class="mb-4">
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email address</label>
                    <input class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" name="email" id="email" type="email" />
                  </div>
                  <div class="mb-4">
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-2">Password</label>
                    <input class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" name="password" id="password" type="password" />
                  </div>
                  <div class="flex items-center justify-between">
                    <div>
                      <div class="flex items-center mb-0">
                        <input class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded" type="checkbox" id="setcookie" name="setcookie" checked="checked" value="yes" />
                        <label class="ml-2 text-sm text-gray-700 mb-0" for="setcookie">Remember me</label>
                      </div>
                    </div>
                    <div><a class="text-sm text-blue-600 hover:text-blue-700" href="<?php echo htmlspecialchars($forgot_link); ?>">Forgot Password?</a></div>
                  </div>
                  <div class="mt-4">
                    <button class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded transition" type="submit" name="submit">Log in</button>
                  </div>

				<?php echo $formwriter->end_form();	 ?>

              </div>
            </div>
          </div>
        </div>
      </div>
    </main>

	<?php
	$page->public_footer($foptions=array('track'=>TRUE, 'header_only' => true));

?>
