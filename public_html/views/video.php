<?php
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));
	require_once(LibraryFunctions::get_theme_file_path('FormWriterPublicTW.php', '/includes'));
	require_once (LibraryFunctions::get_logic_file_path('video_logic.php'));

	$page_vars = video_logic($_GET, $_POST, $video, $params);
	$video = $page_vars['video'];

	$page = new PublicPageTW(TRUE);
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => $video->get('vid_title')
	));
	echo PublicPageTW::BeginPage($video->get('vid_title'));
	echo PublicPageTW::BeginPanel();
	
	echo '<div class="text-lg prose max-w-prose mx-auto">';
	echo '<div>'. $video->get_embed() . '</div>';
	echo '</div>';
	echo PublicPageTW::EndPanel();
	echo PublicPageTW::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

