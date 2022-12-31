<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php', '/includes'));

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/page_contents_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/pages_class.php');

	$session = SessionControl::get_instance();

	$settings = Globalvars::get_instance();
	if(!$settings->get_setting('page_contents_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}

	if($params[0] != 'page' || !$page){
		require_once(LibraryFunctions::display_404_page());	
	}
	
	if ($session->get_user_id() && $session->get_permission() > 4) {
		//SHOW IT EVEN IF UNPUBLISHED OR DELETED
	}
	else {
		if(!$page->get('pag_published_time') || $page->get('pag_delete_time')){
			require_once(LibraryFunctions::display_404_page());	
		}
	}	
	
	
	//TODO:  WORK IN PROGRESS...SCRIPT FILENAMES
	if($page->get('pac_script_filename')){
		//THIS IS A STANDALONE FILE
		include($page->get('pac_script_filename'));
		exit();
	}	
	
	$paget = new PublicPageTW(TRUE);
	$paget->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => $page->get('pag_title')
	));
	echo PublicPageTW::BeginPage($page->get('pag_title'));
	echo PublicPageTW::BeginPanel();
	
	echo '
    <div class="text-lg prose max-w-prose mx-auto">';
	echo '<div>'. $page->get_filled_content() . '</div>';
	echo '</div>';
	echo PublicPageTW::EndPanel();
	echo PublicPageTW::EndPage();
	$paget->public_footer($foptions=array('track'=>TRUE));
?>

