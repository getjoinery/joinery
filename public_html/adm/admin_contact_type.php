<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/contact_types_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$contact_type = new ContactType($_REQUEST['ctt_contact_type_id'], TRUE);

	if($_REQUEST['action'] == 'delete'){
		$contact_type->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));		
		$contact_type->soft_delete();

		header("Location: /admin/admin_contact_types");
		exit();				
	}
	else if($_REQUEST['action'] == 'undelete'){
		$contact_type->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));		
		$contact_type->undelete();

		header("Location: /admin/admin_contact_types");
		exit();				
	}

	$session->set_return();


	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'contact-types',
		'breadcrumbs' => array(
			'Emails'=>'/admin/admin_emails', 
			'Contact Types'=>'/admin/admin_contact_types', 
			'Contact Type: '.$contact_type->get('ctt_name') => '',
		),
		'session' => $session,
	)
	);	



	$options['title'] = 'Contact Type: '.$contact_type->get('ctt_name');
	$options['altlinks'] = array();
	if(!$contact_type->get('ctt_delete_time')) {
		$options['altlinks'] += array('Edit Contact Type' => '/admin/admin_contact_type_edit?ctt_contact_type_id='.$contact_type->key);
	}
	
	if(!$contact_type->get('ctt_delete_time') && $_SESSION['permission'] >= 8) {
		$options['altlinks']['Soft Delete'] = '/admin/admin_contact_type?action=delete&ctt_contact_type_id='.$contact_type->key;
	}
		
	$page->begin_box($options);
	
	echo '<h3>'.$contact_type->get('ctt_name').'</h3>'; 
	
	if($contact_type->get('ctt_delete_time')){
		echo 'Status: Deleted at '.LibraryFunctions::convert_time($contact_type->get('ctt_delete_time'), 'UTC', $session->get_timezone()).'<br />';
	}
	else{
		echo 'Status: Active'.'<br />';
	}
	
	if($contact_type->get('ctt_mailchimp_list_id')){
		echo 'Mailchimp integration active.  Mailchimp ID: '.$contact_type->get('ctt_mailchimp_list_id').'<br />';
	}
	else{
		echo 'Mailchimp integration inactive.';
	}
	echo '<br><br>';
	?><p><?php echo $contact_type->get('ctt_description'); ?></p>


<?php 
	$page->end_box();

	$page->admin_footer();
?>
