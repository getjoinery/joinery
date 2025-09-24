<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
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

	//echo PublicPage::BeginPage('Log In');

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
			  
			  
			  
			  
                <div class="row flex-between-center mb-2">
                  <div class="col-auto">
                    <h5>Log in</h5>
                  </div>
                  <div class="col-auto fs-10 text-600"><span class="mb-0 undefined">or</span> <span><a href="/register<?php if(isset($_GET['m'])){ echo '?m='.htmlspecialchars($_GET['m']); } ?>">Create an account</a></span></div>
                </div>
                
                  <div class="mb-3">
                    <input class="form-control" name="email" id="email" type="email" placeholder="Email address" />
                  </div>
                  <div class="mb-3">
                    <input class="form-control" name="password" id="password" type="password" placeholder="Password" />
                  </div>
                  <div class="row flex-between-center">
                    <div class="col-auto">
                      <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="setcookie" name="setcookie" checked="checked" value="yes" />
                        <label class="form-check-label mb-0" for="setcookie">Remember me</label>
                      </div>
                    </div>
                    <div class="col-auto"><a class="fs-10" href="<?php echo $forgot_link; ?>">Forgot Password?</a></div>
                  </div>
                  <div class="mb-3">
                    <button class="btn btn-primary d-block w-100 mt-3" type="submit" name="submit">Log in</button>
                  </div>
                <!--
                <div class="position-relative mt-4">
                  <hr />
                  <div class="divider-content-center">or log in with</div>
                </div>
                <div class="row g-2 mt-2">
                  <div class="col-sm-6"><a class="btn btn-outline-google-plus btn-sm d-block w-100" href="#"><span class="fab fa-google-plus-g me-2" data-fa-transform="grow-8"></span> google</a></div>
                  <div class="col-sm-6"><a class="btn btn-outline-facebook btn-sm d-block w-100" href="#"><span class="fab fa-facebook-square me-2" data-fa-transform="grow-8"></span> facebook</a></div>
                </div>
				-->
				<?php echo $formwriter->end_form();	 ?>				
				
				
              </div>
            </div>
          </div>
        </div>
      </div>
    </main>

	<?php
	//echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'header_only' => true));

?>
