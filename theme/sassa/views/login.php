<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/PathHelper.php');
PathHelper::requireOnce('includes/ThemeHelper.php');
	ThemeHelper::includeThemeFile('includes/PublicPage');
	require_once (LibraryFunctions::get_logic_file_path('login_logic.php'));
	
	$page_vars = login_logic($_GET, $_POST);
	
	if ($email) {
		$forgot_link = '/password-reset-1?e=' . rawurlencode(htmlspecialchars($email));
	} else {
		$forgot_link = '/password-reset-1';
	}

	$page = new PublicPage();
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

<div class="w-full flex flex-col sm:justify-center items-center ">
  <div class="w-full sm:max-w-md p-5 mx-auto">
	<?php 

		foreach($page_vars['display_messages'] AS $display_message) {
			if($display_message->identifier == 'loginbox') {	
				echo PublicPage::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
			}
		}   		
		
		$formwriter = LibraryFunctions::get_formwriter_object('form1');

		$validation_rules = array();
		$validation_rules['email']['required']['value'] = 'true';
		$validation_rules['password']['required']['value'] = 'true';
		echo $formwriter->set_validate($validation_rules);	
		echo $formwriter->begin_form('form1', 'POST', '/login');
	?>
      <div class="mb-4">
		<?php echo $formwriter->textinput("Email", "email", NULL , 20, htmlspecialchars($page_vars['email']), '',255, ''); ?>

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
			echo $formwriter->new_form_button('Log In', 'th_btn');		
	
	  ?>
      </div>
      <div class="mt-6 text-center">
        <a href="/pricing" class="underline">Sign up for an account</a>
      </div>
    <?php echo $formwriter->end_form();	 ?>
  </div>
</div>

	<?php
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

?>
