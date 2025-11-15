<?php

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	$page = new PublicPage();
	$is_valid_page = false; // This is a 404 page, so page is not valid
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Page not found',
		'is_404' => 1,
		'header_only' => true,
	);
	$page->public_header($hoptions);
	?>

    <main class="min-h-screen" id="top">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-center min-h-screen py-6 text-center">
          <div class="w-full max-w-2xl">
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
                <div class="text-gray-300 font-black text-9xl leading-none">404</div>
                <p class="text-xl font-semibold text-gray-800 mt-4 mx-auto" style="max-width: 75%;">The page you're looking for is not found.</p>
                <hr class="my-4" />
                <p class="text-gray-600">Make sure the address is correct and that the page hasn't moved.</p>
                <?php
                // DEBUG: Show detailed routing information
                $debug_info = $GLOBALS['route_debug_info'] ?? null;
                $requested_path = $debug_info['requested_path'] ?? $_REQUEST['__route'] ?? $_SERVER['REQUEST_URI'] ?? 'unknown';

                echo '<div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mt-4 text-left"><strong>ROUTING DEBUG INFO:</strong><br>';
                echo 'Requested path: <code class="bg-gray-100 px-2 py-1 rounded">' . htmlspecialchars($requested_path) . '</code><br>';
                echo 'Request method: <strong>' . htmlspecialchars($debug_info['request_method'] ?? $_SERVER['REQUEST_METHOD'] ?? 'unknown') . '</strong><br>';

                if ($debug_info) {
                    echo 'Attempted view file: <code class="bg-gray-100 px-2 py-1 rounded">' . htmlspecialchars($debug_info['attempted_view_file']) . '</code><br>';
                    echo 'Full file path tried: <code class="bg-gray-100 px-2 py-1 rounded">' . htmlspecialchars($debug_info['attempted_full_path']) . '</code><br>';
                    echo 'File exists: <strong>' . (file_exists($debug_info['attempted_full_path']) ? 'YES' : 'NO') . '</strong><br>';
                }

                if ($requested_path === '/product') {
                    echo '<hr class="my-2"><strong>SPECIFIC ISSUE:</strong> No route defined for "/product" path.<br>';
                    echo '• Available routes: "/product/{slug}" for viewing specific products<br>';
                    echo '• Form submissions to "/product" need a dedicated route or should redirect to cart handling.<br>';
                    echo '• This form is trying to POST to a non-existent route.';
                }
                echo '</div>';
                ?>
                <a class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded transition text-sm mt-4" href="/">
                  <span class="fas fa-home mr-2"></span>Take me home
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </main>

	<?php

	$page->public_footer(array('track'=>TRUE, 'header_only' => true, 'is_404'=> 1));
?>
