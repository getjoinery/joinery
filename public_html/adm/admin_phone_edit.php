<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('/includes/Activation.php');
	PathHelper::requireOnce('/includes/ErrorHandler.php');
	
	PathHelper::requireOnce('/includes/AdminPage.php');
	PathHelper::requireOnce('/includes/LibraryFunctions.php');
	PathHelper::requireOnce('/includes/SessionControl.php');

	PathHelper::requireOnce('/data/phone_number_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$phn_phone_number_id = LibraryFunctions::fetch_variable('phn_phone_number_id', NULL, 0, '');
	
	$phone_number = NULL;
	if($phn_phone_number_id){
		$phone_number = new PhoneNumber($phn_phone_number_id, TRUE);
		$user_id = $phone_number->get('phn_usr_user_id');
	}
	else{
		$user_id = LibraryFunctions::fetch_variable('usr_user_id', NULL, 1, 'You must pass a user id');
	}


if($_POST){

	$phone_number = PhoneNumber::CreateFromForm($_POST, $user_id, $phone_number, FALSE);


	
	//NOW REDIRECT
	LibraryFunctions::redirect('/admin/admin_user?usr_user_id='. $user_id );
	return;


}
else{
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'users',
		'page_title' => 'Phone Edit',
		'readable_title' => 'Phone Edit',
		'breadcrumbs' => NULL,
		'session' => $session,
	)
	);


	//PhoneNumber::ValidateJS();
?>


			<section class="contact-page-area section-gap">
				<div class="container"> 
<?php if (isset($phn_phone_number_id)) { ?>
		   <h3>Edit Phone Number</h3>
<?php } else { ?>
		   <h3>Add Phone Number</h3>
<?php } 

	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	echo $formwriter->begin_form("", "post", "/admin/admin_phone_edit");
 
	PhoneNumber::PlainForm($formwriter, $phone_number);
	echo $formwriter->hiddeninput('usr_user_id', $user_id);
	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();


	echo $formwriter->end_form();

	$page->endtable();
?>
        
    </div>
</section>

<?php
	$page->admin_footer();
}
?>
