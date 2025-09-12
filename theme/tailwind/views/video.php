<?php
	// PathHelper is now guaranteed available - line removed
PathHelper::requireOnce('includes/ThemeHelper.php');
	ThemeHelper::includeThemeFile('includes/PublicPage.php');
	ThemeHelper::includeThemeFile('logic/video_logic.php');

	$page_vars = video_logic($_GET, $_POST, $video, $params);
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

