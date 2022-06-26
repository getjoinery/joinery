<?php
	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/DbConnector.php');
	require_once( __DIR__ . '/../data/page_contents_class.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));


	$session = SessionControl::get_instance();
	$session->check_permission(5);

	$page_content = new PageContent($_GET['pac_page_content_id'], TRUE);
	
	$page = new PublicPageTW(TRUE);
	$page->public_header(array(
		'is_valid_page' => FALSE,
		'title' => $page_content->get('pac_title')
	));
	echo PublicPageTW::BeginPage($page_content->get('pac_title'));
	echo PublicPageTW::BeginPanel();
	
	echo '
    <div class="text-lg prose max-w-prose mx-auto">';
	echo '<div>'. $page_content->get_filled_content() . '</div>';
	echo '</div>';
	echo PublicPageTW::EndPanel();
	echo PublicPageTW::EndPage();
	$page->public_footer($foptions=array('track'=>FALSE));
			

?>
