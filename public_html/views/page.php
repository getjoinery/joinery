<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/page_contents_class.php');

	$session = SessionControl::get_instance();

	if($params[0] != 'page' || !$page_content){
		require_once(LibraryFunctions::display_404_page());	
	}
	
	if(!$page_content || !$page_content->get('pac_is_published')){
		require_once(LibraryFunctions::display_404_page());	
	}
	
	if(!$page_content->get('pac_link') && $page_content->get('pac_script_filename')){
		//THIS IS A STANDALONE FILE
		include($page_content->get('pac_script_filename'));
		exit();
	}	
	
	$page = new PublicPage(TRUE);
	$page->public_header(array(
	'title' => 'Checkout',
	'profilenav' => TRUE,
	));
	echo PublicPage::BeginPage($page_content->get('pac_title'));
	echo '<div>'. $page_content->get_filled_content() . '</div>';
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

