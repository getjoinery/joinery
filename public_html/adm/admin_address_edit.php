<?php

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SystemClass.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/AdminPage.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	

	$session = SessionControl::get_instance();
	$session->check_permission(8);


	

	$address_id = $_REQUEST['usa_address_id'];
	$address = NULL;
	if($address_id){
		$address = new Address($address_id, TRUE);
		$user_id=$address->get('usa_usr_user_id');
	}
	else{
		$user_id = LibraryFunctions::fetch_variable('usr_user_id', NULL, 1, 'You must pass a user id');
	}

	if($_POST){

		$address = Address::CreateAddressFromForm($_POST, $user_id, $address);
		
		if(!$address_id){
			$address->set('usa_is_default', TRUE);
			$address->save();
		}
		
		LibraryFunctions::redirect('/admin/admin_user?usr_user_id='. $user_id );
		exit;

	} 


	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'users',
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
				
				$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
				
				$validation_rules = array();
				$validation_rules['usa_type']['required']['value'] = 'true';
				$validation_rules['usa_city']['required']['value'] = 'true';
				$validation_rules['usa_state']['required']['value'] = 'true';
				$validation_rules['usa_zip_code_id']['required']['value'] = 'true';
				echo $formwriter->set_validate($validation_rules);					
				
				echo $formwriter->begin_form("", "post", "/admin/admin_address_edit");
					
			
				echo '<div id="newaddressblock">';
				echo $formwriter->hiddeninput('usr_user_id', $user_id);
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
