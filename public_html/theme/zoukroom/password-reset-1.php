<?php
	require_once('includes/PublicPage.php');
	require_once('includes/FormWriterPublic.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	$logic_path = LibraryFunctions::get_logic_file_path('password-reset-1_logic.php');
	require_once ($logic_path);	


	$page = new PublicPage();
	$hoptions=array(
		'title'=>'Password Reset', 
		'disptitle'=>'Password Reset Step 1 of 2',
		'crumbs'=>array('Home'=>'/', 'Password Reset'=>''),		
		'showmap'=>FALSE,
		'showheader'=>TRUE, 
		'sectionstyle'=>'neutral');	
	$page->public_header($hoptions,NULL);
	echo PublicPage::BeginPage('Reset Password - Step 1 of 2');

	if($message){
		echo $message;
	}
	else{
		$formwriter = new FormWriterPublic("form1");
		echo $formwriter->begin_form("uniForm", "post", "/password-reset-1"); 
		echo '<fieldset class="inlineLabels">';
		echo $formwriter->textinput("Enter the Email Address you registered with", "email", "ctrlHolder", 20, htmlspecialchars($email), '', 64, NULL);
		echo $formwriter->start_buttons();
		echo $formwriter->new_form_button('Submit', '', 'submit1');
		echo $formwriter->end_buttons();
		echo '</fieldset>';
		echo $formwriter->end_form();
	}

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));

}
?>
