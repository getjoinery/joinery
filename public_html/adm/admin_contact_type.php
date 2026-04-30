<?php

	require_once(PathHelper::getIncludePath('includes/Activation.php'));

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('data/contact_types_class.php'));

	require_once(PathHelper::getIncludePath('adm/logic/admin_contact_type_logic.php'));

	$page_vars = process_logic(admin_contact_type_logic($_GET, $_POST));

	extract($page_vars);

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

	echo '<h3>'.htmlspecialchars($contact_type->get('ctt_name')).'</h3>';

	if($contact_type->get('ctt_delete_time')){
		echo 'Status: Deleted at '.LibraryFunctions::convert_time($contact_type->get('ctt_delete_time'), 'UTC', $session->get_timezone()).'<br />';
	}
	else{
		echo 'Status: Active'.'<br />';
	}

	if($contact_type->get('ctt_provider_list_id')){
		echo 'Mailing list integration active.  Remote List ID: '.htmlspecialchars($contact_type->get('ctt_provider_list_id')).'<br />';
	}
	else{
		echo 'Mailing list integration inactive.';
	}
	echo '<br><br>';
	?><p><?php echo $contact_type->get('ctt_description'); ?></p>

<?php
	$page->end_box();

	$page->admin_footer();
?>
