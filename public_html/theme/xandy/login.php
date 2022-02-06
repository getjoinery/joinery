<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublic.php');
	require_once (LibraryFunctions::get_logic_file_path('login_logic.php'));
	

	$page = new PublicPage(TRUE);
	$hoptions=array(
		'is_valid_page' => $is_valid_page,
		'title'=>'Log In'
		);
	$page->public_header($hoptions,NULL);

	echo PublicPage::BeginPage('Log In');

	?>
	<script type="text/javascript">
	$(document).ready(function() {

	<?php if (!$email) { ?>
			$('#email').focus();
	<?php } else { ?>
			$('#password').focus();
	<?php } ?>
	});
	</script>

		<div class="section padding-top-20">
			<div class="container">
				<div class="row col-spacing-50">
					<!-- Blog Posts -->
					<div class="col-12 col-lg-8">
				<?php
				if(isset($_GET['msgtext'])){
					if (array_key_exists($_GET['msgtext'], $LOGIN_MESSAGES)) {
						echo '<div class="status_warning">'.htmlspecialchars($LOGIN_MESSAGES[$_GET['msgtext']]).'</div>';
					}
				}

				if ($email) {
					$forgot_link = '/password-reset-1?e=' . rawurlencode(htmlspecialchars($email));
				} else {
					$forgot_link = '/password-reset-1';
				}

				if(isset($_GET['retry'])){
					echo '<div class="status_error">Your username or password was incorrect.  Please try again below, or sign up if you don\'t have an account.  If you forgot your password, <a href="' . $forgot_link . '">click here</a> and we\'ll send you a new one.</div>';
				}

				$formwriter = new FormWriterPublic("form1", TRUE, TRUE);

				$validation_rules = array();
				$validation_rules['email']['required']['value'] = 'true';
				$validation_rules['password']['required']['value'] = 'true';
				echo $formwriter->set_validate($validation_rules);							
				
				echo $formwriter->begin_form("", "post", "/login");

				echo $formwriter->textinput("Email", "email", "ctrlHolder", 20, htmlspecialchars($email), '',255, '');

				echo $formwriter->passwordinput("Password (<a href=\"$forgot_link\">forgot?</a>)", "password", "ctrlHolder", 20, '','', 255, '');
				echo $formwriter->checkboxinput("Remember me", "setcookie", "ctrlHolder", "normal", 'yes', "yes", '');
				echo $formwriter->new_form_button('Log In', 'button button-lg button-dark');			
				echo $formwriter->end_form();
				?>

					</div>
					<!-- end Blog Posts -->

					<!-- Blog Sidebar -->
					<div class="col-12 col-lg-4 sidebar-wrapper">
						<!-- Sidebar box 1 - About me -->
						<div class="sidebar-box">
							<div class="text-center">
								<h6 class="font-small font-weight-normal uppercase">Help</h6>
								
								
								<!--<img class="img-circle-md margin-bottom-20" src="../assets/images/img-circle-medium.jpg" alt="">
								<p>Aenean massa. Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.</p>-->
							</div>
							<ul class="list-category">
								<li><a href="/register<?php if(isset($_GET['m'])){ echo '?m='.htmlspecialchars($_GET['m']); } ?>">Register here</a></li>
								<li><a href="<?php echo $forgot_link ?>">Set or change password here</a></li>
								<li><a href="<?php echo $forgot_link ?>">Forgot password</a></li>
							</ul>
						</div>


					</div>
					<!-- end Blog Sidebar -->
				</div><!-- end row -->
			</div><!-- end container -->
		</div>
		
	<?php
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
