<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	$settings = Globalvars::get_instance();
	$session = SessionControl::get_instance();
	$session->logout();

	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Log Out',
		'header_only' => true,
		),
	NULL);

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
                  <h4 class="text-2xl font-semibold text-gray-900 mb-2">Logged out.</h4>
                  <p class="text-gray-600 mb-4">You are <br />now successfully signed out.</p>
                  <a class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded transition text-sm" href="/login">
                    <span class="fas fa-chevron-left mr-2" data-fa-transform="shrink-4 down-1"></span>Return to Login
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </main>

	<?php
	$page->public_footer($foptions=array('track'=>TRUE, 'header_only' => true));

?>
