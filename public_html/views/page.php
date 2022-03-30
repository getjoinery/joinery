<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php', '/includes'));

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/page_contents_class.php');

	$session = SessionControl::get_instance();

	$settings = Globalvars::get_instance();
	if(!$settings->get_setting('page_contents_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}

	if($params[0] != 'page' || !$page_content){
		require_once(LibraryFunctions::display_404_page());	
	}
	
	if(!$page_content->get('pac_is_published')){
		require_once(LibraryFunctions::display_404_page());	
	}
	
	if(!$page_content->get('pac_link') && $page_content->get('pac_script_filename')){
		//THIS IS A STANDALONE FILE
		include($page_content->get('pac_script_filename'));
		exit();
	}	
	
	$page = new PublicPageTW(TRUE);
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
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
	$page->public_footer($foptions=array('track'=>TRUE));
?>

