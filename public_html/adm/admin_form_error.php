<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/log_form_errors_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');


	$session = SessionControl::get_instance();
	$session->check_permission(9);
	$session->set_return();


	$form_error = new FormError($_GET['lfe_log_form_error_id'], TRUE);
	$user = new User($form_error->get('lfe_usr_user_id'), TRUE);
	
	$page = new AdminPage();

	$settings = Globalvars::get_instance();
	$CDN = $settings->get_setting('CDN');
	$webDir = $settings->get_setting('webDir');

	$page->admin_header(	
	array(
		'menu-id'=> null,
		'page_title' => 'Error',
		'readable_title' => 'Error',
		'breadcrumbs' => NULL,
		'session' => $session,
	)
	);

	if ($form_error->get('lfe_page')) {
		echo '<p>Page w/Error: ' . $form_error->get('lfe_page') . '</p>';
	}

	echo '<pre>'. $form_error->display_time($session) . "\n\n" . 'Errors:<br>-------------------<br>' . htmlspecialchars($form_error->get('lfe_error')).'<br><br>';
	echo 'All formfields:<br>-------------------<br><br>' . htmlspecialchars($form_error->get('lfe_form')).'</pre>';
	
	$page->admin_footer();
