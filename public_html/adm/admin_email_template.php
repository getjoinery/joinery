<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/email_templates_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$email_template = new EmailTemplateStore($_GET['emt_email_template_id'], TRUE);

	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'email-templates',
		'breadcrumbs' => array(
			'Email Templates'=>'/admin/admin_email_templates', 
			$email_template->get('emt_name')=>'',
		),
		'session' => $session,
	)
	);	
	
	$options['title'] = $email_template->get('emt_name');
	$options['altlinks'] = array('Edit Template' => '/admin/admin_email_template_edit?emt_email_template_id='.$email_template->key,
								'Delete Template' => '/admin/admin_email_template_permanent_delete?emt_email_template_id='.$email_template->key);
	$page->begin_box($options);
	
	if($email_template->get('emt_type') == EmailTemplateStore::TEMPLATE_TYPE_OUTER){
		echo '<strong>Type:</strong> Outer template<br />';
	}
	else if($email_template->get('emt_type') == EmailTemplateStore::TEMPLATE_TYPE_INNER){
		echo '<strong>Type:</strong> Inner template<br />';
	} 	
	else if($email_template->get('emt_type') == EmailTemplateStore::TEMPLATE_TYPE_FOOTER){
		echo '<strong>Type:</strong> Footer template<br />';
	}

	//echo '<strong>From:</strong> ('.$sender->key.') <a href="/admin/admin_user?usr_user_id='.$sender->key.'">'.$sender->display_name() .'</a><br />';	
	echo '<strong>Created:</strong> '.LibraryFunctions::convert_time($email_template->get('emt_create_time'), 'UTC', $session->get_timezone()) .'<br />';
	
	
	
	
	echo '<iframe src="/ajax/email_template_preview_ajax?emt_email_template_id='.$email_template->key.'" width="100%" height="500" style="border:1px solid black;"></iframe>';
	//echo '<strong>Content:</strong><br /> '.$email_template->get('emt_body').'<br />';	

	$page->end_box();		
	
	$page->admin_footer();
?>


