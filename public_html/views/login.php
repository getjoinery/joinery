<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');
	require_once (LibraryFunctions::get_logic_file_path('login_logic.php'));
	


$page = new PublicPage(TRUE);
$hoptions=array(
	'title'=>'Log In'
	);
$page->public_header($hoptions,NULL);

echo PublicPage::BeginPage();

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

<div uk-grid>
    <div class="uk-width-2-3@m"><div style="padding: 20px">

<!-- begin: Main content -->
<div id="content-body" class="clear-block centered-form">

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

		?>


					<?php

					$formwriter = new FormWriterPublic("form1", TRUE, TRUE);

					$validation_rules = array();
					$validation_rules['email']['required']['value'] = 'true';
					$validation_rules['password']['required']['value'] = 'true';
					echo $formwriter->set_validate($validation_rules);							
					
					echo $formwriter->begin_form("uniForm", "post", "/login");
					?>
					<div class="body-title bottom-border">
						<h2>Log In</h2>
					</div>
					<?php
					echo '<fieldset class="inlineLabels">';
					echo $formwriter->textinput("Email", "email", "ctrlHolder", 20, htmlspecialchars($email), '',255, '');

					echo $formwriter->passwordinput("Password (<a href=\"$forgot_link\">forgot?</a>)", "password", "ctrlHolder", 20, '','', 255, '');
					echo $formwriter->checkboxinput("Remember me", "setcookie", "ctrlHolder", "normal", 'yes', "yes", '');
					echo $formwriter->start_buttons();
					echo $formwriter->new_form_button('Log In', '');			
					echo $formwriter->end_buttons();
					echo '</fieldset>';
					echo $formwriter->end_form();

					?>


</div><!-- end: #content-body -->
	</div>
	</div>
	<div class="uk-width-1-3@m"><div style="padding: 20px">
						<div class="body-title bottom-border">
						<h2>Help</h2>
					</div>
							<div class="post-links"><a href="/register<?php if(isset($_GET['m'])){ echo '?m='.htmlspecialchars($_GET['m']); } ?>">Register here</a></div>
							<div> <a href="<?php echo $forgot_link ?>">Set or change password here</a></div>
	
		</div>
	</div>
</div>	

<?php
echo PublicPage::EndPage();
$page->public_footer($foptions=array('track'=>TRUE));

?>
