<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPage.php', '/includes'));

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

	//echo PublicPage::BeginPage('You are now logged out');
		
	//echo PublicPage::BeginPanel();
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
                <div class="text-center"><!--<img class="d-block mx-auto mb-4" src="../../../assets/img/icons/spot-illustrations/45.png" alt="shield" width="100" />-->
                  <h4>Logged out.</h4>
                  <p>You are <br />now successfully signed out.</p><a class="btn btn-primary btn-sm mt-3" href="/login"><span class="fas fa-chevron-left me-1" data-fa-transform="shrink-4 down-1"></span>Return to Login</a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </main>

	<?php
	//echo PublicPage::EndPanel();
	//echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'header_only' => true));

?>
