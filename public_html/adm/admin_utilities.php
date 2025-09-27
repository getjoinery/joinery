<?php
	
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/settings_class.php'));
	require_once(PathHelper::getIncludePath('data/email_templates_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> NULL,
		'page_title' => 'Utilities',
		'readable_title' => 'Utilities',
		'breadcrumbs' => array(
			'Utilities'=>'', 
		),
		'session' => $session,
	)
	);	

	$pageoptions['title'] = "Utilities";
	$page->begin_box($pageoptions);

	echo '<h3>General Utilities</h3>';
	
	echo '<h4>Update database script</h4>';
	echo '<a href="/utils/update_database">Update database</a><br>';
	echo '<a href="/utils/update_database?verbose=1">Update database (verbose)</a><br>';
	echo '<a href="/utils/update_database">Upgrade database (fix mismatched column types)</a><br>';
	echo '<a href="/utils/update_database">Cleanup database (delete unused fields)</a><br>';

	$page->end_box();

	$page->admin_footer();

?>
