<?php
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
	require_once(PathHelper::getThemeFilePath('video_logic.php', 'logic'));

	$page_vars = process_logic(video_logic($_GET, $_POST, $video, $params));
	$video = $page_vars['video'];

	$page = new PublicPage();
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => $video->get('vid_title')
	));
	echo PublicPage::BeginPage($video->get('vid_title'));
	echo PublicPage::BeginPanel();
	
	echo '<div class="text-lg prose max-w-prose mx-auto">';
	echo '<div>'. $video->get_embed() . '</div>';
	echo '</div>';
	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

