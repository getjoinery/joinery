<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php'));
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php'));
	require_once (LibraryFunctions::get_logic_file_path('login_logic.php'));
	
	if ($email) {
		$forgot_link = '/password-reset-1?e=' . rawurlencode(htmlspecialchars($email));
	} else {
		$forgot_link = '/password-reset-1';
	}

	$page = new PublicPageTW(TRUE);
	$hoptions=array(
		'is_valid_page' => $is_valid_page,
		'title'=>'Log In'
		);
	$page->public_header($hoptions,NULL);

	echo PublicPageTW::BeginPage('Log In');

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

<div class="w-full flex flex-col sm:justify-center items-center ">
  <div class="w-full sm:max-w-md p-5 mx-auto">
	<?php 
		if(isset($_GET['msgtext'])){
			if (array_key_exists($_GET['msgtext'], $LOGIN_MESSAGES)) {
				echo PublicPageTW::alert('Login warning', htmlspecialchars($LOGIN_MESSAGES[$_GET['msgtext']]), 'warn');
			}
		}
		if(isset($_GET['retry'])){
			echo PublicPageTW::alert('Login warning', 'Your username or password was incorrect.  Please try again below, or sign up if you don\'t have an account.  If you forgot your password, <a href="' . $forgot_link . '">click here</a> and we\'ll send you a new one.', 'warn');
		}
		
		
		$formwriter = new FormWriterPublicTW("form1", TRUE, TRUE);

		$validation_rules = array();
		$validation_rules['email']['required']['value'] = 'true';
		$validation_rules['password']['required']['value'] = 'true';
		echo $formwriter->set_validate($validation_rules);	
		echo $formwriter->begin_form('form1', 'POST', '/login_process');
	?>
      <div class="mb-4">
		<?php echo $formwriter->textinput("Email", "email", NULL , 20, htmlspecialchars($email), '',255, ''); ?>

      </div>
      <div class="mb-4">
		<?php echo $formwriter->passwordinput("Password", "password", NULL, 20, '','', 255, ''); ?>
      </div>
      <div class="mt-6 flex items-center justify-between">
        <div class="flex items-center">
          <?php echo $formwriter->checkboxinput("Remember me", "setcookie", NULL, "normal", 'yes', "yes", ''); ?>
        </div>
        <a href="<?php echo $forgot_link; ?>" class="text-sm"> Forgot your password? </a>
      </div>
      <div class="mt-6">
	  <?php
			echo $formwriter->new_form_button('Log In', 'primary', 'full');			
	  ?>
      </div>
      <div class="mt-6 text-center">
        <a href="/register<?php if(isset($_GET['m'])){ echo '?m='.htmlspecialchars($_GET['m']); } ?>" class="underline">Sign up for an account</a>
      </div>
    <?php echo $formwriter->end_form();	 ?>
  </div>
</div>

	<?php
	echo PublicPageTW::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
