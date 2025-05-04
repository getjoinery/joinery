<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPage.php', '/includes'));

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Page not found', 
		'is_404' => 1,
		'header_only' => true,
	);
	$page->public_header($hoptions);
	//echo PublicPage::BeginPage('Page not found');
	//echo PublicPage::BeginPanel();
	?>

    <main class="main" id="top">
      <div class="container" data-layout="container">
        <div class="row flex-center min-vh-100 py-6 text-center">
          <div class="col-sm-10 col-md-8 col-lg-6 col-xxl-5">
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
                <div class="fw-black lh-1 text-300 fs-error">404</div>
                <p class="lead mt-4 text-800 font-sans-serif fw-semi-bold w-md-75 w-xl-100 mx-auto">The page you're looking for is not found.</p>
                <hr />
                <p>Make sure the address is correct and that the page hasn't moved.</p><a class="btn btn-primary btn-sm mt-3" href="/"><span class="fas fa-home me-2"></span>Take me home</a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </main>

	<?php
	//echo PublicPage::EndPanel();
	//echo PublicPage::EndPage();

	$page->public_footer(array('track'=>TRUE, 'header_only' => true, 'is_404'=> 1));
?>