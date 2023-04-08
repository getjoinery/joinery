<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/page_contents_class.php');
	
	
if ($_POST['confirm']){

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$pac_page_content_id = LibraryFunctions::fetch_variable('pac_page_content_id', NULL, 1, 'You must provide a page_content to delete here.');
	$confirm = LibraryFunctions::fetch_variable('confirm', NULL, 1, 'You must confirm the action.');	
	
	if ($confirm) {
		$page_content = new PageContent($pac_page_content_id, TRUE);
		$page_content->authenticate_write($session);
		$page_content->permanent_delete();
	}

	//NOW REDIRECT
	$session = SessionControl::get_instance();
	$returnurl = $session->get_return();
	header("Location: $returnurl");
	exit();

}
else{
	$pac_page_content_id = LibraryFunctions::fetch_variable('pac_page_content_id', NULL, 1, 'You must provide a page_content to edit.');

	$page_content = new PageContent($pac_page_content_id, TRUE);
	
	$session = SessionControl::get_instance();
	$session->set_return("/admin/admin_page_contents");

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'pages',
		'page_title' => 'Delete Page Content',
		'readable_title' => 'Delete Page Content',
		'breadcrumbs' => array(
			'Page Contents'=>'/admin/admin_page_contents', 
			'Delete ' . $page_content->get('pac_location_name') => '',
		),
		'session' => $session,
	)
	);

	
	$pageoptions['title'] = 'Delete Page Content '.$page_content->get('pac_location_name');
	$page->begin_box($pageoptions);


	$formwriter = new FormWriterMaster("form1");
	echo $formwriter->begin_form("form", "post", "/admin/admin_page_content_permanent_delete");

	echo '<fieldset><h4>Confirm Delete</h4>';
		echo '<div class="fields full">';
		echo '<p>WARNING:  This will permanently delete this page_content ('.$page_content->get('pac_location_name') . ').</p>';

	echo $formwriter->hiddeninput("confirm", 1);
	echo $formwriter->hiddeninput("pac_page_content_id", $pac_page_content_id);

			echo $formwriter->start_buttons();
		echo $formwriter->new_form_button('Submit');
		echo $formwriter->end_buttons();

		echo '</div>';
	echo '</fieldset>';
	echo $formwriter->end_form();

	$page->end_box();

	$page->admin_footer();


}
?>
