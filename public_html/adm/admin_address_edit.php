<?php

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SystemClass.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/FormWriterMaster.php');
	

	$session = SessionControl::get_instance();
	$session->check_permission(8);




	$address_id = $_REQUEST['usa_address_id'];

	if ($address_id == FALSE) {
		throw new SystemInvalidFormError('The form is invalid.');
	}

	$address = new Address($address_id, TRUE);




	if($_POST){

		$user_id=$address->get('usa_usr_user_id');

		$address = Address::CreateAddressFromForm($_POST, $user_id, $address);
		
		
		LibraryFunctions::redirect('/admin/admin_user?usr_user_id='. $user_id );
		exit;



	} 


	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 1,
		'page_title' => 'Address Edit',
		'readable_title' => 'Address Edit',
		'breadcrumbs' => NULL,
		'session' => $session,
	)
	);
	
	$pageoptions['title'] = 'Edit Address';
	$page->begin_box($pageoptions);
	?>

			<section class="contact-page-area section-gap">
				<div class="container"> 
			

				<?php
				
				$formwriter = new FormWriterMaster("form1");
				
				$validation_rules = array();
				$validation_rules['usa_type']['required']['value'] = 'true';
				$validation_rules['usa_city']['required']['value'] = 'true';
				$validation_rules['usa_state']['required']['value'] = 'true';
				$validation_rules['usa_zip_code_id']['required']['value'] = 'true';
				echo $formwriter->set_validate($validation_rules);					
				
				echo $formwriter->begin_form("", "post", "/admin/admin_address_edit");
					
			
				echo '<div id="newaddressblock">';
				Address::PlainForm($formwriter, $address);
				echo '</div>';
			
					echo $formwriter->start_buttons();
					echo $formwriter->new_form_button('Submit');
					echo $formwriter->end_buttons();
					echo '</fieldset>';
			
				echo $formwriter->end_form();
			
				$page->endtable();
				?>
	    </div>
	</section>
	
	<?php
	$page->end_box();
	$page->admin_footer();

?>
